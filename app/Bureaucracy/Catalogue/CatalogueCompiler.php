<?php

namespace App\Bureaucracy\Catalogue;

use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\GuidancePublication;
use App\Bureaucracy\RuleSourcePolicy;
use App\Models\Task;
use DomainException;
use Symfony\Component\Yaml\Yaml;

final class CatalogueCompiler
{
    public const ReviewFields = ['review_status', 'jurisdiction', 'reviewed_by', 'content_version', 'source_verification', 'verified_at', 'effective_from', 'effective_to', 'review_due_at', 'legal_sources', 'is_published'];

    public function __construct(private FactRegistry $facts, private RuleSourcePolicy $policy, private GuidancePublication $publication, private VerifiedActionDirectory $actions) {}

    /** @param list<Task> $tasks @param array<string, array>|null $mapping */
    public function compile(array $tasks, ?array $mapping = null): array
    {
        $mapping ??= Yaml::parseFile(database_path('seeders/data/bureaucracy/schema/process-map.yaml'))['entries'];
        $byKey = [];
        foreach ($tasks as $task) {
            if (! $task instanceof Task || ! is_string($task->key) || ! preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $task->key) || isset($byKey[$task->key])) {
                throw new DomainException('Every catalogue unit requires a unique stable key.');
            }
            $byKey[$task->key] = $task;
        }
        ksort($byKey);
        $this->validateDependencies($byKey);
        $inventory = [];
        $definitions = [];
        foreach ($byKey as $key => $task) {
            $record = $this->snapshot($task);
            $map = $mapping[$key] ?? null;
            $inventory[] = ['key' => $key, 'review_status' => $task->review_status, 'process_id' => $map['process_id'] ?? null,
                'status' => ! $task->is_published ? 'retired' : ($task->review_status === 'approved' ? 'mapped_reviewed' : 'review_required'),
                'source_hash' => $this->publication->contentHash($task), 'authored_record' => $record];
            if (! $task->is_published || $task->review_status !== 'approved') {
                continue;
            }
            if ($this->policy->persistedErrors($task) !== [] || ! array_key_exists($task->jurisdiction, config('bureaucracy_catalogue.jurisdictions', []))) {
                throw new DomainException("Reviewed unit [{$key}] has invalid source metadata, validity or jurisdiction.");
            }
            if (! is_array($map) || ! preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $map['process_id'] ?? '')
                || ! in_array($map['topic'] ?? null, ['address', 'residence', 'tax', 'health', 'money', 'family', 'work', 'education', 'driving'], true)
                || ! in_array($map['kind'] ?? null, ['action', 'preparation', 'option', 'verification', 'context'], true)
                || ! in_array($map['coverage'] ?? null, ['partial', 'complete'], true)) {
                throw new DomainException("Reviewed unit [{$key}] needs an explicit valid process mapping.");
            }
            if (array_key_exists('position', $map) && (! is_int($map['position']) || $map['position'] < 1)) {
                throw new DomainException("Unit [{$key}] needs a positive reviewed step position.");
            }
            $conditions = $task->applies_if ?? [];
            $keys = $this->validateConditions($conditions);
            $decisionPolicy = (new DecisionPolicy)->compile($task, $map, $keys);
            if ($map['coverage'] === 'complete') {
                foreach (array_is_list($conditions) ? $conditions : [$conditions] as $group) {
                    if (array_diff(array_keys($group), ['case_goal']) === []) {
                        throw new DomainException("Unit [{$key}] cannot claim complete coverage from an unconditional or goal-only alternative.");
                    }
                }
                $complete = $map['coverage_review'] ?? [];
                $declared = $complete['criterion_keys'] ?? [];
                sort($keys);
                if (! is_array($declared)) {
                    throw new DomainException('A complete criterion inventory is required.');
                }
                sort($declared);
                if (array_diff($keys, ['case_goal']) === [] || $declared !== $keys || ($complete['content_version'] ?? null) !== $task->content_version
                    || ($complete['reviewed_by'] ?? null) !== $task->reviewed_by || ! is_string($complete['source_review_reference'] ?? null)
                    || trim($complete['source_review_reference']) === '') {
                    throw new DomainException("Unit [{$key}] cannot claim complete coverage without a version-bound criterion review.");
                }
            }
            if (isset($map['occurrence_fact'])) {
                $this->facts->definition($map['occurrence_fact']);
            }
            if ($task->deadline_fact_key !== null) {
                $this->facts->validateDeadlineFact($task->deadline_fact_key);
            }
            foreach ($task->description_variants ?? [] as $variant) {
                $this->validateConditions($variant['applies_if'] ?? []);
            }
            $instructions = $this->items($key, 'instruction', $task->how_to_steps ?? []);
            $documents = $this->items($key, 'document', $task->documents_required ?? []);
            $branches = array_values(array_unique(array_filter(array_column($instructions, 'branch'))));
            foreach ($documents as $document) {
                if (isset($document['branch']) && ! in_array($document['branch'], $branches, true)) {
                    throw new DomainException("Document [{$document['id']}] references a missing branch.");
                }
                if (isset($document['from']) && ! isset($byKey[$document['from']])) {
                    throw new DomainException("Document [{$document['id']}] references a missing producing unit.");
                }
            }
            $actions = [];
            $unavailableActions = [];
            $urls = array_values(array_unique([...($task->links ?? []), ...array_column($instructions, 'link')]));
            foreach ($urls as $index => $url) {
                try {
                    $actions[] = $this->actions->compile($key.'.action.'.($index + 1), $url, 'information', $task->jurisdiction);
                } catch (DomainException) {
                    $unavailableActions[] = ['id' => $key.'.action.'.($index + 1), 'reason' => 'action_host_review_required'];
                }
            }
            foreach ($instructions as &$instruction) {
                if (isset($instruction['link'])) {
                    $action = array_values(array_filter($actions, fn ($action) => $action['url'] === $instruction['link']))[0] ?? null;
                    $instruction['action_id'] = $action['id'] ?? null;
                    unset($instruction['link']);
                }
            }
            unset($instruction);
            $variant = [
                ...$decisionPolicy,
                'id' => $key, 'task_key' => $key, 'source_hash' => $this->publication->contentHash($task),
                'review' => $this->reviewOf($task), 'review_hash' => CatalogueHash::of($this->reviewOf($task)),
                'kind' => $map['kind'], 'coverage' => $map['coverage'], 'coverage_review' => $map['coverage_review'] ?? null,
                'jurisdiction' => $task->jurisdiction, 'occurrence_fact' => $map['occurrence_fact'] ?? null,
                'conditions' => $conditions, 'criterion_keys' => $keys,
                'title' => $task->title, 'description' => $task->description, 'description_variants' => $task->description_variants ?? [],
                'type' => $task->type ?? 'task', 'phase' => $task->phase, 'urgency' => $task->urgency?->value,
                'coverage_scope' => $task->coverage_scope, 'depends_on' => $task->depends_on ?? [], 'conflicts_with' => $task->conflicts_with ?? [],
                'deadline' => ['type' => $task->deadline_type?->value ?? 'none', 'fact_key' => $task->deadline_fact_key, 'days' => $task->deadline_days],
                'trigger_event' => $task->trigger_event, 'recurrence_months' => $task->recurrence_months,
                'step_id' => $key.'.complete', 'position' => $map['position'] ?? null, 'instructions' => $instructions, 'documents' => $documents,
                'actions' => $actions, 'unavailable_actions' => $unavailableActions,
            ];
            $id = $map['process_id'];
            if (isset($definitions[$id]) && $definitions[$id]['topic'] !== $map['topic']) {
                throw new DomainException("Process [{$id}] has contradictory topic mappings.");
            }
            $definitions[$id] ??= ['topic' => $map['topic'], 'variants' => []];
            if ($variant['position'] !== null && in_array($variant['position'], array_column($definitions[$id]['variants'], 'position'), true)) {
                throw new DomainException("Process [{$id}] has two steps at reviewed position [{$variant['position']}].");
            }
            $definitions[$id]['variants'][] = $variant;
        }
        ksort($definitions);

        return ['schema_version' => config('bureaucracy_catalogue.schema_version'), 'registry_version' => $this->facts->version(),
            'mapping_hash' => CatalogueHash::of($mapping), 'mapping' => $mapping, 'inventory' => $inventory,
            'definitions' => array_map(fn ($id, $definition) => (new ProcessDefinition($id, $definition['topic'], $definition['variants']))->toArray(), array_keys($definitions), array_values($definitions))];
    }

    public function reviewOf(Task $task): array
    {
        return array_intersect_key($this->snapshot($task), array_flip(self::ReviewFields));
    }

    private function snapshot(Task $task): array
    {
        $record = $task->attributesToArray();
        unset($record['id'], $record['created_at'], $record['updated_at'], $record['outdated_reports']);
        // Review and legal validity dates are calendar dates, not UTC instants.
        foreach (['verified_at', 'review_due_at', 'effective_from', 'effective_to'] as $date) {
            $record[$date] = $task->{$date}?->toDateString();
        }

        return $record;
    }

    /** @return list<string> */
    private function validateConditions(array $groups): array
    {
        if (! array_is_list($groups)) {
            $groups = [$groups];
        }
        $keys = [];
        foreach ($groups as $group) {
            if (! is_array($group) || ($group !== [] && array_is_list($group))) {
                throw new DomainException('Conditions require OR groups of typed AND predicates.');
            }
            foreach ($group as $key => $condition) {
                $this->facts->validateConditionOperand($key, $condition);
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function items(string $key, string $kind, array $items): array
    {
        $result = [];
        foreach (array_values($items) as $index => $item) {
            $item = is_string($item) ? ['label' => $item] : $item;
            if (! is_array($item) || ! is_string($item[$kind === 'instruction' ? 'title' : 'label'] ?? null)) {
                throw new DomainException("Unit [{$key}] has an invalid {$kind}.");
            }
            $suffix = $item['id'] ?? (string) ($index + 1);
            if (! is_string($suffix) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $suffix)) {
                throw new DomainException('Invalid stable child ID.');
            }
            $id = $key.'.'.$kind.'.'.$suffix;
            if (isset($result[$id])) {
                throw new DomainException("Duplicate stable {$kind} ID [{$id}].");
            }
            $this->validateConditions($item['applies_if'] ?? []);
            $meaning = $item;
            if ($kind === 'document' && isset($item['requirement_version'])) {
                if (! isset($item['id']) || ! is_string($item['requirement_version'])
                    || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}$/D', $item['requirement_version'])
                    || ! is_string($item['evidence_kind'] ?? null) || ! preg_match('/^[a-z][a-z0-9._-]{0,99}$/D', $item['evidence_kind'])) {
                    throw new DomainException('Versioned requirements need explicit stable IDs, evidence kinds and semantic versions.');
                }
                // The reviewed semantic version must change with meaning. Without this contract,
                // free-text legacy requirements conservatively treat every edit as a meaning change.
                $meaning = array_diff_key($item, array_flip(['label', 'note', 'hint', 'tone']));
            }
            $result[$id] = [...$item, 'id' => $id, 'semantic_hash' => CatalogueHash::of($meaning), 'presentation_hash' => CatalogueHash::of($item)];
        }

        return array_values($result);
    }

    /** @param array<string, Task> $tasks */
    private function validateDependencies(array $tasks): void
    {
        $visiting = [];
        $visited = [];
        $visit = function (string $key) use (&$visit, &$visiting, &$visited, $tasks): void {
            if (isset($visiting[$key]) || ! isset($tasks[$key])) {
                throw new DomainException('Catalogue prerequisites contain a cycle or missing stable ID.');
            }
            if (isset($visited[$key])) {
                return;
            }
            $visiting[$key] = true;
            foreach ($tasks[$key]->depends_on ?? [] as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$key]);
            $visited[$key] = true;
        };
        foreach (array_keys($tasks) as $key) {
            $visit($key);
        }
    }
}
