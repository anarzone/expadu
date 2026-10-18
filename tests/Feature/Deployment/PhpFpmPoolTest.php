<?php

test('the app image replaces the php-fpm default pool of 5 workers', function () {
    $dockerfile = file_get_contents(base_path('Dockerfile'));
    $pool = file_get_contents(base_path('docker/prod/php-fpm.conf'));

    // Loaded after the image's www.conf, so its values win.
    expect($dockerfile)
        ->toContain('COPY docker/prod/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf')
        ->toContain('PHP_FPM_MAX_CHILDREN=8')
        ->and($pool)
        ->toContain('pm.max_children = ${PHP_FPM_MAX_CHILDREN}')
        ->toContain('pm.max_requests = 500')
        ->toContain('pm.status_path = /fpm-status');
});

test('a hung request frees its worker just after nginx gives up on it', function () {
    $pool = file_get_contents(base_path('docker/prod/php-fpm.conf'));
    $nginx = file_get_contents(base_path('docker/prod/nginx.conf'));

    preg_match('/^request_terminate_timeout = (\d+)s$/m', $pool, $terminate);
    preg_match('/fastcgi_read_timeout (\d+)s;/', $nginx, $read);

    expect($terminate[1] ?? null)->not->toBeNull()
        ->and($read[1] ?? null)->not->toBeNull()
        ->and((int) $terminate[1])->toBeGreaterThan((int) $read[1])
        ->and((int) $terminate[1] - (int) $read[1])->toBeLessThanOrEqual(10);
});

test('the pool status page is container-local only', function () {
    $nginx = file_get_contents(base_path('docker/prod/nginx.conf'));

    expect($nginx)->toMatch('/location = \/fpm-status \{\s*allow 127\.0\.0\.1;\s*deny all;/');
});
