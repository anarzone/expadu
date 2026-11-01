<?php

namespace App\Bureaucracy\Migration;

use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyPerson;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;

final class LegacyMigrationPlanner
{
    public function __construct(private ConfirmedFactView $facts) {}

    /** Internal operator report: no names, emails, answer values or document labels. No writes. */
    public function for(User $actor): array
    {
        $user = User::query()->findOrFail($actor->id);
        $case = BureaucracyCase::query()->where('user_id', $user->id)->first();
        $person = BureaucracyPerson::query()->where('account_user_id', $user->id)->first();
        $status = match (true) {
            $user->email_verified_at === null || $user->onboarded_at === null => 'account_not_ready',
            $case !== null && $case->status !== 'active' => 'inactive_record',
            $person !== null && $person->record_status !== 'active' => 'inactive_record',
            $case?->person_id !== null && $case->person_id !== $person?->id => 'identity_mismatch',
            $person?->dossier !== null && $person->dossier->id !== $case?->id => 'identity_mismatch',
            $case?->person_id !== null => 'already_linked',
            default => 'ready',
        };
        $assertions = $case?->facts()->orderBy('id')->get() ?? collect();
        $states = $case !== null ? $this->facts->forCase($case, now()->toDateString())['states'] : [];
        $fingerprint = ProcessingConsentStore::digest([
            'version' => 'account-attachment.1', 'user' => $user->getRawOriginal(),
            'case' => $case?->getRawOriginal(), 'person' => $person?->getRawOriginal(),
            'facts' => $assertions->map(fn ($fact) => $fact->getRawOriginal())->all(),
            'questions' => $case?->questions()->orderBy('id')->get()->map(fn ($question) => $question->getRawOriginal())->all() ?? [],
            'conflicts' => $case?->conflicts()->orderBy('id')->get()->map(fn ($conflict) => $conflict->getRawOriginal())->all() ?? [],
            'answer_states' => $states,
        ]);

        return [
            'status' => $status, 'fingerprint' => $fingerprint, 'fact_revision' => $case?->fact_version ?? 0,
            'facts' => $assertions->count(), 'answer_states' => array_count_values($states),
            'legacy_progress_retained' => $user->userTasks()->count(),
        ];
    }
}
