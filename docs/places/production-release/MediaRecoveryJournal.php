<?php

use App\Media\CaptureMediaCandidate;
use App\Media\CommonsPhotoResolver;
use App\Media\MediaAssetValidator;
use App\Media\MediaCandidate;
use App\Media\MediaSourcePolicy;
use App\Media\PublishedMediaSelector;
use App\Media\ReviewMediaMatch;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Spot;
use App\Places\DestinationGrouping;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Operator-only recovery for an explicitly reviewed, bounded existing-media batch. */
class MediaRecoveryJournal
{
    private const KIND = 'existing-place-media-v1';

    private const TABLES = ['media_assets', 'media_attachments', 'media_match_reviews', 'media_validation_attempts'];

    public function apply(string $id, array $context, array $records, array $baseline, string $actor): array
    {
        $this->guard($id, $context, $actor);
        $this->check(count($records) > 0 && count($records) <= 100, 'Use 1 to 100 reviewed existing associations.');
        $this->check(hash_equals($context['package_sha256'], self::hash($records)), 'Reviewed package differs.');
        $ownerIds = array_values(array_unique(array_column($records, 'spot_id')));

        return DB::transaction(function () use ($id, $context, $records, $baseline, $actor, $ownerIds): array {
            $this->lock($id);
            $existing = DB::table('place_catalogue_operations')->where('id', $id)->first();
            if ($existing !== null) {
                $receipt = $this->receipt($existing, $context);
                $this->check($existing->actor === $actor && $existing->state === 'applied', 'Operation actor or state differs.');
                $this->check(self::hash($this->snapshot($ownerIds)) === self::hash($receipt['after']), 'Media changed since this operation.');

                return $receipt;
            }
            $this->check(self::hash($baseline) === self::hash($this->snapshot($ownerIds)), 'Media or owners changed after preview.');
            $seen = [];
            $targets = [];
            foreach ($records as $record) {
                $target = $this->target($record);
                $attachment = $target[2];
                $this->check(! isset($seen[$attachment->id]), 'Duplicate attachment in package.');
                $seen[$attachment->id] = true;
                $targets[] = $target;
            }
            $validated = [];
            foreach ($records as $index => $record) {
                [$spot, $asset, $attachment] = $targets[$index];
                $candidate = app(CommonsPhotoResolver::class)->candidate($record['commons_file'], $record['metadata']);
                $candidate = new MediaCandidate(...array_replace(get_object_vars($candidate), [
                    'providerAssetId' => $asset->provider_asset_id,
                    'priority' => $attachment->priority,
                    'isPrimary' => $attachment->is_primary,
                    'shouldValidate' => false,
                ]));
                if (isset($validated[$asset->id])) {
                    $this->check($validated[$asset->id] === self::hash([$record['metadata'], $record['checksum']]), 'Conflicting shared-asset evidence.');
                } else {
                    $captured = app(CaptureMediaCandidate::class)->execute($spot, $candidate);
                    $this->check($captured?->id === $attachment->id && $captured->media_asset_id === $asset->id, 'Recovery cannot create or remap attachments.');
                    $this->check(app(MediaAssetValidator::class)->validate($asset->fresh()) === 'active', 'Image health validation failed; whole batch refused.');
                    $this->check($asset->fresh()->checksum === $record['checksum'], 'Image bytes changed since the reviewed trial.');
                    $validated[$asset->id] = self::hash([$record['metadata'], $record['checksum']]);
                }
                $reviewer = app(ReviewMediaMatch::class);
                $preview = $reviewer->preview($attachment->id, 'accepted', $record['method']);
                $reviewer->apply($attachment->id, 'accepted', $record['method'], $preview['fingerprint'], $this->encode($record), $actor);
            }
            foreach ($targets as [$spot, $asset]) {
                $selected = app(PublishedMediaSelector::class)->select($spot->fresh(), 'hero');
                $this->check($selected?->id === $asset->id, 'The reviewed photo is not the selected hero after the complete batch.');
            }
            $after = $this->snapshot($ownerIds);
            foreach (['media_assets', 'media_attachments'] as $table) {
                $this->check(array_column($after[$table], 'id') === array_column($baseline[$table], 'id'), 'Recovery may only update existing media rows.');
            }
            $this->check($after['owners'] === $baseline['owners'], 'Owner rows changed during media recovery.');
            $receipt = ['kind' => self::KIND, 'context' => $context, 'owner_ids' => $ownerIds, 'before' => $baseline, 'after' => $after, 'records' => count($records)];
            $receipt['sha256'] = self::hash($receipt);
            DB::table('place_catalogue_operations')->insert(['id' => $id, 'state' => 'applied', 'context' => $this->encode($context), 'actor' => $actor, 'receipt' => $this->encode($receipt), 'created_at' => now(), 'updated_at' => now()]);

            return $receipt;
        });
    }

    public function recover(string $id, array $context, string $actor, string $reason): array
    {
        $this->guard($id, $context, $actor);
        $this->check(mb_strlen(trim($reason)) >= 20, 'A documented recovery reason is required.');

        return DB::transaction(function () use ($id, $context, $actor, $reason): array {
            $this->lock($id);
            $row = DB::table('place_catalogue_operations')->where('id', $id)->first();
            $this->check($row !== null, 'A committed media operation is required.');
            $receipt = $this->receipt($row, $context);
            $current = $this->snapshot($receipt['owner_ids']);
            if ($row->state === 'recovered') {
                $recovery = $this->decode($row->recovery);
                $this->verifyHash($recovery);
                $this->check($recovery['operation_sha256'] === $receipt['sha256'] && self::hash($current) === self::hash($recovery['after']), 'Recovered media changed; refuse replay.');

                return $recovery;
            }
            $this->check($row->state === 'applied' && self::hash($current) === self::hash($receipt['after']), 'Later media or owner edits prevent recovery.');
            foreach (['media_assets', 'media_attachments'] as $table) {
                foreach ($receipt['before'][$table] as $original) {
                    $rowId = $original['id'];
                    unset($original['id']);
                    $values = array_map(fn ($v) => is_array($v) ? $this->encode($v) : $v, $original);
                    DB::table($table)->where('id', $rowId)->update($values);
                }
            }
            $after = $this->snapshot($receipt['owner_ids']);
            $expected = $receipt['after'];
            $expected['media_assets'] = $receipt['before']['media_assets'];
            $expected['media_attachments'] = $receipt['before']['media_attachments'];
            $this->check(self::hash($after) === self::hash($expected), 'Recovery failed to restore media and retain audit history.');
            $recovery = ['kind' => self::KIND, 'operation_sha256' => $receipt['sha256'], 'actor' => $actor, 'reason' => trim($reason), 'after' => $after];
            $recovery['sha256'] = self::hash($recovery);
            DB::table('place_catalogue_operations')->where('id', $id)->update(['state' => 'recovered', 'recovery' => $this->encode($recovery), 'updated_at' => now()]);

            return $recovery;
        });
    }

    public function snapshot(array $ownerIds): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $rows = DB::table($table)->orderBy('id')->limit(2501)->get()->map(fn ($r) => (array) $r)->all();
            $this->check(count($rows) <= 2500, 'Media catalogue exceeds this bounded recovery protocol.');
            $out[$table] = $rows;
        }
        // Recovery restores the complete bounded media catalogue, including shared owners.
        foreach ($out['media_attachments'] as $attachment) {
            $this->check($attachment['mediable_type'] === (new Spot)->getMorphClass(), 'This recovery protocol supports only place-owned media.');
            $ownerIds[] = $attachment['mediable_id'];
        }
        $ownerIds = array_values(array_unique(array_map('intval', $ownerIds)));
        sort($ownerIds);
        do {
            $this->check(count($ownerIds) <= 2500, 'Owner family exceeds this bounded recovery protocol.');
            $rows = DB::table('spots')->whereIn('id', $ownerIds)->orWhereIn('canonical_spot_id', $ownerIds)
                ->orderBy('id')->limit(2501)->get()->map(fn ($r) => (array) $r)->all();
            $this->check(count($rows) <= 2500 && array_diff($ownerIds, array_column($rows, 'id')) === [], 'Missing or excessive media owners.');
            $nextIds = $ownerIds;
            foreach ($rows as $row) {
                foreach (['id', 'canonical_spot_id', 'parent_spot_id', 'destination_spot_id', 'destination_reviewed_parent_id'] as $key) {
                    if (($row[$key] ?? null) !== null) {
                        $nextIds[] = (int) $row[$key];
                    }
                }
            }
            $nextIds = array_values(array_unique($nextIds));
            sort($nextIds);
            $complete = $nextIds === $ownerIds;
            $ownerIds = $nextIds;
        } while (! $complete);
        $out['owners'] = $rows;

        return $out;
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

    private function target(array $r): array
    {
        $this->check(($r['source'] ?? null) === 'osm' && preg_match('~^(node|way|relation)/[0-9]+$~D', $r['source_id'] ?? ''), 'Reviewed OSM source identity required.');
        $spots = Spot::where('source', $r['source'])->where('source_id', $r['source_id'])->get();
        $this->check($spots->count() === 1 && $spots->first()->id === $r['spot_id'], 'Source key does not resolve to the reviewed target.');
        $spot = $spots->first();
        $this->check($spot->canonical_spot_id === null, 'Alias remapping requires separate review.');
        $this->check(app(DestinationGrouping::class)->eligible(Spot::query(), true)->whereKey($spot->id)->exists(), 'Only eligible reviewed owners can receive recovered photos.');
        $asset = MediaAsset::find($r['asset_id']);
        $attachment = MediaAttachment::find($r['attachment_id']);
        $this->check($asset !== null && $attachment !== null && $attachment->media_asset_id === $asset->id && $attachment->mediable_type === $spot->getMorphClass() && $attachment->mediable_id === $spot->id && $attachment->role === 'hero', 'Reviewed attachment identity changed.');
        $this->check($attachment->match_status === 'pending' && ! $attachment->is_manually_locked, 'Only pending unlocked matches may be recovered.');
        $canon = fn (string $file) => str_replace(' ', '_', trim(rawurldecode(preg_replace('/^File:/i', '', $file))));
        $this->check($asset->provider === 'wikimedia-commons' && $canon($asset->provider_asset_id) === $canon($r['commons_file']) && $asset->source_key === hash('sha256', 'wikimedia-commons|'.$asset->provider_asset_id), 'Reviewed asset identity changed.');
        $tag = match ($r['method']) {
            'osm_wikimedia_commons_tag' => 'wikimedia_commons',
            'osm_wikidata_p18' => 'wikidata',
            'osm_wikipedia_pageimage' => 'wikipedia',
            default => throw new DomainException('Unsupported match proof.'),
        };
        $this->check(is_string($r['reference']) && $r['reference'] !== '' && ($spot->tags[$tag] ?? null) === $r['reference'], 'Source match reference changed.');
        $checked = CarbonImmutable::parse($r['checked_at']);
        $this->check($checked->betweenIncluded(now()->subDay(), now()->addMinutes(5)), 'Fresh reviewed source and metadata proof required.');
        foreach (['proof_sha256', 'checksum'] as $key) {
            $this->check(preg_match('/^[a-f0-9]{64}$/D', $r[$key] ?? ''), 'Reviewed proof and byte checksums are required.');
        }
        $m = $r['metadata'];
        $this->check(($m['rights_status'] ?? null) === 'approved' && ($m['health_status'] ?? null) === 'pending' && ($m['checksum'] ?? null) === null, 'Metadata must not substitute for byte health.');
        $this->check(app(CommonsPhotoResolver::class)->isOpenLicense($m['license_code'] ?? '') && trim($m['attribution'] ?? '') !== '' && trim($m['source_page_url'] ?? '') !== '', 'Reviewed open licence and attribution required.');
        $page = parse_url($m['source_page_url']);
        $this->check(($page['scheme'] ?? null) === 'https' && ($page['host'] ?? null) === 'commons.wikimedia.org' && str_starts_with($page['path'] ?? '', '/wiki/File:') && $canon(substr($page['path'], 6)) === $canon($r['commons_file']), 'Source page must identify this Commons file.');
        $this->check(! str_starts_with(mb_strtoupper($m['license_code']), 'CC BY') || (trim($m['author'] ?? '') !== '' && filter_var($m['license_url'] ?? '', FILTER_VALIDATE_URL)), 'Attribution licence requires its author and licence URL.');
        $this->check(MediaAssetValidator::isAllowedProviderUrl('wikimedia-commons', $m['remote_url']) && ! app(MediaSourcePolicy::class)->excludes('wikimedia-commons', $m['author'] ?? null, $m['source_page_url'], $m['remote_url'], ['source_provenance' => $m['source_provenance'] ?? []]), 'Excluded or unsupported media source.');

        return [$spot, $asset, $attachment];
    }

    private function receipt(object $row, array $context): array
    {
        $receipt = $this->decode($row->receipt);
        $this->verifyHash($receipt);
        $this->check(($receipt['kind'] ?? null) === self::KIND && self::hash($this->decode($row->context)) === self::hash($context) && self::hash($receipt['context']) === self::hash($context), 'Operation kind or target context differs.');

        return $receipt;
    }

    private function verifyHash(array $value): void
    {
        $expected = $value['sha256'] ?? '';
        unset($value['sha256']);
        $this->check(hash_equals(self::hash($value), $expected), 'Journal checksum differs.');
    }

    private function guard(string $id, array $context, string $actor): void
    {
        $this->check(DB::transactionLevel() > 0, 'Explicit caller-owned transaction required.');
        $this->check(preg_match('/^[a-f0-9-]{36}$/D', $id) && trim($actor) !== '' && mb_strlen($actor) <= 191, 'Operation UUID and actor required.');
        $this->check(($context['database'] ?? null) === DB::selectOne('select current_database() as name')->name, 'Wrong target database.');
        foreach (['package_sha256', 'application_sha256', 'importer_sha256'] as $key) {
            $this->check(preg_match('/^[a-f0-9]{64}$/D', $context[$key] ?? ''), 'Reviewed application/package/importer hashes required.');
        }
        $this->check(hash_equals(hash_file('sha256', __FILE__), $context['importer_sha256']), 'Importer code differs.');
    }

    private function lock(string $id): void
    {
        DB::statement('SET LOCAL lock_timeout = 10000');
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['media-recovery:'.$id]);
        DB::statement('LOCK TABLE spots, media_assets, media_attachments, media_match_reviews, media_validation_attempts IN SHARE ROW EXCLUSIVE MODE');
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
