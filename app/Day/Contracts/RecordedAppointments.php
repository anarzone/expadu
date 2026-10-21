<?php

namespace App\Day\Contracts;

use App\Day\DayItem;
use App\Day\PaperworkAppointments;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Bind;

/**
 * The account holder's own recorded Paperwork appointments, as day items.
 * Only what the person recorded: no office guessed from their area, no
 * assumed length.
 */
#[Bind(PaperworkAppointments::class)]
interface RecordedAppointments
{
    /**
     * Appointments starting in [$from, $to), oldest first.
     *
     * @return list<DayItem>
     */
    public function between(User $user, CarbonImmutable $from, CarbonImmutable $to): array;
}
