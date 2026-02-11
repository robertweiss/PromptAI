<?php

use ProcessWire\PromptAIAgent;

require_once __DIR__.'/../../PromptAIAgent.php';

describe('PromptAIAgent', function () {
    it('throws when apiKey is missing', function () {
        PromptAIAgent::make([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5-20250929',
        ]);
    })->throws(\Exception::class, 'API key, provider and model are required');

    it('throws when provider is missing', function () {
        PromptAIAgent::make([
            'apiKey' => 'test-key',
            'model' => 'claude-sonnet-4-5-20250929',
        ]);
    })->throws(\Exception::class, 'API key, provider and model are required');

    it('throws when model is missing', function () {
        PromptAIAgent::make([
            'apiKey' => 'test-key',
            'provider' => 'anthropic',
        ]);
    })->throws(\Exception::class, 'API key, provider and model are required');

    it('throws for unsupported provider', function () {
        PromptAIAgent::make([
            'apiKey' => 'test-key',
            'provider' => 'nonexistent',
            'model' => 'test-model',
            'systemPrompt' => '',
        ]);
    })->throws(\Exception::class, 'Provider not supported');

    it('creates agent with valid anthropic config', function () {
        $agent = PromptAIAgent::make([
            'apiKey' => 'test-key',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5-20250929',
            'systemPrompt' => 'You are a test assistant',
        ]);

        expect($agent)->toBeInstanceOf(PromptAIAgent::class);
        expect($agent->instructions())->toBe('You are a test assistant');
    });

    it('creates agent with valid openai config', function () {
        $agent = PromptAIAgent::make([
            'apiKey' => 'test-key',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'systemPrompt' => '',
        ]);

        expect($agent)->toBeInstanceOf(PromptAIAgent::class);
    });

    it('creates agent with valid gemini config', function () {
        $agent = PromptAIAgent::make([
            'apiKey' => 'test-key',
            'provider' => 'gemini',
            'model' => 'gemini-2.0-flash',
            'systemPrompt' => '',
        ]);

        expect($agent)->toBeInstanceOf(PromptAIAgent::class);
    });

    it('creates agent with valid deepseek config', function () {
        $agent = PromptAIAgent::make([
            'apiKey' => 'test-key',
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'systemPrompt' => '',
        ]);

        expect($agent)->toBeInstanceOf(PromptAIAgent::class);
    });

    it('returns empty string for instructions when systemPrompt is empty', function () {
        $agent = PromptAIAgent::make([
            'apiKey' => 'test-key',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5-20250929',
            'systemPrompt' => '',
        ]);

        expect($agent->instructions())->toBe('');
    });

    it('returns empty tools array when enableTools is false', function () {
        $agent = PromptAIAgent::make([
            'apiKey' => 'test-key',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5-20250929',
            'systemPrompt' => '',
            'enableTools' => false,
        ]);

        // getTools merges property tools + tools() method
        expect($agent->getTools())->toBe([]);
    });

    it('returns toolkit when enableTools is true', function () {
        $agent = PromptAIAgent::make([
            'apiKey' => 'test-key',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5-20250929',
            'systemPrompt' => '',
            'enableTools' => true,
        ]);

        $tools = $agent->getTools();
        expect($tools)->toHaveCount(1);
        expect($tools[0])->toBeInstanceOf(\ProcessWire\ProcessWireToolkit::class);
    });
});
