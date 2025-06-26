<?php

namespace NeuronAI\Observability\Events;

use NeuronAI\Chat\Messages\Message;

class PostProcessing
{
    public function __construct(
        public string $processor,
        public Message $question,
        public array $documents
    ) {
    }
}
