<?php

use App\Models\Spot;
use App\Models\User;

test('the landing page renders server-side with live counts and a signup CTA', function () {
    Spot::factory()->count(3)->create();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('The AI companion for your new life in Germany.');
    // Live count from the database, not a hardcoded claim.
    $response->assertSee('official-source guides');
    $response->assertSee('Start free');
    // Legal pages are linked from every marketing page.
    $response->assertSee(route('impressum'));
    $response->assertSee(route('datenschutz'));
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
