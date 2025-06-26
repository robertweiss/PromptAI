<?php namespace ProcessWire;

$info = array(
	'title' => 'PromptAI',
	'summary' => 'Send a prompt and the content of a text field to an AI and save the result into a(nother) text field.',
	'version' => 4,
	'author' => 'Robert Weiss',
	'icon' => 'magic',
    'requires' => [
        'ProcessWire>=3.0.184'
    ],
	'href' => 'https://github.com/robertweiss/PromptAI',
    'singular' => true,
    'autoload' => 'template=admin'
);
