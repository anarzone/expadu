<?php

namespace App\Bureaucracy\Ai;

final readonly class CaseFactExtractionRequest
{
    public function __construct(
        public string $factKey,
        public string $question,
        public string $why,
        public string $message,
    ) {}
}
