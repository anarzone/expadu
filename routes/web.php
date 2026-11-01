<?php

use App\Http\Controllers\LocalReviewController;
use App\Http\Controllers\Marketing\BlogController;
use App\Http\Controllers\Marketing\LandingController;
use App\Http\Controllers\Marketing\RobotsController;
use App\Http\Controllers\Marketing\SitemapController;
use App\Http\Controllers\Marketing\ToolsController;
use App\Http\Controllers\Marketing\WaitlistController;
use App\Http\Controllers\SocialLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Subdomain Routing
|--------------------------------------------------------------------------
|
| Production: marketing routes on expadu.com, app routes on app.expadu.com.
| Local dev: APP_DOMAIN is null, so no subdomain enforcement — all routes
| respond to localhost.
|
*/

$appDomain = config('app.app_domain');
$marketingDomain = config('app.marketing_domain');

// ── Marketing site (expadu.com) ──────────────────────────────────────────
// Server-rendered Blade, not Inertia: Google gets full HTML with zero
// hydration, and the pages stay up even if the app bundle breaks.
$marketingRoutes = function () {
    Route::get('/', LandingController::class)->name('home');

    Route::view('impressum', 'marketing.impressum')->name('impressum');
    Route::view('datenschutz', 'marketing.datenschutz')->name('datenschutz');

    // Free tools — client-side calculators, constants from the app's engines.
    Route::get('tools', [ToolsController::class, 'index'])->name('tools.index');
    Route::get('tools/deutschlandticket-break-even', [ToolsController::class, 'dticket'])->name('tools.dticket');
    Route::get('tools/permanent-residency-timeline', [ToolsController::class, 'residency'])->name('tools.residency');
    Route::get('tools/citizenship-quiz', [ToolsController::class, 'citizenship'])->name('tools.citizenship');
    Route::get('tools/netto-brutto-calculator', [ToolsController::class, 'nettoBrutto'])->name('tools.netto');

    // Blog — markdown guides derived from the sourced bureaucracy catalogue.
    Route::get('blog', [BlogController::class, 'index'])->name('blog.index');
    Route::get('blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

    Route::get('sitemap.xml', SitemapController::class)->name('sitemap');

    // City waitlist — double opt-in: store sends a signed confirmation link.
    Route::post('waitlist', [WaitlistController::class, 'store'])
        ->middleware('throttle:10,1')->name('waitlist.store');
    Route::get('waitlist/confirm/{signup}', [WaitlistController::class, 'confirm'])
        ->middleware('signed')->name('waitlist.confirm');
};

// robots.txt answers on every host; what it allows depends on the host.
Route::get('robots.txt', RobotsController::class)->name('robots');

// ── App (app.expadu.com) ─────────────────────────────────────────────────
// Social login — responds on all domains (same as Fortify login/register)
Route::middleware(['guest', 'throttle:social'])->group(function () {
    Route::get('auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])->name('social.redirect');
    Route::get('auth/{provider}/callback', [SocialLoginController::class, 'callback'])->name('social.callback');
});

$appRoutes = function () use ($appDomain) {
    // Opt-in loopback-only entry for a designated synthetic review account.
    Route::get('dev/review', LocalReviewController::class)->name('dev.review');

    // App root opens Today (only in production with subdomain)
    if ($appDomain) {
        Route::get('/', fn () => redirect('/today'));
    }

    // throttle:app-writes caps every mutation in the group per user; GETs are
    // exempt inside the limiter, so pages and polling stay unthrottled here.
    // Each track keeps its own routes in routes/app/ and appends only there.
    Route::middleware(['auth', 'verified', 'throttle:app-writes'])->group(function () {
        require __DIR__.'/app/today.php';
        require __DIR__.'/app/travel.php';
        require __DIR__.'/app/events.php';
        require __DIR__.'/app/places.php';
        require __DIR__.'/app/composer.php';
        require __DIR__.'/app/paperwork.php';
        require __DIR__.'/app/account.php';
    });
};

// Register routes — with or without subdomain enforcement
if ($appDomain && $marketingDomain) {
    // Production: enforce subdomains
    Route::domain($marketingDomain)->group($marketingRoutes);
    Route::domain($appDomain)->group($appRoutes);
} else {
    // Local dev: no subdomain enforcement, all routes on localhost
    $marketingRoutes();
    $appRoutes();
}

require __DIR__.'/settings.php';
