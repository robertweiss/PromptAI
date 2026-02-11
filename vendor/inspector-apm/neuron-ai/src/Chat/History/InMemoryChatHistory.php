<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

class InMemoryChatHistory extends AbstractChatHistory
{
    public function __construct(int $contextWindow = 50000)
    {
        parent::__construct($contextWindow);
    }

    public function setMessages(array $messages): ChatHistoryInterface
    {
        return $this;
    }

    protected function clear(): ChatHistoryInterface
    {
        return $this;
    }
}
