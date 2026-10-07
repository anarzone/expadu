<?php

namespace App\Bureaucracy\People;

use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\ReadModel\ReassessmentEvents;
use App\Models\Alert;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyEvidenceItem;
use App\Models\BureaucracyGuardianAuthority;
use App\Models\BureaucracyOnboardingDraft;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyProcessingConsent;
use App\Models\BureaucracyQuestionSession;
use App\Models\BureaucracyRelationship;
use App\Models\BureaucracyRequirementUse;
use App\Models\User;
use App\Notifications\BureaucracyDeadlineNotification;
use App\Notifications\PermanentResidencyEligibleNotification;
use App\Privacy\LegacyProcessingUsage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class PersonDataLifecycle
{
    public function __construct(private PersonAccess $access, private LegacyProcessingUsage $legacyUsage, private FactRegistry $registry, private ReassessmentEvents $refresh) {}

    public function export(User $actor, BureaucracyPerson $person): array
    {
        return DB::transaction(function () use ($actor, $person): array {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $current = BureaucracyPerson::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $current);
            if (! $this->access->allows($actor, $current, AccessScope::ViewFacts)) {
                throw new AuthorizationException;
            }
            $case = $current->dossier()->lockForUpdate()->first();

            return [
                'schema_version' => 'bureaucracy-person-export.1',
                'exported_at' => now()->utc()->toIso8601String(),
                'person' => ['id' => $current->id, 'label' => $current->display_label, 'kind' => $current->kind, 'record_version' => $current->record_version],
                'facts' => $case?->facts()->orderBy('id')->get()->map(fn ($fact) => [
                    'id' => $fact->id, 'key' => $fact->key, 'value' => $fact->value, 'state' => $fact->state,
                    'source' => $fact->source, 'confirmed_at' => $fact->confirmed_at?->toIso8601String(),
                    'superseded_at' => $fact->superseded_at?->toIso8601String(),
                    'answer_state' => $fact->answer_state, 'operation' => $fact->operation,
                    'effective_from' => $fact->effective_from?->toDateString(), 'effective_until' => $fact->effective_until?->toDateString(),
                    'end_date_unknown' => $fact->end_date_unknown, 'recorded_at' => $fact->recorded_at?->toIso8601String(),
                    'supersedes_fact_id' => $fact->supersedes_fact_id, 'provenance' => $fact->provenance,
                ])->all() ?? [],
                'onboarding_drafts' => BureaucracyOnboardingDraft::query()->where('person_id', $current->id)->where('status', 'active')
                    ->where('expires_at', '>', now()->utc())->orderBy('id')->get()->map(fn ($draft) => [
                        'id' => $draft->id, 'actor_id' => $draft->actor_id, 'version' => $draft->version,
                        'payload' => $draft->payload, 'expires_at' => $draft->expires_at->toIso8601String(),
                    ])->all(),
                'evidence' => BureaucracyEvidenceItem::query()->where('person_id', $current->id)->orderBy('id')->get()->map(fn ($item) => [
                    'id' => $item->id, 'version' => $item->version, 'status' => $item->status, 'details' => $item->details,
                    'history' => $item->events()->orderBy('id')->get()->map(fn ($event) => ['id' => $event->id, 'actor_id' => $event->actor_id,
                        'version' => $event->evidence_version, 'type' => $event->type, 'payload' => $event->payload, 'recorded_at' => $event->recorded_at->toIso8601String()])->all(),
                ])->all(),
                'questions' => $case?->questions()->orderBy('id')->get()->map(fn ($question) => [
                    'id' => $question->id, 'fact_key' => $question->fact_key, 'answered_at' => $question->answered_at?->toIso8601String(),
                ])->all() ?? [],
                'sharing' => $current->grants()->orderBy('id')->get()->map(fn ($grant) => [
                    'id' => $grant->id, 'scopes' => $grant->scopes, 'accepted_at' => $grant->accepted_at->toIso8601String(),
                    'expires_at' => $grant->expires_at->toIso8601String(), 'revoked_at' => $grant->revoked_at?->toIso8601String(),
                ])->all(),
                'processes' => $case === null ? [] : BureaucracyProcess::query()->where('case_id', $case->id)->orderBy('id')->get()->map(fn ($process) => [
                    'id' => $process->id, 'definition_id' => $process->definition_id, 'occurrence_key' => $process->occurrence_key,
                    'context_id' => $process->context_id, 'jurisdiction' => $process->jurisdiction, 'version' => $process->version,
                    'state' => $process->state, 'step_definitions' => $process->step_definitions,
                    'requirement_uses' => BureaucracyRequirementUse::query()->where('process_id', $process->id)->orderBy('id')->get()->map(fn ($use) => [
                        'id' => $use->id, 'requirement_id' => $use->requirement_id, 'status' => $use->status, 'evidence_id' => $use->evidence_id,
                        'evidence_version' => $use->evidence_version, 'actor_id' => $use->actor_id, 'confirmed_at' => $use->confirmed_at->toIso8601String(),
                        'superseded_at' => $use->superseded_at?->toIso8601String(),
                    ])->all(),
                    'events' => $process->events()->orderBy('id')->get()->map(fn ($event) => [
                        'id' => $event->id, 'actor_id' => $event->actor_id, 'type' => $event->type, 'payload' => $event->payload,
                        'corrects_event_id' => $event->corrects_event_id, 'recorded_at' => $event->recorded_at->toIso8601String(),
                    ])->all(),
                ])->all(),
            ];
        });
    }

    public function erase(User $actor, BureaucracyPerson $person): void
    {
        DB::transaction(function () use ($actor, $person): void {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $current = BureaucracyPerson::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
            if ($current->record_status === 'erased' && $current->account_user_id === $actor->id && $actor->fresh()->hasVerifiedEmail()) {
                return;
            }
            $this->authorize($actor, $current);
            $this->purge($current);
        });
    }

    /**
     * Dependents for which this guardian holds any authority record, captured before an
     * account deletion cascades those records away.
     *
     * @return list<int>
     */
    public function dependentsGuardedBy(int $guardianUserId): array
    {
        return BureaucracyGuardianAuthority::query()->where('guardian_user_id', $guardianUserId)
            ->whereIn('person_id', BureaucracyPerson::query()->where('kind', 'dependent')->whereNull('account_user_id')
                ->where('record_status', 'active')->select('id'))
            ->distinct()->pluck('person_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Erase a dependent dossier that nobody can manage any more. A dependent is kept while
     * any guardian holds an approved, unrevoked, unexpired authority; with $keepRecentPending
     * an unreviewed request younger than the pending window also keeps it.
     */
    public function eraseUnguardedDependent(int $personId, bool $keepRecentPending = false): bool
    {
        return DB::transaction(function () use ($personId, $keepRecentPending): bool {
            $current = BureaucracyPerson::query()->whereKey($personId)->where('kind', 'dependent')->whereNull('account_user_id')
                ->where('record_status', 'active')->lockForUpdate()->first();
            if ($current === null || $this->access->hasLiveGuardian($current, $keepRecentPending)) {
                return false;
            }
            $this->purge($current);

            return true;
        });
    }

    /** Must run inside the caller's transaction with the person row locked. */
    private function purge(BureaucracyPerson $current): void
    {
        $case = BureaucracyCase::query()->where('person_id', $current->id)->lockForUpdate()->first();
        $this->refresh->beforeErasure($current);
        if ($case !== null) {
            if ($case->user_id !== null) {
                $subject = User::query()->findOrFail($case->user_id);
                $this->legacyUsage->preserve($subject, [$case->id]);
            }
            BureaucracyProcessingConsent::query()->where('case_id', $case->id)->update([
                'withdrawn_at' => now()->utc(), 'state' => 'withdrawn', 'result' => null,
            ]);
            $case->conflicts()->delete();
            $case->facts()->delete();
            $case->questions()->delete();
            $case->planSnapshots()->delete();
            $case->messages()->delete();
            BureaucracyQuestionSession::query()->where('case_id', $case->id)->delete();
            BureaucracyProcess::query()->where('case_id', $case->id)->delete();
            $case->update(['status' => 'erased', 'fact_version' => $case->fact_version + 1]);
        }
        $current->grants()->update(['revoked_at' => now()->utc()]);
        BureaucracyOnboardingDraft::query()->where('person_id', $current->id)->delete();
        BureaucracyEvidenceItem::query()->where('person_id', $current->id)->delete();
        BureaucracyRelationship::query()->where('person_id', $current->id)->orWhere('related_person_id', $current->id)->delete();
        if ($current->account_user_id !== null) {
            $subject = User::query()->findOrFail($current->account_user_id);
            $keys = $this->registry->all()->keys()->all();
            $subject->update(['profile_attributes' => array_diff_key($subject->profile_attributes ?? [], array_flip($keys))]);
            $subject->attributeChanges()->whereIn('attribute', $keys)->delete();
            $subject->userTasks()->delete();
            Alert::query()->where('user_id', $subject->id)
                ->where(fn ($query) => $query->where('category', 'bureaucracy')->orWhereNotNull('guidance_reference'))->delete();
            $subject->notifications()->whereIn('type', [
                BureaucracyDeadlineNotification::class,
                PermanentResidencyEligibleNotification::class,
            ])->delete();
        }
        $current->update(['display_label' => null, 'record_status' => 'erased', 'record_version' => $current->record_version + 1, 'erased_at' => now()->utc()]);
        BureaucracyGuardianAuthority::query()->where('person_id', $current->id)
            ->update(['status' => 'revoked', 'revoked_at' => now()->utc(), 'evidence_reference' => null]);
        $this->recordErasure($current, $current->account_user_id);
    }

    private function authorize(User $actor, BureaucracyPerson $person): void
    {
        if (! $this->access->canManage($actor, $person)) {
            throw new AuthorizationException;
        }
    }

    public function accountWasDeleted(int $personId, int $userId): void
    {
        DB::transaction(function () use ($personId, $userId): void {
            $person = BureaucracyPerson::query()->whereKey($personId)->whereNull('account_user_id')->where('kind', 'adult')->lockForUpdate()->first();
            if ($person === null) {
                return;
            }
            $this->refresh->beforeErasure($person);
            $person->grants()->update(['revoked_at' => now()->utc()]);
            BureaucracyOnboardingDraft::query()->where('person_id', $person->id)->delete();
            BureaucracyEvidenceItem::query()->where('person_id', $person->id)->delete();
            BureaucracyRelationship::query()->where('person_id', $person->id)->orWhere('related_person_id', $person->id)->delete();
            $person->update(['display_label' => null, 'record_status' => 'erased', 'erased_at' => now()->utc(), 'record_version' => $person->record_version + 1]);
            $this->recordErasure($person, $userId);
        });
    }

    private function recordErasure(BureaucracyPerson $person, ?int $userId): void
    {
        BureaucracyOutboxEvent::query()->firstOrCreate(['dedupe_key' => "person.erased:{$person->id}:{$person->record_version}"], [
            'event_type' => 'person.erased', 'aggregate_type' => 'person', 'aggregate_id' => $person->id,
            'aggregate_version' => $person->record_version, 'payload' => ['subject_user_id' => $userId],
            'available_at' => now()->utc(),
        ]);
    }
}
