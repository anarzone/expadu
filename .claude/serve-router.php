<?php

/**
 * Router for `php -S`, used instead of `php artisan serve`.
 *
 * `artisan serve` is single-threaded and its access logger writes to stdout
 * through the parent ServeCommand; under the concurrent asset requests an
 * Inertia page fires, that pipe EPIPEs and requests come back as
 * ERR_EMPTY_RESPONSE, so React never mounts and the page renders blank.
 *
 * Serving through `php -S` with PHP_CLI_SERVER_WORKERS and no access log
 * avoids that. Return false for files that exist under public/ so the built-in
 * server streams them directly; everything else goes to Laravel's front
 * controller. Paths are absolute because the framework's own server.php
 * resolves index.php via getcwd(), not the -t docroot.
 */
$public = __DIR__.'/../public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath($public.$path);

if ($file !== false && is_file($file) && str_starts_with($file, realpath($public))) {
    return false;
}

require $public.'/index.php';
