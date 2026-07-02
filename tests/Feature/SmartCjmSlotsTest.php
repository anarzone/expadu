<?php

use App\Services\BuergeramtService;
use App\Services\SmartCjm\SmartCjmClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Live availability from the city's Smart CJM booking wizard
// (termine.stadt-koeln.de). Fixtures are trimmed copies of real wizard
// pages captured 2026-07-02; the wizard is session-scoped POSTs, so every
// test fakes the full page sequence.

function smartCjmFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/smartcjm/{$name}.html"));
}

const SMARTCJM_CALENDAR_URL = 'https://termine.stadt-koeln.de/m/kundenzentren/extern/calendar/?uid=b5a5a394-ec33-4130-9af3-490f99517071';
const SMARTCJM_ANMELDUNG_UID = '0d2f4ea5-74f2-4699-b954-8907a1ca5f80';

test('client walks the wizard and parses per-location slots', function () {
    Http::preventStrayRequests();
    Http::fake([
        'termine.stadt-koeln.de/*' => Http::sequence()
            ->push(smartCjmFixture('services_page'))
            ->push(smartCjmFixture('locations_page'))
            ->push(smartCjmFixture('results_slots')),
    ]);

    $availability = app(SmartCjmClient::class)
        ->fetchAvailability(SMARTCJM_CALENDAR_URL, SMARTCJM_ANMELDUNG_UID);

    expect($availability['locations'])->toHaveCount(10)
        ->and($availability['locations'])->toContain('Kundenzentrum Mülheim')
        ->and($availability['slots'])->toHaveKeys([
            'Kundenzentrum Ehrenfeld', 'Kundenzentrum Innenstadt I', 'Kundenzentrum Mülheim',
        ]);

    // Datetimes are normalized to clean ISO-8601 and sorted.
    expect($availability['slots']['Kundenzentrum Ehrenfeld'])->toBe([
        '2026-07-02T13:30:00+02:00',
        '2026-07-02T14:00:00+02:00',
    ]);
    expect($availability['slots']['Kundenzentrum Mülheim'])->toBe(['2026-07-21T08:30:00+02:00']);

    Http::assertSentCount(3);
    // The services step posts the service selection with the per-step token.
    Http::assertSent(fn ($request) => str_contains($request->body(), 'services='.SMARTCJM_ANMELDUNG_UID)
        && str_contains($request->body(), '__RequestVerificationToken=fixture-token-services-step')
        && str_contains($request->body(), 'step_goto=%2B1'));
    // The locations step posts every offered location as a repeated key.
    Http::assertSent(fn ($request) => substr_count($request->body(), 'locations=') === 10
        && str_contains($request->body(), '__RequestVerificationToken=fixture-token-locations-step'));
});

test('client handles calendars whose locations step is skipped', function () {
    Http::preventStrayRequests();
    Http::fake([
        'termine.stadt-koeln.de/*' => Http::sequence()
            ->push(smartCjmFixture('services_page'))
            ->push(smartCjmFixture('results_slots')),
    ]);

    $availability = app(SmartCjmClient::class)
        ->fetchAvailability(SMARTCJM_CALENDAR_URL, SMARTCJM_ANMELDUNG_UID);

    expect($availability['locations'])->toBe([])
        ->and($availability['slots'])->not->toBeEmpty();
    Http::assertSentCount(2);
});

test('client reports zero slots on the empty results page', function () {
    Http::preventStrayRequests();
    Http::fake([
        'termine.stadt-koeln.de/*' => Http::sequence()
            ->push(smartCjmFixture('services_page'))
            ->push(smartCjmFixture('locations_page'))
            ->push(smartCjmFixture('results_empty')),
    ]);

    $availability = app(SmartCjmClient::class)
        ->fetchAvailability(SMARTCJM_CALENDAR_URL, SMARTCJM_ANMELDUNG_UID);

    expect($availability['slots'])->toBe([])
        ->and($availability['locations'])->toHaveCount(10);
});

test('slots:check aggregates availability by office and caches it', function () {
    config(['services.smartcjm.enabled' => true]);
    Carbon::setTestNow(Carbon::parse('2026-07-02 08:00', 'Europe/Berlin'));

    Http::preventStrayRequests();
    Http::fake([
        'termine.stadt-koeln.de/*' => Http::sequence()
            ->push(smartCjmFixture('services_page'))
            ->push(smartCjmFixture('locations_page'))
            ->push(smartCjmFixture('results_slots')),
    ]);

    $this->artisan('slots:check', ['service' => 'anmeldung'])->assertSuccessful();

    $live = Cache::get('buergeramt_slots_live');
    expect($live['service'])->toBe('anmeldung')
        ->and($live['category'])->toBe('buergeramt');

    // Innenstadt I + II collapse onto one office; I carries the only slot.
    expect($live['offices']['innenstadt'])->toBe([
        'next_slot' => '2026-07-02T09:15:00+02:00', 'slots_today' => 1, 'slots_total' => 1,
    ]);
    expect($live['offices']['ehrenfeld'])->toBe([
        'next_slot' => '2026-07-02T13:30:00+02:00', 'slots_today' => 2, 'slots_total' => 2,
    ]);
    // A slot on a later day counts toward total but not today.
    expect($live['offices']['muelheim'])->toBe([
        'next_slot' => '2026-07-21T08:30:00+02:00', 'slots_today' => 0, 'slots_total' => 1,
    ]);
    // Offered locations without slots are confirmed fully booked, not absent.
    expect($live['offices']['chorweiler'])->toBe([
        'next_slot' => null, 'slots_today' => 0, 'slots_total' => 0,
    ]);
});

test('slots:check refuses to run while the feature flag is off', function () {
    config(['services.smartcjm.enabled' => false]);
    Http::fake();

    $this->artisan('slots:check')->assertFailed();

    Http::assertNothingSent();
    expect(Cache::has('buergeramt_slots_live'))->toBeFalse();
});

test('checkSlots overlays live availability onto the office directory', function () {
    Cache::forget('buergeramt_slots');
    Cache::put('buergeramt_slots_live', [
        'service' => 'anmeldung',
        'category' => 'buergeramt',
        'checked_at' => '2026-07-02T08:00:00+02:00',
        'offices' => [
            'ehrenfeld' => ['next_slot' => '2026-07-02T13:30:00+02:00', 'slots_today' => 2, 'slots_total' => 2],
            'chorweiler' => ['next_slot' => null, 'slots_today' => 0, 'slots_total' => 0],
        ],
    ], 600);

    $slots = app(BuergeramtService::class)->checkSlots();

    expect($slots['ehrenfeld']['status'])->toBe('available')
        ->and($slots['ehrenfeld']['next_slot'])->toBe('2026-07-02T13:30:00+02:00')
        ->and($slots['ehrenfeld']['slots_today'])->toBe(2);
    // Checked but slotless → honest fully booked, not "check online".
    expect($slots['chorweiler']['status'])->toBe('fully_booked');
    // Offices the check never covered keep the link-out fallback.
    expect($slots['nippes']['status'])->toBe('check_online')
        ->and($slots['kfz']['status'])->toBe('check_online');
});

test('booking location labels map onto office keys', function () {
    $service = app(BuergeramtService::class);

    expect($service->officeKeyForLocation('Kundenzentrum Innenstadt I'))->toBe('innenstadt')
        ->and($service->officeKeyForLocation('Kundenzentrum Innenstadt II'))->toBe('innenstadt')
        ->and($service->officeKeyForLocation('Kundenzentrum Mülheim'))->toBe('muelheim')
        ->and($service->officeKeyForLocation('Kfz-Zulassungsstelle'))->toBe('kfz')
        ->and($service->officeKeyForLocation('Ausländerbehörde Köln'))->toBe('auslaenderbehoerde')
        ->and($service->officeKeyForLocation('Kundenzentrum Atlantis'))->toBeNull();
});
