<?php namespace ProcessWire;

require_once __DIR__.'/PromptAIAgent.php';
require_once __DIR__.'/PromptAIConfigForm.php';

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Chat\Attachments\Image;
use NeuronAI\Exceptions\NeuronException;

class PromptAI extends Process implements Module {
    private string $provider;
    private string $model;
    private string $apiKey;
    public ?PromptAIAgent $agent;
    private ?string $systemPrompt;
    private ?string $promptMatrixString;
    private ?array $promptMatrix;
    private int $throttleSave;

    private array $adminTemplates = ['admin', 'language', 'user', 'permission', 'role'];

    private array $textFieldTypes = [
        'ProcessWire\FieldtypePageTitle',
        'ProcessWire\FieldtypePageTitleLanguage',
        'ProcessWire\FieldtypeText',
        'ProcessWire\FieldtypeTextarea',
        'ProcessWire\FieldtypeTextLanguage',
        'ProcessWire\FieldtypeTextareaLanguage',
    ];

    private array $fileFieldTypes = [
        'ProcessWire\FieldtypeImage',
    ];

    public function ___execute() {
        $configForm = new PromptAIConfigForm();

        // Handle form submission
        if(wire('input')->post('submit_prompt_config')) {
            $configForm->processSubmission();
            $this->session->redirect($this->page->url);
        }

        return $configForm->render();
    }

    public function init() {
        if ($this->initSettings()) {
            $this->addHookAfter("ProcessPageEdit::getSubmitActions", $this, "addDropdownOption");
            $this->addHookAfter("Pages::saved", $this, "hookPageSave");
        }

        parent::init();
    }

    public function initSettings(): bool {
        // Set (user-)settings
        $this->provider = $this->get('provider');
        $this->model = $this->get('model');
        $this->apiKey = $this->get('apiKey');
        $this->systemPrompt = $this->get('systemPrompt');
        $this->promptMatrixString = $this->get('promptMatrix');
        $this->throttleSave = 5;

        return ($this->apiKey !== '' && $this->provider !== '' && $this->model !== '');
    }

    private function initAgent(): void {
        $this->agent = PromptAIAgent::make([
                                               'apiKey' => $this->apiKey,
                                               'provider' => $this->provider,
                                               'model' => $this->model,
                                               'systemPrompt' => $this->systemPrompt,
                                           ]);
    }

    public function hookPageSave($event): void {
        /** @var Page $page */
        $page = $event->arguments('page');

        // Only start the magic if post variable is set
        $submitAction = $this->input->post->text('_after_submit_action');

        if (!str_contains($submitAction, 'save_and_chat')) {
            return;
        }

        // Throttle processing (only triggers every after a set amount of time)
        if ($this->page->modified > (time() - $this->throttleSave)) {
            $this->error(__('Please wait some time before you try to send again.'));

            return;
        }

        $this->initAgent();
        $this->promptMatrix = $this->parsePromptMatrix($this->promptMatrixString);

        // Let’s go!
        // Check if a specific prompt should be processed
        if (preg_match('/save_and_chat_(\d+)/', $submitAction, $matches)) {
            $promptIndex = (int)$matches[1];
            $this->processSpecificPrompt($page, $promptIndex);
        } else {
            // Process all prompts (default behavior)
            $this->processPrompts($page);
        }
    }

    public function addDropdownOption($event): void {
        /** @var Page $page */
        $page = $this->pages->get($this->input->get->id);

        // Don’t show option in admin templates
        if (in_array($page->template->name, $this->adminTemplates)) {
            return;
        }

        $this->promptMatrix = $this->parsePromptMatrix($this->promptMatrixString);

        // Only show option if promptMatrix has page template or if there is a wildcard prompt
        if (!$this->showDropdownForThisTemplate($page->template->name)) {
            return;
        }

        $actions = $event->return;

        // Check if individual buttons are enabled
        if ($this->individualButtons) {
            // Add individual buttons for each relevant prompt configuration
            $relevantPrompts = $this->getRelevantPrompts($page->template->name);

            foreach ($relevantPrompts as $index => $promptEntity) {
                $label = $promptEntity->label ?: __('Send to AI');
                $buttonLabel = "%s + " . $label;

                $actions[] = [
                    'value' => 'save_and_chat_' . $index,
                    'icon' => 'magic',
                    'label' => $buttonLabel,
                ];
            }
        } else {
            // Add single general button
            $label = "%s + ".__('Send to AI');

            $actions[] = [
                'value' => 'save_and_chat',
                'icon' => 'magic',
                'label' => $label,
            ];
        }

        $event->return = $actions;
    }

    public function chat(string $prompt, bool $returnText = true, ?string $file = null): string|AssistantMessage|Message {
        if (!$prompt) {
            return '';
        }

        try {
            $message = new UserMessage($prompt);
            if ($file) {
                $fileContents = file_get_contents($file);
                $mimeType = mime_content_type($file);
                if ($fileContents) {
                    $message->addAttachment(
                        new Image(
                            base64_encode($fileContents), AttachmentContentType::BASE64, $mimeType
                        )
                    );
                }
            }
            $response = $this->agent->chat($message);
        } catch (NeuronException $e) {
            $this->error($e->getMessage());

            return '';
        }

        try {
            $result = $response->getContent();
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return '';
        }

        if (isset($result->error)) {
            $this->error($result->error->message);

            return '';
        }

        return $returnText ? $result : $response;
    }

    public function testConnection(): string {
        $this->initAgent();

        if (!isset($this->agent)) {
            return __('No API key set');
        }

        $request = __('This is a test for the AI. Do you hear me?');
        $response = $this->chat($request, false);;

        return json_encode([
                               'request' => $request,
                               'response' => $response,
                           ], JSON_PRETTY_PRINT);
    }

    private function processPrompts(Page $page): void {
        foreach ($this->promptMatrix as $promptMatrixEntity) {
            if ($promptMatrixEntity->template !== '' && $promptMatrixEntity->template !== $page->template->name) {
                continue;
            }

            $this->processField($page, $promptMatrixEntity);
        }
    }

    private function processField(Page $page, PromptMatrixEntity $promptMatrixEntity): void {
        $page->of(false);
        $fields = $page->template->fields;
        $sourceField = null;

        /** @var Field $field */
        foreach ($fields as $field) {
            // Find the right source field
            if ($field->name === $promptMatrixEntity->sourceField) {
                $sourceField = $field;
                break;
            }
        }

        if (!$sourceField) {
            $this->error(__('Source field does not exist in template "'.$page->template->name.'"'));

            return;
        }

        // Process file field
        if (in_array(get_class($field->type), $this->fileFieldTypes)) {
            $this->processFileField($field, $page, $promptMatrixEntity);
        }

        // Process text field
        if (in_array(get_class($field->type), $this->textFieldTypes)) {
            $this->processTextField($field, $page, $promptMatrixEntity);
        }
    }

    private function processTextField(Field $field, Page $page, PromptMatrixEntity $promptMatrixEntity): void {
        $value = $page->get($field->name);

        // Field content is empty, skip
        if (!$value) {
            return;
        }

        $content = trim($promptMatrixEntity->prompt.PHP_EOL.$value);
        $result = $this->chat($content);

        if (!$result) {
            return;
        }

        $target = $promptMatrixEntity->targetField;
        // Check if target field even exists before saving into the void. If not, use source field as target.
        if ($page->get($target) === null) {
            $target = $field->name;
        }

        $page->setAndSave($target, $result, ['noHook' => true]);
    }

    private function processFileField(Field $field, Page $page, PromptMatrixEntity $promptMatrixEntity): void {
        $fieldName = $field->name;
        if (!$page->$fieldName) {
            return;
        }

        $targetSubfield = $promptMatrixEntity->targetField ?: 'description';;

        $page->of(false);
        /** @var PageImage $image */
        foreach ($page->$fieldName as $image) {
            $file = $image->width(800)->filename;
            $result = $this->chat($promptMatrixEntity->prompt, true, $file);

            try {
                $image->$targetSubfield = $result;
                $image->save();
            } catch (\Exception $e) {
                $this->error($e->getMessage());
            }
        }
    }

    public function parsePromptMatrix(?string $promptMatrixString = '', $showErrors = false): array {
        $promptMatrix = [];
        $promptMatrixRows = array_filter(array_map('trim', explode("\n", $promptMatrixString)));
        $c = 0;
        foreach ($promptMatrixRows as $promptMatrixRow) {
            $c++;
            $promptMatrixRow = explode('::', $promptMatrixRow);
            $promptMatrixEntity = new PromptMatrixEntity();
            $promptMatrixEntity->template = $promptMatrixRow[0] ?? null;
            $promptMatrixEntity->sourceField = $promptMatrixRow[1] ?? null;
            $promptMatrixEntity->targetField = $promptMatrixRow[2] ?? null;
            $promptMatrixEntity->prompt = $promptMatrixRow[3] ?? null;
            $promptMatrixEntity->label = $promptMatrixRow[4] ?? null;

            // Is source field set?
            if (!$promptMatrixEntity->sourceField) {
                if ($showErrors) {
                    wire()->error(__('Source field is missing in line '.$c));;
                }
                continue;
            }

            // Is prompt set?
            if (!$promptMatrixEntity->prompt) {
                if ($showErrors) {
                    $this->error(__('Prompt is missing in line '.$c));;
                }
                continue;
            }

            $availableTemplates = $this->getTemplateOptions();
            $availableFields = $this->getFieldOptions();

            // Does template name exist (if set)?
            if ($promptMatrixEntity->template && !in_array($promptMatrixEntity->template, $availableTemplates)) {
                if ($showErrors) {
                    $this->error(__('Template does not exist in line '.$c));
                }
                continue;
            }

            // Does source field name exist?
            if (!in_array($promptMatrixEntity->sourceField, $availableFields)) {
                if ($showErrors) {
                    $this->error(__('Source field does not exist in line '.$c));
                }
                continue;
            }

            // Does target field name exist (if set)?
            if ($promptMatrixEntity->targetField && !in_array($promptMatrixEntity->targetField, $availableFields)) {
                if ($showErrors) {
                    $this->error(__('Target field does not exist in line '.$c));
                }
                continue;
            }

            $promptMatrix[] = $promptMatrixEntity;
        }

//        bd($promptMatrix);

        return $promptMatrix;
    }

    public function getFieldOptions() {
        $fieldsOptions = [];
        if (wire('fields')) {
            foreach (wire('fields') as $field) {
                if ($field->flags && $field->flags === Field::flagSystem) {
                    continue;
                }
                if (!in_array(get_class($field->type), $this->textFieldTypes) && !in_array(get_class($field->type), $this->fileFieldTypes)) {
                    continue;
                }

                $fieldsOptions[] = $field->name;
            }
        }

        return $fieldsOptions;
    }

    public function getTemplateOptions() {
        $templatesOptions = [];
        if (wire('templates')) {
            foreach (wire('templates') as $template) {
                if (in_array($template->name, $this->adminTemplates)) {
                    continue;
                }
                if ($template->flags && $template->flags === Template::flagSystem) {
                    continue;
                }

                $templatesOptions[] = $template->name;
            }
        }

        return $templatesOptions;
    }

    private function showDropdownForThisTemplate(string $templateName): bool {
        foreach ($this->promptMatrix as $promptMatrixEntity) {
            if ($promptMatrixEntity->template === $templateName || $promptMatrixEntity->template === '') {
                return true;
            }
        }

        return false;
    }

    private function getRelevantPrompts(string $templateName): array {
        $relevantPrompts = [];
        foreach ($this->promptMatrix as $index => $promptMatrixEntity) {
            if ($promptMatrixEntity->template === $templateName || $promptMatrixEntity->template === '') {
                $relevantPrompts[$index] = $promptMatrixEntity;
            }
        }
        return $relevantPrompts;
    }

    private function processSpecificPrompt(Page $page, int $promptIndex): void {
        if (!isset($this->promptMatrix[$promptIndex])) {
            $this->error(__('Invalid prompt configuration index'));
            return;
        }

        $promptMatrixEntity = $this->promptMatrix[$promptIndex];

        // Check if this prompt applies to the current template
        if ($promptMatrixEntity->template !== '' && $promptMatrixEntity->template !== $page->template->name) {
            $this->error(__('This prompt configuration does not apply to the current template'));
            return;
        }

        $this->processField($page, $promptMatrixEntity);
    }
}
