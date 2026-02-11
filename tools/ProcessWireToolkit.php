<?php namespace ProcessWire;

use NeuronAI\Tools\Toolkits\AbstractToolkit;

class ProcessWireToolkit extends AbstractToolkit {
    public function guidelines(): ?string {
        return "You have access to ProcessWire CMS tools for querying pages and fields. "
            . "ProcessWire uses a selector engine (e.g. 'template=blog-post, limit=10, sort=-created') to find pages. "
            . "Common selector operators: = (equals), != (not equals), *= (contains), ^= (starts with), $= (ends with), > (greater), < (less). "
            . "Common selector fields: template, title, name, id, parent, created, modified, sort, limit, start.";
    }

    public function provide(): array {
        return [
            GetPagesTool::make(),
            GetPageTool::make(),
            GetFieldsTool::make(),
        ];
    }
}
