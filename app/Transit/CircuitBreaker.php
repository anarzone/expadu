<?php

namespace App\Transit;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

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
        try {
            return (bool) Redis::exists($this->openKey($adapter));
        } catch (Throwable $exception) {
            Log::warning('transit circuit breaker unavailable; allowing provider attempt', [
                'adapter' => $adapter,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function recordFailure(string $adapter): void
    {
        try {
            $failures = (int) Redis::incr($this->failureKey($adapter));
            Redis::expire($this->failureKey($adapter), 300);

            if ($failures >= self::FAILURE_THRESHOLD) {
                Redis::setex($this->openKey($adapter), self::OPEN_SECONDS, '1');
            }
        } catch (Throwable $exception) {
            Log::warning('transit circuit breaker could not record failure', [
                'adapter' => $adapter,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function recordSuccess(string $adapter): void
    {
        try {
            Redis::del($this->failureKey($adapter), $this->openKey($adapter));
        } catch (Throwable $exception) {
            Log::warning('transit circuit breaker could not record success', [
                'adapter' => $adapter,
                'error' => $exception->getMessage(),
            ]);
        }
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
