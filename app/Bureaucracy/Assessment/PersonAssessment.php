<?php

namespace App\Bureaucracy\Assessment;

final readonly class PersonAssessment
{
    public function __construct(private array $result) {}

    public function toArray(): array
    {
        return $this->result;
    }
}
