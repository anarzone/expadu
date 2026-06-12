<?php

use App\Services\BuergeramtService;
use Illuminate\Support\Facades\Cache;

// Slot *monitoring* was removed (the booking system is an unscrapeable
// SPA, so detection never worked — the honest model is link-out). What
// remains is the office directory the Slots tab and take-me-there use.

test('buergeramt service returns correct slot structure', function () {
    $slots = app(BuergeramtService::class)->checkSlots();

    expect($slots)->not->toBeEmpty();
    foreach ($slots as $key => $slot) {
        expect($key)->toBeIn(array_keys(BuergeramtService::OFFICES));
        expect($slot)->toHaveKeys(['name', 'address', 'category', 'status', 'next_slot', 'slots_today', 'booking_url']);
        expect($slot['booking_url'])->not->toBe('');
    }
});

test('slot results are cached', function () {
    Cache::forget('buergeramt_slots');

    app(BuergeramtService::class)->checkSlots();

    expect(Cache::has('buergeramt_slots'))->toBeTrue();
});

test('office resolution maps booking keys to concrete offices', function () {
    $service = app(BuergeramtService::class);

    expect($service->officeForTask('anmeldung', 'Ehrenfeld')['name'])->toBe('Bürgeramt Ehrenfeld');
    expect($service->officeForTask('anmeldung', 'Esch/Auweiler')['name'])->toBe('Bürgeramt Chorweiler');
    expect($service->officeForTask('anmeldung', null)['name'])->toBe('Bürgeramt Innenstadt');
    expect($service->officeForTask('auslaenderbehoerde', 'Nippes')['name'])->toBe('Ausländerbehörde Köln');
    expect($service->officeForTask('kfz', null)['name'])->toBe('KFZ-Zulassungsstelle Köln');
    expect($service->officeForTask(null, 'Nippes'))->toBeNull();
});
