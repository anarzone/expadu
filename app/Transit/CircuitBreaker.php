<?php

namespace App\Transit;

use Illuminate\Support\Facades\Redis;

/**
 * Per-adapter circuit breaker: N consecutive failures open the circuit
 * for a cooldown window; any success closes it. State lives in Redis so
 * web workers and queue workers share the same view of provider health.
 */
class CircuitBreaker
{
    private const FAILURE_THRESHOLD = 2;

    private const OPEN_SECONDS = 120;

    public function isOpen(string $adapter): bool
    {
        return (bool) Redis::exists($this->openKey($adapter));
    }

    public function recordFailure(string $adapter): void
    {
        $failures = (int) Redis::incr($this->failureKey($adapter));
        Redis::expire($this->failureKey($adapter), 300);

        if ($failures >= self::FAILURE_THRESHOLD) {
            Redis::setex($this->openKey($adapter), self::OPEN_SECONDS, '1');
        }
    }

    public function recordSuccess(string $adapter): void
    {
        Redis::del($this->failureKey($adapter), $this->openKey($adapter));
    }

    private function failureKey(string $adapter): string
    {
        return "transit:cb:{$adapter}:failures";
    }

    private function openKey(string $adapter): string
    {
        return "transit:cb:{$adapter}:open";
    }
}
