<?php

use App\Models\Spot;
use App\Models\SpotEnrichment;

test('a ready English place enrichment is kept separate from the source description', function () {
    $spot = Spot::factory()->create([
        'description' => 'Original source description.',
    ]);

    SpotEnrichment::query()->create([
        'spot_id' => $spot->id,
        'locale' => 'en',
        'status' => 'ready',
        'needs_review' => true,
        'summary' => 'A source-grounded English summary.',
        'tip' => null,
        'source_snapshot' => ['name' => $spot->name, 'category' => $spot->category->value],
        'source_hash' => hash('sha256', 'spot-source'),
        'prompt_version' => 'place-summary-v1',
        'producer' => 'local-codex-agent',
        'model' => 'local-worker',
        'generated_at' => now(),
    ]);

    $fresh = $spot->fresh('englishEnrichment');

    expect($fresh->description)->toBe('Original source description.')
        ->and($fresh->englishEnrichment?->summary)->toBe('A source-grounded English summary.')
        ->and($fresh->englishEnrichment?->needs_review)->toBeTrue();
});

test('a stale English place enrichment stays readable while a refresh is pending', function () {
    $spot = Spot::factory()->create();

    SpotEnrichment::query()->create([
        'spot_id' => $spot->id,
        'locale' => 'en',
        'status' => 'stale',
        'needs_review' => true,
        'summary' => 'The last source-grounded English summary.',
        'tip' => null,
        'source_snapshot' => ['name' => $spot->name, 'category' => $spot->category->value],
        'source_hash' => hash('sha256', 'old-spot-source'),
        'prompt_version' => 'place-summary-v1',
        'producer' => 'local-codex-agent',
        'model' => 'local-worker',
        'generated_at' => now()->subDay(),
        'stale_at' => now(),
    ]);

    expect($spot->fresh('englishEnrichment')->englishEnrichment?->summary)
        ->toBe('The last source-grounded English summary.');
});

test('a source change marks a ready English enrichment stale without discarding it', function () {
    $spot = Spot::factory()->create(['veedel' => 'Ehrenfeld']);

    $enrichment = SpotEnrichment::factory()->create([
        'spot_id' => $spot->id,
        'status' => 'ready',
        'summary' => 'A source-grounded English summary.',
    ]);

    $spot->update(['veedel' => 'Nippes']);

    expect($enrichment->fresh())
        ->status->toBe('stale')
        ->stale_at->not->toBeNull()
        ->summary->toBe('A source-grounded English summary.');
});

test('the bulk-source helper stales only ready enrichments for the affected places', function () {
    $affected = Spot::factory()->create();
    $unaffected = Spot::factory()->create();
    $ready = SpotEnrichment::factory()->create(['spot_id' => $affected->id, 'status' => 'ready']);
    $alreadyStale = SpotEnrichment::factory()->create([
        'spot_id' => $unaffected->id,
        'status' => 'stale',
        'stale_at' => now(),
    ]);

    SpotEnrichment::markReadyAsStaleForSpots([$affected->id]);

    expect($ready->fresh())
        ->status->toBe('stale')
        ->stale_at->not->toBeNull()
        ->and($alreadyStale->fresh()->stale_at)->not->toBeNull();
});
