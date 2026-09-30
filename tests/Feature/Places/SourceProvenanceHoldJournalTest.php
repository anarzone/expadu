<?php

use App\Composer\CandidateRepository;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Places\DestinationGrouping;
use App\Places\PlaceFactRevision;
use App\Places\ReconcilePlace;
use App\Places\RecordPlaceObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $file = base_path('docs/places/production-release/SourceProvenanceHoldJournal.php');
    if (is_file($file)) {
        require_once $file;
    }
});

function provenanceFixture(): array
{
    $attrs = ['name' => 'Source-backed cafe', 'category' => 'cafe', 'lat' => 50.95, 'lng' => 6.95, 'tags' => ['amenity' => 'cafe', 'access' => 'yes']];
    $canonical = Spot::factory()->create([...$attrs, 'source' => 'osm', 'source_id' => 'node/991001']);
    $alias = Spot::factory()->create([...$attrs, 'source' => null, 'source_id' => null]);
    $preview = app(ReconcilePlace::class)->preview($alias->id, $canonical->id);
    app(ReconcilePlace::class)->apply($alias->id, $canonical->id, $preview['fingerprint'], 'Preserve the old saved ID through its exact reviewed source counterpart.');
    $unknown = Spot::factory()->create(['name' => 'Unverified legacy place', 'category' => 'park', 'lat' => 50.96, 'lng' => 6.96, 'source' => null, 'source_id' => null, 'tags' => ['access' => 'yes']]);

    return [$unknown, $alias->refresh(), $canonical->refresh()];
}

function provenanceContext(array $before): array
{
    return ['database' => DB::selectOne('select current_database() as name')->name,
        'package_sha256' => SourceProvenanceHoldJournal::hash($before), 'application_sha256' => SourceProvenanceHoldJournal::applicationHash(),
        'importer_sha256' => hash_file('sha256', base_path('docs/places/production-release/SourceProvenanceHoldJournal.php'))];
}

test('provenance hold excludes unknown places while saved aliases and all stored source fields survive recovery', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    [$unknown, $alias, $canonical] = provenanceFixture();
    $saved = SpotFeedback::factory()->create(['spot_id' => $alias->id, 'user_id' => auth()->id()]);
    $savedBefore = $saved->refresh()->getRawOriginal();
    $knownBefore = (array) DB::table('spots')->find($canonical->id);
    $journal = new SourceProvenanceHoldJournal;
    $before = $journal->snapshot();
    $context = provenanceContext($before);
    $id = (string) Str::uuid();
    $reason = 'Exclude unknown provenance from recommendations while preserving saved references.';
    $revision = app(PlaceFactRevision::class)->current();
    $this->getJson('/api/places/'.$unknown->id)->assertOk();
    $receipt = $journal->apply($id, $context, $before, 'test-operator', $reason);
    expect($unknown->fresh()->is_active)->toBeFalse()->and($unknown->fresh()->is_recommendable)->toBeFalse()
        ->and($alias->fresh()->canonical_spot_id)->toBe($canonical->id)
        ->and((array) DB::table('spots')->find($canonical->id))->toBe($knownBefore)
        ->and($saved->fresh()->getRawOriginal())->toBe($savedBefore)
        ->and(app(PlaceFactRevision::class)->current())->toBeGreaterThan($revision);
    $this->getJson('/api/places/'.$unknown->id)->assertOk()->assertJsonPath('data.recommendation_status', 'unavailable');
    expect(app(DestinationGrouping::class)->eligible(Spot::query(), true)->whereKey($unknown->id)->exists())->toBeFalse();
    $this->getJson('/api/places/'.$alias->id)->assertOk()->assertJsonPath('data.id', $canonical->id);
    $candidates = app(CandidateRepository::class)->byIds(['spot:'.$unknown->id, 'spot:'.$alias->id, 'spot:'.$canonical->id], CarbonImmutable::today());
    expect(array_column($candidates, 'id'))->toBe(['spot:'.$canonical->id]);
    expect((new SourceProvenanceHoldJournal)->apply($id, $context, $before, 'test-operator', $reason))->toBe($receipt);
    $recovery = (new SourceProvenanceHoldJournal)->recover($id, $context, 'test-operator', $reason);
    expect($journal->snapshot())->toBe($before)
        ->and($saved->fresh()->getRawOriginal())->toBe($savedBefore)
        ->and((new SourceProvenanceHoldJournal)->recover($id, $context, 'test-operator', $reason))->toBe($recovery)
        ->and(DB::table('place_catalogue_operations')->where('id', $id)->value('state'))->toBe('recovered');
    expect(fn () => $journal->apply($id, $context, $before, 'test-operator', $reason))->toThrow(DomainException::class);
    $this->getJson('/api/places/'.$unknown->id)->assertOk();
});

test('provenance hold refuses a changed complete cohort or protected evidence before any write', function (string $change) {
    [$unknown, $alias, $canonical] = provenanceFixture();
    $journal = new SourceProvenanceHoldJournal;
    $before = $journal->snapshot();
    $context = provenanceContext($before);
    if ($change === 'new') {
        Spot::factory()->create(['source' => null]);
    } elseif ($change === 'source') {
        $unknown->update(['source' => 'osm', 'source_id' => 'node/991002']);
    } elseif ($change === 'name') {
        $unknown->update(['name' => 'Edited after preparation']);
    } elseif ($change === 'point') {
        $unknown->update(['lat' => 50.99]);
    } elseif ($change === 'observation') {
        app(RecordPlaceObservation::class)->record($unknown, ['provider' => 'osm', 'provider_record_id' => 'node/991003', 'observed_at' => now()->toIso8601String(), 'ingestion_key' => 'hold-drift', 'payload' => ['name' => 'Later source evidence']]);
    } elseif ($change === 'incoming') {
        $canonical->update(['parent_spot_id' => $unknown->id]);
    } else {
        DB::table('spots')->where('id', $alias->id)->update(['canonical_spot_id' => null]);
    }
    $current = $journal->snapshot();
    $id = (string) Str::uuid();
    expect(fn () => $journal->apply($id, $context, $before, 'test-operator', 'Refuse any change after the reviewed source-null baseline.'))->toThrow(DomainException::class);
    expect($journal->snapshot())->toBe($current)->and(DB::table('place_catalogue_operations')->where('id', $id)->exists())->toBeFalse();
})->with(['new', 'source', 'name', 'point', 'observation', 'incoming', 'identity']);

test('provenance recovery refuses later owned changes without overwriting source or references', function (string $change) {
    [$unknown, $alias, $canonical] = provenanceFixture();
    $journal = new SourceProvenanceHoldJournal;
    $before = $journal->snapshot();
    $context = provenanceContext($before);
    $id = (string) Str::uuid();
    $reason = 'Quarantine unresolved provenance for the bounded catalogue. ';
    $journal->apply($id, $context, $before, 'test-operator', $reason);
    if ($change === 'new') {
        Spot::factory()->create(['source' => null]);
    } elseif ($change === 'source') {
        $unknown->update(['source' => 'osm', 'source_id' => 'node/991002']);
    } elseif ($change === 'name') {
        $unknown->update(['name' => 'Later review']);
    } else {
        $canonical->update(['parent_spot_id' => $unknown->id]);
    }
    $current = $journal->snapshot();
    expect(fn () => $journal->recover($id, $context, 'test-operator', $reason))->toThrow(DomainException::class);
    expect($journal->snapshot())->toBe($current)->and($unknown->fresh()->is_active)->toBeFalse()
        ->and(DB::table('place_catalogue_operations')->where('id', $id)->value('state'))->toBe('applied');
})->with(['new', 'source', 'name', 'incoming']);

test('provenance hold rejects wrong context and validates durable receipt and replay requests', function () {
    [$unknown] = provenanceFixture();
    $journal = new SourceProvenanceHoldJournal;
    $before = $journal->snapshot();
    $context = provenanceContext($before);
    $id = (string) Str::uuid();
    $reason = 'Hold unresolved sources with an explicit complete-cohort baseline.';
    foreach (['database' => 'wrong-database', 'package_sha256' => str_repeat('a', 64), 'application_sha256' => str_repeat('a', 64), 'importer_sha256' => str_repeat('a', 64)] as $field => $value) {
        expect(fn () => $journal->apply($id, [...$context, $field => $value], $before, 'test-operator', $reason))->toThrow(DomainException::class);
    }
    expect($journal->snapshot())->toBe($before);
    $journal->apply($id, $context, $before, 'test-operator', $reason);
    expect(fn () => $journal->apply($id, $context, $before, 'other-operator', $reason))->toThrow(DomainException::class);
    expect(fn () => $journal->apply($id, $context, $before, 'test-operator', 'Another reason must not replay a different request.'))->toThrow(DomainException::class);
    $row = DB::table('place_catalogue_operations')->where('id', $id)->first();
    $receipt = json_decode($row->receipt, true, flags: JSON_THROW_ON_ERROR);
    $receipt['records'] = 999;
    DB::table('place_catalogue_operations')->where('id', $id)->update(['receipt' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
    expect(fn () => $journal->recover($id, $context, 'test-operator', $reason))->toThrow(DomainException::class, 'Journal checksum differs.');
    expect($unknown->fresh()->is_active)->toBeFalse();
});

test('provenance hold owns only flags and timestamps and outer rollback removes its journal', function () {
    [$unknown] = provenanceFixture();
    $journal = new SourceProvenanceHoldJournal;
    $before = $journal->snapshot();
    $context = provenanceContext($before);
    $revision = app(PlaceFactRevision::class)->current();
    $id = (string) Str::uuid();
    DB::beginTransaction();
    try {
        $journal->apply($id, $context, $before, 'test-operator', 'Exercise the outer transaction recovery boundary without persistent changes.');
        expect($unknown->fresh()->is_active)->toBeFalse();
        SpotFeedback::factory()->create(['spot_id' => $unknown->id]);
        $journal->recover($id, $context, 'test-operator', 'Recovery retains later saved references without reading their user records.');
        expect(SpotFeedback::where('spot_id', $unknown->id)->count())->toBe(1);
    } finally {
        DB::rollBack();
    }
    expect($journal->snapshot())->toBe($before)->and(app(PlaceFactRevision::class)->current())->toBe($revision)
        ->and(DB::table('place_catalogue_operations')->where('id', $id)->exists())->toBeFalse();
});

test('provenance hold rejects stale repeatable-read snapshots before mutation', function () {
    provenanceFixture();
    $journal = new SourceProvenanceHoldJournal;
    $before = $journal->snapshot();
    $context = provenanceContext($before);
    config(['database.connections.provenance_isolation' => config('database.connections.'.DB::getDefaultConnection())]);
    try {
        DB::usingConnection('provenance_isolation', function () use ($journal, $context, $before) {
            DB::beginTransaction();
            try {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                expect(fn () => $journal->apply((string) Str::uuid(), $context, $before, 'test-operator', 'Refuse a stale snapshot rather than accepting a concurrent source change.'))
                    ->toThrow(DomainException::class, 'Read committed isolation is required');
            } finally {
                DB::rollBack();
            }
        });
    } finally {
        DB::purge('provenance_isolation');
    }
    expect(DB::table('place_catalogue_operations')->count())->toBe(0);
});

test('provenance hold requires an explicit caller transaction', function () {
    provenanceFixture();
    $journal = new SourceProvenanceHoldJournal;
    $before = $journal->snapshot();
    $context = provenanceContext($before);
    config(['database.connections.provenance_no_transaction' => config('database.connections.'.DB::getDefaultConnection())]);
    try {
        DB::usingConnection('provenance_no_transaction', function () use ($journal, $context, $before) {
            expect(fn () => $journal->apply((string) Str::uuid(), $context, $before, 'test-operator', 'A caller transaction must own the complete reviewed exclusion.'))
                ->toThrow(DomainException::class, 'Explicit caller-owned transaction required.');
        });
    } finally {
        DB::purge('provenance_no_transaction');
    }
    expect($journal->snapshot())->toBe($before)->and(DB::table('place_catalogue_operations')->count())->toBe(0);
});

test('provenance snapshot refuses an empty or oversized complete cohort', function (bool $oversized) {
    if ($oversized) {
        DB::statement("INSERT INTO spots (name, category, lat, lng, source, is_active, is_recommendable, created_at, updated_at)
            SELECT 'Unknown ' || n, 'park', 50.95, 6.95, NULL, true, true, now(), now() FROM generate_series(1, 5001) AS n");
    } else {
        Spot::factory()->create(['source' => 'osm', 'source_id' => 'node/991004']);
    }
    expect(fn () => (new SourceProvenanceHoldJournal)->snapshot())
        ->toThrow(DomainException::class, 'Use a complete cohort of 1 to 5000 source-null places.');
    expect(DB::table('place_catalogue_operations')->count())->toBe(0);
})->with([false, true]);

test('provenance hold refuses incomplete metadata packages and invalid requests atomically', function () {
    provenanceFixture();
    $journal = new SourceProvenanceHoldJournal;
    $before = $journal->snapshot();
    $partial = $before;
    array_pop($partial['spots']);
    expect(fn () => $journal->apply((string) Str::uuid(), provenanceContext($partial), $partial, 'test-operator', 'An incomplete source-null package must never be applied.'))
        ->toThrow(DomainException::class, 'Source-null cohort or protected evidence changed after preview.');
    foreach ([['bad-id', 'test-operator', 'A documented bounded provenance exclusion is required.'], [(string) Str::uuid(), '', 'A documented bounded provenance exclusion is required.'], [(string) Str::uuid(), 'test-operator', 'short']] as [$id, $actor, $reason]) {
        expect(fn () => $journal->apply($id, provenanceContext($before), $before, $actor, $reason))->toThrow(DomainException::class);
    }
    expect($journal->snapshot())->toBe($before)->and(DB::table('place_catalogue_operations')->count())->toBe(0);
});
