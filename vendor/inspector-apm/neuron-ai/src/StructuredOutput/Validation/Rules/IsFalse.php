<?php

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class IsFalse extends AbstractValidationRule
{
    protected string $message = '{name} must be false';

    public function validate(string $name, mixed $value, array &$violations)
    {
        if ($value !== false) {
            $violations[] = $this->buildMessage($name, $this->message);
        }
    }
}
