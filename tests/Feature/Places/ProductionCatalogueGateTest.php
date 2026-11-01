<?php

use App\Models\Spot;
use App\Services\GeocodingService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SpotSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

test('startup seeding preserves a managed catalogue without recreating old curated names', function () {
    $spot = Spot::factory()->create(['name' => 'Reviewed replacement name', 'source' => 'osm', 'source_id' => 'node/123']);
    $this->mock(GeocodingService::class)->shouldNotReceive('search');
    Artisan::shouldReceive('call')->once()->with('bureaucracy:import-tasks', ['--retire-missing' => true])->andReturn(0);
    Artisan::shouldReceive('call')->once()->with('bureaucracy:compile-catalogue', ['--deploy' => true])->andReturn(0);

    app(DatabaseSeeder::class)->run();

    expect(Spot::query()->count())->toBe(1)
        ->and($spot->fresh()->name)->toBe('Reviewed replacement name');
});

test('curated seed dispatch remains available only after explicit enablement', function () {
    config(['places.curated_seeding_enabled' => true]);
    Artisan::shouldReceive('call')->once()->with('bureaucracy:import-tasks', ['--retire-missing' => true])->andReturn(0);
    Artisan::shouldReceive('call')->once()->with('bureaucracy:compile-catalogue', ['--deploy' => true])->andReturn(0);
    $seeder = Mockery::mock(DatabaseSeeder::class)->makePartial();
    $seeder->shouldReceive('call')->once()->with(SpotSeeder::class)->andReturnSelf();

    $seeder->run();
});

test('catalogue and photo schedules stay dormant until explicitly enabled', function () {
    app(Kernel::class)->bootstrap();
    $events = collect(app(Schedule::class)->events());
    $commands = ['veedels:import', 'spots:assign-veedel --force', 'app:refresh-places-catalogue', 'spots:fetch-photos', 'venues:fetch-photos', 'photos:fetch-mapillary --limit=400', 'photos:fetch-mapillary --venues --limit=100', 'media:revalidate --limit=200'];

    foreach ($commands as $command) {
        $event = $events->filter(fn ($event) => str_contains($event->command ?? '', $command))->sole();
        expect($event->filtersPass(app()))->toBeFalse('Unqualified automated write: '.$command);
        config(['places.automation_enabled' => true]);
        expect($event->filtersPass(app()))->toBeTrue('Explicitly enabled command remains blocked: '.$command);
        config(['places.automation_enabled' => false]);
        expect($event->filtersPass(app()))->toBeFalse('Command did not stop after disabling: '.$command);
    }

    $health = $events->filter(fn ($event) => str_contains($event->command ?? '', 'api:health'))->sole();
    expect($health->filtersPass(app()))->toBeTrue();
});
