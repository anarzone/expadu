<?php

namespace App\Places;

use App\Models\PlaceFactObservation;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PlaceObservationHistory
{
    /** @param Collection<int, PlaceFactObservation> $history
     * @return Collection<int, PlaceFactObservation>
     */
    public static function active(Collection $history): Collection
    {
        $withdrawn = [];
        foreach ($history as $row) {
            if ($row->record_kind === 'withdrawal' && (string) ($row->payload['schema_version'] ?? '') === '1') {
                $withdrawn[self::key($row, (string) ($row->payload['withdraws_observation_id'] ?? ''), (string) ($row->payload['target_payload_hash'] ?? ''))] = true;
            }
        }

        return $history->reject(fn (PlaceFactObservation $row): bool => $row->record_kind === 'withdrawal'
            || isset($withdrawn[self::key($row, (string) $row->id, $row->payload_hash)]))->values();
    }

    private static function key(PlaceFactObservation $row, string $id, string $hash): string
    {
        return implode("\0", [$row->spot_id, $row->provider, $row->provider_record_id, $id, $hash]);
    }

    public static function currentSql(?string $spotColumn = null): string
    {
        if ($spotColumn !== null && ! in_array($spotColumn, ['spots.id', 'destination.id'], true)) {
            throw new InvalidArgumentException('Unsupported source-history correlation.');
        }

        return str_replace('__SCOPE__', $spotColumn === null ? '' : 'WHERE spot_id = '.$spotColumn, <<<'SQL'
            source_history AS MATERIALIZED (
                SELECT id, spot_id, provider, provider_record_id, observed_at, payload, payload_hash, record_kind
                FROM place_fact_observations __SCOPE__
            ), withdrawn_observations AS MATERIALIZED (
                SELECT spot_id, provider, provider_record_id,
                    payload->>'withdraws_observation_id' AS observation_id,
                    payload->>'target_payload_hash' AS target_payload_hash
                FROM source_history
                WHERE record_kind = 'withdrawal' AND payload->>'schema_version' = '1'
            ), current_observations AS MATERIALIZED (
                SELECT DISTINCT ON (history.spot_id, history.provider, history.provider_record_id)
                    history.spot_id, history.provider, history.provider_record_id, history.observed_at, history.payload
                FROM source_history history
                WHERE history.record_kind <> 'withdrawal' AND NOT EXISTS (
                    SELECT 1 FROM withdrawn_observations withdrawn
                    WHERE withdrawn.observation_id = history.id::text
                        AND withdrawn.spot_id = history.spot_id
                        AND withdrawn.provider = history.provider
                        AND withdrawn.provider_record_id = history.provider_record_id
                        AND withdrawn.target_payload_hash = history.payload_hash
                )
                ORDER BY history.spot_id, history.provider, history.provider_record_id, history.observed_at DESC, history.id DESC
            )
            SQL);
    }
}
