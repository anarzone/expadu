<?php

use App\Enums\SpotCategory;
use App\Models\Spot;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;
use App\Services\OpeningHoursParser;
use Illuminate\Support\Facades\DB;

/** Canonical database values without PHP's numeric-string coercion. */
function preparedPlaceRowFingerprint(array $row): string
{
    $sort = static function (mixed $value) use (&$sort): mixed {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($sort, $value);
    };

    return hash('sha256', json_encode($sort($row), JSON_THROW_ON_ERROR));
}

/** Native operations shared by the rehearsal and a separately reviewed promotion. */
function applyPreparedPlaces(array $records, array $baselineRows, string $packHash): array
{
    if (DB::transactionLevel() < 1) {
        throw new RuntimeException('A caller-owned atomic transaction is required.');
    }
    DB::statement('LOCK TABLE spots IN SHARE ROW EXCLUSIVE MODE');
    $ids = [];
    $recorder = app(RecordPlaceObservation::class);
    $facts = app(PlaceFacts::class);
    $existingRows = [];
    foreach (DB::select('SELECT row_to_json(s)::text AS raw FROM spots s ORDER BY id') as $row) {
        $decoded = json_decode($row->raw, true, flags: JSON_THROW_ON_ERROR);
        $existingRows[$decoded['id']] = $decoded;
    }

    foreach ($records as $record) {
        $id = $record['existing_id'];
        if ($record['holds'] !== [] || SpotCategory::tryFrom($record['category']) === null) {
            throw new RuntimeException('An unreviewed or unsupported record reached the import.');
        }
        if ($id !== null && (! isset($baselineRows[$id], $existingRows[$id]) || preparedPlaceRowFingerprint($baselineRows[$id]) !== preparedPlaceRowFingerprint($existingRows[$id]))) {
            throw new RuntimeException('Existing place changed since export: '.$id);
        }
        if ($id === null && Spot::query()->where('source', $record['source'])->where('source_id', $record['source_id'])->exists()) {
            throw new RuntimeException('Proposed new source identity already exists: '.$record['key']);
        }
        $veedel = DB::selectOne('SELECT name FROM veedels WHERE boundary IS NOT NULL AND ST_Covers(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4326)) ORDER BY id LIMIT 1', [$record['lng'], $record['lat']]);
        if ($veedel === null) {
            throw new RuntimeException('No official city neighbourhood contains '.$record['key']);
        }
        $ids[$record['key']] = ['existing_id' => $id, 'veedel' => $veedel->name];
    }

    foreach ($records as $index => $record) {
        $id = $record['existing_id'];
        $spot = $id === null ? new Spot : Spot::query()->findOrFail($id);
        $values = [
            'source' => $record['source'], 'source_id' => $record['source_id'],
            'source_group' => $id !== null ? $spot->source_group : (($record['tags']['leisure'] ?? null) === 'pitch' ? 'pitch' : $record['category']),
            'name' => $id !== null ? $spot->name : $record['name'],
            // Match decimal(10, 7) storage so area-centre precision cannot
            // cause an identical source refresh to write new audit timestamps.
            // The observation and original source retain the full precision.
            'category' => $record['category'], 'lat' => round($record['lat'], 7), 'lng' => round($record['lng'], 7),
            'veedel' => $ids[$record['key']]['veedel'],
            'tags' => $record['tags'] ?: null,
            'last_seen_at' => $record['observed_at'], 'is_active' => true,
            // Existing eligibility and reviewed relationships are never promoted by this batch.
            'is_recommendable' => $id === null ? $record['is_recommendable'] : ($spot->is_recommendable && $record['is_recommendable']),
        ];
        if ($id === null) {
            $values += [
                'address' => $record['address'], 'website' => $record['website'], 'phone' => $record['phone'],
                'opening_hours' => OpeningHoursParser::parse($record['observation']['hours']['raw']),
            ];
        }
        $spot->fill($values);
        if ($spot->isDirty()) {
            $spot->save();
        }
        $recorder->record($spot, [
            'provider' => $record['source'], 'provider_record_id' => $record['source_id'],
            'source_url' => $record['source_url'], 'observed_at' => $record['observed_at'],
            'ingestion_key' => 'prepared:'.substr($packHash, 0, 24).':'.$record['key'],
            'payload' => $record['observation'],
        ]);
        $ids[$record['key']]['id'] = $spot->id;
        if (($index + 1) % 500 === 0) {
            echo json_encode(['processed' => $index + 1, 'total' => count($records)]).PHP_EOL;
        }
    }

    foreach (array_chunk(array_column($ids, 'id'), 150) as $chunk) {
        $spots = Spot::query()->whereIn('id', $chunk)->get();
        $resolvedPage = $facts->resolveMany($spots);
        foreach ($spots as $spot) {
            $resolved = $resolvedPage[$spot->id];
            $spot->fill([
                'name' => $resolved['name']['value'] ?? $spot->name,
                'address' => $resolved['contact']['address']['value'],
                'website' => $resolved['contact']['website']['value'],
                'phone' => $resolved['contact']['phone']['value'],
                'description' => $resolved['description']['value'],
                'opening_hours' => $resolved['hours']['parsed'],
            ]);
            if ($spot->isDirty()) {
                $spot->save();
            }
        }
    }
    echo json_encode(['native_facts_projected' => count($ids)]).PHP_EOL;

    return $ids;
}
