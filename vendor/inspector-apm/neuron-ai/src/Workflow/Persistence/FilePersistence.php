<?php

namespace NeuronAI\Workflow\Persistence;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

class FilePersistence implements PersistenceInterface
{
    public function __construct(
        protected string $directory,
        protected string $prefix = 'neuron_workflow_',
        protected string $ext = '.store'
    ) {
        if (!\is_dir($this->directory)) {
            throw new WorkflowException("Directory '{$this->directory}' does not exist");
        }
    }

    public function save(string $workflowId, WorkflowInterrupt $interrupt): void
    {
        \file_put_contents($this->getFilePath($workflowId), \json_encode($interrupt));
    }

    public function load(string $workflowId): WorkflowInterrupt
    {
        if (!\is_file($this->getFilePath($workflowId))) {
            throw new WorkflowException("No saved workflow found for ID: {$workflowId}.");
        }

        $interrupt = \json_decode(\file_get_contents($this->getFilePath($workflowId)), true) ?? [];

        return new WorkflowInterrupt(
            $interrupt['data'],
            $interrupt['currentNode'],
            new WorkflowState($interrupt['state'])
        );
    }

    public function delete(string $workflowId): void
    {
        if (\file_exists($this->getFilePath($workflowId))) {
            \unlink($this->getFilePath($workflowId));
        }
    }

    protected function getFilePath(string $workflowId): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$this->prefix.$workflowId.$this->ext;
    }
}
