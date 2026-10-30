<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Home\TodayNeeds;
use App\Models\PlannedTrip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

    expect(array_map(fn (array $n): array => [$n['tier'], $n['kind'], $n['severity'], $n['headline'], $n['detail']], $needs['items']))->toBe([
        [1, 'leave_now', 'conflict', 'Leave now for Night bus home.', 'Your way leaves at 22:40.'],
        [1, 'trip_change', 'conflict', 'Your 23:30 way to Late shift is cancelled.', null],
        [1, 'disruption', 'conflict', 'Line 13 disrupted.', 'On one of your lines.'],
    ])->and($needs['items'][0]['href'])->toStartWith('/travel?view=journey&activity=plan-')
        ->and($needs['items'][0]['at'])->toBe(needsAt('22:40')->toIso8601String())
        ->and($needs['checked_at'])->toBe(needsAt('22:30')->toIso8601String())
        // The same item keeps its id, which says nothing of what it is.
        ->and(app(TodayNeeds::class)->for($this->user)['items'][1]['id'])->toBe($needs['items'][1]['id'])
        ->and($needs['items'][1]['id'])->toMatch('/^[0-9a-f]{16}$/');
});

test('what they turned off for push isn’t on Today either', function () {
    needsTrip($this->user, '23:30', 'late');
    $this->user->notificationPreference()->create(['preferences' => ['transit' => false]]);

    // Checked, and nothing: an empty list, not null.
    expect(app(TodayNeeds::class)->for($this->user->fresh())['items'])->toBe([]);
});

test('overdue Paperwork needs them, saying only that', function () {
    $this->travelTo(needsAt('10:00'));
    ReviewedHomePlan::activate($this->user, ['fixture.entry' => ['title' => 'Synthetic preparation']], ['arrival_date' => '2026-06-01', 'visa_expires_at' => '2026-11-10']);

    $items = app(TodayNeeds::class)->for($this->user)['items'];
    expect($items)->toHaveCount(1)->and($items[0])->toMatchArray([
        'tier' => 1, 'kind' => 'paperwork', 'headline' => 'Your Paperwork needs attention.', 'detail' => null, 'at' => null,
        'severity' => 'conflict', 'href' => '/paperwork',
    ]);
});

test('Today has it in its first response', function () {
    needsTrip($this->user, '23:30', 'cancelled', 'Late shift');

    $this->actingAs($this->user)->get(route('today'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('needsYou.items.0.kind', 'trip_change')->has('needsYou.checked_at'));
});

test('it’s worked out once and served from the cache until something in the day changes; leaving now follows the clock', function () {
    $late = needsTrip($this->user, '23:30', 'cancelled', 'Late shift');
    $kinds = fn () => array_column(app(TodayNeeds::class)->for($this->user->fresh())['items'], 'kind');
    expect($kinds())->toBe(['trip_change']);

    // A change that tells nothing (straight to the table): the kept answer stands.
    DB::table('planned_trips')->where('id', $late->id)->update(['alerted_kind' => null]);
    expect($kinds())->toBe(['trip_change']);

    // A change to the day (a planned way saved) works it out again.
    needsTrip($this->user, '23:55', title: 'Not yet');
    expect($kinds())->toBe([]);

    // Leaving now isn't kept: a way leaving in 20 minutes says so 6 minutes on.
    needsTrip($this->user, '22:50', title: 'Night bus home');
    expect($kinds())->toBe([]);
    $this->travelTo(needsAt('22:36'));
    expect($kinds())->toBe(['leave_now']);
});

test('turning a kind off is seen at once', function () {
    needsTrip($this->user, '23:30', 'cancelled', 'Late shift');
    expect(app(TodayNeeds::class)->for($this->user)['items'])->toHaveCount(1);

    $this->user->notificationPreference()->create(['preferences' => ['transit' => false]]);
    expect(app(TodayNeeds::class)->for($this->user->fresh())['items'])->toBe([]);
});
