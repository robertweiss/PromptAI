<?php

namespace NeuronAI\Observability\Events;

class InstructionsChanging
{
    public function __construct(
        public string $instructions
    ) {
    }
}
