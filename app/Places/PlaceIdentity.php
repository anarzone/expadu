<?php

namespace App\Places;

use App\Models\Spot;
use Closure;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PlaceIdentity
{
    /** @param list<int> $ids
     * @return array<int, int>
     */
    public function canonicalIds(array $ids): array
    {
        $map = array_combine($ids, $ids);
        foreach (Spot::query()->whereIn('id', $ids)->get(['id', 'canonical_spot_id']) as $spot) {
            $map[$spot->id] = $spot->canonical_spot_id ?? $spot->id;
        }

        return $map;
    }

    /** @param list<string> $ids
     * @return list<string>
     */
    public function candidateIds(array $ids): array
    {
        $spotIds = [];
        foreach ($ids as $id) {
            if (preg_match('/^spot:([1-9][0-9]*)$/D', $id, $match)) {
                $spotIds[] = (int) $match[1];
            }
        }
        $map = $this->canonicalIds($spotIds);

        return array_values(array_unique(array_map(function (string $id) use ($map): string {
            return preg_match('/^spot:([1-9][0-9]*)$/D', $id, $match)
                ? 'spot:'.($map[(int) $match[1]] ?? $match[1])
                : $id;
        }, $ids)));
    }

    /** @return list<int> */
    public function familyIds(int $id): array
    {
        $canonical = $this->canonicalIds([$id])[$id];

        return Spot::query()->where('id', $canonical)->orWhere('canonical_spot_id', $canonical)
            ->orderBy('id')->pluck('id')->all();
    }

    public function revision(): int
    {
        return (int) DB::table('place_reconciliations')->max('id');
    }

    public function hasProtectedRecords(Builder $query): bool
    {
        return (clone $query)->where(fn ($where) => $where
            ->whereIn('id', DB::table('place_reconciliations')->select('alias_spot_id'))
            ->orWhereIn('id', DB::table('place_reconciliations')->select('canonical_spot_id')))->exists();
    }

    public function withCanonicalLock(int $id, Closure $action): mixed
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $expected = $this->canonicalIds([$id])[$id];
            $result = DB::transaction(function () use ($id, $expected, $action): array {
                $rows = Spot::query()->whereIn('id', [$id, $expected])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $source = $rows->get($id);
                if (! $source) {
                    Spot::query()->findOrFail($id);
                }
                if (($source->canonical_spot_id ?? $source->id) !== $expected) {
                    return ['retry' => true];
                }

                return ['retry' => false, 'result' => $action($rows->get($expected))];
            }, 3);
            if (! $result['retry']) {
                return $result['result'];
            }
        }

        throw new DomainException('The place identity changed while saving; retry the action.');
    }

    /** @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function normalizePlan(array $plan): array
    {
        $all = array_merge(array_column($plan['slots'] ?? [], 'id'), $plan['pins'] ?? [], $plan['locked'] ?? [], $plan['excluded'] ?? [], ...array_values($plan['rejected'] ?? []));
        $ids = [];
        foreach ($all as $id) {
            if (preg_match('/^spot:([1-9][0-9]*)$/D', $id, $match)) {
                $ids[] = (int) $match[1];
            }
        }
        $map = $this->canonicalIds($ids);
        $resolve = fn (string $id): string => preg_match('/^spot:([1-9][0-9]*)$/D', $id, $match)
            ? 'spot:'.($map[(int) $match[1]] ?? $match[1]) : $id;
        foreach ($plan['slots'] ?? [] as $index => $slot) {
            $plan['slots'][$index]['id'] = $resolve($slot['id']);
        }
        foreach (['pins', 'locked', 'excluded'] as $key) {
            if (isset($plan[$key])) {
                $plan[$key] = array_values(array_unique(array_map($resolve, $plan[$key])));
            }
        }
        foreach ($plan['rejected'] ?? [] as $index => $rejected) {
            $plan['rejected'][$index] = array_values(array_unique(array_map($resolve, $rejected)));
        }

        return $plan;
    }
}
