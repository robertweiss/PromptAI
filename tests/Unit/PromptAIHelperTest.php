<?php

use ProcessWire\PromptAIHelper;
use ProcessWire\PromptMatrixEntity;
use ProcessWire\Field;
use ProcessWire\Template;
use ProcessWire\Page;
use ProcessWire\MockCollection;
use ProcessWire\MockModulesService;
use ProcessWire\MockPromptAIModule;
use ProcessWire\FieldtypeText;
use ProcessWire\FieldtypeImage;

require_once __DIR__.'/../../PromptAI.config.php';
require_once __DIR__.'/../../PromptAIHelper.php';

// ── Helper to set up wire() registry ──────────────────────────────

function setupWireRegistry(array $fields = [], array $templates = []): void {
    global $_pw_wire_registry;

    $fieldsCollection = new MockCollection($fields);
    $templatesCollection = new MockCollection($templates);

    $_pw_wire_registry['fields'] = $fieldsCollection;
    $_pw_wire_registry['templates'] = $templatesCollection;
}

function makeField(int $id, string $name, string $typeClass = FieldtypeText::class, ?string $label = null): Field {
    $field = new Field();
    $field->id = $id;
    $field->name = $name;
    $field->label = $label;
    $field->type = new $typeClass();
    return $field;
}

function makeTemplate(int $id, string $name, ?string $label = null): Template {
    $template = new Template();
    $template->id = $id;
    $template->name = $name;
    $template->label = $label;
    return $template;
}

function makePage(int $id, string $templateName, array $data = []): Page {
    $page = new Page();
    $page->id = $id;
    $template = new Template();
    $template->name = $templateName;
    $template->id = $id * 10; // simple convention
    $page->template = $template;
    foreach ($data as $key => $value) {
        $page->set($key, $value);
    }
    return $page;
}

function setupModulesMock(?MockPromptAIModule $module = null): MockPromptAIModule {
    global $_pw_wire_registry;
    $module = $module ?? new MockPromptAIModule();
    $modules = new MockModulesService();
    $modules->register('PromptAI', $module);
    $_pw_wire_registry['modules'] = $modules;
    return $module;
}

// ── parsePromptMatrix tests ──────────────────────────────────────

beforeEach(function () {
    // Set up default wire() registry with some fields and templates
    setupWireRegistry(
        fields: [
            makeField(1, 'title'),
            makeField(2, 'body', FieldtypeText::class),
            makeField(3, 'images', FieldtypeImage::class),
        ],
        templates: [
            makeTemplate(10, 'basic-page', 'Basic Page'),
            makeTemplate(20, 'blog-post', 'Blog Post'),
        ]
    );
});

describe('parsePromptMatrix', function () {
    it('returns empty array for empty string', function () {
        $result = PromptAIHelper::parsePromptMatrix('');
        expect($result)->toBe([]);
    });

    it('returns empty array for null', function () {
        $result = PromptAIHelper::parsePromptMatrix(null);
        expect($result)->toBe([]);
    });

    it('returns empty array for invalid JSON', function () {
        $result = PromptAIHelper::parsePromptMatrix('not json at all');
        expect($result)->toBe([]);
    });

    it('parses valid JSON with all fields', function () {
        $json = json_encode([[
            'mode' => 'page',
            'templates' => [10],
            'fields' => [1, 2],
            'prompt' => 'Test prompt',
            'label' => 'Test label',
            'overwriteTarget' => true,
            'targetSubfield' => 'alt_text',
        ]]);

        $result = PromptAIHelper::parsePromptMatrix($json);

        expect($result)->toHaveCount(1);
        expect($result[0])->toBeInstanceOf(PromptMatrixEntity::class);
        expect($result[0]->mode)->toBe('page');
        expect($result[0]->templates)->toBe([10]);
        expect($result[0]->fields)->toBe([1, 2]);
        expect($result[0]->prompt)->toBe('Test prompt');
        expect($result[0]->label)->toBe('Test label');
        expect($result[0]->overwriteTarget)->toBeTrue();
        expect($result[0]->targetSubfield)->toBe('alt_text');
    });

    it('defaults targetSubfield to description', function () {
        $json = json_encode([[
            'mode' => 'inline',
            'fields' => [1],
            'prompt' => 'Test',
        ]]);

        $result = PromptAIHelper::parsePromptMatrix($json);

        expect($result)->toHaveCount(1);
        expect($result[0]->targetSubfield)->toBe('description');
    });

    it('defaults overwriteTarget to false', function () {
        $json = json_encode([[
            'mode' => 'page',
            'fields' => [1],
            'prompt' => 'Test',
        ]]);

        $result = PromptAIHelper::parsePromptMatrix($json);

        expect($result[0]->overwriteTarget)->toBeFalse();
    });

    it('skips entries with missing prompt', function () {
        $json = json_encode([
            ['mode' => 'page', 'fields' => [1], 'prompt' => ''],
            ['mode' => 'page', 'fields' => [1], 'prompt' => 'Valid'],
        ]);

        $result = PromptAIHelper::parsePromptMatrix($json);

        expect($result)->toHaveCount(1);
        expect($result[0]->prompt)->toBe('Valid');
    });

    it('skips entries with missing fields', function () {
        $json = json_encode([
            ['mode' => 'page', 'fields' => [], 'prompt' => 'Test'],
        ]);

        $result = PromptAIHelper::parsePromptMatrix($json);

        expect($result)->toBe([]);
    });

    it('skips entries with invalid mode', function () {
        $json = json_encode([
            ['mode' => 'unknown', 'fields' => [1], 'prompt' => 'Test'],
        ]);

        $result = PromptAIHelper::parsePromptMatrix($json);

        expect($result)->toBe([]);
    });

    it('skips entries with non-existent template IDs', function () {
        $json = json_encode([
            ['mode' => 'page', 'templates' => [999], 'fields' => [1], 'prompt' => 'Test'],
        ]);

        $result = PromptAIHelper::parsePromptMatrix($json);

        expect($result)->toBe([]);
    });

    it('skips entries with non-existent field IDs', function () {
        $json = json_encode([
            ['mode' => 'page', 'fields' => [999], 'prompt' => 'Test'],
        ]);

        $result = PromptAIHelper::parsePromptMatrix($json);

        expect($result)->toBe([]);
    });

    it('allows null templates (means all templates)', function () {
        $json = json_encode([
            ['mode' => 'page', 'templates' => null, 'fields' => [1], 'prompt' => 'Test'],
        ]);

        $result = PromptAIHelper::parsePromptMatrix($json);

        expect($result)->toHaveCount(1);
        expect($result[0]->templates)->toBeNull();
    });

    it('parses multiple configurations', function () {
        $json = json_encode([
            ['mode' => 'inline', 'fields' => [1], 'prompt' => 'First'],
            ['mode' => 'page', 'fields' => [2], 'prompt' => 'Second'],
        ]);

        $result = PromptAIHelper::parsePromptMatrix($json);

        expect($result)->toHaveCount(2);
        expect($result[0]->mode)->toBe('inline');
        expect($result[1]->mode)->toBe('page');
    });
});

// ── templateMatches tests ────────────────────────────────────────

describe('templateMatches', function () {
    it('matches when templates is null (all templates)', function () {
        expect(PromptAIHelper::templateMatches(null, 10))->toBeTrue();
    });

    it('matches when templates is empty array (all templates)', function () {
        expect(PromptAIHelper::templateMatches([], 10))->toBeTrue();
    });

    it('matches when template ID is in array', function () {
        expect(PromptAIHelper::templateMatches([10, 20], 10))->toBeTrue();
    });

    it('does not match when template ID is not in array', function () {
        expect(PromptAIHelper::templateMatches([10, 20], 30))->toBeFalse();
    });

    it('matches single template in array', function () {
        expect(PromptAIHelper::templateMatches([10], 10))->toBeTrue();
    });
});

// ── getMediaType tests ──────────────────────────────────────────

describe('getMediaType', function () {
    it('returns correct MIME for CSV files', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_') . '.csv';
        file_put_contents($tmpFile, "col1,col2\nval1,val2");

        $result = PromptAIHelper::getMediaType($tmpFile);

        expect($result)->toBe('text/csv');
        unlink($tmpFile);
    });

    it('returns correct MIME for Markdown files', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($tmpFile, "# Heading\nParagraph text");

        $result = PromptAIHelper::getMediaType($tmpFile);

        expect($result)->toBe('text/markdown');
        unlink($tmpFile);
    });

    it('returns correct MIME for plain text files', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_') . '.txt';
        file_put_contents($tmpFile, "Just plain text");

        $result = PromptAIHelper::getMediaType($tmpFile);

        expect($result)->toBe('text/plain');
        unlink($tmpFile);
    });
});

// ── getFieldOptions tests ───────────────────────────────────────

describe('getFieldOptions', function () {
    it('returns text and file fields excluding system fields', function () {
        $result = PromptAIHelper::getFieldOptions();

        expect($result)->toHaveCount(3);
        expect($result)->toHaveKey(1);
        expect($result)->toHaveKey(2);
        expect($result)->toHaveKey(3);
    });

    it('excludes system fields', function () {
        $systemField = makeField(99, 'system_field');
        $systemField->flags = Field::flagSystem;

        setupWireRegistry(
            fields: [
                makeField(1, 'title'),
                $systemField,
            ],
            templates: []
        );

        $result = PromptAIHelper::getFieldOptions();

        expect($result)->toHaveCount(1);
        expect($result)->not->toHaveKey(99);
    });
});

// ── getTemplateOptions tests ────────────────────────────────────

describe('getTemplateOptions', function () {
    it('excludes admin templates', function () {
        setupWireRegistry(
            fields: [],
            templates: [
                makeTemplate(1, 'admin'),
                makeTemplate(2, 'user'),
                makeTemplate(10, 'basic-page'),
            ]
        );

        $result = PromptAIHelper::getTemplateOptions();

        expect($result)->toHaveCount(1);
        expect($result)->toHaveKey(10);
    });

    it('excludes field- prefixed templates', function () {
        setupWireRegistry(
            fields: [],
            templates: [
                makeTemplate(1, 'field-something'),
                makeTemplate(10, 'basic-page'),
            ]
        );

        $result = PromptAIHelper::getTemplateOptions();

        expect($result)->toHaveCount(1);
        expect($result)->not->toHaveKey(1);
    });

    it('labels repeater templates', function () {
        setupWireRegistry(
            fields: [],
            templates: [
                makeTemplate(50, 'repeater_gallery'),
            ]
        );

        $result = PromptAIHelper::getTemplateOptions();

        expect($result[50])->toBe('Repeater: gallery');
    });
});

// ── getRepeaterContext tests ─────────────────────────────────────

describe('getRepeaterContext', function () {
    it('returns page as parentPage and null repeaterItem for regular template', function () {
        $page = makePage(1, 'basic-page');

        $result = PromptAIHelper::getRepeaterContext($page);

        expect($result['parentPage'])->toBe($page);
        expect($result['repeaterItem'])->toBeNull();
    });

    it('returns getForPage as parentPage and page as repeaterItem for repeater template', function () {
        $page = makePage(2, 'repeater_gallery');

        $result = PromptAIHelper::getRepeaterContext($page);

        // getForPage() returns $this on mock Page, so parentPage === page
        expect($result['parentPage'])->toBe($page);
        expect($result['repeaterItem'])->toBe($page);
    });

    it('correctly identifies non-repeater template starting with "repeater" in name', function () {
        // Template name "repeater" without underscore prefix should not match
        $page = makePage(3, 'basic-page');

        $result = PromptAIHelper::getRepeaterContext($page);

        expect($result['repeaterItem'])->toBeNull();
    });
});

// ── getRepeaterTemplateIdsForPage tests ─────────────────────────

describe('getRepeaterTemplateIdsForPage', function () {
    it('returns empty array when no repeater templates exist', function () {
        setupWireRegistry(
            fields: [makeField(1, 'title')],
            templates: [makeTemplate(10, 'basic-page')]
        );

        $page = makePage(1, 'basic-page');
        $result = PromptAIHelper::getRepeaterTemplateIdsForPage($page);

        expect($result)->toBe([]);
    });

    it('returns matching template IDs when page has corresponding repeater fields', function () {
        setupWireRegistry(
            fields: [makeField(1, 'title')],
            templates: [
                makeTemplate(10, 'basic-page'),
                makeTemplate(50, 'repeater_gallery'),
            ]
        );

        $page = makePage(1, 'basic-page', ['gallery' => 'has-content']);
        $result = PromptAIHelper::getRepeaterTemplateIdsForPage($page);

        expect($result)->toBe([50]);
    });

    it('excludes repeater templates for fields not on the page', function () {
        setupWireRegistry(
            fields: [makeField(1, 'title')],
            templates: [
                makeTemplate(10, 'basic-page'),
                makeTemplate(50, 'repeater_gallery'),
                makeTemplate(60, 'repeater_slides'),
            ]
        );

        $page = makePage(1, 'basic-page', ['gallery' => 'has-content']);
        // 'slides' is not set on the page, so repeater_slides should not match
        $result = PromptAIHelper::getRepeaterTemplateIdsForPage($page);

        expect($result)->toBe([50]);
        expect($result)->not->toContain(60);
    });
});

// ── substituteAndPreparePrompt tests ────────────────────────────

describe('substituteAndPreparePrompt', function () {
    beforeEach(function () {
        setupWireRegistry(
            fields: [makeField(1, 'title')],
            templates: [makeTemplate(10, 'basic-page')]
        );
        setupModulesMock();
    });

    it('appends content after substituted prompt with newline', function () {
        $page = makePage(1, 'basic-page', ['title' => 'My Page']);
        $result = PromptAIHelper::substituteAndPreparePrompt(
            'Translate {page.title}',
            $page,
            'Some content here'
        );

        expect($result)->toBe("Translate My Page\nSome content here");
    });

    it('returns just prompt when content is empty string', function () {
        $page = makePage(1, 'basic-page', ['title' => 'My Page']);
        $result = PromptAIHelper::substituteAndPreparePrompt(
            'Translate {page.title}',
            $page,
            ''
        );

        expect($result)->toBe('Translate My Page');
    });

    it('passes repeaterItem through to substitutePlaceholders', function () {
        $page = makePage(1, 'basic-page', ['title' => 'Parent Title']);
        $repeaterItem = makePage(2, 'repeater_gallery', ['caption' => 'Item Caption']);

        $captionField = makeField(5, 'caption');
        setupWireRegistry(
            fields: [makeField(1, 'title'), $captionField],
            templates: [makeTemplate(10, 'basic-page')]
        );
        setupModulesMock();

        $result = PromptAIHelper::substituteAndPreparePrompt(
            '{page.title} - {item.caption}',
            $page,
            '',
            $repeaterItem
        );

        expect($result)->toBe('Parent Title - Item Caption');
    });
});

// ── substitutePlaceholders tests ────────────────────────────────

describe('substitutePlaceholders', function () {
    beforeEach(function () {
        setupWireRegistry(
            fields: [makeField(1, 'title'), makeField(2, 'body')],
            templates: [makeTemplate(10, 'basic-page')]
        );
        setupModulesMock();
    });

    it('replaces {page.fieldname} with page field value', function () {
        $page = makePage(1, 'basic-page', ['title' => 'Hello World']);

        $result = PromptAIHelper::substitutePlaceholders(
            'Process: {page.title}',
            $page
        );

        expect($result)->toBe('Process: Hello World');
    });

    it('replaces {item.fieldname} with repeater item field value', function () {
        $page = makePage(1, 'basic-page', ['title' => 'Parent']);
        $item = makePage(2, 'repeater_gallery', ['body' => 'Item Body']);

        $result = PromptAIHelper::substitutePlaceholders(
            '{item.body}',
            $page,
            $item
        );

        expect($result)->toBe('Item Body');
    });

    it('replaces placeholder with empty string when field value is null', function () {
        $page = makePage(1, 'basic-page'); // no 'title' set

        $result = PromptAIHelper::substitutePlaceholders(
            'Value: {page.title}',
            $page
        );

        expect($result)->toBe('Value: ');
    });

    it('warns when {item.*} used without repeater context', function () {
        $module = setupModulesMock();
        $page = makePage(1, 'basic-page');

        PromptAIHelper::substitutePlaceholders(
            'Use {item.caption} here',
            $page,
            null
        );

        expect($module->warnings)->toContain('Placeholder {item.*} used outside repeater context');
    });

    it('handles multiple placeholders in one prompt', function () {
        $page = makePage(1, 'basic-page', ['title' => 'My Title', 'body' => 'My Body']);

        $result = PromptAIHelper::substitutePlaceholders(
            '{page.title} and {page.body}',
            $page
        );

        expect($result)->toBe('My Title and My Body');
    });
});

// ── getRelevantPrompts tests ────────────────────────────────────

describe('getRelevantPrompts', function () {
    it('returns prompts matching page template directly', function () {
        $page = makePage(1, 'basic-page');
        $page->template->id = 10;

        $prompt = PromptMatrixEntity::fromArray([
            'mode' => 'page',
            'templates' => [10],
            'fields' => [1],
            'prompt' => 'Test',
        ]);

        $result = PromptAIHelper::getRelevantPrompts($page, [$prompt]);

        expect($result)->toHaveCount(1);
    });

    it('returns prompts with null templates (matches all)', function () {
        $page = makePage(1, 'basic-page');
        $page->template->id = 10;

        $prompt = PromptMatrixEntity::fromArray([
            'mode' => 'page',
            'templates' => null,
            'fields' => [1],
            'prompt' => 'Test',
        ]);

        $result = PromptAIHelper::getRelevantPrompts($page, [$prompt]);

        expect($result)->toHaveCount(1);
    });

    it('returns prompts targeting repeater templates when page has that repeater field', function () {
        setupWireRegistry(
            fields: [makeField(1, 'title')],
            templates: [
                makeTemplate(10, 'basic-page'),
                makeTemplate(50, 'repeater_gallery'),
            ]
        );

        $page = makePage(1, 'basic-page', ['gallery' => 'has-items']);
        $page->template->id = 10;

        $prompt = PromptMatrixEntity::fromArray([
            'mode' => 'page',
            'templates' => [50],
            'fields' => [1],
            'prompt' => 'Test',
        ]);

        $result = PromptAIHelper::getRelevantPrompts($page, [$prompt]);

        expect($result)->toHaveCount(1);
    });

    it('excludes non-matching prompts', function () {
        $page = makePage(1, 'basic-page');
        $page->template->id = 10;

        $prompt = PromptMatrixEntity::fromArray([
            'mode' => 'page',
            'templates' => [99],
            'fields' => [1],
            'prompt' => 'Test',
        ]);

        setupWireRegistry(
            fields: [makeField(1, 'title')],
            templates: [makeTemplate(10, 'basic-page'), makeTemplate(99, 'other-page')]
        );

        $result = PromptAIHelper::getRelevantPrompts($page, [$prompt]);

        expect($result)->toHaveCount(0);
    });
});
