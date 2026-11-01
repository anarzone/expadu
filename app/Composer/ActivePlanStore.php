<?php

namespace App\Composer;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ActivePlanStore
{
    public function __construct(private PrivatePlanCache $cache, private AppointmentRepository $appointments) {}

    public function save(User $user, array $plan): void
    {
        $this->store($user, 'plan', $plan, 72 * 3600);
    }

    /** Keep the account/person locks through the derived write, in the same order as erasure. */
    public function store(User $user, string $purpose, array $plan, int $ttl): void
    {
        DB::transaction(function () use ($user, $purpose, $plan, $ttl): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrent($user, $plan);
            $this->cache->put($user, $purpose, $plan, $ttl);
            try {
                // Covers same-transaction callbacks too; never leave a recreated erased copy behind.
                $this->assertCurrent($user, $plan);
            } catch (ValidationException $error) {
                $this->cache->forget($user, $purpose);
                throw $error;
            }
        });
    }

    public function get(User $user): ?array
    {
        $plan = $this->cache->get($user, 'plan');
        if ($plan !== null) {
            $this->assertCurrent($user, $plan);
        }

        return $plan;
    }

    public function assertCurrent(User $user, array $plan): void
    {
        if (! is_string($plan['appointment_revision'] ?? null)
            || ! is_string($plan['constraints']['window_start'] ?? null) || ! is_string($plan['constraints']['window_end'] ?? null)
            || ! hash_equals($plan['appointment_revision'], $this->appointments->revision($user, Constraints::fromArray($plan['constraints'])))) {
            throw ValidationException::withMessages(['appointments' => 'Your recorded appointments have changed or this plan needs a fresh check. Rebuild the day plan before using or saving it.']);
        }
    }
}
