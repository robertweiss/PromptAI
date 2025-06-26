<?php

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class Email extends AbstractValidationRule
{
    protected string $message = '{name} must be a valid email address';

    public function validate(string $name, mixed $value, array &$violations)
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $violations[] = $this->buildMessage($name, $this->message);
        }
    }
}
