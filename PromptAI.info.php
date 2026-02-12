<?php namespace ProcessWire;

$info = [
    'title' => 'PromptAI',
    'summary' => 'Multi-provider AI integration (Anthropic, OpenAI, Gemini, DeepSeek) for processing text and file fields via configurable prompts.',
    'version' => 22,
    'author' => 'Robert Weiss',
    'icon' => 'magic',
    'requires' => [
        'ProcessWire>=3.0.184',
        'PHP>=8.3.0',
    ],
    'href' => 'https://github.com/robertweiss/PromptAI',
    'singular' => true,
    'autoload' => 'template=admin',
    'page' => [
        'parent' => 'setup',
        'name' => 'prompt-ai',
        'title' => 'Prompt AI',
    ],
];
