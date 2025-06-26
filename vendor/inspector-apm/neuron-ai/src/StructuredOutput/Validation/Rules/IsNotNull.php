<?php

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class IsNotNull extends AbstractValidationRule
{
    protected string $message = '{name} must not be null';

    public function validate(string $name, mixed $value, array &$violations)
    {
        if ($value === null) {
            $violations[] = $this->buildMessage($name, $this->message);
        }
    }
}
