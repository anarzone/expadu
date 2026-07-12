<?php

test('the blog index lists the published guides', function () {
    $response = $this->get('/blog');

    $response->assertOk();
    $response->assertSee('Anmeldung in Cologne');
    $response->assertSee('Your first 90 days in Cologne');
});

test('a post renders with its sources and article metadata', function () {
    $response = $this->get('/blog/anmeldung-in-cologne-english-guide');

    $response->assertOk();
    $response->assertSee('the complete English guide');
    $response->assertSee('Wohnungsgeberbestätigung');
    // Trust discipline: a guide without sources must not ship.
    $response->assertSee('Sources');
    $response->assertSee('stadt-koeln.de', escape: false);
    $response->assertSee('"@type":"Article"', escape: false);
});

test('an unknown slug is a 404, not an error page', function () {
    $this->get('/blog/does-not-exist')->assertNotFound();
});

test('the sitemap includes blog posts', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk();
    $response->assertSee(route('blog.index'), escape: false);
    $response->assertSee(route('blog.show', 'anmeldung-in-cologne-english-guide'), escape: false);
});
