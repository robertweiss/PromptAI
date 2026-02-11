<?php

use ProcessWire\GetPagesTool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

require_once __DIR__.'/../../../PromptAI.config.php';
require_once __DIR__.'/../../../PromptAIHelper.php';
require_once __DIR__.'/../../../tools/GetPagesTool.php';

describe('GetPagesTool', function () {
    it('has the correct name', function () {
        $tool = GetPagesTool::make();
        expect($tool->getName())->toBe('getPages');
    });

    it('has a description', function () {
        $tool = GetPagesTool::make();
        expect($tool->getDescription())->not->toBeEmpty();
    });

    it('has a required selector property', function () {
        $tool = GetPagesTool::make();
        $properties = $tool->getProperties();

        expect($properties)->toHaveCount(1);
        expect($properties[0]->getName())->toBe('selector');
        expect($properties[0]->getType())->toBe(PropertyType::STRING);
        expect($properties[0]->isRequired())->toBeTrue();
    });

    it('blocks include=all selector', function () {
        $tool = GetPagesTool::make();
        $result = $tool->__invoke('template=basic-page, include=all');
        $data = json_decode($result, true);

        expect($data)->toHaveKey('error');
        expect($data['error'])->toContain('not allowed');
    });

    it('blocks check_access=0 selector', function () {
        $tool = GetPagesTool::make();
        $result = $tool->__invoke('template=blog, check_access=0');
        $data = json_decode($result, true);

        expect($data)->toHaveKey('error');
        expect($data['error'])->toContain('not allowed');
    });

    it('blocks check_access=false selector', function () {
        $tool = GetPagesTool::make();
        $result = $tool->__invoke('template=blog, check_access=false');
        $data = json_decode($result, true);

        expect($data)->toHaveKey('error');
    });
});
