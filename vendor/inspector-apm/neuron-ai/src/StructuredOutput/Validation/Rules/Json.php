<?php

namespace NeuronAI\StructuredOutput\Validation\Rules;

use NeuronAI\StructuredOutput\StructuredOutputException;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class Json extends AbstractValidationRule
{
    protected string $message = '{name} must be a valid JSON string';

    public function validate(string $name, mixed $value, array &$violations)
    {
        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_scalar($value) && !$value instanceof \Stringable) {
            throw new StructuredOutputException('Cannot validate a non-scalar value.');
        }

        $value = (string) $value;

        try {
            json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $violations[] = $this->buildMessage($name, $this->message);
        }
    }
}
