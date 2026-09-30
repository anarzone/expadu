<?php

use App\Places\PlaceFactRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Operator-only complete-cohort quarantine. It never deletes or rewrites place identity. */
class SourceProvenanceHoldJournal
{
    private const KIND = 'source-provenance-hold-v2';

    public function apply(string $id, array $context, array $baseline, string $actor, string $reason): array
    {
        $reason = $this->reason($reason);
        $this->guard($id, $context, $actor);
        $this->check(hash_equals($context['package_sha256'], self::hash($baseline)), 'Reviewed source-null package differs.');

        return DB::transaction(function () use ($id, $context, $baseline, $actor, $reason): array {
            $this->lock($id);
            $row = DB::table('place_catalogue_operations')->where('id', $id)->first();
            if ($row !== null) {
                $receipt = $this->receipt($row, $context);
                $this->check($row->state === 'applied' && $row->actor === $actor && $receipt['reason'] === $reason,
                    'Operation actor reason or state differs.');
                $this->check(self::hash($this->snapshot()) === self::hash($receipt['after']), 'Source-null cohort changed since apply.');

                return $receipt;
            }
            $this->check(self::hash($this->snapshot()) === self::hash($baseline), 'Source-null cohort or protected evidence changed after preview.');
            $count = DB::table('spots')->whereNull('source')->update(['is_active' => false, 'is_recommendable' => false, 'updated_at' => now()]);
            $this->check($count === count($baseline['spots']), 'Complete source-null cohort was not held.');
            app(PlaceFactRevision::class)->bump();
            $after = $this->snapshot();
            $this->check($this->preserved($baseline) === $this->preserved($after)
                && array_all($after['spots'], fn ($r): bool => ! $r['is_active'] && ! $r['is_recommendable']),
                'Hold changed protected fields or failed to exclude the complete cohort.');
            $receipt = ['kind' => self::KIND, 'context' => $context, 'actor' => $actor, 'reason' => $reason,
                'records' => $count, 'before' => $baseline, 'after' => $after];
            $receipt['sha256'] = self::hash($receipt);
            DB::table('place_catalogue_operations')->insert(['id' => $id, 'state' => 'applied', 'context' => $this->encode($context),
                'actor' => $actor, 'receipt' => $this->encode($receipt), 'created_at' => now(), 'updated_at' => now()]);

            return $receipt;
        });
    }

    public function recover(string $id, array $context, string $actor, string $reason): array
    {
        $reason = $this->reason($reason);
        $this->guard($id, $context, $actor);

        return DB::transaction(function () use ($id, $context, $actor, $reason): array {
            $this->lock($id);
            $row = DB::table('place_catalogue_operations')->where('id', $id)->first();
            $this->check($row !== null, 'A committed source hold operation is required.');
            $receipt = $this->receipt($row, $context);
            $current = $this->snapshot();
            if ($row->state === 'recovered') {
                $recovery = $this->decode($row->recovery);
                $this->verifyHash($recovery);
                $this->check($recovery['operation_sha256'] === $receipt['sha256'] && $recovery['actor'] === $actor
                    && $recovery['reason'] === $reason && self::hash($current) === self::hash($recovery['after']),
                    'Recovery request or recovered cohort changed.');

                return $recovery;
            }
            $this->check($row->state === 'applied' && self::hash($current) === self::hash($receipt['after']),
                'Later source cohort evidence or relationship changes prevent recovery.');
            foreach (array_chunk($receipt['before']['spots'], 500) as $batch) {
                $values = implode(', ', array_fill(0, count($batch), '(CAST(? AS bigint), CAST(? AS boolean), CAST(? AS boolean), CAST(? AS timestamp))'));
                $bindings = [];
                foreach ($batch as $spot) {
                    array_push($bindings, $spot['id'], $spot['is_active'], $spot['is_recommendable'], $spot['updated_at']);
                }
                $count = DB::update('UPDATE spots AS s SET is_active = v.active, is_recommendable = v.recommendable, updated_at = v.updated_at
                    FROM (VALUES '.$values.') AS v(id, active, recommendable, updated_at) WHERE s.id = v.id AND s.source IS NULL', $bindings);
                $this->check($count === count($batch), 'Complete owned cohort was not restored.');
            }
            app(PlaceFactRevision::class)->bump();
            $after = $this->snapshot();
            $this->check(self::hash($after) === self::hash($receipt['before']), 'Recovery changed protected source fields or references.');
            $recovery = ['kind' => self::KIND, 'operation_sha256' => $receipt['sha256'], 'actor' => $actor, 'reason' => $reason,
                'restored_records' => $receipt['records'], 'after' => $after];
            $recovery['sha256'] = self::hash($recovery);
            DB::table('place_catalogue_operations')->where('id', $id)->update(['state' => 'recovered', 'recovery' => $this->encode($recovery), 'updated_at' => now()]);

            return $recovery;
        });
    }

    /** Metadata and digests only: no user, media or raw source payload is returned. */
    public function snapshot(): array
    {
        $rows = DB::table('spots as s')->whereNull('s.source')->orderBy('s.id')->limit(5001)
            ->selectRaw("s.id, s.is_active, s.is_recommendable, s.updated_at, to_jsonb(s)::text AS full_payload,
                (to_jsonb(s) - 'is_active' - 'is_recommendable' - 'updated_at')::text AS preserved_payload")->get();
        $this->check($rows->isNotEmpty() && $rows->count() <= 5000, 'Use a complete cohort of 1 to 5000 source-null places.');
        $spots = $rows->map(fn ($r): array => ['id' => (int) $r->id, 'is_active' => (bool) $r->is_active,
            'is_recommendable' => (bool) $r->is_recommendable, 'updated_at' => $r->updated_at,
            'row_sha256' => hash('sha256', $r->full_payload), 'preserved_sha256' => hash('sha256', $r->preserved_payload)])->all();
        $ids = array_column($spots, 'id');
        $hashes = function ($query, string $expression): array {
            $rows = $query->selectRaw('id, '.$expression.'::text AS digest_payload')->orderBy('id')->limit(50001)->get();
            $this->check($rows->count() <= 50000, 'Protected evidence exceeds the bounded protocol.');

            return $rows->map(fn ($r): array => ['id' => (int) $r->id, 'sha256' => hash('sha256', $r->digest_payload)])->all();
        };
        $evidence = [];
        foreach (['place_fact_observations', 'place_fact_corrections'] as $table) {
            $evidence[$table] = $hashes(DB::table($table)->whereIn('spot_id', $ids), 'to_jsonb('.$table.')');
        }
        $evidence['place_reconciliations'] = $hashes(DB::table('place_reconciliations')->whereIn('alias_spot_id', $ids)->orWhereIn('canonical_spot_id', $ids), 'to_jsonb(place_reconciliations)');
        $evidence['place_destination_reviews'] = $hashes(DB::table('place_destination_reviews')->whereIn('spot_id', $ids)->orWhereIn('destination_spot_id', $ids), 'to_jsonb(place_destination_reviews)');
        $evidence['incoming_spots'] = $hashes(DB::table('spots')->whereNotIn('id', $ids)->where(fn ($q) => $q->whereIn('canonical_spot_id', $ids)
            ->orWhereIn('parent_spot_id', $ids)->orWhereIn('destination_spot_id', $ids)->orWhereIn('destination_reviewed_parent_id', $ids)),
            'jsonb_build_array(id, canonical_spot_id, parent_spot_id, destination_spot_id, destination_reviewed_parent_id)');
        $evidence['incoming_park_areas'] = $hashes(DB::table('park_areas')->whereIn('parent_spot_id', $ids), 'jsonb_build_array(id, parent_spot_id)');
        $evidence['incoming_venues'] = $hashes(DB::table('venues')->whereIn('place_id', $ids), 'jsonb_build_array(id, place_id)');

        return ['kind' => self::KIND, 'spots' => $spots, 'evidence' => $evidence];
    }

    /** Fingerprint runtime code and dependency lock, never environment or credentials. */
    public static function applicationHash(): string
    {
        $files = [];
        foreach (['app', 'config', 'routes', 'database/migrations'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[substr($file->getPathname(), strlen(base_path()) + 1)] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        foreach (['composer.lock', 'bootstrap/app.php'] as $file) {
            $files[$file] = hash_file('sha256', base_path($file));
        }

        return self::hash($files);
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

    private function preserved(array $snapshot): array
    {
        return ['evidence' => $snapshot['evidence'], 'spots' => array_map(fn ($r): array => ['id' => $r['id'], 'sha256' => $r['preserved_sha256']], $snapshot['spots'])];
    }

    private function guard(string $id, array $context, string $actor): void
    {
        $this->check(DB::transactionLevel() > 0, 'Explicit caller-owned transaction required.');
        $this->check(DB::selectOne('SHOW transaction_isolation')->transaction_isolation === 'read committed', 'Read committed isolation is required for current drift checks.');
        $this->check(Str::isUuid($id) && trim($actor) === $actor && $actor !== '' && mb_strlen($actor) <= 191, 'Operation UUID and actor required.');
        $this->check(($context['database'] ?? null) === DB::selectOne('select current_database() as name')->name, 'Wrong target database.');
        foreach (['package_sha256', 'application_sha256', 'importer_sha256'] as $key) {
            $this->check(is_string($context[$key] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $context[$key]), 'Exact package runtime and helper hashes required.');
        }
        $this->check(hash_equals(self::applicationHash(), $context['application_sha256']), 'Application runtime differs.');
        $this->check(hash_equals(hash_file('sha256', __FILE__), $context['importer_sha256']), 'Source hold helper differs.');
    }

    private function lock(string $id): void
    {
        DB::statement('SET LOCAL lock_timeout = 10000');
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['source-provenance-hold:'.$id]);
        DB::statement('LOCK TABLE spots, place_fact_observations, place_fact_corrections, place_fact_revisions, place_reconciliations,
            place_destination_reviews, park_areas, venues, place_catalogue_operations IN SHARE ROW EXCLUSIVE MODE');
    }

    private function receipt(object $row, array $context): array
    {
        $receipt = $this->decode($row->receipt);
        $this->verifyHash($receipt);
        $this->check(($receipt['kind'] ?? null) === self::KIND && ($receipt['actor'] ?? null) === $row->actor
            && self::hash($this->decode($row->context)) === self::hash($context) && self::hash($receipt['context']) === self::hash($context)
            && self::hash($receipt['before']) === $context['package_sha256'] && count($receipt['before']['spots']) === $receipt['records'],
            'Operation kind context or owned cohort differs.');

        return $receipt;
    }

    private function verifyHash(array $value): void
    {
        $expected = $value['sha256'] ?? '';
        unset($value['sha256']);
        $this->check(is_string($expected) && hash_equals(self::hash($value), $expected), 'Journal checksum differs.');
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        $this->check(mb_strlen($reason) >= 20 && mb_strlen($reason) <= 2000, 'Document the source hold or recovery reason in 20 to 2000 characters.');

        return $reason;
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
