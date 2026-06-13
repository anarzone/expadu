<?php

namespace App\Composer;

/**
 * The result of the single parse call: an intent plus exactly one
 * payload. `plan` carries Constraints for plan_day; `query` carries the
 * normalized search text for find / bureaucracy_q / take_me_there.
 * `source` is "llm" or "heuristic" so the UI can frame a degraded parse
 * honestly instead of showing a spinner that ends in an apology.
 */
final readonly class ParsedPrompt
{
    public function __construct(
        public PromptIntent $intent,
        public ?Constraints $plan = null,
        public ?string $query = null,
        public string $source = 'heuristic',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent->value,
            'source' => $this->source,
            // Kept as `constraints` to match the built pipeline + frontend.
            'constraints' => $this->plan?->toArray(),
            'query' => $this->query,
        ];
    }
}
