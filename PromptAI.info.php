<?php namespace ProcessWire;

$info = [
    'title' => 'PromptAI',
    'summary' => 'Send a prompt and the content of a text field to an AI and save the result into a(nother) text field.',
    'version' => 11,
    'author' => 'Robert Weiss',
    'icon' => 'magic',
    'requires' => [
        'ProcessWire>=3.0.184',
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
