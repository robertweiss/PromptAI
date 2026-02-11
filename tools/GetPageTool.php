<?php namespace ProcessWire;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class GetPageTool extends Tool {
    public function __construct() {
        parent::__construct(
            name: 'getPage',
            description: 'Get detailed information about a single page by its ID or URL path. Returns page info including all text field values.',
        );
    }

    protected function properties(): array {
        return [
            ToolProperty::make(
                name: 'identifier',
                type: PropertyType::STRING,
                description: 'Page ID (numeric) or URL path (e.g. "/about/team/")',
                required: true,
            ),
        ];
    }

    public function __invoke(string $identifier): string {
        $page = is_numeric($identifier)
            ? wire('pages')->get((int)$identifier)
            : wire('pages')->get(wire('sanitizer')->selectorValue($identifier));

        if (!$page || !$page->id) {
            return json_encode(['error' => 'Page not found: ' . $identifier]);
        }

        // Don't expose admin pages
        if (in_array($page->template->name, PromptAIHelper::$adminTemplates)) {
            return json_encode(['error' => 'Access denied']);
        }

        $data = [
            'id' => $page->id,
            'title' => $page->title,
            'name' => $page->name,
            'url' => $page->url,
            'template' => $page->template->name,
            'parent' => $page->parent->title . ' (' . $page->parent->url . ')',
            'created' => date('Y-m-d H:i:s', $page->created),
            'modified' => date('Y-m-d H:i:s', $page->modified),
            'status' => $page->statusStr,
            'fields' => [],
        ];

        // Include values from text fields only (safe exposure)
        foreach ($page->template->fields as $field) {
            $fieldType = get_class($field->type);
            if (in_array($fieldType, PromptAIHelper::$textFieldTypes)) {
                $value = $page->get($field->name);
                $data['fields'][$field->name] = (string)$value;
            }
        }

        return json_encode($data);
    }
}
