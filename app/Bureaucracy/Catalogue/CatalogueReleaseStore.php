<?php

namespace App\Bureaucracy\Catalogue;

use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\GuidancePublication;
use App\Bureaucracy\ReadModel\CatalogueOrder;
use App\Bureaucracy\RuleSourcePolicy;
use App\Models\BureaucracyCatalogueRelease;
use App\Models\BureaucracyOutboxEvent;
use App\Models\Task;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class CatalogueReleaseStore
{
    /** The code current()'s checks run; a change to any of it checks everything again. */
    private const CheckedBy = [self::class, CatalogueCompiler::class, CatalogueHash::class, GuidancePublication::class, RuleSourcePolicy::class, Task::class];

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
        if (! hash_equals(CatalogueHash::of($artifact), CatalogueHash::of($this->compiler->compile($records, $artifact['mapping'], $artifact['process_titles'] ?? [], $artifact['document_terms'] ?? [])))) {
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

    /**
     * The active release's process names (definition id => title), for reads that need only
     * a name, such as Your day's "EU Blue Card appointment". A name is the process map's,
     * or for a release without one its first step's title, as ProcessDetails names it; no
     * figure or advice, so current()'s re-check of every card isn't needed. Cached per
     * release; it never caches an approval.
     *
     * @return array<string, string>
     */
    public function processTitles(): array
    {
        $pointer = DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->first();
        if ($pointer?->release_id === null) {
            return [];
        }

        return Cache::remember('bureaucracy.process_titles.'.$pointer->release_id, now()->addDay(), function () use ($pointer): array {
            $release = BureaucracyCatalogueRelease::query()->findOrFail($pointer->release_id);
            $this->validateIntegrity($release);
            // As ProcessDetails names a process: its own name, else its first step's title.
            $order = new CatalogueOrder($release->artifact);
            $titles = [];
            foreach ($release->artifact['definitions'] as $definition) {
                $title = $order->processTitle($definition['id']) ?? $order->definitionTitle($definition['id']);
                if ($title !== null) {
                    $titles[$definition['id']] = $title;
                }
            }

            return $titles;
        });
    }

    /**
     * Internal read model input; inventory is not user-facing advice. Never reuses an approval
     * whose inputs changed: the integrity and per-card checks are remembered only under a key
     * made of everything they read (the stored release bytes, every authoritative task row,
     * today, the source and catalogue config and the checks' own code), so any change runs
     * them all again, and a failed integrity check is never remembered.
     */
    public function current(): ?array
    {
        $pointer = DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->first();
        if ($pointer?->release_id === null) {
            return null;
        }
        $release = BureaucracyCatalogueRelease::query()->findOrFail($pointer->release_id);
        $artifact = $release->artifact;
        $current = Task::query()->authoritative()->get()->keyBy('key');
        $key = $this->checkKey($release, $current);
        $withdrawn = $this->rememberedWithdrawals($key);
        if ($withdrawn === null) {
            $this->validateIntegrity($release);
            $withdrawn = [];
            foreach ($artifact['definitions'] as $definition) {
                foreach ($definition['variants'] as $variant) {
                    $task = $current->get($variant['task_key']);
                    $allowed = $task !== null && $this->policy->persistedErrors($task) === []
                        && hash_equals($variant['source_hash'], $this->publication->contentHash($task))
                        && hash_equals($variant['review_hash'], CatalogueHash::of($this->compiler->reviewOf($task)));
                    if (! $allowed) {
                        $withdrawn[] = $variant['task_key'];
                    }
                }
            }
            $withdrawn = array_values(array_unique($withdrawn));
            $this->rememberWithdrawals($key, $withdrawn);
        }
        foreach ($artifact['definitions'] as &$definition) {
            // By task: a card with any failing variant is withheld whole (fails closed).
            $definition['variants'] = array_values(array_filter($definition['variants'], fn ($variant): bool => ! in_array($variant['task_key'], $withdrawn, true)));
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

    /** Everything the checks read; any change to it is a new key. */
    private function checkKey(BureaucracyCatalogueRelease $release, Collection $tasks): string
    {
        $rows = $tasks->sortKeys()->map(fn (Task $task): array => $task->getAttributes())->all();
        $code = array_map(fn (string $class): string => hash_file('sha256', (new ReflectionClass($class))->getFileName()), self::CheckedBy);

        return 'bureaucracy.catalogue_check.'.hash('sha256', json_encode([
            'release' => [$release->id, $release->content_hash, $release->schema_version, hash('sha256', (string) $release->getRawOriginal('artifact'))],
            'tasks' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
            'today' => CarbonImmutable::today(config('app.timezone'))->toDateString(),
            'config' => [config('app.timezone'), config('bureaucracy_sources'), config('bureaucracy_catalogue'), $this->facts->version()],
            'code' => $code,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<string>|null the withdrawn task keys from a check of exactly these inputs */
    private function rememberedWithdrawals(string $key): ?array
    {
        try {
            $stored = Cache::get($key);
        } catch (Throwable) {
            return null;
        }
        // Signed with the app key: an entry the app didn't write (say, straight into Redis) is a miss.
        [$signature, $json] = is_string($stored) && str_contains($stored, '|') ? explode('|', $stored, 2) : ['', ''];
        if (! hash_equals($this->sign($key, $json), $signature)) {
            return null;
        }
        $withdrawn = json_decode($json, true);

        return is_array($withdrawn) && array_is_list($withdrawn) && array_filter($withdrawn, 'is_string') === $withdrawn ? $withdrawn : null;
    }

    /** Stored only after the integrity check passed; a scalar, as cached objects break. */
    private function rememberWithdrawals(string $key, array $withdrawn): void
    {
        try {
            $json = json_encode($withdrawn, JSON_THROW_ON_ERROR);
            Cache::put($key, $this->sign($key, $json).'|'.$json, now()->addDay());
        } catch (Throwable) {
            // Unremembered, the next read checks everything again.
        }
    }

    private function sign(string $key, string $json): string
    {
        return hash_hmac('sha256', $key."\n".$json, (string) config('app.key'));
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
