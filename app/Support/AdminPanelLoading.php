<?php

namespace App\Support;

use App\Providers\Filament\AdminPanelProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\AliasLoader;
use Livewire\Blaze\BlazeServiceProvider;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use RyanChandler\BladeCaptureDirective\Facades\BladeCaptureDirective;

/**
 * The admin panel (Filament on Livewire) loads only where it's used: its
 * own paths and the console (artisan, queue workers, tests, and the
 * config/route caches built there). Its providers cost ~45 ms on every
 * request, and the app's pages are Inertia (owner, 26 Oct 2026: pages load
 * whole and fast). composer.json keeps these packages out of discovery;
 * bootstrap/providers.php adds them back through here.
 */
final class AdminPanelLoading
{
    /** The panel's path (AdminPanelProvider::panel). */
    public const PATH = 'admin';

    /** In the order package discovery registered them. */
    private const PROVIDERS = [
        BladeHeroiconsServiceProvider::class,
        BladeIconsServiceProvider::class,
        ActionsServiceProvider::class,
        FilamentServiceProvider::class,
        FormsServiceProvider::class,
        InfolistsServiceProvider::class,
        NotificationsServiceProvider::class,
        QueryBuilderServiceProvider::class,
        SchemasServiceProvider::class,
        SupportServiceProvider::class,
        TablesServiceProvider::class,
        WidgetsServiceProvider::class,
        BlazeServiceProvider::class,
        LivewireServiceProvider::class,
        BladeCaptureDirectiveServiceProvider::class,
    ];

    /** The facades their discovery used to register. */
    private const ALIASES = [
        'Livewire' => Livewire::class,
        'BladeCaptureDirective' => BladeCaptureDirective::class,
    ];

    /**
     * The panel's providers, with the panel's own last, when this process
     * may serve it; none otherwise.
     *
     * @return list<class-string>
     */
    public static function providers(): array
    {
        if (! self::wanted()) {
            return [];
        }

        AliasLoader::getInstance(self::ALIASES);

        return [...self::PROVIDERS, AdminPanelProvider::class];
    }

    /** The console, or a request for the panel, its Livewire endpoints or its files. */
    public static function wanted(): bool
    {
        $console = $_ENV['APP_RUNNING_IN_CONSOLE'] ?? $_SERVER['APP_RUNNING_IN_CONSOLE'] ?? (getenv('APP_RUNNING_IN_CONSOLE') ?: null);
        if ($console !== null ? filter_var($console, FILTER_VALIDATE_BOOLEAN) : in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            return true;
        }

        $path = ltrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
        $first = strtolower(explode('/', $path, 2)[0]);

        return $first === self::PATH || $first === 'filament' || str_starts_with($first, 'livewire');
    }
}
