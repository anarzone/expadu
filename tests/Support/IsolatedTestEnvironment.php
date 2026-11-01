<?php

namespace Tests\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/** Opt-in guard for the disposable bureaucracy backend test services. */
final class IsolatedTestEnvironment
{
    public static function assertMatches(Repository $config, array $manifest): void
    {
        $fixed = [
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.cache.host' => '127.0.0.1',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'mail.default' => 'array',
        ];

        foreach ($fixed as $key => $value) {
            if (($manifest[$key] ?? null) !== $value) {
                throw new RuntimeException('Test isolation manifest has an unsafe setting: '.$key);
            }
        }

        foreach ([
            'database.connections.pgsql.database' => '/^expadu_bureaucracy_[a-z0-9_]+_test$/D',
            'database.connections.pgsql.username' => '/^expadu_bureaucracy_[a-z0-9_]+$/D',
            'database.redis.options.prefix' => '/^expadu_bureaucracy_[a-z0-9_]+_test_$/D',
        ] as $key => $pattern) {
            if (! is_string($manifest[$key] ?? null) || preg_match($pattern, $manifest[$key]) !== 1) {
                throw new RuntimeException('Test isolation manifest lacks a task-owned identity: '.$key);
            }
        }

        foreach (['database.connections.pgsql.port', 'database.redis.default.port', 'database.redis.cache.port'] as $key) {
            $port = filter_var($manifest[$key] ?? null, FILTER_VALIDATE_INT);
            if ($port === false || $port < 1024 || $port > 65535 || in_array($port, [5432, 5433, 6379, 6380, 6390], true)) {
                throw new RuntimeException('Test isolation manifest lacks a disposable service port: '.$key);
            }
        }

        foreach ($manifest as $key => $expected) {
            $actual = $config->get($key);
            if (! is_scalar($actual) || (string) $actual !== (string) $expected) {
                throw new RuntimeException('Test isolation configuration mismatch: '.$key);
            }
        }

        foreach ([
            'database.connections.pgsql.url',
            'database.connections.pgsql.read',
            'database.connections.pgsql.write',
            'database.redis.default.url',
            'database.redis.cache.url',
            'database.redis.clusters',
        ] as $key) {
            if (! empty($config->get($key))) {
                throw new RuntimeException('Test isolation refuses a connection override: '.$key);
            }
        }
    }

    public static function checkConfiguredManifest(Repository $config): void
    {
        $path = getenv('BUREAUCRACY_TEST_MANIFEST');
        if ($path === false || $path === '') {
            return;
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Test isolation manifest is unavailable.');
        }

        $manifest = json_decode(file_get_contents($path), true);
        if (! is_array($manifest)) {
            throw new RuntimeException('Test isolation manifest is invalid.');
        }

        self::assertMatches($config, $manifest);
    }
}
