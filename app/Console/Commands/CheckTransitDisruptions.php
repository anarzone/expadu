<?php

namespace App\Console\Commands;

use App\Events\Context\TransitDisruptionDetected;
use App\Services\DisruptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckTransitDisruptions extends Command
{
    protected $signature = 'transit:check-disruptions';

    protected $description = 'Check for new transit disruptions and emit context events';

    public function handle(DisruptionService $disruptionService): int
    {
        $disruptions = $disruptionService->getLineDisruptions();

        if (empty($disruptions)) {
            $this->info('No disruptions found.');

            return self::SUCCESS;
        }

        // Build current disruption IDs
        $currentIds = collect($disruptions)->pluck('id')->filter()->all();
        $seenIds = Cache::get('disruption:seen_ids', []);

        // Find new disruptions
        $newIds = array_diff($currentIds, $seenIds);

        if (empty($newIds)) {
            $this->info('No new disruptions.');

            return self::SUCCESS;
        }

        // Cache current IDs for next run
        Cache::put('disruption:seen_ids', $currentIds, now()->addHours(6));

        // Get new disruptions with their affected lines
        $newDisruptions = collect($disruptions)
            ->filter(fn ($d) => in_array($d['id'] ?? null, $newIds));

        // Per-user matching, scoring, and push delivery happen in
        // TransitDisruptionEvaluator (queued listener) via the ActionBus.
        foreach ($newDisruptions as $d) {
            event(new TransitDisruptionDetected(
                disruptionId: (int) ($d['id'] ?? 0),
                lines: array_values(array_map('strval', $d['affected_lines'] ?? [])),
                stopsAffected: array_values(array_map('strval', $d['stops_affected'] ?? [])),
                severity: (string) ($d['severity'] ?? 'minor'),
                bbox: $d['bbox'] ?? null,
                expiresAt: ! empty($d['expires_at']) ? CarbonImmutable::parse($d['expires_at']) : null,
            ));
        }

        $this->info('Emitted '.count($newIds).' new disruption event(s).');

        Log::info('Transit disruption events emitted', [
            'new_disruptions' => count($newIds),
        ]);

        return self::SUCCESS;
    }
}
