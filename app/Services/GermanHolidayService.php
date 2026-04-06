<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * German public holiday detection — national + NRW state holidays.
 *
 * Holidays are calculated per year using fixed dates and Easter-based offsets.
 * NRW-specific: Fronleichnam, Allerheiligen.
 */
class GermanHolidayService
{
    /**
     * Check if a given date is a German public holiday (NRW).
     */
    public function isHoliday(CarbonInterface|Carbon|null $date = null): bool
    {
        $date ??= Carbon::today();

        return $this->getHolidayName($date) !== null;
    }

    /**
     * Check if tomorrow is a holiday.
     */
    public function isHolidayTomorrow(CarbonInterface|Carbon|null $date = null): bool
    {
        $date ??= Carbon::today();

        return $this->isHoliday($date->copy()->addDay());
    }

    /**
     * Check if a date is a non-working day (holiday or weekend).
     */
    public function isNonWorkingDay(CarbonInterface|Carbon|null $date = null): bool
    {
        $date ??= Carbon::today();

        return $date->isWeekend() || $this->isHoliday($date);
    }

    /**
     * Check if tomorrow is a non-working day (shops closed).
     */
    public function isShopsClosedTomorrow(CarbonInterface|Carbon|null $date = null): bool
    {
        $date ??= Carbon::today();
        $tomorrow = $date->copy()->addDay();

        return $tomorrow->isSunday() || $this->isHoliday($tomorrow);
    }

    /**
     * Get the holiday name for a given date, or null if not a holiday.
     */
    public function getHolidayName(CarbonInterface|Carbon|null $date = null): ?string
    {
        $date ??= Carbon::today();
        $key = $date->format('m-d');
        $year = $date->year;

        $holidays = $this->getHolidaysForYear($year);

        return $holidays[$key] ?? null;
    }

    /**
     * Get the next upcoming holiday (including today if it's a holiday).
     *
     * @return array{date: Carbon, name: string}|null
     */
    public function getNextHoliday(?Carbon $from = null): ?array
    {
        $from ??= Carbon::today();
        $holidays = $this->getHolidaysForYear($from->year);

        // Also check next year's holidays for Dec lookups
        $nextYearHolidays = $this->getHolidaysForYear($from->year + 1);

        foreach ($holidays as $key => $name) {
            $holidayDate = Carbon::createFromFormat('m-d', $key)->year($from->year);
            if ($holidayDate->gte($from)) {
                return ['date' => $holidayDate, 'name' => $name];
            }
        }

        // Check next year
        foreach ($nextYearHolidays as $key => $name) {
            $holidayDate = Carbon::createFromFormat('m-d', $key)->year($from->year + 1);

            return ['date' => $holidayDate, 'name' => $name];
        }

        return null;
    }

    /**
     * Get all NRW holidays for a given year, sorted by date.
     *
     * @return array<string, string> key = 'm-d', value = holiday name
     */
    public function getHolidaysForYear(int $year): array
    {
        return Cache::remember("german_holidays:{$year}", 86400, function () use ($year) {
            $easter = Carbon::create($year, 3, 21)->addDays(easter_days($year));

            $holidays = [
                // Fixed national holidays
                '01-01' => 'Neujahrstag',
                '05-01' => 'Tag der Arbeit',
                '10-03' => 'Tag der Deutschen Einheit',
                '12-25' => '1. Weihnachtstag',
                '12-26' => '2. Weihnachtstag',

                // NRW state holidays
                '11-01' => 'Allerheiligen',

                // Easter-based movable holidays
                $easter->copy()->subDays(2)->format('m-d') => 'Karfreitag',
                $easter->format('m-d') => 'Ostersonntag',
                $easter->copy()->addDay()->format('m-d') => 'Ostermontag',
                $easter->copy()->addDays(39)->format('m-d') => 'Christi Himmelfahrt',
                $easter->copy()->addDays(49)->format('m-d') => 'Pfingstsonntag',
                $easter->copy()->addDays(50)->format('m-d') => 'Pfingstmontag',
                $easter->copy()->addDays(60)->format('m-d') => 'Fronleichnam', // NRW
            ];

            ksort($holidays);

            return $holidays;
        });
    }
}
