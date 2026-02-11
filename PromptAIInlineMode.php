<?php namespace ProcessWire;

/**
 * PromptAI Inline Mode Handler
 *
 * Handles inline mode functionality where AI buttons appear directly on fields
 * using ProcessWire's native render hooks instead of JavaScript injection.
 */
class PromptAIInlineMode extends Wire {
    private PromptAI $module;
    private ?array $promptMatrix = null;

    public function __construct(PromptAI $module) {
        $this->module = $module;
    }

    /**
     * Initialize inline mode hooks
     */
    public function init(): void {
        // Hook to add buttons to specific fields during rendering
        $this->addHookAfter("Inputfield::renderReadyHook", $this, "addButton");

        // Hook to inject JavaScript and CSS assets
        $this->addHookAfter("ProcessPageEdit::buildForm", $this, "injectAssets");
    }

    /**
     * Add AI button to fields using ProcessWire's native header actions
     */
    public function addButton($event): void {
        /** @var Inputfield $inputfield */
        $inputfield = $event->object;

        // Get the page that this inputfield actually belongs to
        // For repeater fields, this will be the repeater item page
        // For regular fields, this will be the main page
        $page = $inputfield->hasPage;
        if (!$page || !$page->id) return;

        // Skip admin templates
        if (in_array($page->template->name, PromptAIHelper::$adminTemplates)) {
            return;
        }

        // Get field being rendered
        $fieldName = $inputfield->attr('name');
        if (!$fieldName) return;

        // Extract actual field name (handle repeater fields, language fields, etc.)
        $actualFieldName = $this->extractFieldName($fieldName);
        $field = $this->wire('fields')->get($actualFieldName);
        if (!$field) return;

        // Check if this is a supported field type
        $fieldType = get_class($field->type);
        $isTextfield = in_array($fieldType, PromptAIHelper::$textFieldTypes);
        $isFileField = in_array($fieldType, PromptAIHelper::$fileFieldTypes);

        if (!$isTextfield && !$isFileField) return;

        // Get relevant prompts for this field
        $relevantPrompts = $this->getRelevantPromptsForField($page, $field);

        if (count($relevantPrompts) === 0) return;

        // Mark fields for JavaScript to add buttons
        // Both text fields and file fields now use JavaScript-injected buttons for consistent positioning
        if ($isFileField) {
            // Mark this field so JavaScript knows to add buttons to each file's description input
            $inputfield->wrapAttr('data-promptai-filefield', '1');
            $inputfield->wrapAttr('data-promptai-prompts', json_encode(array_keys($relevantPrompts)));
            $inputfield->wrapAttr('data-promptai-page-id', $page->id); // Add page ID for repeaters
        } else {
            // Mark this field so JavaScript knows to add button after the input
            $inputfield->wrapAttr('data-promptai-textfield', '1');
            $inputfield->wrapAttr('data-promptai-prompts', json_encode(array_keys($relevantPrompts)));
        }
    }

    /**
     * Inject JavaScript and CSS assets
     */
    public function injectAssets($event): void {
        $moduleUrl = $this->wire('config')->urls->siteModules . 'PromptAI/';

        // Get prompts with indices preserved
        $this->promptMatrix = PromptAIHelper::parsePromptMatrix(
            $this->module->get('promptMatrix'),
            false
        );

        $prompts = [];
        foreach ($this->promptMatrix as $index => $promptEntity) {
            $prompts[$index] = [
                'label' => $promptEntity->label ?: __('Untitled Prompt'),
                'prompt' => $promptEntity->prompt,
                'targetSubfield' => $promptEntity->targetSubfield ?? 'description',
            ];
        }

        // Add JavaScript config
        $ajaxUrl = $this->wire('config')->urls->admin . 'setup/prompt-ai/?action=inline_process';

        $this->wire('config')->js('PromptAIInlineMode', [
            'prompts' => $prompts,
            'ajaxUrl' => $ajaxUrl,
            'pageId' => $this->wire('input')->get->int('id'),
            'useNativeButtons' => true,
        ]);

        // Add CSS and JavaScript files
        $this->wire('config')->styles->add($moduleUrl . 'styles.css');
        $this->wire('config')->scripts->add($moduleUrl . 'inline-mode.js');
    }

    /**
     * Extract actual field name from various field naming patterns
     *
     * Handles:
     * - Repeater fields: images_repeater1041 -> images, copy2_repeater1041 -> copy2
     * - Language fields: field__1234 -> field
     * - File/image subfield inputs: description_images_abc123 -> images, alt_text_images_abc123 -> images
     */
    private function extractFieldName(string $fieldName): string {
        // Handle file/image subfield inputs: {subfield}{langid?}_{fieldname}{_repeaterXXXX?}_{hash}
        // The hash is always a 32-char hex string at the end.
        // Subfield names can contain underscores (e.g., alt_text, custom_field).
        // Strategy: match hash at end, then split the middle part to find the field name.
        // Examples: description_images_abc123, alt_text_images_def456, description1234_images_repeater1042_abc123
        if (preg_match('/^(.+)_([a-f0-9]{32})$/i', $fieldName, $hashMatch)) {
            $prefix = $hashMatch[1]; // Everything before the hash

            // Check for repeater suffix: ..._fieldname_repeaterXXXX
            // Match _repeater\d+ at the end, then the field name is the segment before it
            if (preg_match('/_repeater(\d+)$/', $prefix, $repMatch)) {
                // Remove _repeaterXXXX suffix to get ..._fieldname
                $beforeRepeater = substr($prefix, 0, -strlen($repMatch[0]));
                // Field name is the last underscore-separated segment
                $lastUnderscorePos = strrpos($beforeRepeater, '_');
                if ($lastUnderscorePos !== false) {
                    return substr($beforeRepeater, $lastUnderscorePos + 1);
                }
                return $beforeRepeater;
            }

            // No repeater: field name is the last underscore-separated segment
            $lastUnderscorePos = strrpos($prefix, '_');
            if ($lastUnderscorePos !== false) {
                return substr($prefix, $lastUnderscorePos + 1);
            }
        }

        // Handle repeater fields: fieldname_repeaterXXXX -> fieldname
        // Pattern: {fieldname}_{repeaterfield}{id}
        if (preg_match('/^(.+?)_repeater\d+$/', $fieldName, $matches)) {
            return $matches[1];
        }

        // Handle language fields: field__1234 -> field
        if (preg_match('/^(.+)__\d+$/', $fieldName, $matches)) {
            return $matches[1];
        }

        return $fieldName;
    }

    /**
     * Get prompts relevant to a specific page and field
     *
     * Filters by:
     * - Mode (must be 'inline')
     * - Template match (if specified in prompt, null = all templates)
     * - Field match (REQUIRED - field must be in fields array)
     */
    private function getRelevantPromptsForField(Page $page, Field $field): array {
        // Lazy load prompt matrix
        if ($this->promptMatrix === null) {
            $this->promptMatrix = PromptAIHelper::parsePromptMatrix(
                $this->module->get('promptMatrix'),
                false
            );
        }

        $relevantPrompts = [];

        foreach ($this->promptMatrix as $index => $promptEntity) {
            // Only include prompts in inline mode
            if ($promptEntity->mode !== 'inline') {
                continue;
            }

            // Check template match (null/empty = all templates)
            if (!PromptAIHelper::templateMatches($promptEntity->templates, $page->template->id)) {
                continue;
            }

            // Check if this field is in the prompt's fields array
            if (!$promptEntity->fields || !in_array($field->id, $promptEntity->fields)) {
                continue;
            }

            $relevantPrompts[$index] = $promptEntity;
        }

        return $relevantPrompts;
    }
}
