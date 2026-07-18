<?php

test('production isolates commute-critical jobs from long-running default jobs', function () {
    $configuration = file_get_contents(base_path('docker/prod/supervisord.conf'));

    expect($configuration)
        ->toContain('[program:queue-commute]')
        ->toContain('--queue=commute')
        ->toContain('[program:queue-default]')
        ->toContain('--queue=default')
        ->not->toContain('--queue=commute,default');
});

test('production deployment no longer provisions the retired Valhalla router', function () {
    $application = file_get_contents(base_path('docker-compose.prod.yml'));
    $companionServices = file_get_contents(base_path('docker/prod/services.yml'));

    expect($application)
        ->not->toContain('VALHALLA_URL')
        ->not->toContain('valhalla:')
        ->and($companionServices)
        ->not->toContain('valhalla:')
        ->not->toContain('valhalla-data:');
});
