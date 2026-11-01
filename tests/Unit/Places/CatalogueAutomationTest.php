<?php

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

uses(TestCase::class);

test('catalogue mutation schedules require an explicit qualified automation opt in', function () {
    config(['places.automation_enabled' => false]);
    $commands = ['veedels:import', 'spots:assign-veedel', 'app:refresh-places-catalogue', 'spots:fetch-photos', 'venues:fetch-photos', 'photos:fetch-mapillary'];
    $found = [];
    foreach (app(Schedule::class)->events() as $event) {
        foreach ($commands as $command) {
            if (str_contains($event->command ?? '', $command)) {
                $found[$command] = true;
                expect($event->filtersPass(app()))->toBeFalse($command);
                config(['places.automation_enabled' => true]);
                expect($event->filtersPass(app()))->toBeTrue($command);
                config(['places.automation_enabled' => false]);
            }
        }
    }
    expect(array_keys($found))->toBe($commands);
});
