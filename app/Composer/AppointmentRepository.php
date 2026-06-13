<?php

namespace App\Composer;

use App\Models\User;
use App\Models\UserTask;
use App\Services\BuergeramtService;
use App\Services\GeocodingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The composer's other impure boundary: the user's booked appointments
 * inside the window become fixed-time, non-swappable Candidates. This is
 * the cross-feature moment nothing else can copy — the app that knows your
 * Saturday also knows your Tuesday Ausländerbehörde appointment, and plans
 * the leisure around it. Office coordinates are geocoded once from the
 * static office address and cached forever; if the geocoder is down the
 * anchor still shows (sans travel buffer) and take-me-there re-geocodes on
 * tap, exactly like the bureaucracy office cards.
 */
class AppointmentRepository
{
    private const COLOGNE_CENTRE = [50.9375, 6.9603];

    public function __construct(
        private readonly BuergeramtService $offices,
        private readonly GeocodingService $geocoder,
    ) {}

    /**
     * @return list<Candidate>
     */
    public function within(User $user, Constraints $constraints): array
    {
        return UserTask::query()
            ->where('user_id', $user->id)
            ->whereNotNull('appointment_at')
            ->whereBetween('appointment_at', [$constraints->windowStart, $constraints->windowEnd])
            ->with('task')
            ->get()
            ->map(fn (UserTask $userTask) => $this->toCandidate($userTask, $user))
            ->all();
    }

    private function toCandidate(UserTask $userTask, User $user): Candidate
    {
        $task = $userTask->task;
        $office = $this->offices->officeForTask($task?->booking_service_key, $user->veedel);
        [$lat, $lng] = $this->locate($office);

        $documentCount = is_array($task?->documents_required) ? count($task->documents_required) : 0;

        return new Candidate(
            id: "appointment:{$userTask->id}",
            type: 'appointment',
            name: $task?->title ?? 'Your appointment',
            lat: $lat,
            lng: $lng,
            veedel: null,
            category: 'appointment',
            outdoor: false,
            typicalDurationMin: 45,
            costTier: 'free',
            opensAt: null,
            closesAt: null,
            fixedStart: CarbonImmutable::parse($userTask->appointment_at)->setTimezone('Europe/Berlin'),
            swappable: false,
            subtitle: $this->subtitle($office, $documentCount),
        );
    }

    /**
     * @param  array{name: string, address: string}|null  $office
     */
    private function subtitle(?array $office, int $documentCount): ?string
    {
        $parts = [];
        if ($office !== null) {
            $parts[] = $office['name'];
        }
        if ($documentCount > 0) {
            $parts[] = $documentCount.' '.($documentCount === 1 ? 'document' : 'documents').' on your checklist';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @param  array{name: string, address: string}|null  $office
     * @return array{0: float, 1: float}
     */
    private function locate(?array $office): array
    {
        $address = $office['address'] ?? null;
        if (! is_string($address) || $address === '') {
            return self::COLOGNE_CENTRE;
        }

        return Cache::rememberForever('office_geo:'.md5($address), function () use ($address) {
            try {
                $hit = $this->geocoder->search($address.', Köln')[0] ?? null;

                if (is_array($hit) && isset($hit['lat'], $hit['lng'])) {
                    return [(float) $hit['lat'], (float) $hit['lng']];
                }
            } catch (\Throwable) {
                // Fall through to centre; navigation still works on tap.
            }

            return self::COLOGNE_CENTRE;
        });
    }
}
