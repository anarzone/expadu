<?php

namespace App\Bureaucracy\Catalogue;

final readonly class ProcessDefinition
{
    public function __construct(public string $id, public string $topic, public array $variants, public ?string $title = null) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'topic' => $this->topic, 'title' => $this->title, 'variants' => $this->variants];
    }
}
