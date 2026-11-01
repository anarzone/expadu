<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;
use Tests\Support\IsolatedTestEnvironment;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // Runs before RefreshDatabase traits and the global cache cleanup.
        IsolatedTestEnvironment::checkConfiguredManifest($app['config']);
        if (getenv('BUREAUCRACY_TEST_MANIFEST')) {
            Http::preventStrayRequests();
        }

        return $app;
    }

    protected function skipUnlessFortifyFeature(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
