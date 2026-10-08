<?php

namespace App\Bureaucracy\Privacy;

use App\Models\BureaucracyOutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProcessErasureOutbox
{
    public function __construct(private PurgePersonCopies $copies) {}

    public function process(BureaucracyOutboxEvent $event): bool
    {
        $claimed = DB::transaction(function () use ($event): ?BureaucracyOutboxEvent {
            $row = BureaucracyOutboxEvent::query()->whereKey($event->id)->where('event_type', 'person.erased')
                ->whereNull('delivered_at')->where('available_at', '<=', now()->utc())
                ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<=', now()->utc()))
                ->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            $row->update(['claim_token' => (string) Str::uuid(), 'claimed_until' => now()->utc()->addMinutes(5), 'attempts' => $row->attempts + 1]);

            return $row;
        });
        if ($claimed === null) {
            return false;
        }
        try {
            $this->copies->purge($claimed->payload);
        } catch (Throwable $error) {
            BureaucracyOutboxEvent::query()->whereKey($claimed->id)->where('claim_token', $claimed->claim_token)->update([
                'claim_token' => null, 'claimed_until' => null,
                'last_error_type' => $error::class,
                'available_at' => now()->utc()->addSeconds(min(3600, 60 * (2 ** min(6, $claimed->attempts - 1)))),
            ]);

            return false;
        }

        return BureaucracyOutboxEvent::query()->whereKey($claimed->id)->where('claim_token', $claimed->claim_token)->update([
            'delivered_at' => now()->utc(), 'claim_token' => null, 'claimed_until' => null, 'last_error_type' => null,
        ]) === 1;
    }
}
