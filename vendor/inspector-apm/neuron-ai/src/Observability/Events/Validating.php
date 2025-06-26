<?php

namespace NeuronAI\Observability\Events;

class Validating
{
    public function __construct(public string $class, public string $json)
    {
    }
}
