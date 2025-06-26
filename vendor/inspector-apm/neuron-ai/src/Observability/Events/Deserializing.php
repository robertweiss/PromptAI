<?php

namespace NeuronAI\Observability\Events;

class Deserializing
{
    public function __construct(public string $class)
    {
    }
}
