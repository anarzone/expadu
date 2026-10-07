<?php

namespace App\Bureaucracy\Assessment;

use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\People\RelationshipFactView;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyRelationship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PrepareAssessmentInput
{
    public function __construct(private PersonAccess $access, private ConfirmedFactView $facts, private RelationshipFactView $relationships, private CatalogueReleaseStore $catalogues) {}

    /** Read-only boundary. It never creates people, facts, processes, sessions or offers. */
    public function for(User $actor, BureaucracyPerson $person, string $jurisdiction): AssessmentInput
    {
        return DB::transaction(function () use ($actor, $person, $jurisdiction): AssessmentInput {
            $subject = BureaucracyPerson::query()->whereKey($person->id)->sharedLock()->firstOrFail();
            $this->access->authorize($actor, $subject, AccessScope::ViewPlan);
            $case = BureaucracyCase::query()->where('person_id', $subject->id)->where('status', 'active')->sharedLock()->firstOrFail();
            $at = CarbonImmutable::now(config('bureaucracy_catalogue.jurisdictions.'.$jurisdiction.'.timezone', 'UTC'));
            $facts = $this->facts->forCase($case, $at->toDateString());
            $related = BureaucracyRelationship::query()->where('person_id', $subject->id)->where('type', 'sponsor')->whereNull('revoked_at')->orderBy('id')->get()
                ->map(fn ($link) => $this->relationships->forApplicant($actor, $subject, $link->id, $at->toDateString()))->all();
            $catalogue = $this->catalogues->current() ?? ['release_hash' => null, 'definitions' => [], 'withdrawn' => [], 'release_state' => 'not_activated'];

            $processes = BureaucracyProcess::query()->where('case_id', $case->id)->where('jurisdiction', $jurisdiction)->orderBy('id')->get()
                ->map(fn ($process) => ['id' => $process->id, 'definition_id' => $process->definition_id, 'topic' => $process->topic,
                    'occurrence_key' => $process->occurrence_key, 'context_id' => $process->context_id,
                    'catalogue_hash' => $process->catalogue_hash, 'version' => $process->version,
                    'step_definitions' => $process->step_definitions, 'state' => $process->state])->all();

            return new AssessmentInput($facts, $related, $processes, $catalogue, $jurisdiction, $at, $facts['values']['case_goal'] ?? null);
        });
    }
}
