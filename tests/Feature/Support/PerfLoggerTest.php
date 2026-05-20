<?php

use App\Support\PerfLogger;
use Illuminate\Support\Facades\Redis;

uses()->group('perf');

beforeEach(function () {
    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        $prefix.'perf:*'
    );
});

test('measure records timing and returns the callable result', function () {
    $result = PerfLogger::measure('test.success', function () {
        usleep(5_000);

        return 'ok';
    });

    expect($result)->toBe('ok');

    $entries = PerfLogger::entries('test.success', now()->subMinute()->timestamp);
    expect($entries)->toHaveCount(1);
    expect($entries[0]['ok'])->toBe(1);
    expect($entries[0]['ms'])->toBeGreaterThanOrEqual(0);
});

test('measure records failure and rethrows the exception', function () {
    expect(fn () => PerfLogger::measure('test.fail', function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    $entries = PerfLogger::entries('test.fail', now()->subMinute()->timestamp);
    expect($entries)->toHaveCount(1);
    expect($entries[0]['ok'])->toBe(0);
});

test('measure attaches tags to the entry', function () {
    PerfLogger::measure('test.tagged', fn () => null, ['cache_hit' => 1, 'lines' => 3]);

    $entries = PerfLogger::entries('test.tagged', now()->subMinute()->timestamp);
    expect($entries[0]['cache_hit'])->toBe(1);
    expect($entries[0]['lines'])->toBe(3);
});

test('keys returns recorded keys without the perf prefix', function () {
    PerfLogger::measure('route:dashboard', fn () => null);
    PerfLogger::measure('ext:vrs.getDepartures', fn () => null);

    $keys = PerfLogger::keys();
    expect($keys)->toContain('route:dashboard');
    expect($keys)->toContain('ext:vrs.getDepartures');
    foreach ($keys as $k) {
        expect($k)->not->toStartWith('perf:');
    }
});

test('entries filters by time window', function () {
    PerfLogger::measure('test.window', fn () => null);

    $future = PerfLogger::entries('test.window', now()->addHour()->timestamp);
    expect($future)->toBeEmpty();

    $past = PerfLogger::entries('test.window', now()->subHour()->timestamp);
    expect($past)->toHaveCount(1);
});
