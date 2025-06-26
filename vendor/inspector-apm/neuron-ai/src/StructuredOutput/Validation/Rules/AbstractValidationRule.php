<?php

namespace NeuronAI\StructuredOutput\Validation\Rules;

use NeuronAI\StructuredOutput\Validation\ValidationRuleInterface;

abstract class AbstractValidationRule implements ValidationRuleInterface
{
    protected function buildMessage(string $name, string $messageTemplate, array $vars = [])
    {
        foreach ($vars as $key => $value) {
            $messageTemplate = \str_replace('{'.$key.'}', $value, $messageTemplate);
        }

        return \str_replace('{name}', $name, $messageTemplate);
    }
}
