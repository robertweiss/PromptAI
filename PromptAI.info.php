<?php namespace ProcessWire;

$info = [
    'title' => 'PromptAI',
    'summary' => 'Multi-provider AI integration (Anthropic, OpenAI, Gemini, DeepSeek) for processing text and file fields via configurable prompts.',
    'version' => 240,
    'author' => 'Robert Weiss',
    'icon' => 'magic',
    'requires' => [
        'ProcessWire>=3.0.184',
        'PHP>=8.3.0',
    ],
    'href' => 'https://github.com/robertweiss/PromptAI',
    'singular' => true,
    'autoload' => 'template=admin',
    'permission' => 'promptai',
    'permissions' => array(
		'promptai-config' => __('Show PromptAI configuration'),
		'promptai' => __('Show PromptAI buttons on page edit screen')
	),
    'page' => [
        'parent' => 'setup',
        'name' => 'prompt-ai',
        'title' => __('Prompt AI'),
    ],
];
