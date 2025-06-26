<?php

namespace NeuronAI\Observability\Events;

use NeuronAI\Chat\Messages\Message;

class InferenceStart
{
    public function __construct(public Message $message)
    {
    }
}
