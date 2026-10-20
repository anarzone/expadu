<?php

namespace App\Bureaucracy\Catalogue;

use App\Bureaucracy\GuidancePublication;
use App\Bureaucracy\RuleSourcePolicy;
use App\Models\Task;

/** Internal, read-only review inventory. Unit counts never measure all legal situations. */
final class CoverageManifest
{
    public function __construct(private CatalogueReleaseStore $releases, private RuleSourcePolicy $policy,
        private CatalogueCompiler $compiler, private GuidancePublication $publication) {}

    public function current(): array
    {
        // Use the live publication gate, including withdrawals since activation.
        $release = $this->releases->current();
        $available = collect($release['definitions'] ?? [])->flatMap(fn ($definition) => $definition['variants'])->keyBy('task_key');
        $inventory = collect($release['inventory'] ?? [])->keyBy('key');
        $records = Task::query()->orderBy('id')->get();
        $identified = fn (Task $task): bool => is_string($task->key) && preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $task->key) === 1;
        $unidentified = $records->reject($identified)->where('is_published', true)->map(fn ($task) => [
            'record_id' => $task->id, 'code' => 'stable_key_missing',
            'review_action' => 'Assign a reviewed stable catalogue key or explicitly retire this unclassified record.',
        ])->values()->all();
        $tasks = $records->filter($identified)->keyBy('key');
        $keys = $tasks->keys()->merge($inventory->keys())->unique()->sort()->values();
        $units = [];
        foreach ($keys as $key) {
            $task = $tasks->get($key);
            $record = $task ?? new Task($inventory[$key]['authored_record']);
            $variant = $available->get($key);
            $withdrawn = in_array($key, $release['withdrawn'] ?? [], true);
            if ($variant !== null && $task !== null
                && (! hash_equals($variant['source_hash'], $this->publication->contentHash($task))
                    || ! hash_equals($variant['review_hash'], CatalogueHash::of($this->compiler->reviewOf($task))))) {
                // Do not combine a newer database record with an older approval snapshot.
                $variant = null;
                $withdrawn = true;
            }
            $mapping = $release['mapping'][$key] ?? [];
            $gaps = [];
            $addGap = function (string $code, string $action) use (&$gaps): void {
                $gaps[] = ['code' => $code, 'review_action' => $action];
            };
            $errors = $task === null ? [] : $this->policy->persistedErrors($task);
            if ($task === null) {
                $coverage = 'review_required';
                $addGap('source_record_missing', 'Review the removed source unit and publish an explicit catalogue revision.');
            } elseif (! $task->is_published) {
                $coverage = 'unsupported';
                $addGap('unit_not_published', 'Confirm retirement or review and publish the replacement unit.');
            } elseif ($task->review_status !== RuleSourcePolicy::Approved || $errors !== []) {
                $coverage = 'review_required';
                $addGap('content_source_review_required', 'Review the content, official sources and validity metadata before publication.');
            } elseif ($variant === null) {
                $coverage = $withdrawn ? 'review_required' : 'unsupported';
                $addGap($coverage === 'review_required' ? 'active_release_unit_withdrawn' : 'active_release_mapping_missing',
                    'Review the source and process mapping, then compile and activate an authorised catalogue revision.');
            } elseif ($variant['coverage'] === 'complete') {
                $coverage = 'covered';
            } else {
                $coverage = 'partial';
                $addGap('complete_criterion_review_missing', 'Inventory and verify the remaining criteria and exceptions; preparation guidance is not a complete eligibility check.');
            }
            if ($withdrawn
                && ! in_array('active_release_unit_withdrawn', array_column($gaps, 'code'), true)) {
                $addGap('active_release_unit_withdrawn', 'Resolve the live publication withdrawal before this unit can be used again.');
            }
            foreach ($variant['unavailable_actions'] ?? [] as $action) {
                $gaps[] = ['code' => $action['reason'], 'action_id' => $action['id'],
                    'review_action' => 'Verify the destination and its purpose before enabling this action.'];
            }
            $groups = $record->applies_if ?? [];
            $validGroups = is_array($groups);
            $groups = ! $validGroups ? [] : (array_is_list($groups) ? $groups : [$groups]);
            $criterionKeys = [];
            foreach ($groups as $group) {
                if (! is_array($group) || ($group !== [] && array_is_list($group))) {
                    $validGroups = false;

                    continue;
                }
                $criterionKeys = [...$criterionKeys, ...array_keys($group)];
            }
            if (! $validGroups) {
                $addGap('malformed_conditions', 'Repair and review this unit’s condition structure; no criteria completeness can be inferred.');
                $coverage = 'review_required';
            }
            $sources = $record->legal_sources ?? [];
            $validSources = is_array($sources) && array_is_list($sources);
            $sourceUrls = [];
            foreach (is_array($sources) ? $sources : [] as $source) {
                if (! is_array($source) || ! is_string($source['url'] ?? null) || trim($source['url']) === '') {
                    $validSources = false;

                    continue;
                }
                $sourceUrls[] = $source['url'];
            }
            if (! $validSources) {
                $addGap('malformed_source_inventory', 'Repair and verify this unit’s official-source inventory.');
                $coverage = 'review_required';
            }
            $criterionKeys = array_values(array_unique($criterionKeys));
            sort($criterionKeys);
            $units[] = ['key' => $key, 'process_id' => $mapping['process_id'] ?? null, 'topic' => $mapping['topic'] ?? null,
                'jurisdiction' => $record->jurisdiction, 'phase' => $record->phase, 'coverage_scope' => $record->coverage_scope,
                'coverage' => $coverage, 'criterion_keys' => $criterionKeys,
                'coverage_review' => $variant['coverage_review'] ?? null,
                'source_urls' => $sourceUrls,
                'content_version' => $record->content_version, 'verified_at' => $record->verified_at?->toDateString(),
                'review_due_at' => $record->review_due_at?->toDateString(), 'source_policy_errors' => $errors,
                'gaps' => $gaps];
        }
        $counts = ['total' => count($units), 'covered' => 0, 'partial' => 0, 'unsupported' => 0, 'review_required' => 0];
        foreach ($units as $unit) {
            $counts[$unit['coverage']]++;
        }

        return ['schema_version' => 'bureaucracy.coverage-manifest.1', 'scope' => 'catalogue_units_not_all_legal_cases',
            'release_hash' => $release['release_hash'] ?? null, 'evaluated_on' => now()->toDateString(),
            'counts' => $counts, 'units' => $units, 'unidentified_units' => $unidentified];
    }
}
