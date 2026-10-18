<?php

beforeEach(function () {
    config(['app.app_domain' => 'app.expadu.com', 'app.marketing_domain' => 'expadu.com']);
});

test('auth screens on the marketing host move permanently to the app host', function (string $path) {
    $this->get("http://expadu.com/{$path}?next=%2Fdashboard")
        ->assertStatus(301)
        ->assertRedirect("http://app.expadu.com/{$path}?next=%2Fdashboard");
})->with(['login', 'register']);

test('auth screens stay put on the app host', function () {
    $this->get('http://app.expadu.com/login')->assertOk();
});

test('auth form posts on the marketing host are never redirected', function () {
    $response = $this->post('http://expadu.com/login', ['email' => 'a@example.com', 'password' => 'x']);

    expect($response->getStatusCode())->not->toBe(301);
});
