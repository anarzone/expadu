<?php

use App\Media\MediaAcquisitionScheduler;
use App\Models\MediaAcquisitionAttempt;
use App\Models\Spot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

test('media acquisition attempts persist reproducible scheduling evidence', function () {
    expect(Schema::hasColumns('media_acquisition_attempts', [
        'id',
        'target_type',
        'target_id',
        'provider',
        'strategy',
        'input_fingerprint',
        'input_snapshot',
        'outcome',
        'attempted_at',
        'next_attempt_at',
        'error_code',
        'candidate_count',
        'selected_asset_ids',
        'metadata',
        'active_key',
    ]))->toBeTrue()
        ->and(class_exists(MediaAcquisitionAttempt::class))->toBeTrue()
        ->and(class_exists(MediaAcquisitionScheduler::class))->toBeTrue();
});

test('an acquisition target is claimed once while work is in progress', function () {
    $spot = Spot::factory()->create(['category' => 'park']);
    $scheduler = app(MediaAcquisitionScheduler::class);
    $snapshot = fn (Spot $candidate): array => ['name' => $candidate->name, 'lat' => $candidate->lat, 'lng' => $candidate->lng];

    $first = $scheduler->select(Spot::query(), 'mapillary', 'facing_frame', 1, $snapshot);
    $concurrent = $scheduler->select(Spot::query(), 'mapillary', 'facing_frame', 1, $snapshot);

    expect($first)->toHaveCount(1)
        ->and($concurrent)->toBeEmpty()
        ->and(MediaAcquisitionAttempt::query()->sole()->outcome)->toBe('in_progress')
        ->and(MediaAcquisitionAttempt::query()->sole()->active_key)->not->toBeNull();

    $completed = $scheduler->record($first->sole(), 'no_result');
    expect($completed->active_key)->toBeNull()
        ->and($completed->outcome)->toBe('no_result');
});

test('cooling down misses do not starve later target ids', function () {
    $spots = Spot::factory()->count(4)->sequence(
        ['name' => 'First park'],
        ['name' => 'Second park'],
        ['name' => 'Third park'],
        ['name' => 'Fourth park'],
    )->create(['category' => 'park']);
    $scheduler = app(MediaAcquisitionScheduler::class);
    $snapshot = fn (Spot $spot): array => ['name' => $spot->name, 'lat' => $spot->lat, 'lng' => $spot->lng];

    foreach ($spots->take(3) as $spot) {
        $scheduled = $scheduler->select(
            Spot::query()->whereKey($spot->id),
            'wikimedia-commons',
            'spot_geosearch',
            1,
            $snapshot,
        )->sole();
        $scheduler->record($scheduled, 'no_result');
    }

    $next = $scheduler->select(
        Spot::query()->orderBy('id'),
        'wikimedia-commons',
        'spot_geosearch',
        1,
        $snapshot,
    );

    expect($next)->toHaveCount(1)
        ->and($next->sole()->target->is($spots->last()))->toBeTrue();
});

test('a stable acquisition input change makes a target eligible immediately', function () {
    $spot = Spot::factory()->create(['name' => 'Old park name', 'category' => 'park']);
    $scheduler = app(MediaAcquisitionScheduler::class);
    $snapshot = fn (Spot $candidate): array => ['name' => $candidate->name, 'lat' => $candidate->lat, 'lng' => $candidate->lng];
    $scheduled = $scheduler->select(Spot::query(), 'wikimedia-commons', 'spot_geosearch', 1, $snapshot)->sole();
    $scheduler->record($scheduled, 'no_result');

    expect($scheduler->select(Spot::query(), 'wikimedia-commons', 'spot_geosearch', 1, $snapshot))->toBeEmpty();

    $spot->update(['name' => 'Corrected park name']);
    $changed = $scheduler->select(Spot::query(), 'wikimedia-commons', 'spot_geosearch', 1, $snapshot)->sole();

    expect($changed->target->is($spot))->toBeTrue()
        ->and($changed->inputFingerprint)->not->toBe($scheduled->inputFingerprint);
});

test('acquisition outcomes receive bounded retry windows and retain attempt history', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 08:00:00', 'UTC'));
    $spot = Spot::factory()->create(['category' => 'park']);
    $scheduler = app(MediaAcquisitionScheduler::class);
    $snapshot = fn (Spot $candidate): array => ['name' => $candidate->name, 'lat' => $candidate->lat, 'lng' => $candidate->lng];

    foreach ([1, 6, 24] as $expectedHours) {
        $scheduled = $scheduler->select(Spot::query(), 'mapillary', 'facing_frame', 1, $snapshot)->sole();
        $attempt = $scheduler->record(
            $scheduled,
            'failed',
            errorCode: 'provider_unavailable',
            candidateCount: 2,
            selectedAssetIds: [41],
        );
        expect($attempt->next_attempt_at->diffInHours(now(), true))->toBe((float) $expectedHours);
        $this->travelTo($attempt->next_attempt_at);
    }

    $attempts = MediaAcquisitionAttempt::query()->orderBy('id')->get();
    expect($attempts)->toHaveCount(3)
        ->and($attempts->last()->input_snapshot)->toMatchArray(['target_id' => $spot->id])
        ->and($attempts->last()->error_code)->toBe('provider_unavailable')
        ->and($attempts->last()->candidate_count)->toBe(2)
        ->and($attempts->last()->selected_asset_ids)->toBe([41]);
});

test('no result and ambiguous outcomes cool down for thirty days while retry-after is honored', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 08:00:00', 'UTC'));
    $spots = Spot::factory()->count(3)->create(['category' => 'park']);
    $scheduler = app(MediaAcquisitionScheduler::class);
    $snapshot = fn (Spot $candidate): array => ['name' => $candidate->name];
    $scheduled = $scheduler->select(Spot::query(), 'wikimedia-commons', 'spot_geosearch', 3, $snapshot);

    $noResult = $scheduler->record($scheduled[0], 'no_result');
    $ambiguous = $scheduler->record($scheduled[1], 'ambiguous');
    $limited = $scheduler->record($scheduled[2], 'rate_limited', retryAfterSeconds: 7200);

    expect($noResult->next_attempt_at->equalTo(now()->addDays(30)))->toBeTrue()
        ->and($ambiguous->next_attempt_at->equalTo(now()->addDays(30)))->toBeTrue()
        ->and($limited->next_attempt_at->equalTo(now()->addHours(2)))->toBeTrue();
});

test('acquisition cooldowns and transient retries are configurable', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 08:00:00', 'UTC'));
    config()->set('media.acquisition.outcome_cooldown_days', 2);
    config()->set('media.acquisition.transient_retry_seconds', [90, 180]);
    $spots = Spot::factory()->count(2)->create(['category' => 'park']);
    $scheduler = app(MediaAcquisitionScheduler::class);
    $snapshot = fn (Spot $candidate): array => ['name' => $candidate->name];
    $scheduled = $scheduler->select(Spot::query(), 'wikimedia-commons', 'spot_geosearch', 2, $snapshot);

    $noResult = $scheduler->record($scheduled[0], 'no_result');
    $failed = $scheduler->record($scheduled[1], 'failed');

    expect($noResult->next_attempt_at->equalTo(now()->addDays(2)))->toBeTrue()
        ->and($failed->next_attempt_at->equalTo(now()->addSeconds(90)))->toBeTrue();
});

test('selection reports attempted deferred remaining-due and completed outcomes', function () {
    $spots = Spot::factory()->count(3)->create(['category' => 'park']);
    $scheduler = app(MediaAcquisitionScheduler::class);
    $snapshot = fn (Spot $candidate): array => ['name' => $candidate->name];
    $cooling = $scheduler->select(
        Spot::query()->whereKey($spots[0]->id),
        'wikimedia-commons',
        'spot_geosearch',
        1,
        $snapshot,
    )->sole();
    $scheduler->record($cooling, 'no_result');

    $selected = $scheduler->select(
        Spot::query(),
        'wikimedia-commons',
        'spot_geosearch',
        1,
        $snapshot,
    );
    $scheduler->record($selected->sole(), 'captured', candidateCount: 1, selectedAssetIds: [41]);

    expect($scheduler->summary($selected))->toMatchArray([
        'attempted' => 1,
        'captured' => 1,
        'no_result' => 0,
        'ambiguous' => 0,
        'rate_limited' => 0,
        'failed' => 0,
        'deferred' => 1,
        'remaining_due' => 1,
    ])->and($selected->sole()->inputSnapshot['canonical_identity'])->toBe([
        'type' => $spots[1]->getMorphClass(),
        'id' => $spots[1]->id,
    ]);
});
