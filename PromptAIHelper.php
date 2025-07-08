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

    public static function migratePromptMatrix(PromptAI $module): void {
        $currentConfig = $module->get('promptMatrix');

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

        $module->message(__('PromptAI configuration migrated to new format'));
    }

    public static function migrateTemplateToArray(PromptAI $module): void {
        $currentConfig = $module->get('promptMatrix');

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

            $module->message(__('PromptAI template configuration migrated to array format'));
        }
    }

    public static function migrateOverwriteTargetToPrompts(PromptAI $module): void {
        $moduleConfig = wire('modules')->getConfig('PromptAI');
        $currentConfig = $moduleConfig['promptMatrix'] ?? '';
        $globalOverwriteTarget = (bool)($moduleConfig['overwriteTarget'] ?? false);

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
            // Only migrate if overwriteTarget is not already set
            if (!isset($config['overwriteTarget'])) {
                $config['overwriteTarget'] = $globalOverwriteTarget;
                $migrated = true;
            }
        }

        // Save updated configuration if changes were made
        if ($migrated) {
            $jsonConfig = json_encode($jsonData, JSON_PRETTY_PRINT);
            $moduleConfig = wire('modules')->getConfig('PromptAI');
            $moduleConfig['promptMatrix'] = $jsonConfig;
            wire('modules')->saveConfig('PromptAI', $moduleConfig);

            $module->message(__('PromptAI overwriteTarget configuration migrated to per-prompt setting'));
        }
    }

    public static function parsePromptMatrix(?string $promptMatrixString = '', $showErrors = false): array {
        $promptMatrix = [];

        // If empty, return empty array
        if (empty($promptMatrixString)) {
            return $promptMatrix;
        }

        // Parse JSON format (new format)
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
            $promptMatrixEntity = new PromptMatrixEntity();
            $promptMatrixEntity->template = $config['template'] ?? null;
            $promptMatrixEntity->sourceField = $config['sourceField'] ?? null;
            $promptMatrixEntity->targetField = $config['targetField'] ?? null;
            $promptMatrixEntity->prompt = $config['prompt'] ?? null;
            $promptMatrixEntity->label = $config['label'] ?? null;
            $promptMatrixEntity->overwriteTarget = $config['overwriteTarget'] ?? false;

            // Validation
            if (!$promptMatrixEntity->sourceField) {
                if ($showErrors) {
                    wire()->error(__('Source field is missing in configuration ').($index + 1));
                }
                continue;
            }

            if (!$promptMatrixEntity->prompt) {
                if ($showErrors) {
                    wire()->error(__('Prompt is missing in configuration ').($index + 1));
                }
                continue;
            }

            // Validate template IDs exist (if set)
            if ($promptMatrixEntity->template && is_array($promptMatrixEntity->template)) {
                foreach ($promptMatrixEntity->template as $templateId) {
                    if (!array_key_exists($templateId, $availableTemplates)) {
                        if ($showErrors) {
                            wire()->error(__('Template ID ').$templateId.__(' does not exist in configuration ').($index + 1));
                        }
                        continue 2; // Skip this entire configuration
                    }
                }
            }

            // Validate source field ID exists
            if (!array_key_exists($promptMatrixEntity->sourceField, $availableFields)) {
                if ($showErrors) {
                    wire()->error(__('Source field ID does not exist in configuration ').($index + 1));
                }
                continue;
            }

            // Validate target field ID exists (if set)
            if ($promptMatrixEntity->targetField && !array_key_exists($promptMatrixEntity->targetField, $availableFields)) {
                if ($showErrors) {
                    wire()->error(__('Target field ID does not exist in configuration ').($index + 1));
                }
                continue;
            }

            $promptMatrix[] = $promptMatrixEntity;
        }

        return $promptMatrix;
    }

    public static function getFieldOptions() {
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

    public static function getTemplateOptions() {
        $templatesOptions = [];
        if (wire('templates')) {
            foreach (wire('templates') as $template) {
                if (in_array($template->name, self::$adminTemplates)) {
                    continue;
                }

                if (str_starts_with($template->name, 'field-')) {
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

    public static function getRepeaterTemplateIdsForPage(Page $page) {
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
            if (PromptAIHelper::templateMatches($promptMatrixEntity->template, $template->id)) {
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
}
