<?php

namespace App\Bureaucracy\Assessment;

use App\Bureaucracy\Catalogue\CatalogueHash;

/** Deterministic decision core: no container, database, network, AI, or implicit clock. */
final class AssessPerson
{
    public const DecisionVersion = '2026-10-07.urgency-first.1';

    private const GoalBonus = 50;

    private const AnchorBonus = 75;

    /** Catalogue phases that only start once the person has arrived. */
    private const AfterArrivalPhases = ['first_30_days', 'settling', 'ongoing'];

    private const OrientationKeys = ['citizenship_group', 'purpose', 'current_residence_title', 'entry_mode', 'sponsor', 'permit_track', 'business_kind'];

    public function assess(AssessmentInput $input): PersonAssessment
    {
        $evaluator = new EvaluateCriteria;
        $facts = (new AssessmentFacts)->combine($input->facts, $input->relationships);
        $dependencies = new DependencyIndex;
        $results = [];
        foreach ($input->catalogue['definitions'] ?? [] as $definition) {
            $variants = [];
            $priority = 0;
            $relevances = [];
            $reviewedCoverages = [];
            foreach ($definition['variants'] as $variant) {
                if (! $this->current($variant, $input)) {
                    continue;
                }
                $reviewedCoverages[] = $variant['coverage'];
                $conditions = $variant['conditions'];
                if ($conditions !== [] && ! array_is_list($conditions)) {
                    $conditions = [$conditions];
                }
                $relevanceGroups = array_map(fn ($group) => array_intersect_key($group, array_flip($variant['relevance_keys'] ?? self::OrientationKeys)), $conditions);
                $relevance = $evaluator->evaluate($relevanceGroups, $facts, $input->at);
                $relevances[] = $relevance['status'];
                if ($relevance['status'] === 'unmet') {
                    continue;
                }
                $criteria = $evaluator->evaluate($conditions, $facts, $input->at);
                // Choosing a procedure is separate from satisfying its legal
                // criteria. Do not turn an unrequested application into a duty.
                $confirmedIntent = false;
                $unresolvedIntent = false;
                $intentMissing = [];
                foreach ($conditions as $index => $group) {
                    $alternative = $criteria['alternatives'][$index];
                    if (isset($group['case_goal']) && $alternative['status'] !== 'unmet') {
                        $intent = $evaluator->condition('case_goal', $group['case_goal'], $facts, $input->at);
                        $confirmedIntent = $confirmedIntent || ($intent === CriterionResult::Met && $alternative['status'] === 'met');
                        $unresolvedIntent = $unresolvedIntent || $intent->unresolved();
                        if ($intent === CriterionResult::Met) {
                            $intentMissing = [...$intentMissing, ...$alternative['missing']];
                        }
                    }
                }
                // A goal only breaks ties inside an urgency tier, and only for a
                // route that can still apply. It never outranks legal urgency.
                $matchesGoal = false;
                foreach ($conditions as $index => $group) {
                    if (isset($group['case_goal']) && $input->goal !== null && $criteria['alternatives'][$index]['status'] !== 'unmet') {
                        $matchesGoal = $matchesGoal || $evaluator->condition('case_goal', $group['case_goal'], ['values' => ['case_goal' => $input->goal]], $input->at) === CriterionResult::Met;
                    }
                }
                $rank = self::urgencyRank($variant['urgency'] ?? 'medium') + ($matchesGoal ? self::GoalBonus : 0);
                // Someone still planning the move gets no steps whose catalogue phase is after arrival;
                // those wait, and ask nothing yet. A step without a phase is not held back.
                $afterArrival = $evaluator->condition('arrival_planned', true, $facts, $input->at) === CriterionResult::Met
                    && in_array($variant['kind'], ['preparation', 'action', 'verification'], true)
                    && in_array($variant['phase'] ?? null, self::AfterArrivalPhases, true);
                if ($criteria['status'] !== 'unmet') {
                    $priority = max($priority, $rank);
                }
                $complete = $variant['coverage'] === 'complete' && ! empty($variant['coverage_review']['criterion_keys']) && ! empty($variant['coverage_review']['source_review_reference']);
                $assessment = match ($criteria['status']) {
                    'unknown' => 'needs_information',
                    'unmet' => 'not_met',
                    default => $complete ? 'requirements_met' : 'supported_preparation',
                };
                foreach ($afterArrival ? [] : $criteria['missing'] as $key) {
                    $dependencies->add($key, $definition['id'], $variant['id'], $rank, $relevance['status'] === 'met');
                }
                if (! $afterArrival && ($variant['action_requires_intent'] ?? false) && ! $confirmedIntent && $unresolvedIntent) {
                    // Offer a preference/conflict review, not a legal criterion.
                    $dependencies->add('case_goal', $definition['id'], $variant['id'], $rank, $relevance['status'] === 'met');
                }
                if (! $afterArrival && ($variant['action_requires_intent'] ?? false) && ! $confirmedIntent) {
                    foreach ($intentMissing as $key) {
                        $dependencies->add($key, $definition['id'], $variant['id'], $rank, $relevance['status'] === 'met');
                    }
                }
                $anchor = (new TemporalDependencies)->anchorKey($variant, $facts);
                if (! $afterArrival && $criteria['status'] === 'met' && $anchor !== null && $evaluator->condition($anchor, ['present' => true], $facts, $input->at)->unresolved()) {
                    $dependencies->add($anchor, $definition['id'], $variant['id'], $rank + self::AnchorBonus, $relevance['status'] === 'met');
                }
                $variants[] = [...$variant, 'assessment' => $assessment, 'criteria' => $criteria['criteria'],
                    'alternatives' => $criteria['alternatives'], 'missing_facts' => $criteria['missing'],
                    'relevance' => $relevance['status'] === 'met' ? 'relevant' : 'unknown',
                    'actionable' => $criteria['status'] === 'met' && in_array($variant['kind'], ['preparation', 'action', 'verification'], true)
                        && (! ($variant['action_requires_intent'] ?? false) || $confirmedIntent)
                        && ! in_array($variant['type'], ['info'], true) && ! $afterArrival,
                    'after_arrival' => $afterArrival && $criteria['status'] === 'met'];
            }
            $coverage = $reviewedCoverages === [] ? 'review_required' : (in_array('partial', $reviewedCoverages, true) ? 'partial' : 'covered');
            $relevance = in_array('met', $relevances, true) ? 'relevant' : (in_array('unknown', $relevances, true) ? 'unknown' : ($relevances === [] ? 'unknown' : 'not_relevant'));
            $results[] = (new ProcessAssessment($definition['id'], $definition['topic'], $relevance,
                new CoverageResult($coverage, $coverage === 'review_required' ? ['no_current_reviewed_variant'] : []), $variants, $priority))->toArray();
        }
        usort($results, fn ($one, $two) => $two['priority'] <=> $one['priority'] ?: strcmp($one['definition_id'], $two['definition_id']));

        return new PersonAssessment(['schema_version' => 'bureaucracy.assessment.1', 'evaluated_at' => $input->at->toIso8601String(),
            'next_reassessment_at' => $input->at->addDay()->startOfDay()->toIso8601String(), 'jurisdiction' => $input->jurisdiction,
            'input_revision' => CatalogueHash::of(['decision_version' => self::DecisionVersion, 'facts' => $input->facts, 'relationships' => $input->relationships, 'processes' => $input->processes,
                'catalogue' => $input->catalogue, 'date' => $input->at->toDateString(), 'jurisdiction' => $input->jurisdiction, 'goal' => $input->goal]),
            'processes' => $results, 'question_dependencies' => $dependencies->toArray(), 'withdrawn' => $input->catalogue['withdrawn'] ?? []]);
    }

    /** Urgency tiers are 100 apart so goal and anchor bonuses can never cross a tier. */
    public static function urgencyRank(string $urgency): int
    {
        return match ($urgency) {
            'critical' => 400, 'high' => 300, 'medium' => 200, default => 100,
        };
    }

    private function current(array $variant, AssessmentInput $input): bool
    {
        $review = $variant['review'] ?? [];
        $today = $input->at->toDateString();

        return $variant['jurisdiction'] === $input->jurisdiction && ($review['review_status'] ?? null) === 'approved'
            && is_string($review['review_due_at'] ?? null) && $review['review_due_at'] >= $today
            && (! isset($review['effective_from']) || $review['effective_from'] <= $today)
            && (! isset($review['effective_to']) || $review['effective_to'] >= $today);
    }
}
