<?php

use Illuminate\Support\Facades\Artisan;

/*
 * The iPhone app's types are generated from this file, so a change under /api/v1 without
 * ios/Scripts/generate-api.sh would reach phones as a broken decode, not a compile error.
 */
test('the committed API description matches the routes', function () {
    $path = tempnam(sys_get_temp_dir(), 'openapi');

    Artisan::call('scramble:export', ['--path' => $path]);

    expect(json_decode((string) file_get_contents($path), true))
        ->toEqual(json_decode((string) file_get_contents(base_path('ios/Packages/Sources/API/openapi.json')), true));

    unlink($path);
});
