<?php

namespace App\Places;

use App\Models\Spot;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReconcilePlace
{
    /** @return array<string, mixed> */
    public function preview(int $aliasId, int $canonicalId): array
    {
        return $this->inspect($aliasId, $canonicalId);
    }

    public function apply(int $aliasId, int $canonicalId, string $fingerprint, string $evidence): void
    {
        if (mb_strlen(trim($evidence)) < 20) {
            throw new DomainException('Document the reviewed identity evidence before applying a match.');
        }

        DB::transaction(function () use ($aliasId, $canonicalId, $fingerprint, $evidence): void {
            Spot::query()->whereIn('id', [$aliasId, $canonicalId])->orderBy('id')->lockForUpdate()->get();
            $preview = $this->inspect($aliasId, $canonicalId);
            if ($preview['already_reconciled']) {
                return;
            }
            if (! hash_equals($preview['fingerprint'], $fingerprint)) {
                throw new DomainException('The place records changed after preview; review a fresh preview.');
            }

            foreach (['spots' => 'parent_spot_id', 'park_areas' => 'parent_spot_id', 'venues' => 'place_id'] as $table => $column) {
                DB::table($table)->where($column, $aliasId)->update([$column => $canonicalId]);
            }
            DB::table('spots')->where('id', $aliasId)->update(['canonical_spot_id' => $canonicalId, 'updated_at' => now()]);
            DB::table('place_reconciliations')->insert([
                'alias_spot_id' => $aliasId,
                'canonical_spot_id' => $canonicalId,
                'fingerprint' => $fingerprint,
                'evidence' => trim($evidence),
                'snapshot' => json_encode($preview['snapshot'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            Spot::query()->findOrFail($canonicalId)->updateRating();
        });
    }

    /** @return array<string, mixed> */
    private function inspect(int $aliasId, int $canonicalId): array
    {
        $alias = Spot::query()->find($aliasId);
        $canonical = Spot::query()->find($canonicalId);
        if (! $alias || ! $canonical || $aliasId === $canonicalId) {
            throw new DomainException('Choose two different existing place records.');
        }
        if ($canonical->canonical_spot_id !== null || ($alias->canonical_spot_id !== null && $alias->canonical_spot_id !== $canonicalId)) {
            throw new DomainException('An alias cannot be a target or be moved to a different identity.');
        }
        $completed = $alias->canonical_spot_id === $canonicalId;
        if (! $completed) {
            if ($alias->source !== null || $alias->source_id !== null || $canonical->source !== 'osm' || ! $canonical->source_id || ! $canonical->is_active) {
                throw new DomainException('This reconciliation path requires a source-null legacy record and an active OSM target.');
            }
            if (Spot::query()->where('canonical_spot_id', $aliasId)->exists()) {
                throw new DomainException('A canonical identity that already owns aliases cannot itself become an alias.');
            }
            $near = DB::table('spots as a')->crossJoin('spots as c')
                ->where('a.id', $aliasId)->where('c.id', $canonicalId)
                ->whereColumn('a.name', 'c.name')->whereColumn('a.category', 'c.category')
                ->whereRaw('ST_DWithin(a.location, c.location, 10)')->exists();
            if (! $near) {
                throw new DomainException('The reviewed pair must have matching names and categories within 10 metres.');
            }
            $ancestor = $canonical;
            $visited = [];
            while ($ancestor !== null) {
                if ($ancestor->id === $aliasId || isset($visited[$ancestor->id])) {
                    throw new DomainException('Reconciliation would create a containment cycle.');
                }
                $visited[$ancestor->id] = true;
                $ancestor = $ancestor->parent_spot_id ? Spot::query()->find($ancestor->parent_spot_id) : null;
            }
            if ($alias->parent_spot_id !== null && $alias->parent_spot_id !== $canonical->parent_spot_id) {
                throw new DomainException('Resolve the conflicting parent relationships before reconciling identity.');
            }
        }

        $snapshot = ['alias' => $alias->attributesToArray(), 'canonical' => $canonical->attributesToArray()];
        unset($snapshot['alias']['location'], $snapshot['canonical']['location']);
        foreach (['spots' => 'parent_spot_id', 'park_areas' => 'parent_spot_id', 'venues' => 'place_id'] as $table => $column) {
            $snapshot['references'][$table] = DB::table($table)->where($column, $aliasId)->orderBy('id')->pluck('id')->all();
        }

        return [
            'alias_id' => $aliasId,
            'canonical_id' => $canonicalId,
            'already_reconciled' => $completed,
            'review_required' => ! $completed,
            'fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'snapshot' => $snapshot,
        ];
    }
}
