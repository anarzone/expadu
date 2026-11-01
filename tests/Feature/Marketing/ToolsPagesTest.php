<?php

test('the tools hub distinguishes available calculators from tools awaiting review', function () {
    $response = $this->get('/tools');

    $response->assertOk();
    $response->assertSeeText('Deutschlandticket break-even');
    $response->assertSeeText('Permanent-residency timeline');
    $response->assertSee('Citizenship quiz');
    $response->assertSee('Awaiting review');
    $response->assertDontSee('href="'.route('tools.citizenship').'"', escape: false);
    $response->assertDontSee('href="'.route('tools.residency').'"', escape: false);
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

test('unreviewed legal calculators do not publish figures or collect answers', function (string $path) {
    $this->get($path)->assertOk()
        ->assertSee('This tool is awaiting review')
        ->assertSee('noindex', escape: false)
        ->assertDontSee('id="tool-data"', escape: false)
        ->assertDontSee('class="tool-form"', escape: false)
        ->assertDontSee('fast track')
        ->assertDontSee('qualify after');
})->with(['/tools/permanent-residency-timeline', '/tools/citizenship-quiz']);

test('the sitemap promotes only available tool pages', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk();
    $response->assertSee(route('tools.dticket'), escape: false);
    $response->assertDontSee(route('tools.residency'), escape: false);
    $response->assertDontSee(route('tools.citizenship'), escape: false);
});

test('the landing page does not advertise unreviewed legal calculator results', function () {
    $this->get('/')->assertOk()
        ->assertDontSee('href="'.route('tools.citizenship').'"', escape: false)
        ->assertDontSee('href="'.route('tools.residency').'"', escape: false)
        ->assertDontSee('under the current rules')
        ->assertDontSee('Your earliest Niederlassungserlaubnis date');
});

test('the netto-brutto tool ships the sourced 2026 constants', function () {
    $response = $this->get('/tools/netto-brutto-calculator');

    $response->assertOk();
    // The tariff and ceilings come from config/lohnrechner.php — verified
    // against §32a EStG and the official Rechengrößen; the page must carry
    // the same numbers plus their sources.
    $response->assertSee((string) config('lohnrechner.tariff.basic_allowance'), escape: false);
    $response->assertSee((string) config('lohnrechner.social.bbg_pension_year'), escape: false);
    $response->assertSee(config('lohnrechner.source_tariff'));
    $response->assertSee(config('lohnrechner.verified_at'));
    $response->assertSee('An honest estimate, not your payslip.');
});

test('the netto-brutto tool covers all six tax classes with the §39b parameters', function () {
    $response = $this->get('/tools/netto-brutto-calculator');

    $response->assertOk();
    // The full engine needs the classes V/VI corridor and the ermäßigter
    // KV rate for the Vorsorgepauschale — both live in the injected config
    // and were cross-checked against the official BMF calculator.
    $response->assertSee((string) config('lohnrechner.wage_tax.v56.w2'), escape: false);
    $response->assertSee((string) config('lohnrechner.wage_tax.child_allowance_full'), escape: false);
    $response->assertSee(config('lohnrechner.source_39b'));
    $response->assertSee('BMF calculator');
    $response->assertSee('Federal state');
    $response->assertSee('Private (PKV)');
});

test('the tools hub and sitemap include the netto-brutto tool', function () {
    $this->get('/tools')->assertOk()->assertSeeText('Netto-brutto calculator');
    $this->get('/sitemap.xml')->assertOk()->assertSee(route('tools.netto'), escape: false);
});
