<?php

namespace App\Console\Commands;

use App\Events\Context\UserContextChanged;
use App\Models\User;
use App\Notifications\LeaveByReminderNotification;
use App\Services\DisruptionService;
use App\Services\GermanHolidayService;
use App\Services\GtfsDepartureService;
use App\Services\RecommendationService;
use App\Services\WeatherService;
use App\Support\NotificationThrottle;
use App\Support\RedisLogger;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendLeaveByReminders extends Command
{
    protected $signature = 'commute:send-leaveby-reminders';

    protected $description = 'Send proactive leave-by push notifications before users need to leave for work/course/etc';

    private const REMINDER_LEAD_MINUTES = 15;

    public function handle(
        RecommendationService $recommendationService,
        WeatherService $weatherService,
        DisruptionService $disruptionService,
        GtfsDepartureService $gtfsService,
    ): int {
        // Skip entirely on public holidays
        if (app(GermanHolidayService::class)->isHoliday()) {
            $this->info('Public holiday — skipping leave-by reminders.');

            return self::SUCCESS;
        }

        $notifiedCount = 0;

        User::whereNotNull('onboarded_at')->chunk(100, function ($users) use (
            $recommendationService,
            $weatherService,
            $disruptionService,
            $gtfsService,
            &$notifiedCount,
        ) {
            foreach ($users as $user) {
                $notifiedCount += $this->processUser(
                    $user,
                    $recommendationService,
                    $weatherService,
                    $disruptionService,
                    $gtfsService,
                );
            }
        });

        $this->info("Sent {$notifiedCount} leave-by reminder(s).");

        if ($notifiedCount > 0) {
            Log::info('Leave-by reminders sent', ['count' => $notifiedCount]);
        }

        return self::SUCCESS;
    }

    private function processUser(
        User $user,
        RecommendationService $recommendationService,
        WeatherService $weatherService,
        DisruptionService $disruptionService,
        GtfsDepartureService $gtfsService,
    ): int {
        // Get active places with arrive_by for today (exclude home)
        $places = $user->places()
            ->whereNotNull('arrive_by')
            ->where('category', '!=', 'home')
            ->get()
            ->filter(fn ($p) => $p->isActiveToday());

        if ($places->isEmpty()) {
            return 0;
        }

        // Get home as origin
        $home = $user->places()->where('category', 'home')->first();
        $homeLat = $home?->lat ? (float) $home->lat : null;
        $homeLng = $home?->lng ? (float) $home->lng : null;

        if (! $homeLat || ! $homeLng) {
            return 0;
        }

        // Fetch weather and disruptions once per user
        $weather = $weatherService->getCurrentWeather($homeLat, $homeLng);
        $forecast = $weatherService->getForecast($homeLat, $homeLng);
        $disruptedLines = $disruptionService->getDisruptedLines();

        // Get transit lines near home for disruption check
        $departures = $gtfsService->getDeparturesNearby($homeLat, $homeLng, 4);
        $userTransitLines = array_column($departures['departures'] ?? [], 'line');

        $sent = 0;

        foreach ($places as $place) {
            $sent += $this->processPlace(
                $user, $place, $homeLat, $homeLng,
                $recommendationService, $weather, $forecast,
                $disruptedLines, $userTransitLines,
            );
        }

        return $sent;
    }

    private function processPlace(
        User $user,
        mixed $place,
        float $homeLat,
        float $homeLng,
        RecommendationService $recommendationService,
        array $weather,
        array $forecast,
        array $disruptedLines,
        array $userTransitLines,
    ): int {
        $placeLat = (float) $place->lat;
        $placeLng = (float) $place->lng;
        $arriveBy = Carbon::parse($place->arrive_by)->format('H:i');

        // Calculate bike time for mode decision
        $bikeTime = max(5, (int) round((
            6371 * 2 * atan2(
                sqrt(sin(deg2rad($placeLat - $homeLat) / 2) ** 2 + cos(deg2rad($homeLat)) * cos(deg2rad($placeLat)) * sin(deg2rad($placeLng - $homeLng) / 2) ** 2),
                sqrt(1 - (sin(deg2rad($placeLat - $homeLat) / 2) ** 2 + cos(deg2rad($homeLat)) * cos(deg2rad($placeLat)) * sin(deg2rad($placeLng - $homeLng) / 2) ** 2))
            ) / 15
        ) * 60));

        // Determine best mode (disruption-aware)
        $modeResult = $recommendationService->determineBestMode(
            $forecast, $bikeTime, $disruptedLines, $userTransitLines,
        );

        // Calculate leave-by time
        $leaveBy = $recommendationService->calculateLeaveBy(
            $homeLat, $homeLng, $placeLat, $placeLng,
            $arriveBy, $modeResult['mode'], $forecast, $weather,
        );

        if (! $leaveBy) {
            $this->logCalculation($user, $place, $arriveBy, null, null, $modeResult, $weather, $forecast, $disruptedLines, false, 'no_leave_by');

            return 0;
        }

        // Check if we're in the notification window: leave_by - 15min to leave_by
        $leaveAt = Carbon::createFromFormat('H:i', $leaveBy['time']);
        $notifyAt = $leaveAt->copy()->subMinutes(self::REMINDER_LEAD_MINUTES);
        $now = Carbon::now();

        if ($now->lt($notifyAt) || $now->gte($leaveAt)) {
            $this->logCalculation($user, $place, $arriveBy, $leaveBy, $modeResult, $modeResult, $weather, $forecast, $disruptedLines, false, 'outside_window');

            return 0;
        }

        // Dedup: don't re-notify about the same place on the same day
        $dedupKey = "leaveby_notif:{$user->id}:{$place->id}:".now()->format('Y-m-d');
        if (Cache::has($dedupKey)) {
            $this->logCalculation($user, $place, $arriveBy, $leaveBy, $modeResult, $modeResult, $weather, $forecast, $disruptedLines, false, 'already_notified');

            return 0;
        }

        // Check notification preferences
        if (! $user->wantsNotification('transit')) {
            $this->logCalculation($user, $place, $arriveBy, $leaveBy, $modeResult, $modeResult, $weather, $forecast, $disruptedLines, false, 'user_disabled');

            return 0;
        }

        // Check throttle
        if (! NotificationThrottle::canPush($user, 'leave_by_reminder')) {
            $this->logCalculation($user, $place, $arriveBy, $leaveBy, $modeResult, $modeResult, $weather, $forecast, $disruptedLines, false, 'throttled');

            return 0;
        }

        // Build weather context string
        $temp = $weather['temperature'] ?? '?';
        $condition = $weather['condition'] ?? 'Clear';
        $rainStarts = $forecast['rain_starts'] ?? null;

        if ($rainStarts && now()->format('H:i') >= $rainStarts) {
            $weatherContext = "Raining · {$temp}°C";
        } elseif ($rainStarts) {
            $weatherContext = "Rain from {$rainStarts} · {$temp}°C";
        } else {
            $weatherContext = "{$condition} · {$temp}°C";
        }

        $modeEmoji = $modeResult['mode'] === 'bike' ? '🚲' : '🚊';
        $modeName = $modeResult['mode'] === 'bike' ? 'Bike' : 'Tram';

        // Send notification
        Cache::put($dedupKey, true, now()->endOfDay());

        // Dual-emit: LeaveByEvaluator listens for `leave_by_due` and inserts a ScoredAction.
        event(new UserContextChanged(
            userId: $user->id,
            contextType: 'leave_by_due',
            placeId: $place->id,
            at: CarbonImmutable::instance($leaveAt),
        ));

        $user->notify(new LeaveByReminderNotification([
            'place_name' => $place->name,
            'leave_by' => $leaveBy['time'],
            'mode' => $modeName,
            'mode_emoji' => $modeEmoji,
            'weather_context' => $weatherContext,
            'arrive_by' => $arriveBy,
            'travel_min' => $leaveBy['travel_min'],
        ]));

        NotificationThrottle::recordSent($user);

        $this->logCalculation($user, $place, $arriveBy, $leaveBy, $modeResult, $modeResult, $weather, $forecast, $disruptedLines, true, null);

        return 1;
    }

    private function logCalculation(
        User $user,
        mixed $place,
        string $arriveBy,
        ?array $leaveBy,
        ?array $modeResult,
        mixed $modeData,
        array $weather,
        array $forecast,
        array $disruptedLines,
        bool $notified,
        ?string $skipReason,
    ): void {
        // Find disruptions affecting user's transit lines
        $affectingDisruptions = array_filter($disruptedLines, fn ($severity) => in_array($severity, ['major', 'critical'], true));

        RedisLogger::log("leaveby_debug:{$user->id}", [
            'place_id' => $place->id,
            'place_name' => $place->name,
            'arrive_by' => $arriveBy,
            'leave_by' => $leaveBy['time'] ?? null,
            'travel_min' => $leaveBy['travel_min'] ?? null,
            'mode' => $modeResult['mode'] ?? null,
            'mode_reason' => $modeResult['reason'] ?? null,
            'weather' => [
                'condition' => $weather['condition'] ?? null,
                'temp' => $weather['temperature'] ?? null,
                'bike_score' => $forecast['bike_score'] ?? null,
            ],
            'disruptions_affecting' => $affectingDisruptions,
            'notified' => $notified,
            'skip_reason' => $skipReason,
        ]);
    }
}
