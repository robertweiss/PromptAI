<?php

namespace NeuronAI\Observability\Events;

use NeuronAI\Chat\Messages\Message;

class InferenceStop
{
    public function __construct(
        public Message $message,
        public Message $response
    ) {
    }
}
