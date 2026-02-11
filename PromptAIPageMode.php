<?php namespace ProcessWire;

/**
 * PromptAI Page Mode Handler
 *
 * Handles page mode functionality where AI processing happens on page save
 * via "Save + Send to AI" button(s) in the ProcessPageEdit interface.
 */
class PromptAIPageMode extends Wire {
    private PromptAI $module;

    public function __construct(PromptAI $module) {
        $this->module = $module;
    }

    /**
     * Initialize page mode hooks
     */
    public function init(): void {
        // Add "Save + Send to AI" button(s) to page editor
        $this->addHookAfter("ProcessPageEdit::getSubmitActions", $this, "addDropdownOption");

        // Process AI requests when page is saved
        $this->addHookAfter("Pages::saved", $this, "hookPageSave");
    }

    /**
     * Add "Save + Send to AI" button(s) to page editor
     */
    public function addDropdownOption(HookEvent $event): void {
        /** @var Page $page */
        $page = $this->wire('pages')->get($this->wire('input')->get->id);

        // Don't show option in admin templates
        if (in_array($page->template->name, PromptAIHelper::$adminTemplates)) {
            return;
        }

        $promptMatrix = PromptAIHelper::parsePromptMatrix($this->module->get('promptMatrix'));
        $individualButtons = (bool)$this->module->get('individualButtons');

        // Filter for page mode prompts only
        $pageModePrompts = [];
        foreach ($promptMatrix as $index => $promptEntity) {
            if ($promptEntity->mode === 'page') {
                $pageModePrompts[$index] = $promptEntity;
            }
        }

        // Check if there are relevant page mode prompts for this page
        $relevantPrompts = [];
        foreach ($pageModePrompts as $index => $promptEntity) {
            if (PromptAIHelper::templateMatches($promptEntity->templates, $page->template->id)) {
                $relevantPrompts[$index] = $promptEntity;
            }
        }

        if (count($relevantPrompts) === 0) {
            return;
        }

        $actions = $event->return;

        if ($individualButtons) {
            // Add individual button for each prompt
            foreach ($relevantPrompts as $index => $promptEntity) {
                $label = $promptEntity->label ?: __('Untitled Prompt');
                $actions[] = [
                    'value' => 'save_and_chat_' . $index,
                    'icon' => 'magic',
                    'label' => "%s + {$label}",
                ];
            }
        } else {
            // Add single "Save + Send to AI" button
            $label = "%s + " . __('Send to AI');
            $actions[] = [
                'value' => 'save_and_chat',
                'icon' => 'magic',
                'label' => $label,
            ];
        }

        $event->return = $actions;
    }

    /**
     * Hook into page save to process AI requests
     */
    public function hookPageSave(HookEvent $event): void {
        /** @var Page $page */
        $page = $event->arguments('page');

        // Check if save_and_chat button (or individual button) was clicked
        $submitAction = $this->wire('input')->post->text('_after_submit_action');

        // Check if it's a save_and_chat action (either single or individual)
        if (!str_starts_with($submitAction, 'save_and_chat')) {
            return;
        }

        // Throttle processing (only triggers every after a set amount of time)
        $throttleSave = 5;
        if ($this->wire('page')->modified > (time() - $throttleSave)) {
            $this->module->error(__('Please wait some time before you try to send again.'));
            return;
        }

        // Initialize AI agent and prompt matrix
        $this->module->initAgent();
        $promptMatrix = PromptAIHelper::parsePromptMatrix($this->module->get('promptMatrix'));

        // Check if specific prompt index was clicked (e.g., save_and_chat_0)
        if (preg_match('/^save_and_chat_(\d+)$/', $submitAction, $matches)) {
            $promptIndex = (int)$matches[1];
            $this->processSpecificPrompt($page, $promptMatrix, $promptIndex);
        } else {
            // Process all page mode prompts
            $this->processPrompts($page, $promptMatrix);
        }
    }

    /**
     * Process all relevant page mode prompts for a page
     */
    private function processPrompts(Page $page, array $promptMatrix): void {
        foreach ($promptMatrix as $promptMatrixEntity) {
            // Only process page mode prompts
            if ($promptMatrixEntity->mode !== 'page') {
                continue;
            }

            // Check template match
            if (!PromptAIHelper::templateMatches($promptMatrixEntity->templates, $page->template->id)) {
                continue;
            }

            // Process all fields in this prompt's fields array
            $this->processPromptFields($page, $promptMatrixEntity);
        }
    }

    /**
     * Process a specific prompt by index
     */
    private function processSpecificPrompt(Page $page, array $promptMatrix, int $promptIndex): void {
        // Check if prompt exists at index
        if (!isset($promptMatrix[$promptIndex])) {
            $this->module->error(__('Prompt configuration not found'));
            return;
        }

        $promptMatrixEntity = $promptMatrix[$promptIndex];

        // Verify it's a page mode prompt
        if ($promptMatrixEntity->mode !== 'page') {
            return;
        }

        // Check template match
        if (!PromptAIHelper::templateMatches($promptMatrixEntity->templates, $page->template->id)) {
            return;
        }

        // Process all fields in this prompt's fields array
        $this->processPromptFields($page, $promptMatrixEntity);
    }

    /**
     * Process all fields in a prompt configuration for a page
     */
    private function processPromptFields(Page $page, PromptMatrixEntity $promptMatrixEntity): void {
        $page->of(false);

        // Loop through each field in the prompt's fields array
        foreach ($promptMatrixEntity->fields as $fieldId) {
            $field = $this->wire('fields')->get($fieldId);

            if (!$field) {
                $this->module->error(__('Field with ID ') . $fieldId . __(' does not exist'));
                continue;
            }

            // Check if field exists in page template
            if (!$page->template->fields->has($field)) {
                continue; // Skip fields not in this template
            }

            // Process based on field type
            if (in_array(get_class($field->type), PromptAIHelper::$fileFieldTypes)) {
                $this->processFileField($field, $page, $promptMatrixEntity);
            } elseif (in_array(get_class($field->type), PromptAIHelper::$textFieldTypes)) {
                $this->processTextField($field, $page, $promptMatrixEntity);
            }
        }
    }

    /**
     * Process a text field with AI (field is both source and target)
     */
    private function processTextField(Field $field, Page $page, PromptMatrixEntity $promptMatrixEntity): void {
        $value = $page->get($field->name);

        // Check overwriteTarget: if false, only process empty fields
        if (!$promptMatrixEntity->overwriteTarget && (string)$value) {
            $fieldLabel = $field->label ?: $field->name;
            $this->module->warning(__('Field skipped (already has content): ') . $fieldLabel);
            return; // Field has content and overwrite is disabled
        }

        // For empty fields, there's nothing to process
        if (!$value) {
            return;
        }

        // Determine if we're in a repeater context
        $context = PromptAIHelper::getRepeaterContext($page);

        // Combine prompt with field content (with placeholder substitution)
        $finalPrompt = PromptAIHelper::substituteAndPreparePrompt(
            $promptMatrixEntity->prompt,
            $context['parentPage'],
            $value,
            $context['repeaterItem']
        );
        $result = $this->module->chat($finalPrompt);

        if (!$result) {
            return;
        }

        // Write result back to the same field
        $page->setAndSave($field->name, $result, ['noHook' => true]);
    }

    /**
     * Process a file field with AI (processes file descriptions)
     */
    private function processFileField(Field $field, Page $page, PromptMatrixEntity $promptMatrixEntity): void {
        $fieldName = $field->name;
        if (!$page->$fieldName) {
            return;
        }

        if ($this->module->get('provider') === 'deepseek') {
            $this->module->error(__('DeepSeek is currently not supported for file or image fields.'));
            return;
        }

        $page->of(false);

        $targetSubfield = $promptMatrixEntity->targetSubfield ?? 'description';
        $skippedFiles = [];

        /** @var PageImage $file */
        foreach ($page->$fieldName as $file) {
            // Check if target subfield already has content and overwrite is disabled
            if (!$promptMatrixEntity->overwriteTarget && (string)$file->$targetSubfield) {
                $skippedFiles[] = $file->basename;
                continue; // Skip files with existing descriptions when overwrite is disabled
            }

            $isImage = ((string)$field->type === 'FieldtypeImage');
            $filePath = ($isImage) ? $file->width(800)->filename : $file->filename;
            $attachmentType = ($isImage) ? 'image' : 'document';

            // Determine if we're in a repeater context
            $context = PromptAIHelper::getRepeaterContext($page);

            // Send file to AI with prompt (with placeholder substitution)
            $finalPrompt = PromptAIHelper::substituteAndPreparePrompt(
                $promptMatrixEntity->prompt,
                $context['parentPage'],
                '',
                $context['repeaterItem']
            );
            $result = $this->module->chat($finalPrompt, true, $filePath, $attachmentType);

            if (!$result) {
                continue;
            }

            try {
                $file->$targetSubfield = $result;
                $file->save();
            } catch (\Exception $e) {
                $this->module->error($e->getMessage());
            }
        }

        // Show warning if any files were skipped
        if (!empty($skippedFiles)) {
            $fieldLabel = $field->label ?: $field->name;
            $fileList = implode(', ', $skippedFiles);
            $this->module->warning(__('Files skipped in field ') . $fieldLabel . __(': ') . $fileList);
        }
    }
}
