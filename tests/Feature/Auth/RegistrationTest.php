<?php

use App\Models\User;
use App\Security\FormTraps;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyFeature(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register and are redirected', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...formDrawn(),
    ]);

    $this->assertAuthenticated();
    // New accounts enter Today; settling-in questions are optional.
    $response->assertRedirect(route('today', absolute: false));
});

test('registering with an already-taken email surfaces an error', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->from(route('register'))->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'taken@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...formDrawn(),
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
});

test('a throwaway inbox cannot register', function () {
    $response = $this->from(route('register'))->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'someone@mailinator.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...formDrawn(),
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
    expect(User::query()->where('email', 'someone@mailinator.com')->exists())->toBeFalse();
});

test('a scripted registration is turned away before any account or mail exists', function (array $post) {
    Notification::fake();

    $response = $this->from(route('register'))->post(route('register.store'), [
        'name' => 'Script',
        'email' => 'stranger@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$post,
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
    expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with([
    'the hidden field filled in' => [fn () => [...formDrawn(), FormTraps::Trap => 'x']],
    'no stamp of when the form was drawn' => [fn () => []],
    'posted a second after the form was drawn' => [fn () => formDrawn(1)],
]);

test('the registration page hands the form its stamp', function () {
    $this->get(route('register'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('drawn'));
});
