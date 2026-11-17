<?php

use App\Day\Busy;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Their own calendar on Today: the phone sends only when they're busy, today and tomorrow, with
 * each look. No idea is offered then, the day line says so, and nothing of it is kept.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 08:40', 'Europe/Berlin'));
});

function eventAt(string $title, string $from, string $to): Event
{
    return Event::factory()->create([
        'title' => $title, 'category' => 'music',
        'starts_at' => CarbonImmutable::parse($from, 'Europe/Berlin')->utc(),
        'ends_at' => CarbonImmutable::parse($to, 'Europe/Berlin')->utc(),
    ]);
}

function todayWithBusy(User $user, ?string $busy)
{
    $request = test()->withToken($user->createToken('iPhone')->plainTextToken);

    return ($busy === null ? $request : $request->withHeader(Busy::HEADER, $busy))->getJson('/api/v1/today')->assertOk();
}

test('only well-formed times today and tomorrow count, soonest first', function () {
    $now = CarbonImmutable::now('Europe/Berlin');
    $busy = Busy::parse('2026-11-18T14:00/2026-11-18T17:00, 2026-11-18T09:00/2026-11-18T12:30,nonsense,2026-11-25T09:00/2026-11-25T10:00,2026-11-18T12:00/2026-11-18T11:00,2026-11-19T23:00/2026-11-20T02:00', $now);

    $times = array_map(fn (array $span) => [CarbonImmutable::createFromTimestamp($span[0], 'Europe/Berlin')->format('d H:i'), CarbonImmutable::createFromTimestamp($span[1], 'Europe/Berlin')->format('d H:i')], $busy);

    expect($times)->toBe([['18 09:00', '18 12:30'], ['18 14:00', '18 17:00'], ['19 23:00', '19 23:59']]);
});

test('no idea is offered while they’re busy, and the day line says until when', function () {
    $user = User::factory()->onboarded()->create();
    eventAt('Lunch concert', '2026-11-18 12:00', '2026-11-18 13:00');
    eventAt('Evening gig', '2026-11-18 19:00', '2026-11-18 21:00');

    $free = todayWithBusy($user, null);
    expect(collect($free->json('ideas'))->pluck('title')->all())->toContain('Lunch concert');

    $busy = todayWithBusy($user, '2026-11-18T08:30/2026-11-18T12:30,2026-11-18T12:35/2026-11-18T17:00');
    expect(collect($busy->json('ideas'))->pluck('title')->all())->toBe(['Evening gig'])
        // Two stretches five minutes apart read as one.
        ->and($busy->json('day_line.text'))->toStartWith('Busy until 17:00.')
        ->and(collect($busy->json('day_line.parts'))->where('strong', true)->pluck('text')->all())->toContain('17:00');
});

test('🔒 nothing of their calendar is kept', function () {
    $user = User::factory()->onboarded()->create();
    eventAt('Evening gig', '2026-11-18 19:00', '2026-11-18 21:00');

    todayWithBusy($user, '2026-11-18T09:00/2026-11-18T12:30');

    // No table holds the times it was sent, in any text column.

    $tables = collect(DB::select("select table_name from information_schema.columns where data_type in ('text','json','jsonb','character varying') and table_schema = 'public' group by table_name"))->pluck('table_name');
    foreach ($tables as $table) {
        $hit = collect(DB::select("select 1 from \"{$table}\" t where t::text like '%12:30%' limit 1"));
        expect($hit)->toBeEmpty("{$table} keeps a busy time");
    }
});
