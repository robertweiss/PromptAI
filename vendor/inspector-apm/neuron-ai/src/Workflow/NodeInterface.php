<?php

namespace NeuronAI\Workflow;

interface NodeInterface
{
    public function run(WorkflowState $state): WorkflowState;
    public function setContext(WorkflowContext $context): void;
}
