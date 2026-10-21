<?php

namespace App\Bureaucracy\Analyser;

/** What came back from the processor, before verification. Never logged. */
final readonly class AnswerDraft
{
    /**
     * @param  'ok'|'refusal'|'max_tokens'|'invalid'|'unavailable'|'limited'  $status
     * @param  list<mixed>  $sentences
     * @param  list<string>  $relatedCardIds
     */
    public function __construct(
        public string $status,
        public bool $answerable = false,
        public array $sentences = [],
        public array $relatedCardIds = [],
        public bool $dispatched = false,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public int $latencyMs = 0,
    ) {}

    public static function failed(string $status, bool $dispatched = false, ?int $inputTokens = null, ?int $outputTokens = null, int $latencyMs = 0): self
    {
        return new self($status, dispatched: $dispatched, inputTokens: $inputTokens, outputTokens: $outputTokens, latencyMs: $latencyMs);
    }
}
