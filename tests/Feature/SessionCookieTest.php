<?php

use Illuminate\Support\Env;

/**
 * Guards the staging login loop: APP_NAME was absent from the container
 * environment, so the session cookie fell back to a different name, browsers
 * held two cookies at once, and every login bounced back to the login page.
 */
test('session cookie name is stable when APP_NAME is missing', function () {
    $repository = Env::getRepository();
    $original = $repository->get('APP_NAME');

    $repository->clear('APP_NAME');

    try {
        $config = require config_path('session.php');
    } finally {
        if ($original !== null) {
            $repository->set('APP_NAME', $original);
        }
    }

    expect($config['cookie'])->toBe('expadu-session');
});

test('session cookie is never laravel branded', function () {
    expect(config('session.cookie'))
        ->not->toContain('laravel')
        ->toBe('expadu-session');
});
