<?php

namespace App\Bureaucracy\Facts;

use Carbon\Carbon;

final class CalendarDate
{
    public static function historical(mixed $value): ?Carbon
    {
        $date = self::parse($value);

        return $date !== null && $date->lessThanOrEqualTo(Carbon::today()) ? $date : null;
    }

    public static function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m-d', $value);
    }
}
