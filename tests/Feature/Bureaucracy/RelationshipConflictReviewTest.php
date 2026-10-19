<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\People\RecordRelationship;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyCaseFact;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

function relatedConflictSponsor(User $applicant, $case): array
{
    $sponsor = User::factory()->onboarded()->create();
    $sponsorCase = app(EnsureAccountHolder::class)->dossier($sponsor);
    $invite = app(ManageDelegation::class)->invite($applicant, $case->person->workspace, $sponsor->email, ['view_facts']);
    app(ManageDelegation::class)->accept($sponsor, $invite['token'], ['view_facts']);
    app(RecordFactChange::class)->execute($sponsor, $sponsorCase->person, 'current_residence_title', 'blue_card', null, 1);
    app(RecordFactChange::class)->execute($sponsor, $sponsorCase->person, 'citizenship_group', 'non_eu', null, 2);
    $link = app(RecordRelationship::class)->execute($applicant, $case->person, $sponsorCase->person, 'sponsor', null, $case->person->fresh()->record_version);

    return [$sponsor, $sponsorCase, $link];
}

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->actor = User::factory()->onboarded()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'citizenship_group', 'non_eu', null, 1);
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'arrival_planned', true, null, 2);
    $task = Task::factory()->approvedFixture()->create([
        'key' => 'fixture.relationship-review', 'type' => 'task', 'deadline_type' => 'none', 'depends_on' => [],
        'applies_if' => [['sponsor' => 'non_eu', 'sponsor_current_title' => 'blue_card']],
        'documents_required' => [], 'how_to_steps' => [], 'links' => [],
    ])->fresh();
    $artifact = app(CatalogueCompiler::class)->compile([$task], [
        $task->key => ['process_id' => $task->key, 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial'],
    ]);
    $release = app(CatalogueReleaseStore::class)->stage($artifact);
    app(CatalogueReleaseStore::class)->activate($release->id, null);
    $this->base = '/bureaucracy/v2/people/'.$this->case->person_id;
    $this->actingAs($this->actor);
});

test('multiple sponsors open relationship review and removing the incorrect link unblocks the question flow', function () {
    [$one, $oneCase, $oneLink] = relatedConflictSponsor($this->actor, $this->case);
    [$two, $twoCase, $twoLink] = relatedConflictSponsor($this->actor, $this->case);
    $session = $this->postJson($this->base.'/question-sessions', ['request_id' => (string) Str::uuid(), 'jurisdiction' => 'de-nrw-cologne'])
        ->assertCreated()->json('session_id');
    $next = '/bureaucracy/v2/question-sessions/'.$session.'/next';
    $this->postJson($next, ['request_id' => (string) Str::uuid()])->assertOk()
        ->assertJsonPath('question.kind', 'review_relationship')
        ->assertJsonPath('question.action.type', 'review_relationships')
        ->assertJsonPath('question.action.reason', 'multiple_sponsors');
    $this->getJson($this->base.'/fact-conflicts')->assertJsonCount(0, 'conflicts');
    $review = $this->getJson($this->base.'/relationships')->assertOk()->assertJsonCount(2, 'relationships')->json();
    expect($review['reviews'][0]['reason'])->toBe('multiple_sponsors')
        ->and($review['relationships'][1]['can_remove'])->toBeTrue();
    $this->deleteJson($this->base.'/relationships/'.$twoLink->id)->assertNoContent();
    $this->postJson($next, ['request_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('status', 'no_more_questions');
    expect($oneLink->fresh()->revoked_at)->toBeNull();
});

test('a linked report mismatch can be reviewed and corrected without editing the sponsors own record', function () {
    [$sponsor, $sponsorCase] = relatedConflictSponsor($this->actor, $this->case);
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'sponsor_current_title', 'settlement_permit_18c', null, 3);
    $this->getJson($this->base.'/question-preview?jurisdiction=de-nrw-cologne')->assertOk()
        ->assertJsonPath('question.kind', 'review_relationship')->assertJsonPath('question.action.reason', 'linked_report_mismatch');
    $review = $this->getJson($this->base.'/relationships')->assertOk()->json();
    expect($review['reported_facts']['values']['sponsor_current_title'])->toBe('settlement_permit_18c')
        ->and($review['relationships'][0]['linked_facts']['sponsor_current_title'])->toBe('blue_card')
        ->and($review['relationships'][0]['can_edit_related_facts'])->toBeFalse();
    $this->putJson($this->base.'/facts/sponsor_current_title', ['expected_revision' => $review['fact_revision'],
        'value' => 'blue_card', 'effective_from' => '2026-09-08'])->assertOk();
    $this->getJson($this->base.'/question-preview?jurisdiction=de-nrw-cologne')->assertOk()->assertJsonPath('status', 'no_more_questions');
    expect($sponsorCase->fresh()->fact_version)->toBe(3);
});

test('relationship review never exposes revoked sponsor values or labels and requires fact reading permission', function () {
    [$sponsor, $sponsorCase] = relatedConflictSponsor($this->actor, $this->case);
    $grant = BureaucracyAccessGrant::query()->where('person_id', $sponsorCase->person_id)->sole();
    app(ManageDelegation::class)->revoke($sponsor, $grant);
    $this->getJson($this->base.'/relationships')->assertOk()
        ->assertJsonPath('relationships.0.linked_facts', [])->assertJsonPath('relationships.0.display_label', null)
        ->assertJsonPath('relationships.0.can_edit_related_facts', false);
    $helper = User::factory()->onboarded()->create();
    $helperCase = app(EnsureAccountHolder::class)->dossier($helper);
    $invitation = app(ManageDelegation::class)->invite($helper, $helperCase->person->workspace, $this->actor->email, ['view_plan', 'edit_facts']);
    app(ManageDelegation::class)->accept($this->actor, $invitation['token'], ['view_plan', 'edit_facts']);
    $this->actingAs($helper)->getJson($this->base.'/relationships')->assertForbidden();
});

test('a local assertion conflict is resolved before a relationship mismatch', function () {
    relatedConflictSponsor($this->actor, $this->case);
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'sponsor_current_title', 'settlement_permit_18c', null, 3);
    BureaucracyCaseFact::factory()->create(['case_id' => $this->case->id, 'key' => 'sponsor_current_title',
        'value' => 'blue_card', 'source' => 'attributed_report']);
    $this->getJson($this->base.'/question-preview?jurisdiction=de-nrw-cologne')->assertOk()
        ->assertJsonPath('question.kind', 'resolve_conflict')->assertJsonPath('question.action.type', 'review_fact_conflict');
    $this->getJson($this->base.'/fact-conflicts')->assertOk()->assertJsonCount(1, 'conflicts');
});
