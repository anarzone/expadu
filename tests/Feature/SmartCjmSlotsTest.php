<?php

use App\Models\User;
use App\Services\BuergeramtService;
use App\Services\SmartCjm\SlotAvailabilityService;
use App\Services\SmartCjm\SmartCjmClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Live availability from the city's Smart CJM booking wizard. The wizard is
// session-scoped POSTs; after selecting a service we read its own
// search_mode=earliest JSON endpoint (soonest appointment per office +
// booking deep-link). Fixtures are trimmed copies of real responses.

function smartCjmFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/smartcjm/{$name}.html"));
}

const SMARTCJM_CALENDAR_URL = 'https://termine.stadt-koeln.de/m/kundenzentren/extern/calendar/?uid=b5a5a394-ec33-4130-9af3-490f99517071';
const SMARTCJM_ANMELDUNG_UID = '0d2f4ea5-74f2-4699-b954-8907a1ca5f80';

/** Fake the three-request earliest flow: calendar → services POST → earliest JSON. */
function fakeEarliest(string $resultFixture = 'earliest_has'): void
{
    Http::preventStrayRequests();
    Http::fake([
        'termine.stadt-koeln.de/*' => Http::sequence()
            ->push(smartCjmFixture('services_page'))
            ->push(smartCjmFixture('locations_page'))
            ->push(smartCjmFixture($resultFixture)),
    ]);
}

test('client walks the wizard and parses the earliest slot per office', function () {
    fakeEarliest();

    $earliest = app(SmartCjmClient::class)
        ->fetchEarliest(SMARTCJM_CALENDAR_URL, SMARTCJM_ANMELDUNG_UID);

    expect($earliest)->toHaveCount(3);
    expect($earliest[0])->toMatchArray([
        'office' => 'Kundenzentrum Porz',
        'datetime' => '2026-07-23T10:30:00+02:00',
        'duration' => 15,
    ]);
    // Relative booking link is made absolute and points at the exact slot.
    expect($earliest[0]['booking_url'])
        ->toStartWith('https://termine.stadt-koeln.de/m/kundenzentren/extern/calendar/booking?')
        ->toContain('appointment_datetime=2026-07-23');

    Http::assertSentCount(3);
    // Service is selected with the per-step token before earliest is read.
    Http::assertSent(fn ($request) => str_contains($request->body(), 'services='.SMARTCJM_ANMELDUNG_UID)
        && str_contains($request->body(), '__RequestVerificationToken=fixture-token-services-step')
        && str_contains($request->body(), 'step_goto=%2B1'));
    // Earliest is read from the wizard's own JSON endpoint, session-scoped.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'search_result')
        && str_contains($request->url(), 'search_mode=earliest')
        && str_contains($request->url(), 'wsid=8b812049-f2f0-487e-8e9e-e99e3b95f217'));
});

test('client returns nothing when the city found no appointments', function () {
    fakeEarliest('earliest_empty');

    $earliest = app(SmartCjmClient::class)
        ->fetchEarliest(SMARTCJM_CALENDAR_URL, SMARTCJM_ANMELDUNG_UID);

    expect($earliest)->toBe([]);
});

test('refresh aggregates the soonest slot per office and caches per service', function () {
    config(['services.smartcjm.enabled' => true]);
    fakeEarliest();

    $live = app(SlotAvailabilityService::class)->refresh('anmeldung');

    expect($live['service'])->toBe('anmeldung')
        ->and($live['category'])->toBe('buergeramt');

    // Innenstadt I (Jul 24) + II (Jul 23) collapse onto one office, keeping
    // the soonest (Innenstadt II) and its booking link.
    expect($live['offices']['innenstadt']['next_slot'])->toBe('2026-07-23T12:10:00+02:00');
    expect($live['offices']['innenstadt']['booking_url'])->toContain('bcddb3b0-01fe-44be-9cfb-0703adb411aa');
    expect($live['offices']['porz']['next_slot'])->toBe('2026-07-23T10:30:00+02:00');

    // Cache is keyed by service, so other services stay independent.
    expect(Cache::get(SlotAvailabilityService::cacheKey('anmeldung'))['offices'])->toHaveKey('porz');
    expect(Cache::has(SlotAvailabilityService::cacheKey('ummeldung')))->toBeFalse();
});

test('refresh reuses a still-fresh result instead of re-probing', function () {
    config(['services.smartcjm.enabled' => true]);
    Cache::put(SlotAvailabilityService::cacheKey('anmeldung'), [
        'service' => 'anmeldung', 'category' => 'buergeramt',
        'checked_at' => now()->toIso8601String(), 'offices' => [],
    ], 600);
    Http::fake();

    app(SlotAvailabilityService::class)->refresh('anmeldung');

    Http::assertNothingSent();
});

test('pollable services cover Bürgeramt and KFZ but not Elster-based Finanzamt', function () {
    $pollable = app(SlotAvailabilityService::class)->pollableServices();

    expect($pollable)->toContain('anmeldung', 'ummeldung', 'kfz_gebraucht')
        ->and($pollable)->not->toContain('finanzamt');
    // Every pollable key is a real service booked through the city system.
    foreach ($pollable as $key) {
        expect(BuergeramtService::SERVICES[$key]['category'])->toBeIn(['buergeramt', 'kfz']);
    }
});

test('checkSlots overlays a service onto only its category of offices', function () {
    $availability = [
        'offices' => [
            'ehrenfeld' => ['next_slot' => '2026-07-23T09:00:00+02:00', 'booking_url' => 'https://book/ehrenfeld', 'duration' => 10],
        ],
    ];

    $slots = app(BuergeramtService::class)->checkSlots('ummeldung', $availability);

    // Only the ten Kundenzentren, never KFZ/Finanzämter.
    expect($slots)->toHaveKey('ehrenfeld')
        ->and($slots)->not->toHaveKey('kfz')
        ->and($slots)->not->toHaveKey('finanzamt_altstadt');

    expect($slots['ehrenfeld']['status'])->toBe('available')
        ->and($slots['ehrenfeld']['next_slot'])->toBe('2026-07-23T09:00:00+02:00')
        ->and($slots['ehrenfeld']['booking_url'])->toBe('https://book/ehrenfeld');
    // Checked, but this office had no appointment → honest "no appointments".
    expect($slots['nippes']['status'])->toBe('no_appointments');
});

test('checkSlots without availability leaves every office at check_online', function () {
    $slots = app(BuergeramtService::class)->checkSlots('anmeldung', null);

    expect($slots['ehrenfeld']['status'])->toBe('check_online');
    // Fallback link deep-links the selected service.
    expect($slots['ehrenfeld']['booking_url'])->toContain('service='.BuergeramtService::SERVICES['anmeldung']['uid']);
});

test('checkSlots for a KFZ service returns only the Zulassungsstelle', function () {
    $slots = app(BuergeramtService::class)->checkSlots('kfz_gebraucht', null);

    expect(array_keys($slots))->toBe(['kfz']);
});

test('slots:check --all sweeps every pollable service', function () {
    config(['services.smartcjm.enabled' => true]);
    Http::preventStrayRequests();
    // Every service's three-request flow resolves to the same fixtures.
    Http::fake([
        '*search_result*' => Http::response(smartCjmFixture('earliest_has')),
        'termine.stadt-koeln.de/*' => Http::response(smartCjmFixture('services_page')),
    ]);

    $this->artisan('slots:check --all')->assertSuccessful();

    expect(Cache::has(SlotAvailabilityService::cacheKey('anmeldung')))->toBeTrue();
    expect(Cache::has(SlotAvailabilityService::cacheKey('kfz_gebraucht')))->toBeTrue();
});

test('slots:check refuses to run while the feature flag is off', function () {
    config(['services.smartcjm.enabled' => false]);
    Http::fake();

    $this->artisan('slots:check')->assertFailed();

    Http::assertNothingSent();
});

test('the refresh endpoint checks the requested service and redirects back', function () {
    config(['services.smartcjm.enabled' => true]);
    $this->actingAs(User::factory()->onboarded()->create());
    fakeEarliest();

    $this->from(route('bureaucracy'))
        ->post(route('bureaucracy.refresh-slots'), ['service' => 'ummeldung'])
        ->assertRedirect(route('bureaucracy', ['service' => 'ummeldung']));

    expect(Cache::has(SlotAvailabilityService::cacheKey('ummeldung')))->toBeTrue();
});

test('the refresh endpoint is a quiet no-op while the flag is off', function () {
    config(['services.smartcjm.enabled' => false]);
    $this->actingAs(User::factory()->onboarded()->create());
    Http::fake();

    $this->post(route('bureaucracy.refresh-slots'), ['service' => 'anmeldung'])->assertRedirect();

    Http::assertNothingSent();
    expect(Cache::has(SlotAvailabilityService::cacheKey('anmeldung')))->toBeFalse();
});

test('the bureaucracy page carries the selected service and its metadata', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    Cache::put(SlotAvailabilityService::cacheKey('ummeldung'), [
        'service' => 'ummeldung', 'category' => 'buergeramt',
        'checked_at' => '2026-07-02T08:00:00+02:00', 'offices' => [],
    ], 600);

    $this->get(route('bureaucracy', ['service' => 'ummeldung']))
        ->assertInertia(fn ($page) => $page
            ->component('bureaucracy')
            ->where('selectedService', 'ummeldung')
            ->where('slotsMeta.checked_at', '2026-07-02T08:00:00+02:00')
            ->has('bookingServices', count(BuergeramtService::SERVICES)));
});

test('booking location labels map onto office keys', function () {
    $service = app(BuergeramtService::class);

    expect($service->officeKeyForLocation('Kundenzentrum Innenstadt I'))->toBe('innenstadt')
        ->and($service->officeKeyForLocation('Kundenzentrum Innenstadt II'))->toBe('innenstadt')
        ->and($service->officeKeyForLocation('Kundenzentrum Mülheim'))->toBe('muelheim')
        ->and($service->officeKeyForLocation('Kfz-Zulassungsstelle'))->toBe('kfz')
        ->and($service->officeKeyForLocation('Kundenzentrum Atlantis'))->toBeNull();
});
