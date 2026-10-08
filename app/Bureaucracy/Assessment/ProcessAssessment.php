<?php

namespace App\Bureaucracy\Assessment;

final readonly class ProcessAssessment
{
    public function __construct(public string $definitionId, public string $topic, public string $relevance, public CoverageResult $coverage, public array $variants, public int $priority) {}

    public function toArray(): array
    {
        return ['definition_id' => $this->definitionId, 'topic' => $this->topic, 'relevance' => $this->relevance,
            'coverage' => $this->coverage->toArray(), 'variants' => $this->variants, 'priority' => $this->priority];
    }
}
