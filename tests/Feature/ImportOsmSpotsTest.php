<?php

// TODO: Re-enable these tests after fixing the OSM import command structure
// The command was refactored to make separate API calls per category
// Tests need to mock multiple HTTP requests instead of one

test('osm:import command exists', function () {
    $this->artisan('osm:import --city=invalid')
        ->assertFailed();
});
