<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\FactDefinition;
use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\RuleSourcePolicy;
use App\Models\BureaucracyCase;
use App\Models\Task;
use App\Profile\Applicability;
use App\Profile\ProfileEngine;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Routes that do not apply yet, and the single answer that would open them.
 *
 * A rule the engine hides is invisible for a reason the user cannot see. The
 * spouse of a Blue Card holder does not get the §9(3a) settlement route —
 * §9 Absatz 3a needs the sponsor to hold a Niederlassungserlaubnis nach §18c —
 * and nothing said so, so the route read as non-existent rather than not open
 * yet. The condition is already written in the rule's own `applies_if`; this
 * just says it out loud.
 *
 * Deliberately narrow, because a route that does not apply must never read as
 * one that does:
 *
 *  - authoritative rules only, like every other legal surface;
 *  - exactly ONE unmet condition, so the statement stays a fact rather than a
 *    speculation about a distant situation;
 *  - the rule's title and the unmet condition only — never its description,
 *    which is guidance written for people the rule actually covers.
 */
final class NearMissRules
{
    public function __construct(
        private FactRegistry $factRegistry,
        private RuleSourcePolicy $sourcePolicy,
        private CaseAttributes $caseAttributes,
    ) {}

    /**
     * @return list<array{key: string, content_version: ?string, title: string, opens_when: string}>
     */
    public function forCase(BureaucracyCase $case): array
    {
        $attributes = $this->caseAttributes->for($case);
        $rows = [];

        foreach ($this->candidateRules() as $task) {
            $blocking = Applicability::blockingConditions($task->applies_if, $attributes);

            if (count($blocking) !== 1) {
                continue;
            }

            if ($this->isBranchPredicate($blocking[0]['attribute'])) {
                continue;
            }

            $sentence = $this->describe($blocking[0]['attribute'], $blocking[0]['expected']);

            if ($sentence === null) {
                continue;
            }

            $rows[] = [
                'key' => $task->key,
                'content_version' => $task->content_version,
                'title' => $task->title,
                'opens_when' => $sentence,
            ];
        }

        usort($rows, fn (array $left, array $right): int => $left['key'] <=> $right['key']);

        return $rows;
    }

    /**
     * @return Collection<int, Task>
     */
    private function candidateRules(): Collection
    {
        return Task::query()
            ->authoritative()
            ->whereNotNull('applies_if')
            ->where('coverage_scope', 'case')
            ->orderBy('key')
            ->get()
            ->filter(fn (Task $task): bool => $this->sourcePolicy->persistedErrors($task) === [])
            ->values();
    }

    /**
     * `purpose`, `citizenship_group`, `permit_track` and friends are compiled
     * from a rule's `situation:` header — they say which branch a rule belongs
     * to, not what a person could do next. "Opens when your purpose is digital
     * nomad" is not an option anyone has; it just describes someone else.
     */
    private function isBranchPredicate(string $attribute): bool
    {
        foreach (ProfileEngine::BRANCH_PREDICATES as $predicate) {
            if (array_key_exists($attribute, $predicate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Phrase one unmet condition. Returns null when the operator has no
     * unambiguous phrasing — better to stay silent than to describe a legal
     * condition loosely.
     */
    private function describe(string $attribute, mixed $expected): ?string
    {
        try {
            $definition = $this->factRegistry->definition($attribute);
        } catch (DomainException) {
            return null;
        }

        $subject = $this->subject($definition);

        if ($definition->type === 'boolean') {
            // Only ever phrase the affirmative. `case.family.independent_after
            // _separation` opens when the marriage does NOT continue, and
            // "opens when you no longer live together" is not an opportunity to
            // put in front of someone — it is a life event, not a next step.
            return $expected === true || $expected === 'true' || $expected === 1
                ? $subject
                : null;
        }

        if (! is_array($expected)) {
            return $subject.' is '.$this->valueLabel($definition, (string) $expected);
        }

        if (array_is_list($expected)) {
            $labels = array_map(fn (mixed $value): string => $this->valueLabel($definition, (string) $value), $expected);

            return $subject.' is '.$this->joinOr($labels);
        }

        $operator = array_key_first($expected);
        $operand = $expected[$operator];

        return match ($operator) {
            'in' => is_array($operand)
                ? $subject.' is '.$this->joinOr(array_map(
                    fn (mixed $value): string => $this->valueLabel($definition, (string) $value),
                    $operand,
                ))
                : null,
            'gte' => is_numeric($operand) ? $subject.' is at least '.$operand : null,
            'lte' => is_numeric($operand) ? $subject.' is at most '.$operand : null,
            'at_least_months_ago' => is_numeric($operand)
                ? $subject.' goes back at least '.$this->months((int) $operand)
                : null,
            'present' => $operand === true ? $subject.' is answered' : null,
            default => null,
        };
    }

    /**
     * The fact stated as a subject rather than as its question, so the sentence
     * reads "your spouse's residence status is …" instead of repeating
     * "Which German residence status does your spouse currently have?".
     */
    private function subject(FactDefinition $definition): string
    {
        return match ($definition->key) {
            'sponsor_current_title' => "your spouse's residence status",
            'current_residence_title' => 'the title you hold',
            'case_goal' => 'your goal',
            'marital_household_continues' => 'you still live together as a married household',
            'family_residence_permit_held_since' => 'your family-reunification permit',
            'blue_card_qualifying_months' => 'your documented Blue Card months',
            'weekly_work_hours' => 'your weekly working hours',
            'german_level' => 'your documented German',
            'livelihood_secured' => 'your secured livelihood',
            'housing_sufficient' => 'your housing',
            'legal_social_knowledge_proved' => 'your legal and social knowledge proof',
            'entry_mode' => 'how you entered',
            default => Str::lower(Str::headline($definition->key)),
        };
    }

    private function valueLabel(FactDefinition $definition, string $value): string
    {
        if ($definition->type === 'boolean') {
            return $value === '1' || $value === 'true' ? 'yes' : 'no';
        }

        return match ($value) {
            'national_d_visa' => 'a national D visa',
            'standard_work_permit' => 'a work residence permit',
            'blue_card' => 'an EU Blue Card',
            'blue_card_pending' => 'a pending EU Blue Card',
            'family_reunification' => 'a family reunification permit',
            'settlement_permit_9' => 'permanent residence (§9)',
            'settlement_permit_18c' => 'permanent residence (§18c)',
            'settlement_permit' => 'a settlement permit',
            'renew_current_title' => 'renewing your current title',
            'understand_options' => 'understanding your options',
            'yes' => 'confirmed',
            'a1', 'a2', 'b1', 'b2', 'c1', 'c2' => Str::upper($value),
            default => Str::lower(Str::headline($value)),
        };
    }

    /**
     * @param  list<string>  $labels
     */
    private function joinOr(array $labels): string
    {
        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' or '.$last;
    }

    private function months(int $count): string
    {
        if ($count % 12 === 0) {
            $years = intdiv($count, 12);

            return $years === 1 ? '1 year' : $years.' years';
        }

        return $count.' months';
    }
}
