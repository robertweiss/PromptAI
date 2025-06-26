<?php

namespace NeuronAI\Observability\Events;

use NeuronAI\Workflow\WorkflowState;

class WorkflowNodeEnd
{
    public function __construct(
        public string $node,
        public WorkflowState $state
    ) {
    }
}
