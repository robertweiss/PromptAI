<?php

namespace NeuronAI\Tools;

use NeuronAI\Exceptions\ArrayPropertyException;

class ArrayProperty implements ToolPropertyInterface
{
    protected PropertyType $type = PropertyType::ARRAY;

    /**
     * @throws ArrayPropertyException
     */
    public function __construct(
        protected string $name,
        protected string $description,
        protected bool $required = false,
        protected ?ToolPropertyInterface $items = null,
        protected ?int $minItems = null,
        protected ?int $maxItems = null,
    ) {
        $this->validateConstraints();
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type->value,
            'items' => $this->getJsonSchema(),
            'required' => $this->required,
        ];
    }

    public function getJsonSchema(): array
    {
        $schema = [
            'type' => $this->type->value,
            'description' => $this->description,
        ];

        if (!empty($this->items)) {
            $schema['items'] = $this->items->getJsonSchema();
        }

        if (!empty($this->minItems)) {
            $schema['minItems'] = $this->minItems;
        }

        if (!empty($this->maxItems)) {
            $schema['maxItems'] = $this->maxItems;
        }

        return $schema;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): PropertyType
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getItems(): ?ToolPropertyInterface
    {
        return $this->items;
    }

    /**
     * @throws ArrayPropertyException
     */
    protected function validateConstraints(): void
    {
        if ($this->minItems !== null && $this->minItems < 0) {
            throw new ArrayPropertyException("minItems must be >= 0, got {$this->minItems}");
        }

        if ($this->maxItems !== null && $this->maxItems < 0) {
            throw new ArrayPropertyException("maxItems must be >= 0, got {$this->maxItems}");
        }

        if ($this->minItems !== null && $this->maxItems !== null && $this->minItems > $this->maxItems) {
            throw new ArrayPropertyException(
                "minItems ({$this->minItems}) cannot be greater than maxItems ({$this->maxItems})"
            );
        }
    }
}
