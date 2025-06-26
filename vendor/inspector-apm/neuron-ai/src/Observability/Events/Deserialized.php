<?php

namespace NeuronAI\Observability\Events;

class Deserialized
{
    public function __construct(public string $class)
    {
    }
}
