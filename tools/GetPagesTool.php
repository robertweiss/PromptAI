<?php namespace ProcessWire;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class GetPagesTool extends Tool {
    private static array $forbiddenFragments = [
        'include=all',
        'check_access=0',
        'check_access=false',
    ];

    public function __construct() {
        parent::__construct(
            name: 'getPages',
            description: 'Find pages in the CMS using a ProcessWire selector string. Returns an array of pages with id, title, url, template, created, and modified fields.',
        );
    }

    protected function properties(): array {
        return [
            ToolProperty::make(
                name: 'selector',
                type: PropertyType::STRING,
                description: 'ProcessWire selector string (e.g. "template=blog-post, limit=10, sort=-created"). Common fields: template, title, name, id, parent, created, modified. Common operators: = != *= ^= $= > <',
                required: true,
            ),
        ];
    }

    public function __invoke(string $selector): string {
        // Security: block forbidden selector fragments
        $selectorLower = strtolower($selector);
        foreach (self::$forbiddenFragments as $fragment) {
            if (str_contains($selectorLower, $fragment)) {
                return json_encode(['error' => 'Selector fragment not allowed: ' . $fragment]);
            }
        }

        // Enforce a reasonable limit
        if (!preg_match('/\blimit\s*=/', $selector)) {
            $selector .= ', limit=50';
        }

        // Sanitize selector values
        $selector = wire('sanitizer')->text($selector, ['maxLength' => 500]);

        $pages = wire('pages')->find($selector);

        $results = [];
        foreach ($pages as $page) {
            $results[] = [
                'id' => $page->id,
                'title' => $page->title,
                'url' => $page->url,
                'template' => $page->template->name,
                'created' => date('Y-m-d H:i:s', $page->created),
                'modified' => date('Y-m-d H:i:s', $page->modified),
            ];
        }

        return json_encode([
            'count' => count($results),
            'pages' => $results,
        ]);
    }
}
