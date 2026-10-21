<?php

namespace App\Personalisation;

use App\Composer\CategoryHours;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The coarse context a signal is kept under: daypart, weekday or weekend,
 * who it was with when that is known, and a wet/dry bucket when a fresh
 * reading is cached. Never a location.
 */
final class SignalContext
{
    /** Composer's companion values (Constraints::$companions). */
    private const COMPANIONS = ['alone', 'partner', 'friends', 'kids'];

    private const WET = ['rain', 'sleet', 'snow', 'hail', 'thunderstorm'];

    public function __construct(private WeatherService $weather) {}

    /**
     * @param  CarbonInterface|null  $at  when the item is for (a plan stop's start, an event's occurrence); null = now
     * @return array<string, string>
     */
    public function at(?CarbonInterface $at = null, ?string $companions = null): array
    {
        $local = CarbonImmutable::instance($at ?? now())->setTimezone('Europe/Berlin');
        $context = [
            'daypart' => CategoryHours::daypart($local),
            'week' => $local->isWeekend() ? 'weekend' : 'weekday',
        ];

        if (in_array($companions, self::COMPANIONS, true)) {
            $context['companions'] = $companions;
        }

        // Today's weather says nothing about a stop next Saturday.
        if ($at === null && ($icon = $this->weather->cachedCurrent()['icon'] ?? null) !== null) {
            $context['weather'] = in_array($icon, self::WET, true) ? 'wet' : 'dry';
        }

        return $context;
    }
}
