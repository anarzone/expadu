<?php

namespace App\Bureaucracy\Migration;

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Internal cutover command. Existing fact rows stay in place; legacy progress is never replayed. */
final class BackfillPersonDossiers
{
    public function __construct(private LegacyMigrationPlanner $planner, private EnsureAccountHolder $holders) {}

    public function execute(User $actor, string $expectedFingerprint): array
    {
        return DB::transaction(function () use ($actor, $expectedFingerprint): array {
            $user = User::query()->whereKey($actor->id)->lock('for no key update')->firstOrFail();
            BureaucracyPerson::query()->where('account_user_id', $user->id)->lockForUpdate()->first();
            BureaucracyCase::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $review = $this->planner->for($user);
            if (! hash_equals($review['fingerprint'], $expectedFingerprint)
                || ! in_array($review['status'], ['ready', 'already_linked'], true)) {
                throw new ConflictHttpException('The account record changed or cannot be attached safely. Rehearse it again before continuing.');
            }
            if ($review['status'] === 'already_linked') {
                return $review;
            }
            $case = $this->holders->dossier($user);
            BureaucracyOutboxEvent::query()->firstOrCreate([
                'dedupe_key' => 'account.attached:'.$case->id.':'.$case->person_id,
            ], [
                'event_type' => 'person.reassessment_requested', 'aggregate_type' => 'person',
                'aggregate_id' => $case->person_id, 'aggregate_version' => $case->person->record_version,
                'payload' => [], 'available_at' => now()->utc(),
            ]);

            return [...$this->planner->for($user), 'status' => 'attached'];
        });
    }
}
