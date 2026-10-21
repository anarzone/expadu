<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/*
 * A folder under public/ with a page's name answers instead of the page:
 * `php artisan serve` (CI's browser tests) serves PHP's own 404 for it, and
 * nginx's `try_files $uri $uri/ /index.php` (docker/prod) answers 403. The
 * Events street pictures in public/events/ did exactly that to /events.
 */
test('no folder under public/ has the name of a page', function () {
    $folders = collect(scandir(public_path()))
        ->reject(fn (string $name): bool => str_starts_with($name, '.'))
        ->filter(fn (string $name): bool => is_dir(public_path($name)))
        ->values();
    $pages = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => in_array('GET', $route->methods(), true))
        ->map(fn (Route $route): string => explode('/', trim($route->uri(), '/'))[0])
        ->reject(fn (string $segment): bool => $segment === '' || str_starts_with($segment, '{'))
        ->unique();

    expect($folders->intersect($pages)->values()->all())->toBe([]);
});
