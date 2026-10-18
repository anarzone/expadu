<?php

use App\Models\PlaceFactObservation;
use App\Models\Spot;
use App\Places\PlaceFactRevision;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

if (! function_exists('applyPreparedPlaces')) {
    require_once dirname(__DIR__, 4).'/production-pack/apply-pack.php';
}

class GuardedCanary
{
    public const SCHEMA_VERSION = 1;

    private const OWNED = ['source_group', 'name', 'category', 'lat', 'lng', 'location', 'veedel', 'tags', 'last_seen_at', 'is_active', 'is_recommendable', 'address', 'website', 'phone', 'description', 'opening_hours'];

    public function apply(array $records, array $baseline, string $packHash): array
    {
        $this->requireCaller();
        $this->check(count($records) > 0 && count($records) <= 100 && count(array_unique(array_column($records, 'key'))) === count($records), 'A unique bounded canary of at most 100 records is required.');
        $this->check(preg_match('/^[a-f0-9]{64}$/D', $packHash) === 1, 'An exact package hash is required.');

        return DB::transaction(function () use ($records, $baseline, $packHash): array {
            $this->lockCatalogue();
            $existingIds = array_values(array_filter(array_column($records, 'existing_id'), fn ($id) => $id !== null));
            $before = $this->snapshot($existingIds);
            $previous = [];
            $facts = [];
            $aliases = [];
            $eligibility = [];
            foreach ($records as $record) {
                if ($record['existing_id'] === null) {
                    continue;
                }
                $spot = Spot::findOrFail($record['existing_id']);
                $this->check($spot->canonical_spot_id === null, 'Canonical identity changed; reconcile again.');
                $this->check($spot->source === $record['source'] && $spot->source_id === $record['source_id'], 'Source identity changed; reconcile again.');
                $this->check(! DB::table('place_fact_corrections')->where('spot_id', $spot->id)->whereNull('revoked_at')->whereIn('field', ['access', 'fee', 'activity_discovery'])->exists(), 'Existing reviewed qualification needs a separate recovery rule.');
                $latest = $this->latest($spot->id, $record['source'], $record['source_id']);
                $this->check($latest !== null, 'A prior source observation is required for every refresh.');
                $this->check($latest->observed_at->lt(now()) && CarbonImmutable::parse($record['observed_at'])->lt(now()), 'Future or equal source timestamps cannot be recovered safely.');
                $previous[$record['key']] = $latest->id;
                $facts[$spot->id] = $this->substantiveFacts($spot);
                $aliases[$spot->id] = app(PlaceFacts::class)->resolve($spot)['aliases'];
                $eligibility[$spot->id] = $this->eligible($spot->id);
            }
            $mapping = applyPreparedPlaces($records, $baseline, $packHash);
            $ids = array_column($mapping, 'id');
            $receipt = ['schema_version' => self::SCHEMA_VERSION, 'before_aliases' => $aliases, 'package_sha256' => $packHash, 'before' => $before, 'before_facts' => $facts, 'before_eligibility' => $eligibility, 'previous_observations' => $previous, 'mapping' => $mapping, 'after' => $this->snapshot($ids)];
            $receipt['sha256'] = $this->hash($receipt);

            return $receipt;
        });
    }

    public function recover(array $receipt, string $actor, string $reason, ?array $completed = null): array
    {
        $this->requireCaller();
        $this->validateReceipt($receipt);
        $this->check(trim($actor) !== '' && mb_strlen($actor) <= 191 && mb_strlen(trim($reason)) >= 20, 'Recovery actor and reason are required.');

        return DB::transaction(function () use ($receipt, $actor, $reason, $completed): array {
            $this->lockCatalogue();
            $ids = array_column($receipt['mapping'], 'id');
            if ($completed !== null) {
                $this->validateReceipt($completed);
                $this->check(($completed['operation_sha256'] ?? null) === $receipt['sha256'], 'Recovery belongs to another operation.');
                $this->check($this->hash($this->snapshot($ids)) === $this->hash($completed['after']), 'Catalogue changed since recovery; refuse replay.');

                return $completed;
            }
            $this->check($this->hash($this->snapshot($ids)) === $this->hash($receipt['after']), 'Catalogue changed since import; refuse recovery.');
            $restored = 0;
            $withdrawn = 0;
            $revisionBefore = app(PlaceFactRevision::class)->current();
            $aliasDeltas = [];
            $recoveredIds = [];
            foreach ($receipt['mapping'] as $key => $mapping) {
                $id = $mapping['id'];
                if ($mapping['existing_id'] === null) {
                    Spot::findOrFail($id)->update(['is_active' => false, 'is_recommendable' => false]);
                    $withdrawn++;

                    continue;
                }
                $prior = PlaceFactObservation::findOrFail($receipt['previous_observations'][$key]);
                $current = $this->latest($id, $prior->provider, $prior->provider_record_id);
                $this->check($current !== null && $current->observed_at->lt(now()), 'Current source ordering prevents recovery.');
                if ($current->id !== $prior->id) {
                    app(RecordPlaceObservation::class)->restore($prior->id, $current->payload_hash, $actor, $reason);
                    $restored++;
                }
                $values = array_intersect_key($receipt['before']['spots'][$id], array_flip(self::OWNED));
                // Preserve exact stored JSON/numeric/spatial values; location is restored too.
                DB::table('spots')->where('id', $id)->update([...$values, 'updated_at' => now()]);
                $recoveredIds[] = $id;
            }
            if ($withdrawn > 0 || app(PlaceFactRevision::class)->current() === $revisionBefore) {
                app(PlaceFactRevision::class)->bump();
            }
            foreach ($recoveredIds as $id) {
                $spot = Spot::findOrFail($id);
                $this->check($this->hash($this->substantiveFacts($spot)) === $this->hash($receipt['before_facts'][$id]), 'Recovery changed resolved facts; whole batch refused.');
                $this->check($this->eligible($id) === $receipt['before_eligibility'][$id], 'Recovery changed eligibility; whole batch refused.');
                $afterAliases = app(PlaceFacts::class)->resolve($spot)['aliases'];
                $beforeAliases = $receipt['before_aliases'][$id];
                if ($afterAliases !== $beforeAliases) {
                    $aliasDeltas[$id] = ['before' => $beforeAliases, 'after' => $afterAliases];
                }
            }

            $recovery = ['schema_version' => self::SCHEMA_VERSION, 'alias_deltas' => $aliasDeltas, 'operation_sha256' => $receipt['sha256'], 'actor' => $actor, 'reason' => $reason, 'restored_source_streams' => $restored, 'withdrawn_additions' => $withdrawn, 'after' => $this->snapshot($ids)];
            $recovery['sha256'] = $this->hash($recovery);

            return $recovery;
        });
    }

    public function snapshot(array $ids): array
    {
        sort($ids);
        $out = ['spots' => []];
        foreach (Spot::query()->whereIn('id', $ids)->orderBy('id')->get() as $spot) {
            $out['spots'][$spot->id] = $spot->getRawOriginal();
        }
        foreach (['place_fact_observations', 'place_fact_corrections', 'place_destination_reviews'] as $table) {
            $query = DB::table($table)->whereIn('spot_id', $ids);
            if ($table === 'place_destination_reviews') {
                $query->orWhereIn('destination_spot_id', $ids);
            }
            $out[$table] = $query->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $out['place_reconciliations'] = DB::table('place_reconciliations')->whereIn('alias_spot_id', $ids)->orWhereIn('canonical_spot_id', $ids)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $out['related_spots'] = DB::table('spots')->whereNotIn('id', $ids)->where(fn ($query) => $query->whereIn('canonical_spot_id', $ids)->orWhereIn('destination_spot_id', $ids)->orWhereIn('parent_spot_id', $ids)->orWhereIn('destination_reviewed_parent_id', $ids))->orderBy('id')->get(['id', 'canonical_spot_id', 'destination_spot_id', 'parent_spot_id', 'destination_reviewed_parent_id'])->map(fn ($row) => (array) $row)->all();

        return $out;
    }

    private function lockCatalogue(): void
    {
        DB::statement('SET LOCAL lock_timeout = 10000');
        DB::statement('LOCK TABLE spots, place_fact_observations, place_fact_corrections, place_fact_revisions, place_destination_reviews, place_reconciliations IN SHARE ROW EXCLUSIVE MODE');
    }

    private function latest(int $id, string $provider, string $record): ?PlaceFactObservation
    {
        return PlaceFactObservation::query()->where('spot_id', $id)->where('provider', $provider)->where('provider_record_id', $record)->orderByDesc('observed_at')->orderByDesc('id')->first();
    }

    private function substantiveFacts(Spot $spot): array
    {
        $facts = app(PlaceFacts::class)->resolve($spot);
        unset($facts['revision'], $facts['aliases']);
        $strip = function (mixed $value) use (&$strip): mixed {
            if (! is_array($value)) {
                return $value;
            }
            unset($value['observed_at'], $value['reviewed_at']);

            return array_map($strip, $value);
        };

        return $strip($facts);
    }

    private function eligible(int $id): bool
    {
        return Spot::query()->recommendationEligible()->whereKey($id)->exists();
    }

    public function hash(array $value): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function validateReceipt(array $receipt): void
    {
        $body = $receipt;
        unset($body['sha256']);
        $this->check(($body['schema_version'] ?? null) === self::SCHEMA_VERSION && hash_equals($this->hash($body), $receipt['sha256'] ?? ''), 'Operation receipt checksum or schema mismatch.');
    }

    private function requireCaller(): void
    {
        $this->check(DB::transactionLevel() > 0, 'A caller-owned transaction is required; this helper never commits it.');
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new DomainException($message);
        }
    }
}
