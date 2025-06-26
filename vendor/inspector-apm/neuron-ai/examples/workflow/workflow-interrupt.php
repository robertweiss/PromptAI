<?php

declare(strict_types=1);

use NeuronAI\Tests\Workflow\AfterInterruptNode;
use NeuronAI\Tests\Workflow\BeforeInterruptNode;
use NeuronAI\Tests\Workflow\InterruptNode;
use NeuronAI\Workflow\Edge;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

require_once __DIR__ . '/../../vendor/autoload.php';


$persistence = new FilePersistence(__DIR__);
$workflow = new Workflow($persistence, 'test_workflow');

$workflow->addNode(new BeforeInterruptNode())
    ->addNode(new InterruptNode())
    ->addNode(new AfterInterruptNode())
    ->addEdge(new Edge(BeforeInterruptNode::class, InterruptNode::class))
    ->addEdge(new Edge(InterruptNode::class, AfterInterruptNode::class))
    ->setStart(BeforeInterruptNode::class)
    ->setEnd(AfterInterruptNode::class);

try {
    $workflow->run(new WorkflowState(['value' => 8]));
} catch (WorkflowInterrupt $interrupt) {
    // Verify interrupt was saved
    $savedInterrupt = $persistence->load('test_workflow');
    echo "Workflow interrupted at {$savedInterrupt->getCurrentNode()}.".PHP_EOL;
}

$result = $workflow->resume(['status' => 'approved']);

echo $result->get('final_value').PHP_EOL; // It should print 28
