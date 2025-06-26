<?php

namespace NeuronAI\Observability;

use NeuronAI\AgentInterface;
use NeuronAI\Observability\Events\PostProcessed;
use NeuronAI\Observability\Events\PostProcessing;
use NeuronAI\Observability\Events\VectorStoreResult;
use NeuronAI\Observability\Events\VectorStoreSearching;

trait HandleRagEvents
{
    public function vectorStoreSearching(AgentInterface $agent, string $event, VectorStoreSearching $data)
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $id = \md5($data->question->getContent());

        $this->segments[$id] = $this->inspector
            ->startSegment(self::SEGMENT_TYPE.'-vector-search', "vectorSearch( {$data->question->getContent()} )")
            ->setColor(self::SEGMENT_COLOR);
    }

    public function vectorStoreResult(AgentInterface $agent, string $event, VectorStoreResult $data)
    {
        $id = \md5($data->question->getContent());

        if (\array_key_exists($id, $this->segments)) {
            $segment = $this->segments[$id];
            $segment->addContext('Data', [
                    'question' => $data->question->getContent(),
                    'documents' => \count($data->documents)
                ]);
            $segment->end();
        }
    }

    public function postProcessing(AgentInterface $agent, string $event, PostProcessing $data)
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $segment = $this->inspector
            ->startSegment(self::SEGMENT_TYPE.'-postprocessing', $data->processor)
            ->setColor(self::SEGMENT_COLOR);

        $segment->addContext('Question', $data->question->jsonSerialize())
            ->addContext('Documents', $data->documents);

        $this->segments[$data->processor] = $segment;
    }

    public function postProcessed(AgentInterface $agent, string $event, PostProcessed $data)
    {
        if (\array_key_exists($data->processor, $this->segments)) {
            $this->segments[$data->processor]
                ->end()
                ->addContext('PostProcess', $data->documents);
        }
    }
}
