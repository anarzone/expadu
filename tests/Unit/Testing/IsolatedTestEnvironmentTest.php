<?php

use Illuminate\Config\Repository;
use Tests\Support\IsolatedTestEnvironment;

function isolatedEnvironmentFixture(): array
{
    return [
        'app.env' => 'testing',
        'database.default' => 'pgsql',
        'database.connections.pgsql.host' => '127.0.0.1',
        'database.connections.pgsql.port' => '32768',
        'database.connections.pgsql.database' => 'expadu_bureaucracy_v2_test',
        'database.connections.pgsql.username' => 'expadu_bureaucracy_test',
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => '32769',
        'database.redis.cache.host' => '127.0.0.1',
        'database.redis.cache.port' => '32769',
        'database.redis.options.prefix' => 'expadu_bureaucracy_v2_test_',
        'cache.default' => 'array',
        'queue.default' => 'sync',
        'mail.default' => 'array',
    ];
}

test('the test guard accepts the explicitly recorded disposable services', function () {
    $expected = isolatedEnvironmentFixture();
    $config = new Repository;
    $config->set($expected);

    IsolatedTestEnvironment::assertMatches($config, $expected);

    expect($config->get('app.env'))->toBe('testing');
});

test('the test guard refuses a changed connection before refresh can run', function (string $key, mixed $value) {
    $expected = isolatedEnvironmentFixture();
    $config = new Repository;
    $config->set($expected);
    $config->set($key, $value);

    IsolatedTestEnvironment::assertMatches($config, $expected);
})->with([
    ['app.env', 'production'],
    ['database.default', 'sqlite'],
    ['database.connections.pgsql.database', 'expadu'],
    ['database.connections.pgsql.host', 'example.com'],
    ['database.connections.pgsql.port', 5433],
    ['database.connections.pgsql.url', 'pgsql://private-user:private-password@example.com/app'],
    ['database.connections.pgsql.read', ['host' => 'example.com']],
    ['database.redis.default.port', 6380],
    ['database.redis.cache.port', 6380],
    ['database.redis.cache.url', 'redis://example.com'],
    ['database.redis.clusters', ['default' => [['host' => 'example.com']]]],
    ['cache.default', 'redis'],
    ['queue.default', 'database'],
    ['mail.default', 'smtp'],
])->throws(RuntimeException::class);

test('the test guard does not accept a manifest that declares app data disposable', function () {
    $expected = isolatedEnvironmentFixture();
    $expected['database.connections.pgsql.database'] = 'expadu';
    $config = new Repository;
    $config->set($expected);

    IsolatedTestEnvironment::assertMatches($config, $expected);
})->throws(RuntimeException::class);

test('test isolation failures do not disclose connection URLs or credentials', function () {
    $expected = isolatedEnvironmentFixture();
    $config = new Repository;
    $config->set($expected);
    $config->set('database.connections.pgsql.url', 'pgsql://private-user:private-password@example.com/app');

    try {
        IsolatedTestEnvironment::assertMatches($config, $expected);
        $this->fail('The unsafe URL must be rejected.');
    } catch (RuntimeException $error) {
        expect($error->getMessage())->not->toContain('private-user', 'private-password', 'example.com');
    }
});
