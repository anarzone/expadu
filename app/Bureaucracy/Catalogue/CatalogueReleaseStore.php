<?php

namespace App\Bureaucracy\Catalogue;

use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\GuidancePublication;
use App\Bureaucracy\RuleSourcePolicy;
use App\Models\BureaucracyCatalogueRelease;
use App\Models\BureaucracyOutboxEvent;
use App\Models\Task;
use DomainException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CatalogueReleaseStore
{
    public function __construct(private CatalogueCompiler $compiler, private GuidancePublication $publication, private RuleSourcePolicy $policy, private FactRegistry $facts, private VerifiedActionDirectory $actions) {}

    public function stage(array $artifact): BureaucracyCatalogueRelease
    {
        if (($artifact['schema_version'] ?? null) !== config('bureaucracy_catalogue.schema_version')
            || ($artifact['registry_version'] ?? null) !== $this->facts->version()
            || ! is_array($artifact['definitions'] ?? null) || ! is_array($artifact['inventory'] ?? null) || ! is_array($artifact['mapping'] ?? null)) {
            throw new DomainException('An intact compiled artifact is required.');
        }
        $records = array_map(function ($unit): Task {
            if (! is_array($unit['authored_record'] ?? null)) {
                throw new DomainException('A complete source snapshot is required for each unit.');
            }

            return new Task($unit['authored_record']);
        }, $artifact['inventory']);
        if (! hash_equals(CatalogueHash::of($artifact), CatalogueHash::of($this->compiler->compile($records, $artifact['mapping'], $artifact['process_titles'] ?? [])))) {
            throw new DomainException('The compiled artifact does not match its source snapshots and mappings.');
        }

        return BureaucracyCatalogueRelease::query()->firstOrCreate(['content_hash' => CatalogueHash::of($artifact)], [
            'schema_version' => $artifact['schema_version'], 'artifact' => $artifact,
        ]);
    }

    public function activate(int $releaseId, ?string $expectedHash): void
    {
        DB::transaction(function () use ($releaseId, $expectedHash): void {
            $pointer = DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->lockForUpdate()->first();
            $release = BureaucracyCatalogueRelease::query()->findOrFail($releaseId);
            $currentHash = $pointer->release_id === null ? null : BureaucracyCatalogueRelease::query()->findOrFail($pointer->release_id)->content_hash;
            $this->validateIntegrity($release);
            if ($currentHash === $release->content_hash) {
                return;
            }
            if ($currentHash !== $expectedHash) {
                throw new ConflictHttpException('Another catalogue release was activated. Recheck before replacing it.');
            }
            DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->update(['release_id' => $release->id, 'version' => $pointer->version + 1, 'updated_at' => now()->utc()]);
            BureaucracyOutboxEvent::query()->create([
                'event_type' => 'catalogue.activated', 'aggregate_type' => 'catalogue', 'aggregate_id' => $release->id,
                'aggregate_version' => $pointer->version + 1, 'dedupe_key' => 'catalogue.activated:'.($pointer->version + 1),
                'payload' => [], 'available_at' => now()->utc(),
            ]);
        });
    }

    /** Emergency withdrawal never loads potentially damaged guidance or deletes history. */
    public function suspend(string $expectedHash): void
    {
        DB::transaction(function () use ($expectedHash): void {
            $pointer = DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->lockForUpdate()->first();
            if ($pointer->release_id === null) {
                return;
            }
            $hash = BureaucracyCatalogueRelease::query()->whereKey($pointer->release_id)->value('content_hash');
            if (! is_string($hash) || ! hash_equals($hash, $expectedHash)) {
                throw new ConflictHttpException('The active catalogue changed. Recheck before suspending it.');
            }
            DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->update([
                'release_id' => null, 'version' => $pointer->version + 1, 'updated_at' => now()->utc(),
            ]);
            BureaucracyOutboxEvent::query()->create([
                'event_type' => 'catalogue.suspended', 'aggregate_type' => 'catalogue', 'aggregate_id' => $pointer->release_id,
                'aggregate_version' => $pointer->version + 1, 'dedupe_key' => 'catalogue.suspended:'.($pointer->version + 1),
                'payload' => [], 'available_at' => now()->utc(),
            ]);
        });
    }

    /** Internal read model input; inventory is not user-facing advice. Never caches approval. */
    public function current(): ?array
    {
        $pointer = DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->first();
        if ($pointer?->release_id === null) {
            return null;
        }
        $release = BureaucracyCatalogueRelease::query()->findOrFail($pointer->release_id);
        $this->validateIntegrity($release);
        $artifact = $release->artifact;
        $current = Task::query()->authoritative()->get()->keyBy('key');
        $withdrawn = [];
        foreach ($artifact['definitions'] as &$definition) {
            $definition['variants'] = array_values(array_filter($definition['variants'], function ($variant) use ($current, &$withdrawn): bool {
                $task = $current->get($variant['task_key']);
                $allowed = $task !== null && $this->policy->persistedErrors($task) === []
                    && hash_equals($variant['source_hash'], $this->publication->contentHash($task))
                    && hash_equals($variant['review_hash'], CatalogueHash::of($this->compiler->reviewOf($task)));
                if (! $allowed) {
                    $withdrawn[] = $variant['task_key'];
                }

                return $allowed;
            }));
            foreach ($definition['variants'] as &$variant) {
                $variant['actions'] = array_values(array_filter($variant['actions'], function ($action) use (&$variant): bool {
                    try {
                        $this->actions->compile($action['id'], $action['url'], $action['purpose'], $action['jurisdiction']);

                        return true;
                    } catch (DomainException) {
                        $variant['unavailable_actions'][] = ['id' => $action['id'], 'reason' => 'action_host_review_required'];

                        return false;
                    }
                }));
                $ids = array_column($variant['actions'], 'id');
                foreach ($variant['instructions'] as &$instruction) {
                    if (isset($instruction['action_id']) && ! in_array($instruction['action_id'], $ids, true)) {
                        $instruction['action_id'] = null;
                    }
                }
                unset($instruction);
            }
            unset($variant);
        }
        unset($definition);

        return [...$artifact, 'release_id' => $release->id, 'release_hash' => $release->content_hash, 'pointer_version' => $pointer->version, 'withdrawn' => $withdrawn];
    }

    private function validateIntegrity(BureaucracyCatalogueRelease $release): void
    {
        if ($release->schema_version !== config('bureaucracy_catalogue.schema_version')
            || ! hash_equals($release->content_hash, CatalogueHash::of($release->artifact))
            || ($release->artifact['registry_version'] ?? null) !== $this->facts->version()) {
            throw new DomainException('The compiled catalogue is stale or damaged. Recompile before use.');
        }
    }
}
