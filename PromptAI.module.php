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

    public function upgrade($fromVersion, $toVersion) {
        if ($fromVersion < 12 && $toVersion >= 12) {
            $this->migratePromptMatrix();
        }
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
        if (!$this->showDropdownForThisTemplate($page->template)) {
            return;
        }

        $actions = $event->return;

        // Check if individual buttons are enabled
        if ($this->individualButtons) {
            // Add individual buttons for each relevant prompt configuration
            $relevantPrompts = $this->getRelevantPrompts($page->template);

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
            if ($promptMatrixEntity->template !== null && $promptMatrixEntity->template !== $page->template->id) {
                continue;
            }

            $this->processField($page, $promptMatrixEntity);
        }
    }

    private function processField(Page $page, PromptMatrixEntity $promptMatrixEntity): void {
        $page->of(false);
        $fields = $page->template->fields;
        $sourceField = null;

        // Find the right source field by ID
        $sourceField = $fields->get($promptMatrixEntity->sourceField);

        if (!$sourceField) {
            $this->error(__('Source field with ID ') . $promptMatrixEntity->sourceField . __(' does not exist in template ') . $page->template->name);

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
        $sourceField = wire('fields')->get($promptMatrixEntity->sourceField);
        // Check if target field even exists before saving into the void. If not, use source field as target.
        if ($target) {
            $targetField = wire('fields')->get($target);
            if (!$targetField || $page->get($targetField->name) === null) {
                $target = $sourceField->name;
            } else {
                $target = $targetField->name;
            }
        } else {
            $target = $sourceField->name;
        }

        $page->setAndSave($target, $result, ['noHook' => true]);
    }

    private function processFileField(Field $field, Page $page, PromptMatrixEntity $promptMatrixEntity): void {
        $fieldName = $field->name;
        if (!$page->$fieldName) {
            return;
        }

        $targetSubfield = 'description';
        if ($promptMatrixEntity->targetField) {
            $targetField = wire('fields')->get($promptMatrixEntity->targetField);
            $targetSubfield = $targetField ? $targetField->name : 'description';
        }

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

        // If empty, return empty array
        if (empty($promptMatrixString)) {
            return $promptMatrix;
        }

        // Parse JSON format (new format)
        $jsonData = json_decode($promptMatrixString, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($jsonData)) {
            if ($showErrors) {
                $this->error(__('Invalid JSON format in prompt configuration'));
            }
            return $promptMatrix;
        }

        $availableTemplates = $this->getTemplateOptions();
        $availableFields = $this->getFieldOptions();

        foreach ($jsonData as $index => $config) {
            $promptMatrixEntity = new PromptMatrixEntity();
            $promptMatrixEntity->template = $config['template'] ?? null;
            $promptMatrixEntity->sourceField = $config['sourceField'] ?? null;
            $promptMatrixEntity->targetField = $config['targetField'] ?? null;
            $promptMatrixEntity->prompt = $config['prompt'] ?? null;
            $promptMatrixEntity->label = $config['label'] ?? null;

            // Validation
            if (!$promptMatrixEntity->sourceField) {
                if ($showErrors) {
                    wire()->error(__('Source field is missing in configuration ') . ($index + 1));
                }
                continue;
            }

            if (!$promptMatrixEntity->prompt) {
                if ($showErrors) {
                    $this->error(__('Prompt is missing in configuration ') . ($index + 1));
                }
                continue;
            }

            // Validate template ID exists (if set)
            if ($promptMatrixEntity->template && !array_key_exists($promptMatrixEntity->template, $availableTemplates)) {
                if ($showErrors) {
                    $this->error(__('Template ID does not exist in configuration ') . ($index + 1));
                }
                continue;
            }

            // Validate source field ID exists
            if (!array_key_exists($promptMatrixEntity->sourceField, $availableFields)) {
                if ($showErrors) {
                    $this->error(__('Source field ID does not exist in configuration ') . ($index + 1));
                }
                continue;
            }

            // Validate target field ID exists (if set)
            if ($promptMatrixEntity->targetField && !array_key_exists($promptMatrixEntity->targetField, $availableFields)) {
                if ($showErrors) {
                    $this->error(__('Target field ID does not exist in configuration ') . ($index + 1));
                }
                continue;
            }

            $promptMatrix[] = $promptMatrixEntity;
        }

        return $promptMatrix;
    }

    private function migratePromptMatrix(): void {
        $currentConfig = $this->get('promptMatrix');

        if (empty($currentConfig)) {
            return;
        }

        // Check if already in JSON format
        $jsonData = json_decode($currentConfig, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
            return; // Already migrated
        }

        // Parse old format and convert to new JSON format
        $newConfig = [];
        $promptMatrixRows = array_filter(array_map('trim', explode("\n", $currentConfig)));

        foreach ($promptMatrixRows as $promptMatrixRow) {
            $parts = explode('::', $promptMatrixRow);

            $templateName = $parts[0] ?? '';
            $sourceFieldName = $parts[1] ?? '';
            $targetFieldName = $parts[2] ?? '';
            $prompt = $parts[3] ?? '';
            $label = $parts[4] ?? '';

            // Skip if required fields are missing
            if (empty($sourceFieldName) || empty($prompt)) {
                continue;
            }

            // Convert names to IDs
            $templateId = null;
            if (!empty($templateName)) {
                $template = wire('templates')->get($templateName);
                $templateId = $template ? $template->id : null;
            }

            $sourceFieldId = null;
            if (!empty($sourceFieldName)) {
                $sourceField = wire('fields')->get($sourceFieldName);
                $sourceFieldId = $sourceField ? $sourceField->id : null;
            }

            $targetFieldId = null;
            if (!empty($targetFieldName)) {
                $targetField = wire('fields')->get($targetFieldName);
                $targetFieldId = $targetField ? $targetField->id : null;
            }

            // Only add if source field exists
            if ($sourceFieldId) {
                $newConfig[] = [
                    'template' => $templateId,
                    'sourceField' => $sourceFieldId,
                    'targetField' => $targetFieldId,
                    'prompt' => $prompt,
                    'label' => $label
                ];
            }
        }

        // Save new JSON format
        $jsonConfig = json_encode($newConfig, JSON_PRETTY_PRINT);
        $moduleConfig = wire('modules')->getConfig('PromptAI');
        $moduleConfig['promptMatrix'] = $jsonConfig;
        wire('modules')->saveConfig('PromptAI', $moduleConfig);

        $this->message(__('PromptAI configuration migrated to new format'));
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

                $label = $field->label ? $field->name.' ('.$field->label.')' : $field->name;
                $fieldsOptions[$field->id] = $label;
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

                $label = $template->label ? $template->name.' ('.$template->label.')' : $template->name;
                $templatesOptions[$template->id] = $label;
            }
        }

        return $templatesOptions;
    }

    private function showDropdownForThisTemplate(Template $template): bool {
        $templateId = $template ? $template->id : null;

        foreach ($this->promptMatrix as $promptMatrixEntity) {
            if ($promptMatrixEntity->template === $templateId || $promptMatrixEntity->template === null) {
                return true;
            }
        }

        return false;
    }

    private function getRelevantPrompts(Template $template): array {
        $templateId = $template ? $template->id : null;

        $relevantPrompts = [];
        foreach ($this->promptMatrix as $index => $promptMatrixEntity) {
            if ($promptMatrixEntity->template === $templateId || $promptMatrixEntity->template === null) {
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
        if ($promptMatrixEntity->template !== null && $promptMatrixEntity->template !== $page->template->id) {
            $this->error(__('This prompt configuration does not apply to the current template'));
            return;
        }

        $this->processField($page, $promptMatrixEntity);
    }
}
