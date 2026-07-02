<?php

use App\Services\BuergeramtService;

// The office directory behind the Slots tab and take-me-there. checkSlots is
// service-scoped: it returns the offices in the service's category, each a
// check_online link-out until a `slots:check` run overlays real availability
// (see SmartCjmSlotsTest for the live-check pipeline).

test('buergeramt service returns correct slot structure', function () {
    $slots = app(BuergeramtService::class)->checkSlots('anmeldung');

    expect($slots)->not->toBeEmpty();
    foreach ($slots as $key => $slot) {
        expect($key)->toBeIn(array_keys(BuergeramtService::OFFICES));
        expect($slot)->toHaveKeys(['name', 'address', 'category', 'status', 'next_slot', 'booking_url']);
        expect($slot['booking_url'])->not->toBe('');
    }
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
