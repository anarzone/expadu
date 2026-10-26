<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Home\TodayNeeds;
use App\Models\PlannedTrip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Tests\Support\ReviewedHomePlan;

// "Needs you" on Today (owner, 26 Oct 2026): the same items push and the brief say, in their
// order, through the same gate, but never held back by quiet hours or the day's budget.

function needsAt(string $time, string $date = '2026-11-11'): CarbonImmutable
{
    return CarbonImmutable::parse("{$date} {$time}", 'Europe/Berlin');
}

function needsTrip(User $user, string $leaves, ?string $alerted = null, string $title = 'Blue Card appointment'): PlannedTrip
{
    $at = needsAt($leaves);

    return PlannedTrip::query()->create(['user_id' => $user->id, 'date' => '2026-11-11', 'target_id' => 'plan-'.uniqid(), 'title' => $title,
        'journey' => [], 'remind' => false, 'depart_at' => $at, 'arrive_at' => $at->addMinutes(40), 'alerted_kind' => $alerted]);
}

beforeEach(function () {
    Notification::fake();
    $this->travelTo(needsAt('22:30'));
    $this->user = User::factory()->onboarded()->create();
});

test('what needs them, leave now first, then the brief’s order, in quiet hours and over the day’s budget', function () {
    needsTrip($this->user, '22:40', title: 'Night bus home');
    needsTrip($this->user, '23:30', 'cancelled', 'Late shift');
    needsTrip($this->user, '23:50', title: 'Not yet');
    app(ActionBus::class)->insert($this->user, new ScoredAction(type: 'transit_disruption', actionKey: "disruption:1:user:{$this->user->id}", score: 70.0,
        severity: 'major', validUntil: now()->addHours(2), deliverChannels: [ScoredAction::CHANNEL_ALERT_PAGE],
        payload: ['disruption_id' => '1', 'lines' => ['13'], 'stops_affected' => [], 'on_user_line' => true], createdAt: now()));
    Redis::set("notif_throttle:day:{$this->user->id}:2026-11-11", 99);

    $needs = app(TodayNeeds::class)->for($this->user);

    expect(array_map(fn (array $n): array => [$n['kind'], $n['severity'], $n['line'], $n['why']], $needs))->toBe([
        ['leave_now', 'major', 'Leave now for Night bus home.', 'Your way leaves at 22:40.'],
        ['trip_change', 'critical', 'Your 23:30 way to Late shift is cancelled.', null],
        ['disruption', 'major', 'Line 13 disrupted.', 'On one of your lines.'],
    ])->and($needs[0]['link'])->toStartWith('/timetable?view=journey&activity=plan-')
        // The same item keeps its id, which says nothing of what it is.
        ->and(app(TodayNeeds::class)->for($this->user)[1]['id'])->toBe($needs[1]['id'])
        ->and($needs[1]['id'])->toMatch('/^[0-9a-f]{16}$/');
});

test('what they turned off for push isn’t on Today either', function () {
    needsTrip($this->user, '23:30', 'late');
    $this->user->notificationPreference()->create(['preferences' => ['transit' => false]]);

    expect(app(TodayNeeds::class)->for($this->user->fresh()))->toBe([]);
});

test('overdue Paperwork needs them, saying only that', function () {
    $this->travelTo(needsAt('10:00'));
    ReviewedHomePlan::activate($this->user, ['fixture.entry' => ['title' => 'Synthetic preparation']], ['arrival_date' => '2026-06-01', 'visa_expires_at' => '2026-11-10']);

    expect(app(TodayNeeds::class)->for($this->user))->toMatchArray([[
        'id' => app(TodayNeeds::class)->for($this->user)[0]['id'], 'kind' => 'paperwork', 'severity' => 'critical',
        'line' => 'Your Paperwork needs attention.', 'why' => null, 'link' => '/bureaucracy',
    ]]);
});

test('Today has it in the day’s deferred group', function () {
    needsTrip($this->user, '23:30', 'cancelled', 'Late shift');

    $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->assertInertia(fn ($page) => $page
        ->missing('needs')
        ->loadDeferredProps('day', fn ($reload) => $reload->where('needs.0.kind', 'trip_change')));
});
