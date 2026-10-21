<?php

namespace App\Bureaucracy\Analyser;

/**
 * One live, source-checked card as the analyser may use it: only the text whose
 * figures the source check covers, and what the engine decided about this case.
 */
final readonly class CheckedCard
{
    /**
     * @param  list<string>  $segments  checked text pieces, each quotable on its own
     * @param  list<string>  $conditionKeys  fact keys the card's conditions read
     * @param  list<array{fact_key: string, status: string}>  $criteria
     * @param  list<string>  $missingFacts
     * @param  list<array{kind: string, label: string, url: string}>  $sources
     * @param  list<array{states: string, source: string}>  $claims
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $definitionId,
        public string $topic,
        public array $segments,
        public array $conditionKeys,
        public ?bool $appliesToCase,
        public ?string $assessment,
        public array $criteria,
        public array $missingFacts,
        public array $sources,
        public array $claims,
        public int $urgencyRank,
    ) {}

    /** Where a quoted sentence comes from: the claim it restates, else the official page. */
    public function sourceFor(string $quote): ?array
    {
        $quoteKey = AnswerText::key($quote);
        $bySource = array_column($this->sources, null, 'label');
        foreach ($this->claims as $claim) {
            $states = AnswerText::key($claim['states']);
            if ($states !== '' && (str_contains($quoteKey, $states) || str_contains($states, $quoteKey)) && isset($bySource[$claim['source']])) {
                return $bySource[$claim['source']];
            }
        }

        return collect($this->sources)->firstWhere('kind', 'implementation') ?? ($this->sources[0] ?? null);
    }
}
