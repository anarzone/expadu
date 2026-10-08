<?php

namespace App\Bureaucracy\Questions;

use App\Bureaucracy\Assessment\AssessmentFacts;
use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\Assessment\AssessPerson;
use App\Bureaucracy\Assessment\CriterionResult;
use App\Bureaucracy\Assessment\EvaluateCriteria;
use App\Bureaucracy\Assessment\TemporalDependencies;
use App\Bureaucracy\Facts\FactRegistry;
use App\Privacy\ProcessingConsentStore;
use Symfony\Component\Yaml\Yaml;

final class QuestionProtocol
{
    private array $protocol;

    public function __construct(private FactRegistry $registry)
    {
        $this->protocol = Yaml::parseFile(database_path('seeders/data/bureaucracy/schema/question-protocol.yaml'));
        foreach ([...$this->protocol['orientation'], ...($this->protocol['not_asked'] ?? [])] as $item) {
            $this->registry->definition($item['fact_key']);
            foreach ($item['when'] as $group) {
                foreach ($group as $key => $condition) {
                    $this->registry->validateConditionOperand($key, $condition);
                }
            }
        }
    }

    public function version(): string
    {
        return $this->protocol['version'];
    }

    /** Candidates are previews. Persisting an offer requires a separate explicit command. */
    public function candidates(AssessmentInput $input): array
    {
        $facts = (new AssessmentFacts)->combine($input->facts, $input->relationships);
        $assessment = (new AssessPerson)->assess($input)->toArray();
        $candidates = [];
        foreach ($assessment['question_dependencies'] as $dependency) {
            $candidates[$dependency['fact_key']] = [...$dependency, 'reason' => 'reviewed_process_dependency'];
        }
        // A question the person's own answers already settle for the route they chose is not asked.
        foreach ($this->protocol['not_asked'] ?? [] as $item) {
            // Checked condition by condition: a goal is an intent, which criteria evaluation skips.
            $settled = array_filter($item['when'], fn (array $group) => $group !== [] && array_filter(array_keys($group),
                fn ($key) => (new EvaluateCriteria)->condition($key, $group[$key], $facts, $input->at) !== CriterionResult::Met) === []);
            if (isset($candidates[$item['fact_key']]) && $settled !== []) {
                unset($candidates[$item['fact_key']]);
            }
        }
        foreach ($this->protocol['orientation'] as $item) {
            $key = $item['fact_key'];
            $state = $facts['states'][$key] ?? (isset($facts['values'][$key]) ? 'value' : 'unknown');
            if ($state !== 'value' && (new EvaluateCriteria)->evaluate($item['when'], $facts, $input->at)['status'] === 'met') {
                $candidates[$key] ??= ['fact_key' => $key, 'process_ids' => [], 'variant_ids' => [], 'priority' => 0, 'reason' => 'orientation'];
            }
        }
        foreach ($candidates as $key => &$candidate) {
            $definition = $this->registry->definition($key);
            $candidate['kind'] = ($facts['states'][$key] ?? null) === 'conflict' ? 'resolve_conflict' : 'answer';
            if ($candidate['kind'] === 'resolve_conflict') {
                $origin = $facts['conflict_origins'][$key] ?? 'local_assertions';
                if ($origin === 'local_assertions') {
                    $candidate['action'] = ['type' => 'review_fact_conflict', 'fact_key' => $key, 'required_scopes' => ['view_facts', 'edit_facts']];
                } else {
                    $candidate['kind'] = 'review_relationship';
                    $candidate['action'] = ['type' => 'review_relationships', 'fact_key' => $key, 'reason' => $origin,
                        'required_scopes' => ['view_plan', 'view_facts', 'edit_facts']];
                }
            }
            $candidate['question'] = $definition->question;
            $candidate['why'] = $definition->why;
            $candidate['answer_schema'] = ['type' => $definition->type, 'options' => $definition->options,
                'date_semantics' => $definition->dateSemantics, 'allows_not_applicable' => $definition->allowsNotApplicable];
            $candidate['registry_priority'] = $definition->priority;
            $candidate['protocol_version'] = $this->version();
            $candidate['dependency_token'] = ProcessingConsentStore::digest([
                'protocol' => $this->protocol, 'registry' => $this->registry->version(), 'key' => $key,
                'assessment_revision' => $assessment['input_revision'], 'subject_fact_revision' => $input->facts['revision'],
            ]);
            $candidate['deferral_token'] = $this->deferralToken($candidate, $input, $facts);
        }
        unset($candidate);
        $result = array_values($candidates);
        // Within one urgency, a question for work that already applies comes before one that only
        // decides whether other routes apply.
        usort($result, fn ($a, $b) => $b['priority'] <=> $a['priority'] ?: ($b['relevant'] ?? false) <=> ($a['relevant'] ?? false)
            ?: count($b['process_ids']) <=> count($a['process_ids']) ?: $b['registry_priority'] <=> $a['registry_priority'] ?: strcmp($a['fact_key'], $b['fact_key']));

        return array_map(function (array $candidate): array {
            unset($candidate['relevant']);

            return $candidate;
        }, $result);
    }

    /** Skip persists until one of this question's dependencies changes, not every unrelated edit. */
    private function deferralToken(array $candidate, AssessmentInput $input, array $facts): string
    {
        $keys = [$candidate['fact_key']];
        $variants = [];
        foreach ($input->catalogue['definitions'] ?? [] as $process) {
            foreach ($process['variants'] as $variant) {
                if (! in_array($variant['id'], $candidate['variant_ids'], true)) {
                    continue;
                }
                $variants[] = $variant;
                $groups = $variant['conditions'];
                $groups = $groups === [] || array_is_list($groups) ? $groups : [$groups];
                foreach ($groups as $group) {
                    $keys = [...$keys, ...array_keys($group)];
                }
                $anchor = (new TemporalDependencies)->anchorKey($variant, $facts);
                if ($anchor !== null) {
                    $keys[] = $anchor;
                }
            }
        }
        foreach ($this->protocol['orientation'] as $item) {
            if ($item['fact_key'] === $candidate['fact_key']) {
                foreach ($item['when'] as $group) {
                    $keys = [...$keys, ...array_keys($group)];
                }
            }
        }
        $keySet = array_flip(array_unique($keys));

        return ProcessingConsentStore::digest(['protocol' => $this->protocol, 'registry' => $this->registry->version(),
            'key' => $candidate['fact_key'], 'jurisdiction' => $input->jurisdiction, 'variants' => $variants,
            'values' => array_intersect_key($facts['values'], $keySet), 'states' => array_intersect_key($facts['states'], $keySet),
            'evidence' => array_intersect_key($facts['evidence'] ?? [], $keySet),
            'relationships' => array_intersect($keys, ['sponsor', 'sponsor_current_title']) !== [] ? $input->relationships : []]);
    }
}
