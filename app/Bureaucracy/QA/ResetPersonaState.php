<?php

namespace App\Bureaucracy\QA;

use App\Models\BureaucracyCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Return a QA account to a blank slate so "become this persona" means
 * EXACTLY that persona.
 *
 * Without this, a switch layered the new persona on top of whatever the
 * account already held: onboarding answers overwrite situation and path but
 * leave `qa_persona` behind (the corner badge then names a persona the
 * profile no longer matches), scenario facts only retire their own
 * `qa_scenario:*` rows so hand-answered `onboarding` facts survive, and task
 * progress carries across personas entirely. Every one of those leaks reads
 * as a product bug during QA.
 *
 * Places (Home/Work) are deliberately kept — `become()` recreates them
 * anyway, and dropping them would discard hand-set coordinates that make the
 * commute surfaces testable.
 */
final class ResetPersonaState
{
    /**
     * Columns owned by onboarding answers. Anything the persona does not
     * set must return to null, or the previous persona bleeds through.
     *
     * @var array<int, string>
     */
    private const ANSWER_COLUMNS = [
        'situation',
        'is_eu',
        'bureaucracy_path',
        'arrival_date',
        'veedel',
        'onboarded_at',
    ];

    public function execute(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $locked->userTasks()->delete();

            // Cascades to facts, questions, conflicts, plan snapshots and
            // messages, so no earlier answer can survive the switch.
            BureaucracyCase::query()->where('user_id', $locked->getKey())->delete();

            $locked->forceFill([
                ...array_fill_keys(self::ANSWER_COLUMNS, null),
                'profile_attributes' => null,
            ])->save();

            $user->setRawAttributes($locked->getAttributes(), true);
            $user->unsetRelations();
        });
    }
}
