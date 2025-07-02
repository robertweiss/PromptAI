<?php namespace ProcessWire;

require_once __DIR__.'/PromptAIAgent.php';
require_once __DIR__.'/PromptAIConfigForm.php';

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
        'ProcessWire\FieldtypeFile',
    ];

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
            $this->migratePromptMatrix();
        }
        if ($fromVersion < 15 && $toVersion >= 15) {
            $this->migrateTemplateToArray();
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
        if (!$this->showDropdownForThisPage($page)) {
            return;
        }

        $actions = $event->return;

        // Check if individual buttons are enabled
        if ($this->get('individualButtons')) {
            // Add individual buttons for each relevant prompt configuration
            $relevantPrompts = $this->getRelevantPrompts($page);

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
            if (!$this->templateMatches($promptMatrixEntity->template, $page->template->id)) {
                continue;
            }

            $this->processField($page, $promptMatrixEntity);
        }
    }

    /**
     * Check if a template configuration matches a given template ID
     * Template configuration is always an array or null (for all templates)
     */
    private function templateMatches($configTemplate, int $pageTemplateId): bool {
        if ($configTemplate === null || empty($configTemplate)) {
            return true; // null/empty means all templates
        }

        return is_array($configTemplate) && in_array($pageTemplateId, $configTemplate);
    }

    private function processField(Page $page, PromptMatrixEntity $promptMatrixEntity): void {
        $page->of(false);
        // Template can differ from Page template when a repeater field is set as template
        $template = wire('templates')->get($promptMatrixEntity->template);

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
        if (in_array(get_class($sourceField->type), $this->fileFieldTypes)) {
            $this->processFileField($sourceField, $page, $promptMatrixEntity);
        }

        // Process text field
        if (in_array(get_class($sourceField->type), $this->textFieldTypes)) {
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
        if (in_array(get_class($sourceField->type), $this->fileFieldTypes)) {
            $this->processFileField($sourceField, $item, $promptMatrixEntity);
        }

        // Process text field
        if (in_array(get_class($sourceField->type), $this->textFieldTypes)) {
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
            if (!$this->overwriteTarget && (string) $file->$targetSubfield) {
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
                    wire()->error(__('Source field is missing in configuration ').($index + 1));
                }
                continue;
            }

            if (!$promptMatrixEntity->prompt) {
                if ($showErrors) {
                    $this->error(__('Prompt is missing in configuration ').($index + 1));
                }
                continue;
            }

            // Validate template IDs exist (if set)
            if ($promptMatrixEntity->template && is_array($promptMatrixEntity->template)) {
                foreach ($promptMatrixEntity->template as $templateId) {
                    if (!array_key_exists($templateId, $availableTemplates)) {
                        if ($showErrors) {
                            $this->error(__('Template ID ').$templateId.__(' does not exist in configuration ').($index + 1));
                        }
                        continue 2; // Skip this entire configuration
                    }
                }
            }

            // Validate source field ID exists
            if (!array_key_exists($promptMatrixEntity->sourceField, $availableFields)) {
                if ($showErrors) {
                    $this->error(__('Source field ID does not exist in configuration ').($index + 1));
                }
                continue;
            }

            // Validate target field ID exists (if set)
            if ($promptMatrixEntity->targetField && !array_key_exists($promptMatrixEntity->targetField, $availableFields)) {
                if ($showErrors) {
                    $this->error(__('Target field ID does not exist in configuration ').($index + 1));
                }
                continue;
            }

            $promptMatrix[] = $promptMatrixEntity;
        }

        ray($promptMatrix);

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
                    'label' => $label,
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

    private function migrateTemplateToArray(): void {
        $currentConfig = $this->get('promptMatrix');

        if (empty($currentConfig)) {
            return;
        }

        // Parse JSON format
        $jsonData = json_decode($currentConfig, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($jsonData)) {
            return; // Invalid format, skip migration
        }

        $migrated = false;
        foreach ($jsonData as &$config) {
            // Check if template is a single integer and convert to array
            if (isset($config['template']) && is_int($config['template'])) {
                $config['template'] = [$config['template']];
                $migrated = true;
            }
        }

        // Save updated configuration if changes were made
        if ($migrated) {
            $jsonConfig = json_encode($jsonData, JSON_PRETTY_PRINT);
            $moduleConfig = wire('modules')->getConfig('PromptAI');
            $moduleConfig['promptMatrix'] = $jsonConfig;
            wire('modules')->saveConfig('PromptAI', $moduleConfig);

            $this->message(__('PromptAI template configuration migrated to array format'));
        }
    }

    public function getFieldOptions() {
        $fieldsOptions = [];
        if (wire('fields')) {
            /** @var Field $field */
            foreach (wire('fields') as $field) {
                if ($field->flags && ($field->flags === Field::flagSystem || $field->flags === 24)) {
                    continue;
                }
                if (!in_array(get_class($field->type), $this->textFieldTypes) && !in_array(get_class($field->type), $this->fileFieldTypes)) {
                    continue;
                }

                $label = $field->label ? $field->label.' ('.$field->name.')' : $field->name;
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

                $label = $template->label ? $template->label.' ('.$template->name.')' : $template->name;
                if (str_starts_with($template->name, 'repeater_')) {
                    $name = str_replace('repeater_', '', $template->name);
                    $label = 'Repeater: '.$name;
                }
                $templatesOptions[$template->id] = $label;
            }
        }

        return $templatesOptions;
    }

    private function showDropdownForThisPage(Page $page): bool {
        $template = $page ? $page->template : null;
        foreach ($this->promptMatrix as $promptMatrixEntity) {
            // Check regular template matches first
            if ($this->templateMatches($promptMatrixEntity->template, $template->id)) {
                return true;
            }
            
            // Check repeater templates
            if (is_array($promptMatrixEntity->template)) {
                foreach ($promptMatrixEntity->template as $templateId) {
                    $entityTemplate = wire('templates')->get($templateId);
                    if ($entityTemplate && str_starts_with($entityTemplate->name, 'repeater_')) {
                        $repeaterName = str_replace('repeater_', '', $entityTemplate->name);
                        if ($page->$repeaterName) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function getRelevantPrompts(Page $page): array {
        $template = $page ? $page->template : null;

        $relevantPrompts = [];
        foreach ($this->promptMatrix as $index => $promptMatrixEntity) {
            if ($this->templateMatches($promptMatrixEntity->template, $template->id)) {
                $relevantPrompts[$index] = $promptMatrixEntity;
                continue;
            }

            // Handle repeater templates
            if (is_array($promptMatrixEntity->template)) {
                foreach ($promptMatrixEntity->template as $templateId) {
                    $entityTemplate = wire('templates')->get($templateId);
                    if ($entityTemplate && str_starts_with($entityTemplate->name, 'repeater_')) {
                        $repeaterName = str_replace('repeater_', '', $entityTemplate->name);
                        if ($page->$repeaterName) {
                            $relevantPrompts[$index] = $promptMatrixEntity;
                            break; // Found a matching repeater, no need to check others
                        }
                    }
                }
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
        $relevantPrompts = $this->getRelevantPrompts($page);
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

        $this->processField($page, $promptMatrixEntity);
    }
}
