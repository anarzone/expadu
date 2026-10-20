<?php

namespace App\Places;

use App\Models\PlaceFactObservation;
use App\Models\Spot;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WithdrawPlaceObservation
{
    /** @return array<string, mixed> */
    public function preview(int $observationId): array
    {
        $target = PlaceFactObservation::findOrFail($observationId);
        $spot = Spot::findOrFail($target->spot_id);
        $history = $spot->factObservations()->reorder('id')->get();

        return [
            'spot_id' => $spot->id,
            'observation_id' => $target->id,
            'fingerprint' => $this->fingerprint($spot, $history),
            'can_withdraw' => $target->record_kind === 'source' && $this->stream($history, $target)->count() === 1,
        ];
    }

    public function apply(int $observationId, string $fingerprint, string $actor, string $reason): bool
    {
        $actor = trim($actor);
        $reason = trim($reason);
        if (! preg_match('/^[a-f0-9]{64}$/D', $fingerprint) || $actor === '' || mb_strlen($actor) > 191 || mb_strlen($reason) < 20) {
            throw new DomainException('An exact preview fingerprint, actor and documented withdrawal reason are required.');
        }
        $initial = PlaceFactObservation::findOrFail($observationId);

        return app(PlaceIdentity::class)->withCanonicalLock($initial->spot_id, function (Spot $spot) use ($observationId, $fingerprint, $actor, $reason): bool {
            $history = $spot->factObservations()->reorder('id')->lockForUpdate()->get();
            DB::table('place_fact_corrections')->where('spot_id', $spot->id)->orderBy('id')->lockForUpdate()->get();
            $target = $history->firstWhere('id', $observationId);
            if ($target === null || $target->record_kind !== 'source') {
                throw new DomainException('Only a source observation on the locked canonical place can be withdrawn.');
            }
            $stream = $this->stream($history, $target);
            $metadata = [
                'schema_version' => 1,
                'withdraws_observation_id' => $target->id,
                'target_payload_hash' => $target->payload_hash,
                'target_row_sha256' => $this->hash($target->getRawOriginal()),
                'expected_before_sha256' => $fingerprint,
            ];
            $payloadHash = $this->hash($metadata);
            $operationKey = 'withdrawal:'.$target->id.':'.$fingerprint;
            $marker = $stream->firstWhere('record_kind', 'withdrawal');
            if ($marker !== null) {
                if ($stream->count() !== 2 || $marker->ingestion_key !== $operationKey || $marker->payload != $metadata
                    || ! hash_equals($marker->payload_hash, $payloadHash) || $marker->actor !== $actor || $marker->reason !== $reason) {
                    throw new DomainException('Withdrawal replay differs from the recorded action or source history changed.');
                }

                return false;
            }
            if ($stream->count() !== 1 || ! hash_equals($this->fingerprint($spot, $history), $fingerprint)) {
                throw new DomainException('Source history or place facts changed after preview; only an initial source can be withdrawn.');
            }
            if (! $target->observed_at->lt(now())) {
                throw new DomainException('A future or current source observation cannot be withdrawn safely.');
            }

            PlaceFactObservation::create([
                'spot_id' => $spot->id,
                'provider' => $target->provider,
                'provider_record_id' => $target->provider_record_id,
                'source_url' => $target->source_url,
                'observed_at' => now()->utc(),
                'ingestion_key' => $operationKey,
                'record_kind' => 'withdrawal',
                'payload' => $metadata,
                'payload_hash' => $payloadHash,
                'actor' => $actor,
                'reason' => $reason,
            ]);
            app(PlaceFactRevision::class)->bump();

            return true;
        });
    }

    /** @param Collection<int, PlaceFactObservation> $history */
    private function fingerprint(Spot $spot, Collection $history): string
    {
        return $this->hash([
            'spot' => $spot->getRawOriginal(),
            'observations' => $history->map(fn (PlaceFactObservation $row): array => $row->getRawOriginal())->all(),
            'corrections' => DB::table('place_fact_corrections')->where('spot_id', $spot->id)->orderBy('id')->get()->all(),
        ]);
    }

    /** @param Collection<int, PlaceFactObservation> $history
     * @return Collection<int, PlaceFactObservation>
     */
    private function stream(Collection $history, PlaceFactObservation $target): Collection
    {
        return $history->where('provider', $target->provider)->where('provider_record_id', $target->provider_record_id)->values();
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
