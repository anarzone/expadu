<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\IsolatedTestEnvironment;

// Read-only preflight. Run from the same environment as the subsequent Pest run.
require dirname(__DIR__).'/vendor/autoload.php';

if (! getenv('BUREAUCRACY_TEST_MANIFEST')) {
    fwrite(STDERR, "Provide BUREAUCRACY_TEST_MANIFEST for the disposable services.\n");
    exit(1);
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    IsolatedTestEnvironment::checkConfiguredManifest($app['config']);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, "Disposable test configuration verified; no database or cache was modified.\n");
