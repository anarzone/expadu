<?php

use App\Composer\CandidateRepository;
use App\Composer\PlanSlot;
use App\Http\Resources\PlaceResource;
use App\Models\Spot;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;
use Carbon\CarbonImmutable;

function practicalObservation(Spot $spot, array $payload, string $provider = 'osm', string $time = '2026-09-28T10:00:00Z'): void
{
    app(RecordPlaceObservation::class)->record($spot, [
        'provider' => $provider,
        'provider_record_id' => $spot->source_id,
        'source_url' => 'https://www.openstreetmap.org/'.$spot->source_id,
        'observed_at' => $time,
        'ingestion_key' => $provider.'-'.$time,
        'payload' => $payload,
    ]);
}

test('people composer candidates and slots share practical facts and provenance', function () {
    $spot = Spot::factory()->create([
        'category' => 'pitch', 'source' => 'osm', 'source_id' => 'way/900101',
        'lat' => 50.94, 'lng' => 6.95,
        'tags' => ['sport' => 'soccer;basketball', 'surface' => 'artificial_turf', 'lit' => 'no', 'wheelchair' => 'limited', 'reservation' => 'yes', 'access' => 'yes', 'fee' => 'no'],
    ]);
    $facts = app(PlaceFacts::class)->resolve($spot);
    $day = CarbonImmutable::parse('2026-09-28 14:00', 'Europe/Berlin');
    $candidate = app(CandidateRepository::class)->byIds(["spot:{$spot->id}"], $day)[0];
    $resource = (new PlaceResource($spot))->resolve(request());
    $slot = (new PlanSlot($candidate, $day, $day->addHour(), 0))->toArray();

    expect($facts['identity'])->toBe(['canonical_id' => $spot->id, 'provider' => 'osm', 'provider_record_id' => 'way/900101'])
        ->and($resource['place_facts']['identity'])->toBe($candidate->placeFacts['identity'])
        ->and($facts['activities'])->toBe(['soccer', 'basketball'])
        ->and($facts['practical']['lit']['value'])->toBe('no')
        ->and($facts['practical']['surface']['value'])->toBe('artificial_turf')
        ->and($facts['practical']['wheelchair']['value'])->toBe('limited')
        ->and($facts['practical']['reservation']['source_url'])->toBe('https://www.openstreetmap.org/way/900101')
        ->and($resource['place_facts']['practical'])->toBe($facts['practical'])
        ->and($candidate->placeFacts['practical'])->toBe($facts['practical'])
        ->and($slot['place_facts']['practical'])->toBe($facts['practical'])
        ->and($slot['hours_assumed'])->toBeTrue()
        ->and($slot['place_facts']['hours']['status'])->toBe('unknown')
        ->and($resource['photo_url'])->toBeNull();
});

test('new observations preserve practical facts and later omissions do not resurrect old tags', function () {
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'way/900102', 'tags' => ['sport' => 'soccer', 'lit' => 'yes']]);
    practicalObservation($spot, ['practical' => ['sport' => 'basketball', 'lit' => 'no']]);
    $facts = app(PlaceFacts::class)->resolve($spot->fresh());
    expect($facts['activities'])->toBe(['basketball'])
        ->and($facts['practical']['lit']['value'])->toBe('no')
        ->and($facts['practical']['lit']['observed_at'])->not->toBeNull();
    practicalObservation($spot, ['practical' => []], time: '2026-09-28T11:00:00Z');
    $facts = app(PlaceFacts::class)->resolve($spot->fresh());
    expect($facts['activities'])->toBe([])
        ->and($facts['practical']['lit']['status'])->toBe('unknown');
});

test('venue amenity tags become sourced facts and missing ones stay unknown', function () {
    $spot = Spot::factory()->create(['category' => 'cafe', 'source' => 'osm', 'source_id' => 'node/900104', 'lat' => 50.94, 'lng' => 6.95]);
    practicalObservation($spot, ['practical' => [
        'internet_access' => 'wlan', 'outdoor_seating' => 'yes', 'cuisine' => 'coffee_shop;cake', 'diet:vegan' => 'yes',
    ]]);
    $facts = app(PlaceFacts::class)->resolve($spot->fresh());
    $candidate = app(CandidateRepository::class)->byIds(["spot:{$spot->id}"], CarbonImmutable::parse('2026-09-28 14:00', 'Europe/Berlin'))[0];

    expect($facts['practical']['internet_access'])->toMatchArray(['value' => 'wlan', 'status' => 'known', 'source_url' => 'https://www.openstreetmap.org/node/900104'])
        ->and($facts['practical']['outdoor_seating']['value'])->toBe('yes')
        ->and($facts['practical']['cuisine']['value'])->toBe('coffee_shop;cake')
        ->and($facts['practical']['diet:vegan']['value'])->toBe('yes')
        ->and($facts['practical']['diet:vegetarian'])->toMatchArray(['value' => null, 'status' => 'unknown'])
        ->and($facts['practical']['dog']['status'])->toBe('unknown')
        ->and($candidate->placeFacts['practical'])->toBe($facts['practical']);
});

test('conditional or contradictory fee evidence never becomes a free claim', function (array $tags) {
    $spot = Spot::factory()->create(['tags' => $tags, 'category' => 'pitch', 'lat' => 50.94, 'lng' => 6.95]);
    $facts = app(PlaceFacts::class)->resolve($spot);
    $resource = (new PlaceResource($spot))->resolve(request());
    $candidate = app(CandidateRepository::class)->byIds(["spot:{$spot->id}"], CarbonImmutable::now())[0];
    expect($facts['fee']['value'])->toBe('unknown')
        ->and($resource['price_text'])->toBeNull()
        ->and($candidate->costTier)->toBe('unknown');
})->with([
    'conditional fee' => [['fee' => 'no', 'fee:conditional' => 'yes @ (Mo-Fr)']],
    'charge contradicts free' => [['fee' => 'no', 'charge' => '5 EUR']],
    'conditional charge' => [['fee' => 'no', 'charge:conditional' => '5 EUR @ (Sa-Su)']],
]);

test('current providers disagreeing on fee remain conflicting despite latest free value', function () {
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'way/900103']);
    practicalObservation($spot, ['fee' => ['raw' => 'yes']], 'operator');
    practicalObservation($spot, ['fee' => ['raw' => 'no']], time: '2026-09-28T11:00:00Z');
    $facts = app(PlaceFacts::class)->resolve($spot->fresh());
    expect($facts['fee']['value'])->toBe('unknown')
        ->and($facts['fee']['status'])->toBe('conflicting')
        ->and($facts['conflicts'])->toContain('fee');
});
