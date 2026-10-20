<?php

namespace App\Day;

use App\Day\Contracts\RecordedAppointments;
use App\Models\User;
use App\Models\UserTask;
use Carbon\CarbonImmutable;

/**
 * v1 Paperwork: a task's appointment time is all that is recorded. Its place
 * and length aren't, so the item says so instead of borrowing the district
 * office the area suggests (the person may have booked any office). The trip
 * to it reads "unavailable" until Paperwork v2 records where it is.
 */
class UserTaskAppointments implements RecordedAppointments
{
    public function between(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return UserTask::query()
            ->where('user_id', $user->id)
            ->whereNotNull('appointment_at')
            ->where('appointment_at', '>=', $from)
            ->where('appointment_at', '<', $to)
            ->with('task:id,title')
            ->orderBy('appointment_at')
            ->get()
            ->map(fn (UserTask $userTask) => new DayItem(
                id: "appointment:{$userTask->id}",
                kind: 'appointment',
                source: 'paperwork',
                title: $userTask->task?->title ?? 'Your appointment',
                startAt: CarbonImmutable::parse($userTask->appointment_at)->setTimezone('Europe/Berlin'),
                endAt: null,
                official: true,
                fixed: true,
            ))
            ->all();
    }
}
