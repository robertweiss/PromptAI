<?php

namespace NeuronAI;

trait StaticConstructor
{
    /**
     * Static constructor.
     *
     * @param ...$arguments
     * @return static
     */
    public static function make(...$arguments): static
    {
        /** @phpstan-ignore new.static */
        return new static(...$arguments);
    }
}
