<?php

use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Places\ReviewPlaceFacts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Operator-only qualification of a bounded, source-reviewed existing facility cohort. */
class FacilityQualificationJournal
{
    private const KIND = 'existing-facility-qualification-v1';

    public function apply(string $id, array $context, array $records, array $baseline, string $actor): array
    {
        $this->guard($id, $context, $actor);
        $this->check(count($records) > 0 && count($records) <= 100, 'Use 1 to 100 reviewed existing facilities.');
        $this->check(hash_equals($context['package_sha256'], self::hash($records)), 'Reviewed package differs.');
        $ownerIds = array_column($records, 'spot_id');
        $this->check(count(array_unique($ownerIds)) === count($records), 'Duplicate facility in package.');

        return DB::transaction(function () use ($id, $context, $records, $baseline, $actor, $ownerIds): array {
            $this->lock($id);
            $existing = DB::table('place_catalogue_operations')->where('id', $id)->first();
            if ($existing !== null) {
                $receipt = $this->receipt($existing, $context);
                $this->check($existing->actor === $actor && $existing->state === 'applied', 'Operation actor or state differs.');
                $this->check(self::hash($this->snapshot($ownerIds)) === self::hash($receipt['after']), 'Facilities changed since this operation.');

                return $receipt;
            }
            $this->check(self::hash($baseline) === self::hash($this->snapshot($ownerIds)), 'Facilities or reviews changed after preview.');
            foreach ($records as $record) {
                $this->target($record);
            }
            $reviewer = app(ReviewPlaceFacts::class);
            foreach ($records as $record) {
                $reviewer->apply($record['spot_id'], ['activity_discovery' => true], $record['fingerprint'], $this->encode($record), $actor);
            }
            foreach ($ownerIds as $spotId) {
                $this->check(app(DestinationGrouping::class)->eligible(Spot::query(), true)->whereKey($spotId)->exists(), 'Qualified facility is not currently eligible.');
            }
            $after = $this->snapshot($ownerIds);
            $this->check($after['spots'] === $baseline['spots'] && $after['observations'] === $baseline['observations'], 'Qualification must preserve source values and identities.');
            $receipt = ['kind' => self::KIND, 'context' => $context, 'owner_ids' => $ownerIds,
                'before' => $baseline, 'after' => $after, 'records' => count($records)];
            $receipt['sha256'] = self::hash($receipt);
            DB::table('place_catalogue_operations')->insert(['id' => $id, 'state' => 'applied',
                'context' => $this->encode($context), 'actor' => $actor, 'receipt' => $this->encode($receipt),
                'created_at' => now(), 'updated_at' => now()]);

            return $receipt;
        });
    }

    public function recover(string $id, array $context, string $actor, string $reason): array
    {
        $this->guard($id, $context, $actor);
        $reason = trim($reason);
        $this->check(mb_strlen($reason) >= 20, 'Document the qualification recovery reason.');

        return DB::transaction(function () use ($id, $context, $actor, $reason): array {
            $this->lock($id);
            $row = DB::table('place_catalogue_operations')->where('id', $id)->first();
            $this->check($row !== null, 'A committed qualification operation is required.');
            $receipt = $this->receipt($row, $context);
            $current = $this->snapshot($receipt['owner_ids']);
            if ($row->state === 'recovered') {
                $recovery = $this->decode($row->recovery);
                $this->verifyHash($recovery);
                $this->check($recovery['operation_sha256'] === $receipt['sha256'] && $recovery['actor'] === $actor
                    && $recovery['reason'] === $reason && self::hash($current) === self::hash($recovery['after']), 'Recovered facilities or recovery request changed.');

                return $recovery;
            }
            $this->check($row->state === 'applied' && self::hash($current) === self::hash($receipt['after']), 'Later source, review or parent edits prevent recovery.');
            $reviewer = app(ReviewPlaceFacts::class);
            foreach ($receipt['owner_ids'] as $spotId) {
                $preview = $reviewer->preview($spotId, ['activity_discovery' => null]);
                $reviewer->apply($spotId, ['activity_discovery' => null], $preview['fingerprint'], $reason, $actor);
                $this->check(! Spot::query()->recommendationEligible(true)->whereKey($spotId)->exists(), 'Recovered facility remains eligible.');
            }
            $after = $this->snapshot($receipt['owner_ids']);
            $this->check($after['spots'] === $receipt['before']['spots'] && $after['observations'] === $receipt['before']['observations'], 'Recovery must retain source rows and identities.');
            $history = array_column($after['corrections'], null, 'id');
            foreach ($receipt['before']['corrections'] as $original) {
                $this->check(($history[$original['id']] ?? null) === $original, 'Recovery changed prior audit history.');
            }
            $this->check(count($after['corrections']) === count($receipt['before']['corrections']) + 2 * $receipt['records'], 'Recovery must retain qualification and revocation audit entries.');
            $recovery = ['kind' => self::KIND, 'operation_sha256' => $receipt['sha256'],
                'actor' => $actor, 'reason' => $reason, 'after' => $after];
            $recovery['sha256'] = self::hash($recovery);
            DB::table('place_catalogue_operations')->where('id', $id)->update(['state' => 'recovered', 'recovery' => $this->encode($recovery), 'updated_at' => now()]);

            return $recovery;
        });
    }

    public function snapshot(array $ownerIds): array
    {
        $this->check($ownerIds !== [] && count($ownerIds) <= 100 && array_all($ownerIds, fn ($id): bool => is_int($id) && $id > 0), 'Bounded existing target IDs required.');
        $ownerIds = array_values(array_unique($ownerIds));
        sort($ownerIds);
        do {
            $rows = DB::table('spots')->whereIn('id', $ownerIds)->orWhereIn('canonical_spot_id', $ownerIds)
                ->orderBy('id')->limit(501)->get()->map(fn ($r) => (array) $r)->all();
            $this->check(count($rows) <= 500 && array_diff($ownerIds, array_column($rows, 'id')) === [], 'Missing or excessive facility family.');
            $nextIds = $ownerIds;
            foreach ($rows as $row) {
                foreach (['id', 'canonical_spot_id', 'parent_spot_id', 'destination_spot_id', 'destination_reviewed_parent_id'] as $field) {
                    if ($row[$field] !== null) {
                        $nextIds[] = (int) $row[$field];
                    }
                }
            }
            $nextIds = array_values(array_unique($nextIds));
            sort($nextIds);
            $complete = $nextIds === $ownerIds;
            $ownerIds = $nextIds;
        } while (! $complete);
        $read = fn (string $table): array => DB::table($table)->whereIn('spot_id', $ownerIds)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return ['spots' => $rows, 'observations' => $read('place_fact_observations'), 'corrections' => $read('place_fact_corrections')];
    }

    public static function hash(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($normalize, $item);
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function target(array $r): void
    {
        $this->check(($r['source'] ?? null) === 'osm' && preg_match('~^(node|way)/[0-9]+$~D', $r['source_id'] ?? ''), 'Reviewed node/way source identity required.');
        $matches = Spot::where('source', 'osm')->where('source_id', $r['source_id'])->get();
        $this->check($matches->count() === 1 && $matches->first()->id === $r['spot_id'], 'Source identity differs from reviewed target.');
        $spot = $matches->first();
        $this->check($spot->is_active && ! $spot->is_recommendable && $spot->canonical_spot_id === null
            && $spot->destination_spot_id === null && $spot->category->value === $r['category'] && $spot->category->isActivityFacility(), 'Only held independent activity facilities can be qualified.');
        $this->check(! $spot->factCorrections()->whereNull('revoked_at')->exists(), 'Existing active reviews require separate qualification handling.');
        $overlap = Spot::query()->where('id', '!=', $spot->id)->whereNotNull('source')->canonical()
            ->where('is_active', true)->where('category', $spot->category->value)
            ->whereBetween('lat', [$spot->lat - .00003, $spot->lat + .00003])
            ->whereBetween('lng', [$spot->lng - .00005, $spot->lng + .00005])
            ->whereRaw('ST_DWithin(ST_SetSRID(ST_MakePoint(lng,lat),4326)::geography,ST_SetSRID(ST_MakePoint(?,?),4326)::geography,2)', [$spot->lng, $spot->lat])->exists();
        $this->check(! $overlap, 'Overlapping source identity requires separate review.');
        $proof = $r['proof'];
        $this->check(hash_equals(self::hash($proof), $r['proof_sha256']) && $proof['source_id'] === $spot->source_id
            && $proof['visible'] === true && $proof['geometry_verified'] === true && is_int($proof['version']) && $proof['version'] > 0
            && self::hash($proof['tags']) === self::hash($spot->tags)
            && abs((float) $proof['lat'] - $spot->lat) <= .00000011 && abs((float) $proof['lng'] - $spot->lng) <= .00000011, 'Source or geometry evidence differs.');
        $checked = CarbonImmutable::parse($r['checked_at']);
        $this->check($checked->betweenIncluded(now()->subDay(), now()->addMinutes(5)), 'Current source proof is required.');
        $facts = app(PlaceFacts::class)->resolve($spot);
        $tags = $proof['tags'];
        $sourceAccess = mb_strtolower(trim((string) ($tags['access'] ?? '')));
        $sourceFee = mb_strtolower(trim((string) ($tags['fee'] ?? '')));
        $mapPoint = $facts['location']['map_point'];
        $this->check(in_array($sourceAccess, ['yes', 'public', 'permissive'], true)
            && trim((string) ($tags['access:conditional'] ?? '')) === ''
            && $facts['access']['value'] === 'public' && $facts['access']['status'] === 'known'
            && ! in_array($sourceFee, ['no', 'free', 'yes', 'paid'], true)
            && array_all(['fee:conditional', 'charge', 'charge:conditional'], fn (string $key): bool => trim((string) ($tags[$key] ?? '')) === '')
            && $facts['fee']['value'] === 'unknown'
            && $mapPoint['status'] === 'known'
            && abs((float) $mapPoint['lat'] - (float) $proof['lat']) <= .00000011
            && abs((float) $mapPoint['lng'] - (float) $proof['lng']) <= .00000011,
            'Current source proof disagrees with effective place facts.');
        $this->check($facts['name_kind'] === 'descriptive' && $facts['access']['value'] === 'public'
            && $facts['access']['status'] === 'known' && $facts['access']['conditional'] === null
            && $facts['fee']['value'] === 'unknown' && $spot->price_range === null
            && $facts['location']['map_point']['status'] === 'known'
            && $facts['location']['entrance_point']['status'] !== 'verified', 'Only reviewed public facilities with preserved unknown fees belong in this cohort.');
        $preview = app(ReviewPlaceFacts::class)->preview($spot->id, ['activity_discovery' => true]);
        $this->check(hash_equals($preview['fingerprint'], $r['fingerprint']), 'Native review preview changed.');
    }

    private function guard(string $id, array $context, string $actor): void
    {
        $this->check(DB::transactionLevel() > 0, 'Explicit caller-owned transaction required.');
        $this->check(Str::isUuid($id) && trim($actor) !== '' && mb_strlen($actor) <= 191, 'Operation UUID and review actor required.');
        $this->check(($context['database'] ?? null) === DB::selectOne('select current_database() as name')->name, 'Wrong target database.');
        foreach (['package_sha256', 'application_sha256', 'importer_sha256'] as $key) {
            $this->check(preg_match('/^[a-f0-9]{64}$/D', $context[$key] ?? ''), 'Reviewed package/application/helper hashes required.');
        }
        $this->check(hash_equals(hash_file('sha256', __FILE__), $context['importer_sha256']), 'Qualification helper differs.');
    }

    private function lock(string $id): void
    {
        DB::statement('SET LOCAL lock_timeout = 10000');
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['facility-qualification:'.$id]);
        DB::statement('LOCK TABLE spots, place_fact_observations, place_fact_corrections, place_catalogue_operations IN SHARE ROW EXCLUSIVE MODE');
    }

    private function receipt(object $row, array $context): array
    {
        $receipt = $this->decode($row->receipt);
        $this->verifyHash($receipt);
        $this->check(($receipt['kind'] ?? null) === self::KIND && self::hash($this->decode($row->context)) === self::hash($context)
            && self::hash($receipt['context']) === self::hash($context), 'Operation kind or context differs.');

        return $receipt;
    }

    private function verifyHash(array $value): void
    {
        $expected = $value['sha256'] ?? '';
        unset($value['sha256']);
        $this->check(hash_equals(self::hash($value), $expected), 'Journal checksum differs.');
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function decode(string $value): array
    {
        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new DomainException($message);
        }
    }
}
