<?php

use App\Transit\NearbyStation;
use App\Transit\TripLive;
use Carbon\CarbonImmutable;

/** A board's run as NearbyStation reads it. */
function liveRun(string $scheduled, int $delay = 0, bool $cancelled = false): array
{
    $at = CarbonImmutable::parse($scheduled)->addMinutes($delay);

    return ['id' => 'r'.$scheduled, 'at' => $at->toIso8601String(), 'time' => $at->format('H:i'), 'scheduled_at' => $scheduled,
        'delay' => $delay, 'cancelled' => $cancelled, 'time_basis' => 'realtime'];
}

/** Appellhofplatz → 18 → Ebertplatz → walk → 12 → Merkenich Mitte → walk home. */
function liveJourney(): array
{
    $leg = fn (string $mode, ?string $line, string $from, string $to, string $depart, string $arrive) => [
        'mode' => $mode, 'line' => $line, 'from' => ['name' => $from], 'to' => ['name' => $to],
        'depart_at' => $depart, 'arrive_at' => $arrive, 'scheduled_depart_at' => $depart, 'scheduled_arrive_at' => $arrive,
        'depart_time' => substr($depart, 11, 5), 'arrive_time' => substr($arrive, 11, 5),
    ];

    return [
        'arrive_at' => '2026-11-12T20:24:00+01:00', 'arrive_time' => '20:24',
        'legs' => [
            $leg('tram', '18', 'Köln Appellhofplatz', 'Köln Ebertplatz', '2026-11-12T19:44:00+01:00', '2026-11-12T19:50:00+01:00'),
            $leg('walk', null, 'Köln Ebertplatz', 'Köln Ebertplatz', '2026-11-12T19:50:00+01:00', '2026-11-12T19:53:00+01:00'),
            $leg('tram', '12', 'Köln Ebertplatz', 'Köln Merkenich Mitte', '2026-11-12T19:55:00+01:00', '2026-11-12T20:19:00+01:00'),
            $leg('walk', null, 'Köln Merkenich Mitte', 'Home', '2026-11-12T20:19:00+01:00', '2026-11-12T20:24:00+01:00'),
        ],
    ];
}

function boardsWith(array $byStop): NearbyStation
{
    $boards = Mockery::mock(NearbyStation::class);
    $boards->shouldReceive('named')->andReturnUsing(fn (string $name) => isset($byStop[$name])
        ? ['lines' => [['line' => $byStop[$name][0], 'dirs' => [['next' => $byStop[$name][1], 'later' => []]]]]]
        : null);

    return $boards;
}

test('a late tram moves its own times and the walk after it, never the next tram', function () {
    $boards = boardsWith(['Appellhofplatz' => ['18', liveRun('2026-11-12T19:44:00+01:00', delay: 4)]]);

    $live = (new TripLive($boards))->apply(liveJourney(), CarbonImmutable::parse('2026-11-12T19:40:00+01:00'));

    expect($live['legs'][0]['depart_time'])->toBe('19:48')
        ->and($live['legs'][0]['delay_min'])->toBe(4)
        ->and($live['legs'][1]['depart_time'])->toBe('19:54')
        ->and($live['legs'][2]['depart_time'])->toBe('19:55')
        ->and($live['arrive_time'])->toBe('20:24');
});

test('a cancelled tram is marked, and a ride the board does not list keeps its times', function () {
    $boards = boardsWith(['Appellhofplatz' => ['18', liveRun('2026-11-12T19:44:00+01:00', cancelled: true)]]);

    $live = (new TripLive($boards))->apply(liveJourney(), CarbonImmutable::parse('2026-11-12T19:40:00+01:00'));

    expect($live['legs'][0]['cancelled'])->toBeTrue()
        ->and($live['legs'][0]['depart_time'])->toBe('19:44')
        ->and($live['legs'][2]['depart_time'])->toBe('19:55')
        ->and($live['legs'][2])->not->toHaveKey('delay_min');
});

test('aboard, the delay is read where they get off, and the arrival moves with it', function () {
    $boards = boardsWith(['Merkenich Mitte' => ['12', liveRun('2026-11-12T20:19:00+01:00', delay: 3)]]);

    $live = (new TripLive($boards))->apply(liveJourney(), CarbonImmutable::parse('2026-11-12T20:05:00+01:00'));

    expect($live['legs'][2]['arrive_time'])->toBe('20:22')
        ->and($live['legs'][3]['arrive_time'])->toBe('20:27')
        ->and($live['arrive_time'])->toBe('20:27');
});

test('a planned way is live only where a board says so, and a delay only the router knew of is set aside', function () {
    $boards = boardsWith(['Appellhofplatz' => ['18', liveRun('2026-11-12T19:44:00+01:00', delay: 2)]]);
    $journey = liveJourney();
    // The router thought the 12 at Ebertplatz was 2 minutes late; its board isn't read.
    $journey['legs'][2] = array_merge($journey['legs'][2], ['realtime' => true, 'delay_min' => 2,
        'depart_at' => '2026-11-12T19:57:00+01:00', 'depart_time' => '19:57']);

    $live = (new TripLive($boards))->confirm($journey, CarbonImmutable::parse('2026-11-12T19:40:00+01:00'));

    expect($live['legs'][0])->toMatchArray(['realtime' => true, 'delay_min' => 2, 'depart_time' => '19:46'])
        ->and($live['legs'][2])->toMatchArray(['realtime' => false, 'delay_min' => null, 'depart_time' => '19:55']);
});
