<?php

use App\Models\Spot;
use App\Models\User;

test('the landing page renders the approved newcomer narrative with live proof', function () {
    Spot::factory()->count(3)->create();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Tell Expadu your day. It plans the rest.');
    $response->assertSee('Your situation, deadlines, ticket, weather and location become one useful next step');
    $response->assertSee('official-source guides');
    $response->assertSee('3 places');
    $response->assertSee('Start free');
    $response->assertSee('AI plans the shape. Verified data supplies the facts.');
    $response->assertSee(route('tools.index'), escape: false);
    $response->assertSee(route('blog.index'), escape: false);
    $response->assertSee(route('impressum'));
    $response->assertSee(route('datenschutz'));
});

test('the landing page server-renders every interactive product proof surface', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('id="marketing-demo-data"', escape: false);
    $response->assertSee('id="personaRow"', escape: false);
    $response->assertSee('id="chipRow"', escape: false);
    $response->assertSee('id="plan"', escape: false);
    $response->assertSee('id="docList"', escape: false);
    $response->assertSee('id="board"', escape: false);
    $response->assertSee('Demo data.', escape: false);
});

test('landing content stays visible when reveal animations are unavailable', function () {
    $css = file_get_contents(resource_path('css/marketing-landing.css'));
    $script = file_get_contents(resource_path('js/marketing-landing.ts'));

    expect($css)
        ->toMatch('/\.landing-v2 \.reveal\s*\{[^}]*opacity:\s*1;/s')
        ->toMatch('/\.landing-v2\.reveal-enabled \.reveal\s*\{[^}]*opacity:\s*0;/s')
        ->and($script)
        ->toContain("if (!('IntersectionObserver' in window))")
        ->toContain("document.body.classList.add('reveal-enabled')")
        ->toContain('window.setTimeout')
        ->toContain("revealElements.forEach((element) => element.classList.add('in'))");
});

test('the landing page links its free tools and cornerstone guides through named routes', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee(route('tools.dticket'), escape: false);
    $response->assertSee(route('tools.residency'), escape: false);
    $response->assertSee(route('tools.citizenship'), escape: false);
    $response->assertSee(route('tools.netto'), escape: false);
    $response->assertSee(route('blog.show', 'anmeldung-in-cologne-english-guide'), escape: false);
    $response->assertSee(route('blog.show', 'first-90-days-in-cologne-in-order'), escape: false);
});

test('a signed-in visitor gets an app link instead of login CTAs', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertOk();
    $response->assertSee('Open the app');
    $response->assertDontSee('>Log in<', escape: false);
});

test('the landing page carries FAQPage structured data', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('application/ld+json', escape: false);
    $response->assertSee('FAQPage', escape: false);
});

test('the legal pages render', function (string $route) {
    $response = $this->get(route($route));

    $response->assertOk();
    $response->assertViewIs("marketing.{$route}");
})->with(['impressum', 'datenschutz']);

test('the sitemap lists the public marketing pages as XML', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/xml');
    $response->assertSee(route('home'), escape: false);
    $response->assertSee(route('impressum'), escape: false);
    $response->assertSee(route('datenschutz'), escape: false);
});
