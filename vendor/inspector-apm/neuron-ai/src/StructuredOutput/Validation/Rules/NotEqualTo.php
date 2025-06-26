<?php

namespace NeuronAI\StructuredOutput\Validation\Rules;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class NotEqualTo extends AbstractValidationRule
{
    public function __construct(protected mixed $reference)
    {
    }

    public function validate(string $name, mixed $value, array &$violations)
    {
        if ($value === $this->reference) {
            $violations[] = $this->buildMessage($name, 'must not be equal to {compare}', ['compare' => get_debug_type($this->reference)]);
        }
    }
}
