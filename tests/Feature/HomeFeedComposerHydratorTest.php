<?php

use App\ContextEngine\ScoredAction;
use App\Services\HomeFeedComposer;
use Carbon\CarbonImmutable;

uses()->group('hydrator');

/**
 * Smoke-tests every ScoredAction type against HomeFeedComposer::actionToCard.
 * Catches hydrator bugs (null payloads, missing keys, broken titles) that
 * would surface as visual glitches in production but pass the type checker.
 *
 * If you add a new ScoredAction type to the pipeline, add a row to the
 * dataset below. CI will fail until the hydrator handles it.
 */
$cases = [
    'transit_disruption' => [[
        'type' => 'transit_disruption',
        'severity' => 'major',
        'payload' => ['lines' => ['12', '15'], 'stops_affected' => [], 'matched_route_id' => 1],
        'expect_card_type' => 'disruption',
        'must_have_keys' => ['title', 'subtitle', 'emoji', 'priority', 'color', 'meta'],
    ]],
    'transit_delay' => [[
        'type' => 'transit_delay',
        'severity' => 'major',
        'payload' => ['line' => '12', 'delay_min' => 12, 'direction' => 'Merkenich', 'stop_id' => 'X'],
        'expect_card_type' => 'disruption',
        'must_have_keys' => ['title', 'subtitle', 'emoji', 'priority', 'color', 'meta'],
    ]],
    'alternative_route' => [[
        'type' => 'alternative_route',
        'severity' => 'major',
        'payload' => [
            'matched_route_id' => 5,
            'alternative' => ['summary' => 'Via 124, 29 min', 'extra_min' => 0, 'segments' => []],
        ],
        'expect_card_type' => 'commute_tip',
        'must_have_keys' => ['title', 'subtitle', 'emoji', 'value', 'unit', 'priority', 'color', 'meta'],
    ]],
    'disruption_no_alt' => [[
        'type' => 'disruption_no_alt',
        'severity' => 'major',
        'payload' => ['matched_route_id' => 1, 'alternative' => null],
        'expect_card_type' => 'commute_tip',
        'must_have_keys' => ['title', 'subtitle', 'emoji', 'priority', 'color', 'meta'],
    ]],
    'weather_alert' => [[
        'type' => 'weather_alert',
        'severity' => 'major',
        'payload' => ['condition' => 'alert', 'alert' => ['title' => 'Rain', 'description' => 'Take umbrella']],
        'expect_card_type' => 'weather_alert',
        'must_have_keys' => ['title', 'subtitle', 'emoji', 'priority', 'color', 'meta'],
    ]],
    'buergeramt_slot' => [[
        'type' => 'buergeramt_slot',
        'severity' => 'critical',
        'payload' => ['office_id' => 'innenstadt', 'dates' => ['2026-05-08']],
        'expect_card_type' => 'deadline_warning',
        'must_have_keys' => ['title', 'subtitle', 'emoji', 'priority', 'color', 'meta'],
    ]],
    'rhine_level' => [[
        'type' => 'rhine_level',
        'severity' => 'major',
        'payload' => ['level' => 7.2, 'threshold' => 'critical'],
        'expect_card_type' => 'weather_alert',
        'must_have_keys' => ['title', 'subtitle', 'emoji', 'priority', 'color', 'meta'],
    ]],
    'market_closure' => [[
        'type' => 'market_closure',
        'severity' => 'minor',
        'payload' => ['market_id' => 'all', 'day' => '2026-05-08', 'reason' => 'Sunday'],
        'expect_card_type' => 'news',
        'must_have_keys' => ['title', 'subtitle', 'emoji', 'priority', 'color', 'meta'],
    ]],
    'leave_by' => [[
        'type' => 'leave_by',
        'severity' => 'major',
        'payload' => ['place_id' => 5, 'at' => '2026-05-07T08:34:00+02:00'],
        'expect_card_type' => 'commute_tip',
        'must_have_keys' => ['title', 'subtitle', 'emoji', 'priority', 'color', 'meta'],
    ]],
];

it('hydrates each ScoredAction type to a complete card', function (array $case) {
    $action = new ScoredAction(
        type: $case['type'],
        actionKey: "{$case['type']}:test",
        score: 50.0,
        severity: $case['severity'],
        validUntil: CarbonImmutable::now()->addHours(2),
        deliverChannels: [ScoredAction::CHANNEL_DASHBOARD],
        payload: $case['payload'],
        createdAt: CarbonImmutable::now(),
    );

    $composer = app(HomeFeedComposer::class);
    $reflection = new ReflectionMethod($composer, 'actionToCard');
    $reflection->setAccessible(true);
    $card = $reflection->invoke($composer, $action);

    expect($card)->toBeArray()
        ->and($card['type'])->toBe($case['expect_card_type']);

    foreach ($case['must_have_keys'] as $key) {
        expect($card)->toHaveKey($key);
        expect($card[$key])->not->toBeNull("'{$key}' is null on {$case['type']}");
    }

    // Title must be non-empty string for any visible card.
    expect($card['title'])->toBeString()->not->toBe('');

    // Priority must be int (rounded score) so usort works.
    expect($card['priority'])->toBeInt();

    // Meta must be an array (frontend reads from it).
    expect($card['meta'])->toBeArray();
})->with($cases);

it('returns null for alternative_route when alternative is missing', function () {
    $action = new ScoredAction(
        type: 'alternative_route',
        actionKey: 'alternative_route:test',
        score: 50.0,
        severity: 'major',
        validUntil: CarbonImmutable::now()->addHours(2),
        deliverChannels: [ScoredAction::CHANNEL_DASHBOARD],
        payload: ['matched_route_id' => 1], // no 'alternative' key
        createdAt: CarbonImmutable::now(),
    );

    $composer = app(HomeFeedComposer::class);
    $reflection = new ReflectionMethod($composer, 'actionToCard');
    $reflection->setAccessible(true);
    $card = $reflection->invoke($composer, $action);

    expect($card)->toBeNull();
});

it('returns null for unknown action types', function () {
    $action = new ScoredAction(
        type: 'unknown_type',
        actionKey: 'unknown:test',
        score: 50.0,
        severity: 'major',
        validUntil: CarbonImmutable::now()->addHours(2),
        deliverChannels: [ScoredAction::CHANNEL_DASHBOARD],
        payload: [],
        createdAt: CarbonImmutable::now(),
    );

    $composer = app(HomeFeedComposer::class);
    $reflection = new ReflectionMethod($composer, 'actionToCard');
    $reflection->setAccessible(true);
    $card = $reflection->invoke($composer, $action);

    expect($card)->toBeNull();
});
