<?php

use App\Providers\Filament\AdminPanelProvider;
use App\Support\AdminPanelLoading;
use Filament\FilamentServiceProvider;
use Livewire\LivewireServiceProvider;

// The admin panel loads only where it's served (and in the console, where the caches are built).

function adminPanelFor(string $uri): array
{
    $before = [$_SERVER['REQUEST_URI'] ?? null, $_ENV['APP_RUNNING_IN_CONSOLE'] ?? null];
    $_SERVER['REQUEST_URI'] = $uri;
    $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';

    try {
        return AdminPanelLoading::providers();
    } finally {
        [$_SERVER['REQUEST_URI'], $console] = $before;
        if ($console === null) {
            unset($_ENV['APP_RUNNING_IN_CONSOLE']);
        } else {
            $_ENV['APP_RUNNING_IN_CONSOLE'] = $console;
        }
    }
}

test('the app’s own pages load without the panel', function (string $uri) {
    expect(adminPanelFor($uri))->toBe([]);
})->with(['/dashboard', '/', '/travel?stop=Neumarkt', '/settings', '/administration-guide', '/api/signals']);

test('the panel, its Livewire endpoints and its files load it', function (string $uri) {
    expect(adminPanelFor($uri))->toContain(AdminPanelProvider::class, LivewireServiceProvider::class, FilamentServiceProvider::class);
})->with(['/admin', '/admin/login', '/Admin/users?page=2', '/livewire-029e6587/update', '/livewire-029e6587/livewire.js', '/filament/exports/1/download']);

test('the console always loads it, so caches built there hold its routes and config', function () {
    expect(AdminPanelLoading::wanted())->toBeTrue();
});
