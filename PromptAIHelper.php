<?php namespace ProcessWire;

class PromptAIHelper {
    public static array $adminTemplates = ['admin', 'language', 'user', 'permission', 'role'];

    public static array $textFieldTypes = [
        'ProcessWire\FieldtypePageTitle',
        'ProcessWire\FieldtypePageTitleLanguage',
        'ProcessWire\FieldtypeText',
        'ProcessWire\FieldtypeTextarea',
        'ProcessWire\FieldtypeTextLanguage',
        'ProcessWire\FieldtypeTextareaLanguage',
    ];

    public static array $fileFieldTypes = [
        'ProcessWire\FieldtypeImage',
        'ProcessWire\FieldtypeFile',
    ];

    public static function parsePromptMatrix(?string $promptMatrixString = '', $showErrors = false): array {
        $promptMatrix = [];

        // If empty, return empty array
        if (empty($promptMatrixString)) {
            return $promptMatrix;
        }

        $jsonData = json_decode($promptMatrixString, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($jsonData)) {
            if ($showErrors) {
                wire()->error(__('Invalid JSON format in prompt configuration'));
            }

            return $promptMatrix;
        }

        $availableTemplates = PromptAIHelper::getTemplateOptions();
        $availableFields = PromptAIHelper::getFieldOptions();

        foreach ($jsonData as $index => $config) {
            $promptMatrixEntity = PromptMatrixEntity::fromArray($config);

            // Validation
            // Mode is required
            if (!$promptMatrixEntity->mode || !in_array($promptMatrixEntity->mode, ['inline', 'page'])) {
                if ($showErrors) {
                    wire()->error(__('Mode is missing or invalid in configuration ').($index + 1));
                }
                continue;
            }

            // Fields array is REQUIRED
            if (!$promptMatrixEntity->fields || !is_array($promptMatrixEntity->fields) || empty($promptMatrixEntity->fields)) {
                if ($showErrors) {
                    wire()->error(__('Fields are missing in configuration ').($index + 1));
                }
                continue;
            }

            // Prompt is required
            if (!$promptMatrixEntity->prompt) {
                if ($showErrors) {
                    wire()->error(__('Prompt is missing in configuration ').($index + 1));
                }
                continue;
            }

            // Validate template IDs exist (if set)
            if ($promptMatrixEntity->templates && is_array($promptMatrixEntity->templates)) {
                foreach ($promptMatrixEntity->templates as $templateId) {
                    if (!array_key_exists($templateId, $availableTemplates)) {
                        if ($showErrors) {
                            wire()->error(__('Template ID ').$templateId.__(' does not exist in configuration ').($index + 1));
                        }
                        continue 2; // Skip this entire configuration
                    }
                }
            }

            // Validate all field IDs exist
            foreach ($promptMatrixEntity->fields as $fieldId) {
                if (!array_key_exists($fieldId, $availableFields)) {
                    if ($showErrors) {
                        wire()->error(__('Field ID ').$fieldId.__(' does not exist in configuration ').($index + 1));
                    }
                    continue 2; // Skip this entire configuration
                }
            }

            $promptMatrix[] = $promptMatrixEntity;
        }

        return $promptMatrix;
    }

    public static function getFieldOptions(): array {
        $fieldsOptions = [];
        if (wire('fields')) {
            /** @var Field $field */
            foreach (wire('fields') as $field) {
                if ($field->flags && ($field->flags === Field::flagSystem || $field->flags === 24)) {
                    continue;
                }
                if (!in_array(get_class($field->type), self::$textFieldTypes) && !in_array(get_class($field->type), self::$fileFieldTypes)) {
                    continue;
                }

                $label = $field->label ? $field->label.' ('.$field->name.')' : $field->name;
                $fieldsOptions[$field->id] = $label;
            }
        }

        return $fieldsOptions;
    }

    public static function getTemplateOptions(): array {
        $templatesOptions = [];
        if (wire('templates')) {
            foreach (wire('templates') as $template) {
                if (in_array($template->name, self::$adminTemplates)) {
                    continue;
                }

                if (str_starts_with($template->name, 'field-')) {
                    continue;
                }

                // Exclude RPB system datapage (not a block template)
                if ($template->name === 'rockpagebuilder_datapage') {
                    continue;
                }

                $label = $template->label ? $template->label.' ('.$template->name.')' : $template->name;
                if (str_starts_with($template->name, 'repeater_')) {
                    $name = str_replace('repeater_', '', $template->name);
                    $label = 'Repeater: '.$name;
                }

                // Label RPB block templates clearly
                if (str_starts_with($template->name, 'rockpagebuilderblock-')) {
                    $blockName = str_replace('rockpagebuilderblock-', '', $template->name);
                    $label = 'RPB Block: ' . ucfirst($blockName);
                }

                $templatesOptions[$template->id] = $label;
            }
        }

        return $templatesOptions;
    }

    public static function getRepeaterTemplateIdsForPage(Page $page): array {
        $templatesIds = [];
        if (wire('templates')) {
            foreach (wire('templates') as $template) {
                if (!str_starts_with($template->name, 'repeater_')) {
                    continue;
                }

                $name = str_replace('repeater_', '', $template->name);

                if ($page->$name) {
                    $templatesIds[] = $template->id;
                }
            }
        }

        return $templatesIds;
    }

    /**
     * Check if a template configuration matches a given template ID
     * Template configuration is always an array or null (for all templates)
     */
    public static function templateMatches($entityTemplates, int $pageTemplateId): bool {
        if ($entityTemplates === null || empty($entityTemplates)) {
            return true; // null/empty means all templates
        }

        return is_array($entityTemplates) && in_array($pageTemplateId, $entityTemplates);
    }

    public static function getRelevantPrompts(Page $page, array $promptMatrix): array {
        $template = $page ? $page->template : null;

        $relevantPrompts = [];
        foreach ($promptMatrix as $index => $promptMatrixEntity) {
            if (PromptAIHelper::templateMatches($promptMatrixEntity->templates, $template->id)) {
                $relevantPrompts[$index] = $promptMatrixEntity;
                continue;
            }

            // Handle repeater templates and RPB block templates
            if (is_array($promptMatrixEntity->templates)) {
                foreach ($promptMatrixEntity->templates as $templateId) {
                    $entityTemplate = wire('templates')->get($templateId);
                    if (!$entityTemplate) continue;

                    if (str_starts_with($entityTemplate->name, 'repeater_')) {
                        $repeaterName = str_replace('repeater_', '', $entityTemplate->name);
                        if ($page->$repeaterName) {
                            $relevantPrompts[$index] = $promptMatrixEntity;
                            break;
                        }
                    } elseif (str_starts_with($entityTemplate->name, 'rockpagebuilderblock-')) {
                        // RPB stores blocks centrally, not as direct children.
                        // Check the page's RPB fields for any blocks of this template.
                        $found = false;
                        foreach ($page->template->fields as $pageField) {
                            if ($found) break;
                            if (strpos(get_class($pageField->type), 'RockPageBuilder') === false) continue;
                            $value = $page->get($pageField->name);
                            if (!$value || !is_iterable($value)) continue;
                            foreach ($value as $block) {
                                if ($block instanceof Page && $block->template->id == $entityTemplate->id) {
                                    $found = true;
                                    break;
                                }
                            }
                        }
                        if ($found) {
                            $relevantPrompts[$index] = $promptMatrixEntity;
                            break;
                        }
                    }
                }
            }
        }

        return $relevantPrompts;
    }

    public static function getMediaType($filePath) {
        $mimeType = mime_content_type($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Fallback-Mapping for problematic file types
        $extensionMap = [
            'csv' => 'text/csv',
            'md' => 'text/markdown',
        ];

        if ($mimeType === 'text/plain' && isset($extensionMap[$extension])) {
            return $extensionMap[$extension];
        }

        return $mimeType;
    }

    /**
     * Determine repeater context from a page
     *
     * @param Page $page The page to check (could be parent or repeater item)
     * @return array ['parentPage' => Page, 'repeaterItem' => Page|null]
     */
    public static function getRepeaterContext(Page $page): array {
        if (strpos($page->template->name, 'repeater_') === 0) {
            return [
                'parentPage' => $page->getForPage(),
                'repeaterItem' => $page,
            ];
        }

        if (strpos($page->template->name, 'rockpagebuilderblock-') === 0) {
            return [
                'parentPage' => $page->parent,
                'repeaterItem' => $page,
            ];
        }

        return [
            'parentPage' => $page,
            'repeaterItem' => null,
        ];
    }

    /**
     * Substitute placeholders in prompt text with actual field values
     *
     * Supported placeholders:
     * - {page.fieldname} - Field from the main/parent page
     * - {item.fieldname} - Field from current repeater item (only in repeater context)
     *
     * @param string $prompt The prompt text with placeholders
     * @param Page $page The main page being processed
     * @param Page|null $repeaterItem Optional repeater item page for {item.*} placeholders
     * @return string Prompt with placeholders replaced
     */
    public static function substitutePlaceholders(
        string $prompt,
        Page $page,
        ?Page $repeaterItem = null
    ): string {
        /** @var PromptAI $module */
        $module = wire('modules')->get('PromptAI');

        // Pattern 1: {page.fieldname} - always references parent/main page
        preg_match_all('/\{page\.([a-zA-Z0-9_]+)\}/', $prompt, $pageMatches, PREG_SET_ORDER);

        foreach ($pageMatches as $match) {
            $placeholder = $match[0]; // e.g., "{page.title}"
            $fieldName = $match[1];   // e.g., "title"

            $field = wire('fields')->get($fieldName);
            $value = $page->get($fieldName);

            // Handle non-existent or empty fields
            if ($value === null || $value === '') {
                $module->warning(__('Placeholder field not found or empty: ') . $placeholder);
                $value = '';
            }

            // Convert to string (handle different field types)
            $fieldtype = $field ? $field->type : null;
            $value = $module->fieldValueToString($value, $fieldtype);

            $prompt = str_replace($placeholder, $value, $prompt);
        }

        // Pattern 2: {item.fieldname} - only available in repeater context
        if ($repeaterItem !== null) {
            preg_match_all('/\{item\.([a-zA-Z0-9_]+)\}/', $prompt, $itemMatches, PREG_SET_ORDER);

            foreach ($itemMatches as $match) {
                $placeholder = $match[0]; // e.g., "{item.title}"
                $fieldName = $match[1];   // e.g., "title"

                $field = wire('fields')->get($fieldName);
                $value = $repeaterItem->get($fieldName);

                if ($value === null || $value === '') {
                    $module->warning(__('Placeholder field not found or empty: ') . $placeholder);
                    $value = '';
                }

                $fieldtype = $field ? $field->type : null;
                /** @var PromptAI $module */
                $module = wire('modules')->get('PromptAI');
                $value = $module->fieldValueToString($value, $fieldtype);

                $prompt = str_replace($placeholder, $value, $prompt);
            }
        } else {
            // Warn if {item.*} used outside repeater context
            if (preg_match('/\{item\.[a-zA-Z0-9_]+\}/', $prompt)) {
                $module->warning(__('Placeholder {item.*} used outside repeater context'));
            }
        }

        return $prompt;
    }

    /**
     * Substitute placeholders in prompt and prepare for AI chat
     *
     * This wrapper method:
     * - Substitutes placeholders ({page.field} and {item.field})
     * - Builds final prompt
     * - Returns the prompt ready for AI chat
     *
     * @param string $prompt The prompt text with placeholders
     * @param Page $page The parent/main page being processed
     * @param string $content Optional content to append after prompt
     * @param Page|null $repeaterItem Optional repeater item for {item.*} placeholders
     * @return string Final prompt with placeholders substituted
     */
    public static function substituteAndPreparePrompt(
        string $prompt,
        Page $page,
        string $content = '',
        ?Page $repeaterItem = null
    ): string {
        // Substitute placeholders
        $substitutedPrompt = self::substitutePlaceholders(
            $prompt,
            $page,
            $repeaterItem
        );

        // Combine with content if provided
        if ($content) {
            return trim($substitutedPrompt . PHP_EOL . $content);
        }

        return $substitutedPrompt;
    }
}
