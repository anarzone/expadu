<?php

use App\Services\BuergeramtService;

// The office directory behind task "Take me there" and booking deep-links.
// (Live slot availability was removed — the city IP-blocks crawling; the app
// deep-links into the city's booking flow instead.)

test('office resolution maps booking keys to concrete offices', function () {
    $service = app(BuergeramtService::class);

    expect($service->officeForTask('anmeldung', 'Ehrenfeld')['name'])->toBe('Bürgeramt Ehrenfeld');
    expect($service->officeForTask('anmeldung', 'Esch/Auweiler')['name'])->toBe('Bürgeramt Chorweiler');
    expect($service->officeForTask('anmeldung', null)['name'])->toBe('Bürgeramt Innenstadt');
    expect($service->officeForTask('auslaenderbehoerde', 'Nippes')['name'])->toBe('Ausländerbehörde Köln');
    expect($service->officeForTask('kfz', null)['name'])->toBe('KFZ-Zulassungsstelle Köln');
    expect($service->officeForTask(null, 'Nippes'))->toBeNull();
});
