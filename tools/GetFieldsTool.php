<?php namespace ProcessWire;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class GetFieldsTool extends Tool {
    public function __construct() {
        parent::__construct(
            name: 'getFields',
            description: 'List templates with their field counts, or list all fields for a specific template. Use without templateName to see available templates, or with templateName to see that template\'s fields.',
        );
    }

    protected function properties(): array {
        return [
            ToolProperty::make(
                name: 'templateName',
                type: PropertyType::STRING,
                description: 'Optional template name to get fields for. If omitted, lists all non-admin templates with field counts.',
                required: false,
            ),
        ];
    }

    public function __invoke(?string $templateName = null): string {
        if ($templateName) {
            return $this->getFieldsForTemplate($templateName);
        }

        return $this->listTemplates();
    }

    private function listTemplates(): string {
        $templates = [];
        foreach (wire('templates') as $template) {
            if (in_array($template->name, PromptAIHelper::$adminTemplates)) {
                continue;
            }
            if (str_starts_with($template->name, 'field-')) {
                continue;
            }

            $templates[] = [
                'name' => $template->name,
                'label' => $template->label ?: $template->name,
                'fieldCount' => $template->fields->count(),
                'pageCount' => wire('pages')->count("template={$template->name}"),
            ];
        }

        return json_encode([
            'count' => count($templates),
            'templates' => $templates,
        ]);
    }

    private function getFieldsForTemplate(string $templateName): string {
        $template = wire('templates')->get(wire('sanitizer')->name($templateName));
        if (!$template) {
            return json_encode(['error' => 'Template not found: ' . $templateName]);
        }

        if (in_array($template->name, PromptAIHelper::$adminTemplates)) {
            return json_encode(['error' => 'Access denied']);
        }

        $fields = [];
        foreach ($template->fields as $field) {
            $fields[] = [
                'name' => $field->name,
                'label' => $field->label ?: $field->name,
                'type' => str_replace('ProcessWire\\Fieldtype', '', get_class($field->type)),
            ];
        }

        return json_encode([
            'template' => $template->name,
            'label' => $template->label ?: $template->name,
            'fieldCount' => count($fields),
            'fields' => $fields,
        ]);
    }
}
