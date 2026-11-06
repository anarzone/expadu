<?php

namespace App\Http\Resources\Api\V1;

use App\Services\KvbLineColors;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Travel's board for the iPhone: one list, soonest first. A row is a line in one direction;
 * its minutes are the next few departures from the stop it leaves from.
 *
 * @property-read array{kvb?: array<int, array<string, mixed>>, db?: array<int, array<string, mixed>>, stops_used?: array<int, array<string, mixed>>} $resource
 */
class NearbyDeparturesResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $rows = collect([...($this->resource['kvb'] ?? []), ...($this->resource['db'] ?? [])])
            ->filter(fn (array $row) => ($row['departures'] ?? []) !== [])
            ->sortBy(fn (array $row) => $row['departures'][0])
            ->values();

        return [
            /** @var array<int, array{name: string, walk_min: int}> */
            'stops' => collect($this->resource['stops_used'] ?? [])
                ->map(fn (array $stop) => ['name' => (string) $stop['name'], 'walk_min' => (int) $stop['walk_min']])
                ->values()->all(),
            /** @var array<int, array{line: string, direction: string, stop: string, color: string, type: string, minutes: array<int, int>, delay: int, cancelled: bool, disrupted: bool}> */
            'departures' => $rows->map(fn (array $row) => [
                'line' => (string) $row['line'],
                'direction' => (string) $row['direction'],
                'stop' => (string) $row['stop_name'],
                'color' => KvbLineColors::for((string) $row['line'], $row['type'] ?? null),
                'type' => (string) ($row['type'] ?? 'tram'),
                'minutes' => array_map('intval', array_slice($row['departures'], 0, 3)),
                'delay' => (int) ($row['delay'] ?? 0),
                'cancelled' => (bool) ($row['cancelled'] ?? false),
                'disrupted' => (bool) ($row['disrupted'] ?? false),
            ])->all(),
        ];
    }
}
