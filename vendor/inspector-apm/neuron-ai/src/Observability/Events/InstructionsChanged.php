<?php

namespace NeuronAI\Observability\Events;

class InstructionsChanged
{
    public function __construct(
        public string $previous,
        public string $current
    ) {
    }
}
