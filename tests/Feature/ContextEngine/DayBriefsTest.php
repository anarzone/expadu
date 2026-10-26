<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\BriefText;
use App\ContextEngine\BriefWriter;
use App\ContextEngine\DayBriefs;
use App\ContextEngine\ScoredAction;
use App\Models\Event;
use App\Models\EventReminder;
use App\Models\PlannedTrip;
use App\Models\User;
use App\Models\UserPlace;
use App\Notifications\DayBriefNotification;
use App\Support\NotificationThrottle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

// The day's briefs (owner, 26 Oct 2026): none on a quiet day, a morning one on a normal day, and
// the evening before too on an important one; what threatens the day first; through the one gate.

uses()->group('context-engine');

/** Wednesday 11 November 2026, Berlin time. */
function briefAt(string $time, string $date = '2026-11-11'): CarbonImmutable
{
    return CarbonImmutable::parse("{$date} {$time}", 'Europe/Berlin');
}

function briefTrip(User $user, string $leaves, string $title = 'Blue Card appointment', string $date = '2026-11-11', ?string $alerted = null): PlannedTrip
{
    $at = briefAt($leaves, $date);

    return PlannedTrip::query()->create(['user_id' => $user->id, 'date' => $date, 'target_id' => 'plan-'.uniqid(), 'title' => $title,
        'journey' => [], 'remind' => false, 'depart_at' => $at, 'arrive_at' => $at->addMinutes(40), 'alerted_kind' => $alerted]);
}

function briefWork(User $user, string $arriveBy = '09:00'): void
{
    UserPlace::factory()->create(['user_id' => $user->id, 'name' => 'Work', 'category' => 'work', 'arrive_by' => $arriveBy, 'day_mode' => 'weekdays']);
}

function briefEvent(User $user, string $title, string $starts): void
{
    $event = Event::factory()->for($user, 'organiser')->create(['title' => $title, 'title_en' => null, 'location_name' => 'Stadtgarten',
        'starts_at' => "2026-11-11 {$starts}:00", 'ends_at' => '2026-11-11 23:00:00', 'recurrence' => null]);
    EventReminder::query()->create(['user_id' => $user->id, 'event_id' => $event->id, 'occurrence_start' => "2026-11-11 {$starts}:00",
        'offset_minutes' => 60, 'remind_at' => '2026-11-11 11:00:00', 'sent_at' => now()]);
}

beforeEach(function () {
    Notification::fake();
    $this->travelTo(briefAt('05:00'));
    $this->user = User::factory()->create();
    $this->user->updatePushSubscription('https://push.example.test/'.uniqid(), 'key', 'token');
    $this->briefs = app(DayBriefs::class);
});

test('a quiet day has no brief; a normal one has its morning brief 45 minutes before they set off, never before 06:30', function () {
    expect($this->briefs->due($this->user, briefAt('07:30')))->toBeNull();

    // Due at Work at 09:00: the way there, then 45 minutes before it, 07:15.
    briefWork($this->user);
    expect($this->briefs->morningAt($this->user, briefAt('00:00'))->format('H:i'))->toBe('07:15')
        ->and($this->briefs->due($this->user, briefAt('07:00')))->toBeNull()
        ->and($this->briefs->due($this->user, briefAt('07:20'))['kind'])->toBe(DayBriefs::MORNING)
        ->and($this->briefs->due($this->user, briefAt('09:30')))->toBeNull();

    // A way leaving at 07:00 would want it at 06:15: 06:30.
    briefTrip($this->user, '07:00');
    expect($this->briefs->morningAt($this->user, briefAt('00:00'))->format('H:i'))->toBe('06:30');
});

test('an important day has a brief the evening before too, at 19:00, with shops closed when they are', function () {
    // Thursday 12 November: Work and two planned ways, 9.
    briefWork($this->user);
    briefTrip($this->user, '08:00', 'Ausländerbehörde', '2026-11-12');
    briefTrip($this->user, '18:00', 'Dinner', '2026-11-12');

    expect($this->briefs->due($this->user, briefAt('18:55')))->toBeNull();
    $due = $this->briefs->due($this->user, briefAt('19:05'));
    expect($due['kind'])->toBe(DayBriefs::EVENING)->and($due['day']->toDateString())->toBe('2026-11-12');

    // The evening before a Sunday: shops are closed tomorrow.
    expect(collect($this->briefs->items($this->user, briefAt('00:00', '2026-11-15'), DayBriefs::EVENING))->pluck('text')->all())
        ->toContain('Shops are closed tomorrow (Sunday).');
});

test('the brief says what threatens the day first, then the day in order, then how many more', function () {
    $this->travelTo(briefAt('07:00'));
    briefTrip($this->user, '17:30', 'Dinner');
    briefTrip($this->user, '08:10', 'Ausländerbehörde', alerted: 'cancelled');
    briefEvent($this->user, 'Jazz im Stadtgarten', '12:00');
    app(ActionBus::class)->insert($this->user, new ScoredAction(type: 'transit_disruption', actionKey: "disruption:1:user:{$this->user->id}", score: 70.0,
        severity: 'major', validUntil: now()->addHours(3), deliverChannels: [ScoredAction::CHANNEL_ALERT_PAGE],
        payload: ['disruption_id' => '1', 'lines' => ['13'], 'stops_affected' => ['Sülzgürtel'], 'on_user_line' => true], createdAt: now()));

    $items = $this->briefs->items($this->user, briefAt('00:00'), DayBriefs::MORNING);
    expect(array_column($items, 'text'))->toBe([
        'Your 08:10 way to Ausländerbehörde is cancelled.',
        'Line 13 disrupted at Sülzgürtel.',
        '12:00 Jazz im Stadtgarten at Stadtgarten.',
        '17:30 leave for Dinner.',
    ])->and(BriefText::template($items))->toBe([
        'Your 08:10 way to Ausländerbehörde is cancelled.',
        'Line 13 disrupted at Sülzgürtel.',
        '12:00 Jazz im Stadtgarten at Stadtgarten.',
        'And 1 more in Expadu.',
    ]);
});

test('the brief goes once, through the gate; one held for the gap between pushes goes on a later run', function () {
    briefWork($this->user);
    $this->travelTo(briefAt('07:20'));
    briefTrip($this->user, '17:30', 'Dinner');
    NotificationThrottle::recordSent($this->user);

    $this->artisan('notifications:send-briefs')->assertSuccessful();
    Notification::assertNothingSent();

    $this->travelTo(briefAt('07:40'));
    $this->artisan('notifications:send-briefs')->assertSuccessful();
    $this->artisan('notifications:send-briefs')->assertSuccessful();
    Notification::assertSentToTimes($this->user, DayBriefNotification::class, 1);
    Notification::assertSentTo($this->user, DayBriefNotification::class, fn (DayBriefNotification $n) => $n->toArray($this->user)['title'] === 'Your Wednesday'
        && $n->toArray($this->user)['body'] === '17:30 leave for Dinner.');
});

test('no brief for someone who turned briefs off', function () {
    briefWork($this->user);
    briefTrip($this->user, '17:30', 'Dinner');
    $this->user->notificationPreference()->create(['preferences' => ['brief' => false]]);
    $this->travelTo(briefAt('07:20'));

    $this->artisan('notifications:send-briefs')->assertSuccessful();
    $this->travelTo(briefAt('07:45'));
    $this->artisan('notifications:send-briefs')->assertSuccessful();
    Notification::assertNothingSent();
});

test('the AI’s brief keeps only lines its items hold, never sends Paperwork, and falls back when none hold', function () {
    $items = [
        ['id' => 'i1', 'kind' => 'trip_change', 'text' => 'Your 08:10 way to Ausländerbehörde is cancelled.', 'facts' => 'Planned way to "Ausländerbehörde", leaving 08:10, is cancelled.', 'own' => true, 'rank' => 0],
        ['id' => 'i2', 'kind' => 'disruption', 'text' => 'Line 13 disrupted.', 'facts' => 'KVB disruption on line 13, one of their lines (major).', 'own' => false, 'rank' => 0],
        ['id' => 'i3', 'kind' => 'paperwork', 'text' => 'Your Paperwork needs attention.', 'facts' => 'Their Paperwork needs attention.', 'own' => true, 'rank' => 20000],
    ];
    $sent = null;
    $reply = function (array $lines) use (&$sent): Closure {
        return function (array $request) use ($lines, &$sent): array {
            $sent = $request;

            return ['content' => [['type' => 'tool_use', 'name' => 'brief', 'input' => ['lines' => $lines]]]];
        };
    };

    $lines = app(BriefWriter::class)->compose($items, briefAt('00:00'), DayBriefs::MORNING, $reply([
        ['text' => 'Your 08:10 way to Ausländerbehörde is cancelled.', 'uses' => ['i1']],
        ['text' => 'Line 13 is disrupted.', 'uses' => ['i2']],
        // A time and a reason no item says.
        ['text' => 'Leave by 07:30 to beat the strike.', 'uses' => ['i2']],
    ]));
    expect($lines)->toBe(['Your 08:10 way to Ausländerbehörde is cancelled.', 'Line 13 is disrupted.', 'Your Paperwork needs attention.'])
        ->and($sent['messages'][0]['content'])->not->toContain('Paperwork');

    // Two items that share nothing said as one ("so your way is hit"), or a line that doesn't hold: the template says them.
    expect(app(BriefWriter::class)->compose($items, briefAt('00:00'), DayBriefs::MORNING, $reply([
        ['text' => 'Your 08:10 way to Ausländerbehörde is cancelled, and line 13 is disrupted.', 'uses' => ['i1', 'i2']],
    ])))->toBeNull()
        ->and(app(BriefWriter::class)->compose($items, briefAt('00:00'), DayBriefs::MORNING, $reply([['text' => 'Line 7 is down all day.', 'uses' => ['i2']]])))->toBeNull()
        // A person's day goes to no AI until they can turn it on.
        ->and(app(BriefWriter::class)->write($this->user, $items, briefAt('00:00'), DayBriefs::MORNING))->toBeNull();
});
