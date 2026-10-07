<?php

namespace App\Bureaucracy\Catalogue;

final readonly class ProcessDefinition
{
    public function __construct(public string $id, public string $topic, public array $variants) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'topic' => $this->topic, 'variants' => $this->variants];
    }
}
