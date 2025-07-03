<?php namespace ProcessWire;

require_once __DIR__.'/PromptAIAgent.php';
require_once __DIR__.'/PromptAIConfigForm.php';
require_once __DIR__.'/PromptAIHelper.php';

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Chat\Attachments\Image;
use NeuronAI\Chat\Attachments\Document;
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
    private bool $overwriteTarget;

    public function ___execute() {
        $configForm = new PromptAIConfigForm();

        // Handle form submission
        if (wire('input')->post('submit_prompt_config')) {
            $configForm->processSubmission();
            $this->session->redirect($this->page->url);
        }

        return $configForm->render();
    }

    public function upgrade($fromVersion, $toVersion) {
        if ($fromVersion < 12 && $toVersion >= 12) {
            PromptAIHelper::migratePromptMatrix($this);
        }
        if ($fromVersion < 15 && $toVersion >= 15) {
            PromptAIHelper::migrateTemplateToArray($this);
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
        $this->overwriteTarget = (bool)$this->get('overwriteTarget');
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
        $this->promptMatrix = PromptAIHelper::parsePromptMatrix($this->promptMatrixString);

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
        if (in_array($page->template->name, PromptAIHelper::$adminTemplates)) {
            return;
        }

        $this->promptMatrix = PromptAIHelper::parsePromptMatrix($this->promptMatrixString);

        // Check if there are relevant prompts for this page
        $relevantPrompts = PromptAIHelper::getRelevantPrompts($page, $this->promptMatrix);

        if (count($relevantPrompts) === 0) {
            return;
        }

        $actions = $event->return;

        // Check if individual buttons are enabled
        if ($this->get('individualButtons')) {
            foreach ($relevantPrompts as $index => $promptEntity) {
                $label = $promptEntity->label ?: __('Send to AI');
                $buttonLabel = "%s + ".$label;

                $actions[] = [
                    'value' => 'save_and_chat_'.$index,
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

    public function chat(string $prompt, bool $returnText = true, ?string $file = null, $fileType = 'image'): string|AssistantMessage|Message {
        if (!$prompt) {
            return '';
        }

        try {
            $message = new UserMessage($prompt);
            if ($file) {
                $fileContents = file_get_contents($file);
                $mimeType = mime_content_type($file);

                if ($fileContents) {
                    if ($fileType === 'image') {
                        // Validate image MIME types
                        $supportedImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

                        if (!in_array($mimeType, $supportedImageTypes)) {
                            throw new \Exception("Unsupported image type: {$mimeType}. Supported types: ".implode(', ', $supportedImageTypes));
                        }

                        $message->addAttachment(
                            new Image(
                                base64_encode($fileContents), AttachmentContentType::BASE64, $mimeType
                            )
                        );
                    } else {
                        // Validate document MIME types
                        $supportedDocTypes = [
                            'application/pdf',
                            'text/plain',
                            'text/csv',
                            'application/rtf',
                            'text/rtf',
                            'text/markdown',
                            'application/json',
                            'text/json',
                            'application/xml',
                            'text/xml',
                        ];

                        if (!in_array($mimeType, $supportedDocTypes)) {
                            throw new \Exception("Unsupported document type: {$mimeType}. Supported types: ".implode(', ', $supportedDocTypes));
                        }

                        $message->addAttachment(
                            new Document(
                                base64_encode($fileContents), AttachmentContentType::BASE64, $mimeType
                            )
                        );
                    }
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
            if (!PromptAIHelper::templateMatches($promptMatrixEntity->template, $page->template->id)) {
                continue;
            }

            $this->processFields($page, $promptMatrixEntity);
        }
    }

    private function processSpecificPrompt(Page $page, int $promptIndex): void {
        if (!isset($this->promptMatrix[$promptIndex])) {
            $this->error(__('Invalid prompt configuration index'));

            return;
        }

        $promptMatrixEntity = $this->promptMatrix[$promptIndex];
        $relevantPrompts = PromptAIHelper::getRelevantPrompts($page, $this->promptMatrix);
        $isAllowedPrompt = false;
        foreach ($relevantPrompts as $promptEntity) {
            if ($promptEntity === $promptMatrixEntity) {
                $isAllowedPrompt = true;
            }
        }
        // Check if this prompt applies to the current template
        if (!$isAllowedPrompt) {
            $this->error(__('This prompt configuration does not apply to the current template'));

            return;
        }

        $this->processFields($page, $promptMatrixEntity);
    }

    private function processFields(Page $page, PromptMatrixEntity $promptMatrixEntity): void {
        if ($promptMatrixEntity->template) {
            foreach ($promptMatrixEntity->template as $templateId) {
                $this->processField($page, $promptMatrixEntity, $templateId);
            }
        } else {
                $this->processField($page, $promptMatrixEntity, $page->template->id);
        }
    }

    private function processField(Page $page, PromptMatrixEntity $promptMatrixEntity, int $templateId): void {
        $page->of(false);
        // Template can differ from Page template when a repeater field is set as template
        $template = wire('templates')->get($templateId);

        // Handle Repeater fields
        if (str_starts_with($template->name, 'repeater_')) {
            $repeaterFieldName = str_replace('repeater_', '', $template->name);
            $repeater = $page->get($repeaterFieldName);

            if (!$repeater || !$repeater->count()) {
                return;
            }

            foreach ($repeater as $item) {
                $this->processRepeaterItem($item, $promptMatrixEntity);
            }

            return;
        }

        $fields = $page->template->fields;
        // Find the right source field by ID
        $sourceField = $fields->get($promptMatrixEntity->sourceField);

        if (!$sourceField) {
            $this->error(__('Source field with ID ').$promptMatrixEntity->sourceField.__(' does not exist in template ').$page->template->name);

            return;
        }

        // Process file field
        if (in_array(get_class($sourceField->type), PromptAIHelper::$fileFieldTypes)) {
            $this->processFileField($sourceField, $page, $promptMatrixEntity);
        }

        // Process text field
        if (in_array(get_class($sourceField->type), PromptAIHelper::$textFieldTypes)) {
            $this->processTextField($sourceField, $page, $promptMatrixEntity);
        }
    }

    private function processRepeaterItem(Page $item, PromptMatrixEntity $promptMatrixEntity): void {
        $item->of(false);
        $fields = $item->template->fields;

        // Find the right source field by ID
        $sourceField = $fields->get($promptMatrixEntity->sourceField);

        if (!$sourceField) {
            $this->error(__('Source field with ID ').$promptMatrixEntity->sourceField.__(' does not exist in repeater template ').$item->template->name);

            return;
        }

        // Process file field
        if (in_array(get_class($sourceField->type), PromptAIHelper::$fileFieldTypes)) {
            $this->processFileField($sourceField, $item, $promptMatrixEntity);
        }

        // Process text field
        if (in_array(get_class($sourceField->type), PromptAIHelper::$textFieldTypes)) {
            $this->processTextField($sourceField, $item, $promptMatrixEntity);
        }
    }

    private function processTextField(Field $field, Page $page, PromptMatrixEntity $promptMatrixEntity): void {
        $value = $page->get($field->name);

        // Field content is empty, skip
        if (!$value) {
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

        // Check if target field already has content and overwrite is disabled
        if (!$this->overwriteTarget && (string)$page->get($target)) {
            return;
        }

        $content = trim($promptMatrixEntity->prompt.PHP_EOL.$value);
        $result = $this->chat($content);

        if (!$result) {
            return;
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
        foreach ($page->$fieldName as $file) {
            // Check if target subfield already has content and overwrite is disabled
            if (!$this->overwriteTarget && (string)$file->$targetSubfield) {
                continue;
            }

            $isImage = ((string)$field->type === 'FieldtypeImage');
            $filePath = ($isImage) ? $file->width(800)->filename : $file->filename;
            $attachmentType = ($isImage) ? 'image' : 'document';
            $result = $this->chat($promptMatrixEntity->prompt, true, $filePath, $attachmentType);

            try {
                $file->$targetSubfield = $result;
                $file->save();
            } catch (\Exception $e) {
                $this->error($e->getMessage());
            }
        }
    }
}
