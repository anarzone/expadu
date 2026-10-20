<?php

use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\People\RecordRelationship;
use App\Bureaucracy\People\RelationshipFactView;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyRelationship;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

test('sponsor data is read through revocable permissions and is never copied into either dossier', function () {
    $applicant = User::factory()->onboarded()->create();
    $sponsor = User::factory()->onboarded()->create();
    $holder = app(EnsureAccountHolder::class);
    $applicantCase = $holder->dossier($applicant);
    $sponsorCase = $holder->dossier($sponsor);
    $invite = app(ManageDelegation::class)->invite($applicant, $applicantCase->person->workspace, $sponsor->email, ['view_facts']);
    app(ManageDelegation::class)->accept($sponsor, $invite['token'], ['view_facts']);
    $link = app(RecordRelationship::class)->execute($applicant, $applicantCase->person, $sponsorCase->person, 'sponsor', '2024-01-01', $applicantCase->person->fresh()->record_version);
    app(RecordFactChange::class)->execute($sponsor, $sponsorCase->person, 'current_residence_title', 'settlement_permit_18c', '2025-01-01', 1);
    app(RecordFactChange::class)->execute($applicant, $applicantCase->person, 'sponsor_title_at_entry', 'blue_card_pending', null, 1);
    $view = app(RelationshipFactView::class)->forApplicant($applicant, $applicantCase->person, $link->id, now()->toDateString());
    expect($view['values']['sponsor_current_title'])->toBe('settlement_permit_18c')
        ->and($applicantCase->facts()->where('key', 'sponsor_current_title')->count())->toBe(0)
        ->and($sponsorCase->facts()->where('key', 'sponsor_title_at_entry')->count())->toBe(0)
        ->and($applicantCase->facts()->where('key', 'sponsor_title_at_entry')->sole()->source)->toBe('attributed_report');
    $grant = BureaucracyAccessGrant::query()->where('person_id', $sponsorCase->person_id)->sole();
    app(ManageDelegation::class)->revoke($sponsor, $grant);
    $after = app(RelationshipFactView::class)->forApplicant($applicant, $applicantCase->person, $link->id, now()->toDateString());
    expect($after['status'])->toBe('access_unavailable')->and($after['values'])->toBe([])
        ->and($after['dependency_token'])->not->toBe($view['dependency_token']);
});

test('a relationship does not grant access or reveal an unrelated persons facts', function () {
    $actor = User::factory()->onboarded()->create();
    $stranger = User::factory()->onboarded()->create();
    $one = app(EnsureAccountHolder::class)->dossier($actor)->person;
    $two = app(EnsureAccountHolder::class)->dossier($stranger)->person;
    expect(fn () => app(RecordRelationship::class)->execute($actor, $one, $two, 'sponsor', null, $one->record_version))->toThrow(AuthorizationException::class);
    $forged = BureaucracyRelationship::query()->create([
        'workspace_id' => $one->workspace_id, 'person_id' => $one->id, 'related_person_id' => $two->id,
        'type' => 'sponsor', 'confirmed_by' => $actor->id, 'source' => 'synthetic_untrusted', 'confirmed_at' => now(),
    ]);
    $result = app(RelationshipFactView::class)->forApplicant($actor, $one, $forged->id, now()->toDateString());
    expect($result['values'])->toBe([])->and($result['status'])->toBe('access_unavailable');
});

test('shared sponsor facts do not assume the same household address', function () {
    $actor = User::factory()->onboarded()->create();
    $sponsor = User::factory()->onboarded()->create();
    $one = app(EnsureAccountHolder::class)->dossier($actor);
    $two = app(EnsureAccountHolder::class)->dossier($sponsor);
    $invite = app(ManageDelegation::class)->invite($actor, $one->person->workspace, $sponsor->email, ['view_facts']);
    app(ManageDelegation::class)->accept($sponsor, $invite['token'], ['view_facts']);
    $link = app(RecordRelationship::class)->execute($actor, $one->person, $two->person, 'sponsor', null, $one->person->fresh()->record_version);
    app(RecordFactChange::class)->execute($sponsor, $two->person, 'moved_in_at', '2026-01-01', null, 1);
    $view = app(RelationshipFactView::class)->forApplicant($actor, $one->person, $link->id, now()->toDateString());
    expect($view['values'])->not->toHaveKey('moved_in_at')->not->toHaveKey('marital_household_continues');
});

test('an undated sponsor relationship is not assumed to exist before it was confirmed', function () {
    $actor = User::factory()->onboarded()->create();
    $sponsor = User::factory()->onboarded()->create();
    $one = app(EnsureAccountHolder::class)->dossier($actor);
    $two = app(EnsureAccountHolder::class)->dossier($sponsor);
    $invite = app(ManageDelegation::class)->invite($actor, $one->person->workspace, $sponsor->email, ['view_facts']);
    app(ManageDelegation::class)->accept($sponsor, $invite['token'], ['view_facts']);
    $link = app(RecordRelationship::class)->execute($actor, $one->person, $two->person, 'sponsor', null, $one->person->fresh()->record_version);
    app(RecordFactChange::class)->execute($sponsor, $two->person, 'current_residence_title', 'blue_card', '2024-01-01', 1);
    $view = app(RelationshipFactView::class);
    expect($view->forApplicant($actor, $one->person, $link->id, '2025-01-01')['status'])->toBe('relationship_unavailable')
        ->and($view->forApplicant($actor, $one->person, $link->id, now()->toDateString())['values']['sponsor_current_title'])->toBe('blue_card');
});

test('a delayed relationship retry cannot overwrite a newer correction', function () {
    $actor = User::factory()->onboarded()->create();
    $sponsor = User::factory()->onboarded()->create();
    $one = app(EnsureAccountHolder::class)->dossier($actor);
    $two = app(EnsureAccountHolder::class)->dossier($sponsor);
    $invite = app(ManageDelegation::class)->invite($actor, $one->person->workspace, $sponsor->email, ['view_facts']);
    app(ManageDelegation::class)->accept($sponsor, $invite['token'], ['view_facts']);
    $records = app(RecordRelationship::class);
    $version = $one->person->fresh()->record_version;
    $records->execute($actor, $one->person, $two->person, 'sponsor', '2024-01-01', $version);
    $corrected = $records->execute($actor, $one->person, $two->person, 'sponsor', '2024-02-01', $version + 1);
    expect(fn () => $records->execute($actor, $one->person, $two->person, 'sponsor', '2024-01-01', $version))
        ->toThrow(ConflictHttpException::class);
    expect($corrected->fresh()->revoked_at)->toBeNull()->and($one->person->fresh()->record_version)->toBe($version + 2);
});
