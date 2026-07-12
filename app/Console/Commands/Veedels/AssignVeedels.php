<?php

namespace App\Console\Commands\Veedels;

use App\Models\Spot;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backfills spots.veedel. Once polygons exist they are authoritative: an OSM
 * point outside every polygon is quarantined, never nearest-centroid assigned.
 */
class AssignVeedels extends Command
{
    protected $signature = 'spots:assign-veedel {--force : Re-assign spots that already have a veedel}';

    protected $description = 'Assign each spot to its Veedel via polygon containment or nearest centroid';

    public function handle(): int
    {
        $veedels = DB::table('veedels')
            ->whereNotNull('centroid_lat')
            ->get(['name', 'centroid_lat', 'centroid_lng']);

        if ($veedels->isEmpty()) {
            $this->error('No veedels imported yet — run veedels:import first.');

            return self::FAILURE;
        }

        $officialVeedels = collect(config('veedels'))->flatten()->all();
        $hasBoundaries = DB::table('veedels')
            ->whereIn('name', $officialVeedels)
            ->whereNotNull('boundary')
            ->count() === 86;

        $query = Spot::query()->whereNotNull('lat')->whereNotNull('lng');
        if (! $this->option('force')) {
            $query->whereNull('veedel');
        }

        $assigned = 0;

        $query->chunkById(200, function ($spots) use ($veedels, $hasBoundaries, &$assigned) {
            foreach ($spots as $spot) {
                $veedel = $hasBoundaries
                    ? $this->byPolygon($spot)
                    : $this->byNearestCentroid($spot, $veedels);

                if ($veedel !== null) {
                    $spot->update([
                        'veedel' => $veedel,
                    ]);
                    $assigned++;
                } elseif ($hasBoundaries && in_array($spot->source, [null, 'osm'], true)) {
                    $spot->update([
                        'veedel' => null,
                        'is_active' => false,
                        'is_recommendable' => false,
                    ]);
                }
            }
        });

        $this->info("Assigned {$assigned} spot(s).".($hasBoundaries ? ' (polygons)' : ' (nearest centroid)'));

        return self::SUCCESS;
    }

    private function byPolygon(Spot $spot): ?string
    {
        $row = DB::selectOne(
            'SELECT name FROM veedels WHERE boundary IS NOT NULL
             AND ST_Covers(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4326)) LIMIT 1',
            [(float) $spot->lng, (float) $spot->lat],
        );

        return $row->name ?? null;
    }

    /**
     * @param  Collection<int, object>  $veedels
     */
    private function byNearestCentroid(Spot $spot, $veedels): ?string
    {
        $best = null;
        $bestDistance = INF;

        foreach ($veedels as $veedel) {
            $dLat = (float) $veedel->centroid_lat - (float) $spot->lat;
            $dLng = ((float) $veedel->centroid_lng - (float) $spot->lng) * cos(deg2rad((float) $spot->lat));
            $distance = $dLat * $dLat + $dLng * $dLng;

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $veedel->name;
            }
        }

        return $best;
    }
}
