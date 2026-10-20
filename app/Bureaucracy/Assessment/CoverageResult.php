<?php

namespace App\Bureaucracy\Assessment;

final readonly class CoverageResult
{
    public function __construct(public string $status, public array $reasons = []) {}

    public function toArray(): array
    {
        return ['status' => $this->status, 'reasons' => $this->reasons];
    }
}
