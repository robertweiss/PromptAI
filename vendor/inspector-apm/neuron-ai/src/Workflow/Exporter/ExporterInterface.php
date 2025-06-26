<?php

namespace NeuronAI\Workflow\Exporter;

use NeuronAI\Workflow\Workflow;

interface ExporterInterface
{
    public function export(Workflow $graph): string;
}
