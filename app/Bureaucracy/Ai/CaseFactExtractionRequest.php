<?php

namespace App\Bureaucracy\Ai;

use App\Privacy\ProcessingPermit;

final readonly class CaseFactExtractionRequest
{
    public function __construct(
        public string $factKey,
        public string $question,
        public string $why,
        public string $message,
        public ?ProcessingPermit $permit = null,
        public ?ExtractionContext $context = null,
    ) {}

    public function processingContext(): array
    {
        return ['fact_key' => $this->factKey, 'question' => $this->question, 'why' => $this->why, 'message' => $this->message,
            ...($this->context === null ? [] : ['selection' => $this->context->selection, 'selected_person' => $this->context->selectedPerson])];
    }
}
