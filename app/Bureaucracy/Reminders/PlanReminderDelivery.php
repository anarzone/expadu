<?php

namespace App\Bureaucracy\Reminders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** A queue reservation is not successful delivery. Only a sent event consumes the tier. */
final class PlanReminderDelivery
{
    public function __construct(private PlanReminderReference $references) {}

    public function reserve(array $reference): ?string
    {
        return $this->locked($reference, function (string $key): ?string {
            if (Cache::has($key.':sent') || Cache::has($key.':lease')) {
                return null;
            }
            $nonce = Str::random(48);
            Cache::put($key.':lease', $nonce, now()->addHour());

            return $nonce;
        });
    }

    public function isReserved(array $reference): bool
    {
        return $this->locked($reference, fn ($key) => ! Cache::has($key.':sent') && $this->owns($key, $reference)) ?? false;
    }

    /** A delayed queue job may reacquire only its own undelivered, currently unclaimed tier. */
    public function claimForSend(array $reference): bool
    {
        return $this->locked($reference, function ($key) use ($reference): bool {
            $nonce = $reference['reservation'] ?? null;
            if (! is_string($nonce) || $nonce === '' || Cache::has($key.':sent')) {
                return false;
            }
            if (! Cache::has($key.':lease')) {
                Cache::put($key.':lease', $nonce, now()->addHour());
            }

            return $this->owns($key, $reference);
        }) ?? false;
    }

    public function release(array $reference): void
    {
        $this->locked($reference, function ($key) use ($reference): void {
            if ($this->owns($key, $reference)) {
                Cache::forget($key.':lease');
            }
        });
    }

    public function delivered(array $reference): bool
    {
        return $this->locked($reference, function ($key) use ($reference): bool {
            if (! Cache::has($key.':sent') && $this->owns($key, $reference)) {
                Cache::put($key.':sent', true, now()->addDays(30));
                Cache::forget($key.':lease');

                return true;
            }

            return false;
        }) ?? false;
    }

    private function owns(string $key, array $reference): bool
    {
        $nonce = $reference['reservation'] ?? null;

        return is_string($nonce) && $nonce !== '' && Cache::get($key.':lease') === $nonce;
    }

    private function locked(array $reference, callable $action): mixed
    {
        $hash = $this->references->deliveryKey($reference);
        if ($hash === null) {
            return null;
        }
        $key = 'bureaucracy:v2:reminder:'.$hash;

        return Cache::lock($key.':lock', 5)->block(2, fn () => $action($key));
    }
}
