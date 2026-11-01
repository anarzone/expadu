<?php

namespace App\Bureaucracy\ReadModel;

use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProcessReassessmentOutbox
{
    public const Types = ['facts.changed', 'relationship.changed', 'access.changed', 'process.changed', 'process.discovered',
        'evidence.changed', 'evidence.sharing_changed', 'catalogue.activated', 'catalogue.suspended', 'person.reassessment_requested'];

    public function __construct(private ReassessmentTargets $targets, private ReassessPerson $effects) {}

    public function process(BureaucracyOutboxEvent $event): bool
    {
        $claimed = DB::transaction(function () use ($event): ?BureaucracyOutboxEvent {
            $row = BureaucracyOutboxEvent::query()->whereKey($event->id)->whereIn('event_type', self::Types)
                ->whereNull('delivered_at')->where('available_at', '<=', now()->utc())
                ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<=', now()->utc()))
                ->lock('for update skip locked')->first();
            if ($row === null) {
                return null;
            }
            $row->update(['claim_token' => (string) Str::uuid(), 'claimed_until' => now()->utc()->addMinutes(5), 'attempts' => $row->attempts + 1]);

            return $row;
        });
        if ($claimed === null) {
            return false;
        }
        $processed = false;
        // A claim inside an outer transaction is not durable yet. Effects and acknowledgement
        // must stay together after that commit, including their retry/error boundary.
        DB::afterCommit(function () use ($claimed, &$processed): void {
            $processed = $this->deliver($claimed);
        });

        return $processed; // Deferred nested work has not completed at return time.
    }

    private function deliver(BureaucracyOutboxEvent $claimed): bool
    {
        if (! BureaucracyOutboxEvent::query()->whereKey($claimed->id)->where('claim_token', $claimed->claim_token)
            ->whereNull('delivered_at')->where('claimed_until', '>', now()->utc())->exists()) {
            return false;
        }
        try {
            if (in_array($claimed->event_type, ['catalogue.activated', 'catalogue.suspended'], true)) {
                // Fan out durable per-person work; a retry cannot duplicate it or discard unfinished people.
                BureaucracyPerson::query()->where('record_status', 'active')->orderBy('id')->chunkById(100, function ($people) use ($claimed): void {
                    foreach ($people as $person) {
                        BureaucracyOutboxEvent::query()->firstOrCreate(['dedupe_key' => 'catalogue.reassess:'.$claimed->id.':'.$person->id], [
                            'event_type' => 'person.reassessment_requested', 'aggregate_type' => 'person', 'aggregate_id' => $person->id,
                            'aggregate_version' => $person->record_version, 'payload' => [], 'available_at' => now()->utc(),
                        ]);
                    }
                });
            } else {
                foreach ($this->targets->for($claimed) as $personId) {
                    $this->effects->execute($personId);
                }
            }
        } catch (Throwable $error) {
            BureaucracyOutboxEvent::query()->whereKey($claimed->id)->where('claim_token', $claimed->claim_token)->update([
                'claim_token' => null, 'claimed_until' => null, 'last_error_type' => $error::class,
                'available_at' => now()->utc()->addSeconds(min(3600, 60 * (2 ** min(6, $claimed->attempts - 1)))),
            ]);

            return false;
        }

        return BureaucracyOutboxEvent::query()->whereKey($claimed->id)->where('claim_token', $claimed->claim_token)->update([
            'delivered_at' => now()->utc(), 'claim_token' => null, 'claimed_until' => null, 'last_error_type' => null,
        ]) === 1;
    }
}
