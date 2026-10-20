<?php

use App\Models\User;

test('the landing page tells the sample day from daylight to the next morning', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSeeInOrder([
        'Your',
        'joined up.',
        'nine open tabs.',
        'Your day, in order of what matters.',
        'On your way, step by step.',
        'Walk in prepared.',
        'Good things to turn up for.',
        'Ask in your own words.',
        'Built in Cologne, by an expat who had to figure it out first.',
        'Honest by design.',
        'Questions before you sleep on it.',
        'sooner.',
    ]);
    $response->assertSee('Start free');
    $response->assertSee('Log in');
    // Until the new app ships, "Start free" leads to the launch waitlist, not sign-up.
    $response->assertSee('href="#waitlistForm"', escape: false);
    $response->assertDontSee(route('register'), escape: false);
    $response->assertSee(route('tools.index'), escape: false);
    $response->assertSee(route('blog.index'), escape: false);
    $response->assertSee(route('impressum'));
    $response->assertSee(route('datenschutz'));
});

test('the landing page labels every product shot as sample content', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Sample day');
    $response->assertSee('Sample programme · the street and posters from Expadu’s Events page');
    $response->assertSee('Scheduled sample times, not live.');
    $response->assertSee('Illustrative map, not to scale · sample day');
    $response->assertSee('Sample checklist, not legal advice.');
    $response->assertSee('the street, posters and map are illustrations');
    $response->assertSee('Place data © OpenStreetMap contributors.');
});

test('the sample checklist uses the reviewed Blue Card catalogue wording', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Valid passport and national D visa');
    $response->assertSee('Employment contract or binding job offer covering at least six months');
    $response->assertSee('Qualification evidence and recognition evidence where required');
    $response->assertSee('Health-insurance evidence');
    $response->assertSee('Professional licence for a regulated profession, where applicable');
    $response->assertSee('Verification recorded 3 Aug 2026 · version 2026-08-03.1');
    $response->assertDontSee('reviewed by people');
});

test('the composer sample conversation renders every answer server-side', function () {
    $response = $this->get('/');

    $response->assertOk();

    foreach (range(0, 4) as $index) {
        $response->assertSee('data-q="'.$index.'"', escape: false);
        $response->assertSee('data-a="'.$index.'"', escape: false);
    }

    // The starter cards show first; every answer waits in the same grid cell.
    $response->assertSee('class="xv-cview xv-cview--home" data-on="1"', escape: false);
    $response->assertSee('data-a="0" data-on="0"', escape: false);
    $response->assertSee('data-a="4" data-on="0"', escape: false);
});

test('the join form posts to the real city waitlist', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('id="waitlistForm"', escape: false);
    $response->assertSee('action="'.route('waitlist.store').'"', escape: false);
    $response->assertSee('name="source" value="landing-join"', escape: false);
    $response->assertSee('class="waitlist-msg"', escape: false);
});

test('landing content stays visible when motion is unavailable', function () {
    $css = file_get_contents(resource_path('css/marketing-landing.css'));
    $script = file_get_contents(resource_path('js/marketing-landing.ts'));

    // Every hidden "not yet revealed" state is gated on the opt-in attribute.
    $hiddenStates = collect(preg_split('/\R/', $css))
        ->filter(fn (string $line): bool => str_contains($line, ':not([data-in])'));

    expect($hiddenStates)->not->toBeEmpty()
        ->and($hiddenStates->every(fn (string $line): bool => str_starts_with(trim($line), "html[data-xv-anim='on'] .xv")))->toBeTrue()
        ->and($css)->toContain('@media (prefers-reduced-motion: reduce)')
        ->and($script)
        ->toContain("html.dataset.xvReady = '1'")
        ->toContain('delete html.dataset.xvAnim');

    // The head guard opts in before first paint and gives up if the script never starts.
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee("html.setAttribute('data-xv-anim', 'on')", escape: false);
    $response->assertSee("if (!html.hasAttribute('data-xv-ready')) html.removeAttribute('data-xv-anim');", escape: false);
});

test('the landing page uses its own header and footer', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('class="xv-footer"', escape: false);
    $response->assertDontSee('<nav class="site">', escape: false);
    $response->assertDontSee('<footer class="site">', escape: false);
});

test('other marketing pages keep the shared header and footer', function () {
    $response = $this->get(route('impressum'));

    $response->assertOk();
    $response->assertSee('class="xv xv-site"', escape: false);
    $response->assertSee('class="xv-footer"', escape: false);
    $response->assertSee('id="waitlistForm"', escape: false);
});

test('the landing page links its free tools and cornerstone guides through named routes', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee(route('tools.dticket'), escape: false);
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
    // A bare '@context' in the template compiles as Blade's @context directive.
    $response->assertSee('{"@context":"https://schema.org"', escape: false);
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
