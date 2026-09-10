<?php

namespace App\Places;

use App\Models\Spot;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DestinationGrouping
{
    public const DESTINATIONS = ['park', 'sports_centre'];

    /** @param Builder<Spot> $query */
    public function eligible(Builder $query): Builder
    {
        return $query->recommendationEligible()->where(function ($where) {
            $where->whereNull('spots.destination_spot_id')->orWhereExists(function ($parent) {
                $parent->selectRaw('1')->from('spots as reviewed_destination')
                    ->join('spots as destination', fn ($join) => $join->whereRaw('destination.id = COALESCE(reviewed_destination.canonical_spot_id, reviewed_destination.id)'))
                    ->join('spots as current_parent', 'current_parent.id', '=', 'spots.parent_spot_id')
                    ->join('spots as reviewed_parent', 'reviewed_parent.id', '=', 'spots.destination_reviewed_parent_id')
                    ->whereColumn('reviewed_destination.id', 'spots.destination_spot_id')
                    ->whereRaw('COALESCE(current_parent.canonical_spot_id, current_parent.id) = destination.id')
                    ->whereRaw('COALESCE(reviewed_parent.canonical_spot_id, reviewed_parent.id) = destination.id')
                    ->whereNull('destination.canonical_spot_id')->whereNull('destination.destination_spot_id')
                    ->whereIn('destination.category', self::DESTINATIONS)
                    ->where('destination.is_active', true)->where('destination.is_recommendable', true);
            });
        });
    }

    /** @param Builder<Spot> $query */
    public function general(Builder $query): Builder
    {
        return $this->eligible($query)->whereNull('spots.destination_spot_id');
    }

    /** @param Builder<Spot> $query
     * @param  list<int>  $destinationIds
     */
    public function components(Builder $query, array $destinationIds): Builder
    {
        return $this->eligible($query)->whereIn('spots.destination_spot_id', DB::table('spots')
            ->select('id')->whereIn('id', $destinationIds)->orWhereIn('canonical_spot_id', $destinationIds));
    }

    /** @param list<int> $spotIds
     * @return array<int, int>
     */
    public function groupIds(array $spotIds): array
    {
        $canonical = app(PlaceIdentity::class)->canonicalIds($spotIds);
        $rows = $this->eligible(Spot::query())->whereIn('spots.id', array_values($canonical))
            ->leftJoin('spots as grouped_destination', 'grouped_destination.id', '=', 'spots.destination_spot_id')
            ->select('spots.id')->selectRaw('COALESCE(grouped_destination.canonical_spot_id, grouped_destination.id, spots.id) as group_id')
            ->get()->pluck('group_id', 'id');

        return array_map(fn ($id) => (int) ($rows[$id] ?? $id), $canonical);
    }

    public function hasComponents(int $spotId): bool
    {
        return Spot::whereIn('destination_spot_id', app(PlaceIdentity::class)->familyIds($spotId))->exists();
    }

    public function revision(): int
    {
        return (int) DB::table('place_destination_reviews')->max('id');
    }

    /** @return array<string, mixed> */
    public function preview(int $spotId, ?int $destinationId): array
    {
        $ids = app(PlaceIdentity::class)->canonicalIds(array_values(array_filter([$spotId, $destinationId])));
        $spot = Spot::findOrFail($ids[$spotId]);
        $destination = $destinationId === null ? null : Spot::findOrFail($ids[$destinationId]);
        if ($destination !== null) {
            $parent = $spot->parent_spot_id ? app(PlaceIdentity::class)->canonicalIds([$spot->parent_spot_id])[$spot->parent_spot_id] : null;
            if ($destination->id === $spot->id || ! in_array($destination->getRawOriginal('category'), self::DESTINATIONS, true)
                || ! $destination->is_active || ! $destination->is_recommendable || $destination->destination_spot_id !== null || $parent !== $destination->id
                || $this->hasComponents($spot->id)) {
                throw new DomainException('Choose an eligible direct destination matching the facility containment; grouping cannot form a chain or cycle.');
            }
        }
        $snapshot = ['spot' => $spot->attributesToArray(), 'destination' => $destination?->attributesToArray()];
        unset($snapshot['spot']['location'], $snapshot['destination']['location']);

        return ['spot_id' => $spot->id, 'destination_id' => $destination?->id, 'fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'snapshot' => $snapshot];
    }

    public function review(int $spotId, ?int $destinationId, string $evidence, string $fingerprint): void
    {
        if (mb_strlen(trim($evidence)) < 20) {
            throw new DomainException('Document the source evidence for component or independent operation.');
        }
        $preview = $this->preview($spotId, $destinationId);
        DB::transaction(function () use ($spotId, $destinationId, $evidence, $fingerprint, $preview): void {
            $lockIds = array_values(array_filter([$spotId, $destinationId, $preview['spot_id'], $preview['destination_id']]));
            Spot::whereIn('id', $lockIds)->orderBy('id')->lockForUpdate()->get();
            $current = $this->preview($spotId, $destinationId);
            if (! hash_equals($fingerprint, $current['fingerprint']) || $preview['spot_id'] !== $current['spot_id'] || $preview['destination_id'] !== $current['destination_id']) {
                throw new DomainException('The membership evidence changed after preview; review a fresh preview.');
            }
            DB::table('spots')->where('id', $current['spot_id'])->update([
                'destination_spot_id' => $current['destination_id'],
                'destination_reviewed_parent_id' => $current['snapshot']['spot']['parent_spot_id'],
                'destination_grouping_evidence' => trim($evidence), 'destination_reviewed_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('place_destination_reviews')->insert([
                'spot_id' => $current['spot_id'], 'destination_spot_id' => $current['destination_id'],
                'fingerprint' => $fingerprint, 'evidence' => trim($evidence),
                'snapshot' => json_encode($current['snapshot'], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        });
    }
}
