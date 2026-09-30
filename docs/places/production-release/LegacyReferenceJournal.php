<?php

use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Places\PlaceFactRevision;
use App\Places\PlaceFacts;
use App\Places\ReconcilePlace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Operator-only journal for a bounded cohort of independently reviewed legacy references. */
class LegacyReferenceJournal
{
    private const KIND = 'reviewed-legacy-reference-v1';

    public function apply(string $id, array $context, array $records, array $baseline, string $actor): array
    {
        $this->guard($id, $context, $actor);
        $this->check(count($records) > 0 && count($records) <= 500, 'Use 1 to 500 reviewed pairs.');
        $this->check(hash_equals($context['package_sha256'], self::hash($records)), 'Reviewed package differs.');
        $ids = array_merge(array_column($records, 'alias_id'), array_column($records, 'canonical_id'));
        $this->check(count($ids) === 2 * count($records) && array_all($ids, fn ($value): bool => is_int($value) && $value > 0)
            && count(array_unique($ids)) === count($ids), 'Pairs must have disjoint positive IDs.');
        $this->check(count(array_unique(array_column($records, 'source_id'))) === count($records), 'Duplicate source identity in package.');

        return DB::transaction(function () use ($id, $context, $records, $baseline, $actor, $ids): array {
            $this->lock($id);
            $row = DB::table('place_catalogue_operations')->where('id', $id)->first();
            if ($row !== null) {
                $receipt = $this->receipt($row, $context);
                $this->check($row->actor === $actor && $row->state === 'applied', 'Operation actor or state differs.');
                $this->check(self::hash($this->snapshot($ids)) === self::hash($receipt['after']), 'Pairs or protected references changed since apply.');

                return $receipt;
            }
            $this->check(self::hash($baseline) === self::hash($this->snapshot($ids)), 'Pairs or protected references changed after preview.');
            foreach ($records as $record) {
                $this->target($record);
            }
            $identity = app(ReconcilePlace::class);
            foreach ($records as $record) {
                $identity->apply($record['alias_id'], $record['canonical_id'], $record['identity_fingerprint'], $this->encode($record));
            }
            $after = $this->snapshot($ids);
            $this->expectedChanges($records, $baseline, $after);
            $receipt = ['kind' => self::KIND, 'context' => $context, 'owner_ids' => $ids,
                'pairs' => array_map(fn ($r): array => ['alias_id' => $r['alias_id'], 'canonical_id' => $r['canonical_id']], $records),
                'before' => $baseline, 'after' => $after, 'records' => count($records)];
            $receipt['sha256'] = self::hash($receipt);
            DB::table('place_catalogue_operations')->insert(['id' => $id, 'state' => 'applied', 'context' => $this->encode($context),
                'actor' => $actor, 'receipt' => $this->encode($receipt), 'created_at' => now(), 'updated_at' => now()]);

            return $receipt;
        });
    }

    public function recover(string $id, array $context, string $actor, string $reason): array
    {
        $this->guard($id, $context, $actor);
        $reason = trim($reason);
        $this->check(mb_strlen($reason) >= 20, 'Document the legacy reference recovery reason.');

        return DB::transaction(function () use ($id, $context, $actor, $reason): array {
            $this->lock($id);
            $row = DB::table('place_catalogue_operations')->where('id', $id)->first();
            $this->check($row !== null, 'A committed legacy operation is required.');
            $receipt = $this->receipt($row, $context);
            $current = $this->snapshot($receipt['owner_ids']);
            if ($row->state === 'recovered') {
                $recovery = $this->decode($row->recovery);
                $this->verifyHash($recovery);
                $this->check($recovery['operation_sha256'] === $receipt['sha256'] && $recovery['actor'] === $actor
                    && $recovery['reason'] === $reason && self::hash($current) === self::hash($recovery['after']), 'Recovery request or recovered references changed.');

                return $recovery;
            }
            $this->check($row->state === 'applied' && self::hash($current) === self::hash($receipt['after']), 'Later identity source or reference changes prevent recovery.');
            $originals = array_column($receipt['before']['spots'], null, 'id');
            $expected = $receipt['after'];
            foreach ($receipt['pairs'] as $pair) {
                DB::table('spots')->where('id', $pair['alias_id'])->update(['canonical_spot_id' => $originals[$pair['alias_id']]['canonical_spot_id']]);
                DB::table('spots')->where('id', $pair['canonical_id'])->update([
                    'rating' => $originals[$pair['canonical_id']]['rating'], 'updated_at' => $originals[$pair['canonical_id']]['updated_at'],
                ]);
                foreach ($expected['spots'] as &$spot) {
                    if ($spot['id'] === $pair['alias_id']) {
                        $spot['canonical_spot_id'] = $originals[$spot['id']]['canonical_spot_id'];
                    } elseif ($spot['id'] === $pair['canonical_id']) {
                        $spot['rating'] = $originals[$spot['id']]['rating'];
                        $spot['updated_at'] = $originals[$spot['id']]['updated_at'];
                    }
                }
                unset($spot);
            }
            app(PlaceFactRevision::class)->bump();
            $after = $this->snapshot($receipt['owner_ids']);
            $this->check(self::hash($after) === self::hash($expected), 'Recovery must retain source identity audit and user reference rows.');
            $recovery = ['kind' => self::KIND, 'operation_sha256' => $receipt['sha256'], 'actor' => $actor, 'reason' => $reason,
                'restored_legacy_references' => $receipt['records'], 'after' => $after];
            $recovery['sha256'] = self::hash($recovery);
            DB::table('place_catalogue_operations')->where('id', $id)->update(['state' => 'recovered', 'recovery' => $this->encode($recovery), 'updated_at' => now()]);

            return $recovery;
        });
    }

    public function snapshot(array $ids): array
    {
        $this->check($ids !== [] && count($ids) <= 1000 && array_all($ids, fn ($value): bool => is_int($value) && $value > 0), 'Bounded existing pair IDs required.');
        $familyIds = array_values(array_unique($ids));
        sort($familyIds);
        do {
            $rows = DB::table('spots')->whereIn('id', $familyIds)->orWhereIn('canonical_spot_id', $familyIds)
                ->orderBy('id')->limit(2501)->get()->map(fn ($r): array => (array) $r)->all();
            $this->check(count($rows) <= 2500 && array_diff($familyIds, array_column($rows, 'id')) === [], 'Missing or excessive identity family.');
            $next = $familyIds;
            foreach ($rows as $row) {
                foreach (['id', 'canonical_spot_id', 'parent_spot_id', 'destination_spot_id', 'destination_reviewed_parent_id'] as $field) {
                    if ($row[$field] !== null) {
                        $next[] = (int) $row[$field];
                    }
                }
            }
            $next = array_values(array_unique($next));
            sort($next);
            $done = $familyIds === $next;
            $familyIds = $next;
        } while (! $done);
        $read = fn ($query): array => $query->orderBy('id')->limit(5001)->get()->map(fn ($r): array => (array) $r)->all();
        $references = [
            'spots' => $read(DB::table('spots')->whereNotIn('id', $familyIds)->where(fn ($where) => $where->whereIn('parent_spot_id', $familyIds)
                ->orWhereIn('destination_spot_id', $familyIds)->orWhereIn('destination_reviewed_parent_id', $familyIds))),
            'park_areas' => $read(DB::table('park_areas')->whereIn('parent_spot_id', $familyIds)),
            'venues' => $read(DB::table('venues')->whereIn('place_id', $familyIds)),
        ];
        $attachments = $read(DB::table('media_attachments')->where('mediable_type', (new Spot)->getMorphClass())->whereIn('mediable_id', $familyIds));
        $result = ['spots' => $rows, 'references' => $references,
            'observations' => $read(DB::table('place_fact_observations')->whereIn('spot_id', $familyIds)),
            'corrections' => $read(DB::table('place_fact_corrections')->whereIn('spot_id', $familyIds)),
            'reconciliations' => $read(DB::table('place_reconciliations')->whereIn('alias_spot_id', $familyIds)->orWhereIn('canonical_spot_id', $familyIds)),
            'destination_reviews' => $read(DB::table('place_destination_reviews')->whereIn('spot_id', $familyIds)->orWhereIn('destination_spot_id', $familyIds)),
            'reviews' => $read(DB::table('reviews')->whereIn('spot_id', $familyIds)),
            'feedback' => $read(DB::table('spot_feedback')->whereIn('spot_id', $familyIds)),
            'attachments' => $attachments, 'assets' => $read(DB::table('media_assets')->whereIn('id', array_column($attachments, 'media_asset_id')))];
        foreach ([...array_values($references), ...array_values(array_diff_key($result, ['references' => true]))] as $bounded) {
            $this->check(count($bounded) <= 5000, 'Related evidence exceeds this bounded protocol.');
        }

        return $result;
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
        $proof = $r['proof'] ?? null;
        $this->check(is_array($proof) && array_diff(['source_id', 'visible', 'version', 'tags', 'geometry_verified', 'lat', 'lng'], array_keys($proof)) === []
            && is_array($proof['tags']) && is_numeric($proof['lat']) && is_numeric($proof['lng'])
            && is_finite((float) $proof['lat']) && is_finite((float) $proof['lng'])
            && isset($r['proof_sha256'], $r['checked_at'], $r['identity_fingerprint']), 'Complete current source proof is required.');
        $this->check(preg_match('~^(node|way)/[1-9][0-9]*$~D', $r['source_id'] ?? ''), 'Reviewed node or way identity required.');
        $matches = Spot::where('source', 'osm')->where('source_id', $r['source_id'])->get();
        $this->check($matches->count() === 1 && $matches->first()->id === $r['canonical_id'], 'Source identity differs from reviewed pair.');
        $canonical = $matches->first();
        $alias = Spot::findOrFail($r['alias_id']);
        $this->check(! DB::table('place_reconciliations')->where('alias_spot_id', $alias->id)->exists(), 'Alias already has reconciliation history.');
        $this->check(! DB::table('place_fact_observations')->where('spot_id', $alias->id)->exists()
            && ! DB::table('place_fact_corrections')->where('spot_id', $alias->id)->exists()
            && ! DB::table('spots')->where('parent_spot_id', $alias->id)->orWhere('destination_spot_id', $alias->id)->orWhere('destination_reviewed_parent_id', $alias->id)->exists()
            && ! DB::table('park_areas')->where('parent_spot_id', $alias->id)->exists()
            && ! DB::table('venues')->where('place_id', $alias->id)->exists(), 'Alias history or incoming references require separate recovery handling.');
        $this->check($canonical->is_recommendable && app(DestinationGrouping::class)->eligible(Spot::query())->whereKey($canonical->id)->exists(), 'The reviewed source counterpart must remain eligible.');
        $map = app(PlaceFacts::class)->resolve($canonical)['location']['map_point'];
        $this->check(hash_equals(self::hash($proof), $r['proof_sha256']) && $proof['source_id'] === $canonical->source_id
            && $proof['visible'] === true && $proof['geometry_verified'] === true && is_int($proof['version']) && $proof['version'] > 0
            && self::hash($proof['tags']) === self::hash($canonical->tags)
            && abs((float) $proof['lat'] - $canonical->lat) <= .00000011 && abs((float) $proof['lng'] - $canonical->lng) <= .00000011
            && $map['status'] === 'known' && abs((float) $map['lat'] - (float) $proof['lat']) <= .00000011
            && abs((float) $map['lng'] - (float) $proof['lng']) <= .00000011, 'Current source or geometry proof differs.');
        foreach ($alias->tags ?? [] as $field => $value) {
            $this->check(($proof['tags'][$field] ?? null) === $value, 'A retained legacy tag differs from current source proof.');
        }
        $time = $r['checked_at'];
        $this->check(is_string($time) && preg_match('~^[0-9]{4}-[0-9]{2}-[0-9]{2}T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]{1,6})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])$~D', $time)
            && checkdate((int) substr($time, 5, 2), (int) substr($time, 8, 2), (int) substr($time, 0, 4)), 'An absolute source timestamp with timezone is required.');
        $this->check(CarbonImmutable::parse($time)->betweenIncluded(now()->subDay(), now()->addMinutes(5)), 'Current source proof is required.');
        $preview = app(ReconcilePlace::class)->preview($alias->id, $canonical->id);
        $this->check(! $preview['already_reconciled'] && hash_equals($preview['fingerprint'], $r['identity_fingerprint']), 'Native identity preview changed.');
    }

    private function expectedChanges(array $records, array $before, array $after): void
    {
        $originals = array_column($before['spots'], null, 'id');
        $aliases = array_column($records, 'canonical_id', 'alias_id');
        $canonicals = array_fill_keys(array_column($records, 'canonical_id'), true);
        $this->check(array_keys($originals) === array_column($after['spots'], 'id'), 'Identity family changed during apply.');
        foreach ($after['spots'] as $row) {
            $allowed = isset($aliases[$row['id']]) ? ['canonical_spot_id', 'updated_at'] : (isset($canonicals[$row['id']]) ? ['rating', 'updated_at'] : []);
            $except = array_fill_keys($allowed, true);
            $this->check(array_diff_key($row, $except) === array_diff_key($originals[$row['id']], $except), 'Native apply changed unsupported place fields.');
            if (isset($aliases[$row['id']])) {
                $this->check($row['canonical_spot_id'] === $aliases[$row['id']], 'Native apply did not resolve the reviewed alias.');
            }
        }
        foreach (array_keys(array_diff_key($before, ['spots' => true, 'reconciliations' => true])) as $field) {
            $this->check($before[$field] === $after[$field], 'Native apply changed protected source or reference evidence.');
        }
        $prior = array_column($before['reconciliations'], null, 'id');
        $added = 0;
        foreach ($after['reconciliations'] as $row) {
            if (isset($prior[$row['id']])) {
                $this->check($row === $prior[$row['id']], 'Native apply changed prior identity audit.');
                unset($prior[$row['id']]);
            } else {
                $this->check(($aliases[$row['alias_spot_id']] ?? null) === $row['canonical_spot_id'], 'Unexpected native identity audit entry.');
                $added++;
            }
        }
        $this->check($prior === [] && $added === count($records), 'Native identity audit count differs.');
    }

    private function guard(string $id, array $context, string $actor): void
    {
        $this->check(DB::transactionLevel() > 0, 'Explicit caller-owned transaction required.');
        $this->check(DB::selectOne('SHOW transaction_isolation')->transaction_isolation === 'read committed', 'Read committed isolation is required for current drift checks.');
        $this->check(Str::isUuid($id) && trim($actor) !== '' && mb_strlen($actor) <= 191, 'Operation UUID and review actor required.');
        $this->check(($context['database'] ?? null) === DB::selectOne('select current_database() as name')->name, 'Wrong target database.');
        foreach (['package_sha256', 'application_sha256', 'importer_sha256'] as $key) {
            $this->check(preg_match('/^[a-f0-9]{64}$/D', $context[$key] ?? ''), 'Reviewed package application and helper hashes required.');
        }
        $this->check(hash_equals(hash_file('sha256', __FILE__), $context['importer_sha256']), 'Legacy reference helper differs.');
    }

    private function lock(string $id): void
    {
        DB::statement('SET LOCAL lock_timeout = 10000');
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['legacy-reference:'.$id]);
        DB::statement('LOCK TABLE spots, place_fact_observations, place_fact_corrections, place_fact_revisions, place_reconciliations,
            place_destination_reviews, park_areas, venues, reviews, spot_feedback, media_attachments, media_assets,
            place_catalogue_operations IN SHARE ROW EXCLUSIVE MODE');
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
