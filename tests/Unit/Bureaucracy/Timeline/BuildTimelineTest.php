<?php

use App\Bureaucracy\Timeline\BuildTimeline;
use Carbon\CarbonImmutable;

function timelineVariant(array $deadline): array
{
    return ['id' => 'synthetic.registration', 'actionable' => true, 'coverage' => 'partial',
        'source_hash' => 'synthetic-only', 'review' => ['content_version' => 'test.1', 'review_due_at' => '2027-01-01'],
        'temporal_policy' => ['kind' => 'legal_due', 'version' => 'synthetic.1'], 'deadline' => $deadline];
}

test('a booked appointment and reported submission cannot move the rule due date', function () {
    $variant = timelineVariant(['type' => 'days_since_move_in', 'days' => 14]);
    $events = [
        ['id' => 1, 'type' => 'appointment_recorded', 'payload' => ['appointment_id' => 'one', 'starts_at' => '2026-10-01T10:00:00+02:00', 'timezone' => 'Europe/Berlin', 'duration_minutes' => 30]],
        ['id' => 2, 'type' => 'submission_recorded', 'payload' => ['occurred_on' => '2026-09-10']],
    ];
    $rows = (new BuildTimeline)->for([$variant], ['values' => ['moved_in_at' => '2026-09-01']], $events, new CarbonImmutable('2026-09-15'), 'Europe/Berlin');
    expect(array_column($rows, 'kind'))->toBe(['legal_due', 'appointment', 'submission_recorded'])
        ->and($rows[0]['date'])->toBe('2026-09-15')->and($rows[1]['starts_at'])->toBe('2026-10-01T10:00:00+02:00')
        ->and($rows[2]['legal_effect'])->toBe('not_assessed');
});

test('missing and conflicted date anchors remain unknown rather than paused or guessed', function (string $state) {
    $variant = timelineVariant(['type' => 'days_since_move_in', 'days' => 14]);
    $rows = (new BuildTimeline)->for([$variant], ['values' => ['moved_in_at' => '2026-09-01'], 'states' => ['moved_in_at' => $state]], [], new CarbonImmutable('2026-09-08'), 'Europe/Berlin');
    expect($rows[0]['date'])->toBeNull()->and($rows[0]['state'])->toBe('date_unknown')->and($rows[0]['needed_fact'])->toBe('moved_in_at');
})->with(['unknown', 'conflict', 'needs_reconfirmation']);

test('an old overdue date stays visible and future occupancy never starts a historical clock', function () {
    $variant = timelineVariant(['type' => 'days_since_move_in', 'days' => 14]);
    $timeline = new BuildTimeline;
    $at = new CarbonImmutable('2026-09-08');
    $past = $timeline->for([$variant], ['values' => ['moved_in_at' => '2025-01-01']], [], $at, 'Europe/Berlin');
    expect($past[0]['date'])->toBe('2025-01-15')->and($past[0]['overdue'])->toBeTrue();
    $future = $timeline->for([$variant], ['values' => ['moved_in_at' => '2027-01-01']], [], $at, 'Europe/Berlin');
    expect($future[0]['state'])->toBe('date_unknown');
});

test('an unlimited title does not suppress the separately recorded card expiry', function () {
    $variant = timelineVariant(['type' => 'fact_date', 'fact_key' => 'residence_title_expires_at', 'days' => null]);
    $rows = (new BuildTimeline)->for([$variant], ['values' => ['current_residence_title' => 'settlement_permit_18c',
        'residence_title_expires_at' => '2026-12-01', 'residence_card_expires_at' => '2027-03-01']], [], new CarbonImmutable('2026-09-08'), 'Europe/Berlin');
    expect($rows)->toHaveCount(1)->and($rows[0]['kind'])->toBe('document_expiry')->and($rows[0]['date'])->toBe('2027-03-01');
});

test('unknown entry cannot use a retained visa value to produce a deadline', function () {
    $variant = timelineVariant(['type' => 'permit_window', 'fact_key' => null, 'days' => 90]);
    $rows = (new BuildTimeline)->for([$variant], ['values' => ['entry_mode' => 'd_visa', 'visa_expires_at' => '2026-12-01'],
        'states' => ['entry_mode' => 'conflict']], [], new CarbonImmutable('2026-09-08'), 'Europe/Berlin');
    expect($rows[0]['date'])->toBeNull()->and($rows[0]['needed_fact'])->toBe('entry_mode');
});

test('the timeline uses an explicit clock and does not upgrade preparation targets to legal dates', function () {
    $variant = timelineVariant(['type' => 'days_since_move_in', 'days' => 14]);
    unset($variant['temporal_policy']);
    $at = new CarbonImmutable('2026-09-08');
    $timeline = new BuildTimeline;
    $before = $timeline->for([$variant], ['values' => ['moved_in_at' => '2026-09-01']], [], $at, 'Europe/Berlin');
    CarbonImmutable::setTestNow('2040-01-01');
    try {
        expect($timeline->for([$variant], ['values' => ['moved_in_at' => '2026-09-01']], [], $at, 'Europe/Berlin'))->toBe($before)
            ->and($before[0]['kind'])->toBe('preparation_target');
    } finally {
        CarbonImmutable::setTestNow();
    }
});
