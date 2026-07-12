<?php

use App\Bureaucracy\PermanentResidencyEligibility;

test('the tools hub lists all three calculators', function () {
    $response = $this->get('/tools');

    $response->assertOk();
    $response->assertSee('Deutschlandticket break-even');
    $response->assertSee('Permanent-residency timeline');
    $response->assertSee('Citizenship quiz');
});

test('the D-Ticket tool carries the fare-engine constants and their source', function () {
    $response = $this->get('/tools/deutschlandticket-break-even');

    $response->assertOk();
    // Prices come from config/rheinlandtarif.php — the same file the app's
    // fare advisor reads — so the page must reflect it verbatim.
    $response->assertSee(number_format(config('rheinlandtarif.deutschlandticket.monthly_eur'), 0));
    $response->assertSee(number_format(config('rheinlandtarif.single.1b'), 2));
    $response->assertSee(config('rheinlandtarif.verified_at'));
    $response->assertSee(config('rheinlandtarif.source'));
});

test('the residency tool exposes the same tracks as the in-app eligibility engine', function () {
    $response = $this->get('/tools/permanent-residency-timeline');

    $response->assertOk();
    foreach (PermanentResidencyEligibility::tracks() as $track) {
        $response->assertSee($track['label']);
    }
});

test('the citizenship quiz cites the statute and disclaims legal advice', function () {
    $response = $this->get('/tools/citizenship-quiz');

    $response->assertOk();
    $response->assertSee('gesetze-im-internet.de/stag');
    $response->assertSee('guide, not legal advice');
});

test('the sitemap includes the tool pages', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk();
    $response->assertSee(route('tools.dticket'), escape: false);
    $response->assertSee(route('tools.residency'), escape: false);
    $response->assertSee(route('tools.citizenship'), escape: false);
});
