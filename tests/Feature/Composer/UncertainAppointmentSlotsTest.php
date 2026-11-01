<?php

use App\Composer\Candidate;
use App\Composer\Constraints;
use App\Composer\Plan;
use App\Composer\PlanSlot;
use App\Composer\TravelEstimator;
use Carbon\CarbonImmutable;

function uncertainCandidate(string $id, string $type, float $lat, float $lng, ?CarbonImmutable $start = null, bool $routable = true, bool $durationKnown = true): Candidate
{
    return new Candidate(id: $id, type: $type, name: $id, lat: $lat, lng: $lng, veedel: null, category: $type, outdoor: false,
        typicalDurationMin: $durationKnown ? 30 : 0, costTier: 'unknown', opensAt: null, closesAt: null, fixedStart: $start,
        swappable: $type !== 'appointment', routable: $routable, durationKnown: $durationKnown);
}

beforeEach(function () {
    $this->start = CarbonImmutable::parse('2026-09-08T11:00:00+02:00');
    $this->constraints = Constraints::fromArray(['window_start' => '2026-09-08T10:00:00+02:00', 'window_end' => '2026-09-08T15:00:00+02:00']);
});

test('legs next to an unroutable appointment carry no journey minutes and no leave-by', function () {
    $before = uncertainCandidate('spot:1', 'spot', 50.94, 6.95);
    $appointment = uncertainCandidate('appointment:a', 'appointment', NAN, NAN, $this->start, routable: false);
    $after = uncertainCandidate('spot:2', 'spot', 50.93, 6.96);
    $slots = (new Plan($this->constraints, [
        new PlanSlot($before, $this->start->subHour(), $this->start->subMinutes(30), 0),
        new PlanSlot($appointment, $this->start, $this->start->addMinutes(30), 12),
        new PlanSlot($after, $this->start->addMinutes(40), $this->start->addMinutes(70), 9),
    ]))->toArray()['slots'];
    expect($slots[0]['travel_known'])->toBeTrue()
        ->and($slots[1])->toMatchArray(['travel_known' => false, 'travel_min_from_previous' => null, 'leave_by' => null, 'lat' => null, 'routable' => false])
        ->and($slots[2])->toMatchArray(['travel_known' => false, 'travel_min_from_previous' => null, 'leave_by' => null]);
    expect(json_encode($slots))->toBeString();
    expect(collect(Plan::appointmentNotices($slots))->pluck('code')->all())->toBe(['appointment_location_unroutable']);
    expect((new TravelEstimator)->minutesBetween(50.9, 6.9, NAN, NAN))->toBe(0);
});

test('the stop after an appointment of unknown length is flagged as a possible overlap, not a conflict', function () {
    $appointment = uncertainCandidate('appointment:a', 'appointment', 50.95, 6.91, $this->start, durationKnown: false);
    $after = uncertainCandidate('spot:2', 'spot', 50.93, 6.96);
    $plan = new Plan($this->constraints, [
        new PlanSlot($appointment, $this->start, $this->start, 15),
        new PlanSlot($after, $this->start->addMinutes(10), $this->start->addMinutes(40), 10),
    ]);
    $slots = $plan->toArray()['slots'];
    expect($slots[0])->toMatchArray(['duration_known' => false, 'end_time' => null, 'duration_label' => null, 'leave_by' => '10:45'])
        ->and($slots[1]['may_overlap_previous'])->toBeTrue()->and($slots[1]['travel_known'])->toBeTrue()
        ->and($plan->toArray()['schedule_feasible'])->toBeTrue();
    expect(collect(Plan::appointmentNotices($slots))->pluck('code')->all())->toBe(['appointment_end_unknown']);
});
