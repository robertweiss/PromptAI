<?php

use ProcessWire\PromptAIInlineMode;
use ProcessWire\PromptAI;

require_once __DIR__.'/../../PromptAI.config.php';
require_once __DIR__.'/../../PromptAIHelper.php';
require_once __DIR__.'/../../PromptAIInlineMode.php';

/**
 * Access the private extractFieldName method via reflection.
 */
function callExtractFieldName(string $fieldName): string {
    // PromptAIInlineMode requires a PromptAI module - we need a minimal mock
    // Since extractFieldName doesn't use $this->module, we can use reflection
    $reflectionClass = new ReflectionClass(PromptAIInlineMode::class);
    $method = $reflectionClass->getMethod('extractFieldName');
    $method->setAccessible(true);

    // Create instance without calling constructor
    $instance = $reflectionClass->newInstanceWithoutConstructor();

    return $method->invoke($instance, $fieldName);
}

describe('extractFieldName', function () {
    // Regular field names
    it('returns simple field names unchanged', function () {
        expect(callExtractFieldName('title'))->toBe('title');
        expect(callExtractFieldName('body'))->toBe('body');
        expect(callExtractFieldName('headline'))->toBe('headline');
    });

    // Language fields: field__1234 -> field
    it('strips language suffix from field names', function () {
        expect(callExtractFieldName('title__1234'))->toBe('title');
        expect(callExtractFieldName('body__5678'))->toBe('body');
    });

    // Repeater fields: fieldname_repeater1041 -> fieldname
    it('extracts field name from repeater context', function () {
        expect(callExtractFieldName('images_repeater1041'))->toBe('images');
        expect(callExtractFieldName('body_repeater2050'))->toBe('body');
        expect(callExtractFieldName('copy2_repeater1041'))->toBe('copy2');
    });

    // File/image subfields: description_images_hash -> images
    it('extracts field name from description subfield', function () {
        expect(callExtractFieldName('description_images_' . str_repeat('a', 32)))->toBe('images');
    });

    // File/image subfields with custom subfield name: alt_text_images_hash -> images
    it('extracts field name from alt_text subfield', function () {
        expect(callExtractFieldName('alt_text_images_' . str_repeat('b', 32)))->toBe('images');
    });

    it('extracts field name from caption subfield', function () {
        expect(callExtractFieldName('caption_images_' . str_repeat('c', 32)))->toBe('images');
    });

    // File/image subfields with language: description1234_images_hash -> images
    it('extracts field name from language-suffixed description', function () {
        expect(callExtractFieldName('description1234_images_' . str_repeat('d', 32)))->toBe('images');
    });

    it('extracts field name from language-suffixed alt_text', function () {
        expect(callExtractFieldName('alt_text1234_images_' . str_repeat('e', 32)))->toBe('images');
    });

    // File/image subfields in repeater context: description_images_repeater1042_hash -> images
    it('extracts field name from repeater file description', function () {
        expect(callExtractFieldName('description_images_repeater1042_' . str_repeat('f', 32)))->toBe('images');
    });

    it('extracts field name from repeater file alt_text', function () {
        expect(callExtractFieldName('alt_text_images_repeater1042_' . str_repeat('a', 32)))->toBe('images');
    });

    // File/image subfields in repeater with language: description1234_images_repeater1042_hash -> images
    it('extracts field name from repeater language file description', function () {
        expect(callExtractFieldName('description1234_images_repeater1042_' . str_repeat('b', 32)))->toBe('images');
    });
});
