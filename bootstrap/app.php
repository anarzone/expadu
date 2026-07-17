<?php

use App\Http\Middleware\EnsureUserIsOnboarded;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PerfTrackMiddleware;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCacheHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                     Request::HEADER_X_FORWARDED_HOST |
                     Request::HEADER_X_FORWARDED_PORT |
                     Request::HEADER_X_FORWARDED_PROTO |
                     Request::HEADER_X_FORWARDED_AWS_ELB,
        );
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->append(SecurityHeaders::class);

        // Flash a visible message when bouncing already-authenticated users
        // off the guest-only auth screens instead of redirecting silently.
        $middleware->alias([
            'guest' => RedirectIfAuthenticated::class,
        ]);

        $middleware->web(append: [
            PerfTrackMiddleware::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureUserIsOnboarded::class,
            SetCacheHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report every unhandled exception to Sentry (Laravel 11 needs this
        // wired explicitly). The scrub + user-id attribution happen in
        // config/sentry.php `before_send`. 404s and validation aren't bugs, so
        // they never reach Sentry — they'd only add noise.
        if (app()->bound('sentry')) {
            Integration::handles($exceptions);
        }

        $exceptions->dontReport([
            NotFoundHttpException::class,
            AuthenticationException::class,
            ValidationException::class,
            TokenMismatchException::class,
        ]);
    })->create();
