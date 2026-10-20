<?php

namespace App\Day\Contracts;

use App\Day\DayItem;
use App\Day\UserTaskAppointments;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Bind;

/**
 * The account holder's own recorded Paperwork appointments, as day items.
 * Only what the person recorded: no office guessed from their area, no
 * assumed length. The Paperwork v2 plan timeline replaces the v1 reader.
 */
#[Bind(UserTaskAppointments::class)]
interface RecordedAppointments
{
    /**
     * Appointments starting in [$from, $to), oldest first.
     *
     * @return list<DayItem>
     */
    public function between(User $user, CarbonImmutable $from, CarbonImmutable $to): array;
}
