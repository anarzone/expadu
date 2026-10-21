<?php

namespace App\Bureaucracy\Analyser;

use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\Assessment\AssessPerson;
use App\Bureaucracy\Assessment\EvaluateCriteria;
use App\Bureaucracy\RuleSourcePolicy;

/**
 * The cards the analyser may answer from: units of the active catalogue release that
 * are live today and passed the automated source check (`quote_checked`). Only the
 * text that check covers is kept (ClaimCheck::visibleText: title, description, its
 * variants, step titles and bodies, document labels and notes), so every figure in
 * it was matched to a quote on the official page. Text a person's confirmed answers
 * rule out (an unmet branch) is left out, as the plan leaves it out.
 */
final class CardCorpus
{
    private const Applies = ['requirements_met', 'supported_preparation'];

    /**
     * @param  array<string, mixed>  $assessment  AssessPerson's output for the same input
     * @param  array<string, mixed>  $facts  the facts the engine evaluated (values and states)
     * @return array<string, CheckedCard> keyed by card id
     */
    public function for(AssessmentInput $input, array $assessment, array $facts): array
    {
        $decided = [];
        foreach ($assessment['processes'] ?? [] as $process) {
            foreach ($process['variants'] ?? [] as $variant) {
                $decided[$variant['id']] = $variant['assessment'] ?? null;
            }
        }
        $claims = [];
        foreach ($input->catalogue['inventory'] ?? [] as $unit) {
            if (is_array($unit) && is_string($unit['key'] ?? null)) {
                $claims[$unit['key']] = array_values(array_filter($unit['authored_record']['claims'] ?? [],
                    fn ($claim) => is_array($claim) && is_string($claim['states'] ?? null) && is_string($claim['source'] ?? null)));
            }
        }
        $evaluate = new EvaluateCriteria;
        $keep = fn (mixed $item): bool => is_array($item)
            && $evaluate->evaluate(is_array($item['applies_if'] ?? null) ? $item['applies_if'] : [], $facts, $input->at)['status'] !== 'unmet';

        $cards = [];
        foreach ($input->catalogue['definitions'] ?? [] as $definition) {
            foreach ($definition['variants'] ?? [] as $variant) {
                if (! $this->live($variant, $input)) {
                    continue;
                }
                $segments = [$variant['title'] ?? null, $variant['description'] ?? null];
                $keys = $variant['criterion_keys'] ?? [];
                foreach (['description_variants' => ['body'], 'instructions' => ['title', 'body'], 'documents' => ['label', 'note']] as $field => $parts) {
                    foreach ($variant[$field] ?? [] as $item) {
                        if (! $keep($item)) {
                            continue;
                        }
                        foreach ($parts as $part) {
                            $segments[] = $item[$part] ?? null;
                        }
                        $keys = [...$keys, ...$this->conditionKeys($item['applies_if'] ?? [])];
                    }
                }
                $segments = array_values(array_unique(array_filter(array_map(
                    fn ($text) => is_string($text) ? AnswerText::whitespace($text) : '', $segments), fn ($text) => $text !== '')));
                $decision = $decided[$variant['id']] ?? null;
                $criteria = $evaluate->evaluate($variant['conditions'] ?? [], $facts, $input->at);
                $cards[$variant['id']] = new CheckedCard(
                    id: $variant['id'],
                    title: AnswerText::whitespace((string) $variant['title']),
                    definitionId: $definition['id'],
                    topic: $definition['topic'],
                    segments: $segments,
                    conditionKeys: array_values(array_unique($keys)),
                    appliesToCase: match (true) {
                        in_array($decision, self::Applies, true) => true,
                        $decision === 'needs_information' => null,
                        default => false,
                    },
                    assessment: $decision,
                    criteria: array_map(fn ($row) => ['fact_key' => $row['fact_key'], 'status' => $row['status']], $criteria['criteria']),
                    missingFacts: $criteria['missing'],
                    sources: array_values(array_filter(array_map(fn ($source) => is_array($source) && is_string($source['url'] ?? null) && str_starts_with($source['url'], 'https://')
                        ? ['kind' => (string) ($source['kind'] ?? ''), 'label' => (string) ($source['label'] ?? ''), 'url' => $source['url']] : null,
                        $variant['review']['legal_sources'] ?? []))),
                    claims: $claims[$variant['task_key'] ?? $variant['id']] ?? [],
                    urgencyRank: AssessPerson::urgencyRank((string) ($variant['urgency'] ?? 'medium')),
                );
            }
        }

        return $cards;
    }

    /** Live today in this jurisdiction (as AssessPerson decides) and approved by the source check. */
    private function live(array $variant, AssessmentInput $input): bool
    {
        $review = $variant['review'] ?? [];
        $today = $input->at->toDateString();

        return ($variant['jurisdiction'] ?? null) === $input->jurisdiction && ($review['review_status'] ?? null) === 'approved'
            && ($review['source_verification'] ?? null) === RuleSourcePolicy::QuoteChecked
            && is_string($review['review_due_at'] ?? null) && $review['review_due_at'] >= $today
            && (! isset($review['effective_from']) || $review['effective_from'] <= $today)
            && (! isset($review['effective_to']) || $review['effective_to'] >= $today);
    }

    /** @return list<string> */
    private function conditionKeys(mixed $groups): array
    {
        if (! is_array($groups) || $groups === []) {
            return [];
        }
        $keys = [];
        foreach (array_is_list($groups) ? $groups : [$groups] as $group) {
            if (is_array($group)) {
                $keys = [...$keys, ...array_filter(array_keys($group), is_string(...))];
            }
        }

        return $keys;
    }
}
