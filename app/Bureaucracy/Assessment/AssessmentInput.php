<?php

namespace App\Bureaucracy\Assessment;

use Carbon\CarbonImmutable;

/** All access, I/O, fact validation and live publication checks happen before this boundary. */
final readonly class AssessmentInput
{
    public function __construct(
        public array $facts,
        public array $relationships,
        public array $processes,
        public array $catalogue,
        public string $jurisdiction,
        public CarbonImmutable $at,
        public ?string $goal = null,
    ) {}
}
