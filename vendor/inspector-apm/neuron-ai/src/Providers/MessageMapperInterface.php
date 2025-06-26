<?php

namespace NeuronAI\Providers;

use NeuronAI\Chat\Messages\Message;

interface MessageMapperInterface
{
    /**
     * @param array<Message> $messages
     * @return array
     */
    public function map(array $messages): array;
}
