<?php

namespace NeuronAI\StructuredOutput\Validation;

interface ValidationRuleInterface
{
    public function validate(string $name, mixed $value, array &$violations);
}
