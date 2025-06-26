<?php

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class IsTrue extends AbstractValidationRule
{
    protected string $message = '{name} must be true';

    public function validate(string $name, mixed $value, array &$violations)
    {
        if ($value !== true) {
            $violations[] = $this->buildMessage($name, $this->message);
        }
    }
}
