<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Aggregates disruption data from multiple sources.
 * Currently returns empty — will be populated when VRS GTFS-RT and news feeds are connected.
 */
class DisruptionService
{
    /**
     * Get active transit/road disruptions.
     *
     * @return array<int, array{id: string, title: string, description: string, severity: string, affected_lines: string[], started_at: string, estimated_end: string|null, source: string}>
     */
    public function getActiveDisruptions(): array
    {
        return Cache::remember('active_disruptions', 120, function () {
            $disruptions = [];

            // TODO: VRS GTFS-RT Service Alerts (awaiting API access)
            // $disruptions = array_merge($disruptions, $this->fetchVrsAlerts());

            // TODO: City news/strike feeds
            // $disruptions = array_merge($disruptions, $this->fetchCityAlerts());

            return $disruptions;
        });
    }

    /**
     * Check if any lines are currently disrupted.
     *
     * @return array<string, string> line => severity
     */
    public function getDisruptedLines(): array
    {
        $disruptions = $this->getActiveDisruptions();
        $lines = [];
        foreach ($disruptions as $d) {
            foreach ($d['affected_lines'] ?? [] as $line) {
                $lines[$line] = $d['severity'];
            }
        }

        return $lines;
    }
}
