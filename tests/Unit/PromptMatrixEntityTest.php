<?php

use ProcessWire\PromptMatrixEntity;

require_once __DIR__.'/../../PromptAI.config.php';

describe('PromptMatrixEntity', function () {
    describe('fromArray', function () {
        it('uses correct defaults for missing keys', function () {
            $entity = PromptMatrixEntity::fromArray([]);

            expect($entity->mode)->toBe('inline');
            expect($entity->templates)->toBeNull();
            expect($entity->fields)->toBeNull();
            expect($entity->prompt)->toBeNull();
            expect($entity->label)->toBeNull();
            expect($entity->overwriteTarget)->toBeFalse();
            expect($entity->targetSubfield)->toBe('description');
            expect($entity->ignoreFieldContent)->toBeFalse();
        });

        it('sets all properties from array', function () {
            $entity = PromptMatrixEntity::fromArray([
                'mode' => 'page',
                'templates' => [10, 20],
                'fields' => [1, 2],
                'prompt' => 'Do something',
                'label' => 'My Label',
                'overwriteTarget' => true,
                'targetSubfield' => 'alt_text',
                'ignoreFieldContent' => true,
            ]);

            expect($entity->mode)->toBe('page');
            expect($entity->templates)->toBe([10, 20]);
            expect($entity->fields)->toBe([1, 2]);
            expect($entity->prompt)->toBe('Do something');
            expect($entity->label)->toBe('My Label');
            expect($entity->overwriteTarget)->toBeTrue();
            expect($entity->targetSubfield)->toBe('alt_text');
            expect($entity->ignoreFieldContent)->toBeTrue();
        });

        it('trims targetSubfield and falls back to description if empty', function () {
            $entity = PromptMatrixEntity::fromArray([
                'targetSubfield' => '   ',
            ]);

            expect($entity->targetSubfield)->toBe('description');
        });

        it('trims targetSubfield whitespace', function () {
            $entity = PromptMatrixEntity::fromArray([
                'targetSubfield' => '  alt_text  ',
            ]);

            expect($entity->targetSubfield)->toBe('alt_text');
        });
    });

    describe('toArray', function () {
        it('roundtrip preserves all values', function () {
            $input = [
                'mode' => 'page',
                'templates' => [10],
                'fields' => [1, 2],
                'prompt' => 'Translate this',
                'label' => 'Translate',
                'overwriteTarget' => true,
                'targetSubfield' => 'alt_text',
                'ignoreFieldContent' => true,
            ];

            $entity = PromptMatrixEntity::fromArray($input);
            $output = $entity->toArray();

            expect($output)->toBe($input);
        });

        it('handles null templates and fields with safe defaults', function () {
            $entity = new PromptMatrixEntity();
            $array = $entity->toArray();

            expect($array['templates'])->toBe([]);
            expect($array['fields'])->toBe([]);
            expect($array['prompt'])->toBe('');
            expect($array['label'])->toBe('');
        });
    });
});
