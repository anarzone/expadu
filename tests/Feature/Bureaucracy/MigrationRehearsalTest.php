<?php

use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Migration\BackfillPersonDossiers;
use App\Bureaucracy\Migration\LegacyMigrationPlanner;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyEvidenceItem;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyWorkspace;
use App\Models\User;
use App\Models\UserTask;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

test('dossier rehearsal reads existing answers but neither attaches identities nor imports completed work', function () {
    $actor = User::factory()->onboarded()->create(['name' => 'Synthetic private name']);
    $case = BureaucracyCase::factory()->for($actor)->create();
    $case->facts()->create(['key' => 'current_residence_title', 'value' => 'blue_card', 'state' => 'confirmed', 'source' => 'onboarding', 'confirmed_at' => now()]);
    $case->facts()->create(['key' => 'citizenship_group', 'value' => 'non_eu', 'state' => 'confirmed', 'source' => 'onboarding', 'confirmed_at' => now()]);
    UserTask::factory()->for($actor)->create(['status' => 'done', 'documents_checked' => ['Synthetic private paper']]);

    $report = app(LegacyMigrationPlanner::class)->for($actor);

    expect($report['status'])->toBe('ready')
        ->and($report['facts'])->toBe(2)->and($report['answer_states'])->toMatchArray(['value' => 1, 'needs_reconfirmation' => 1])
        ->and($report['legacy_progress_retained'])->toBe(1)
        ->and(json_encode($report))->not->toContain($actor->name, $actor->email, 'blue_card', 'non_eu', 'Synthetic private paper')
        ->and(BureaucracyPerson::query()->count())->toBe(0)
        ->and(BureaucracyWorkspace::query()->count())->toBe(0)
        ->and(BureaucracyOutboxEvent::query()->count())->toBe(0);
    $this->artisan('bureaucracy:migrate-dossiers', ['--dry-run' => true])->assertSuccessful();
    expect($case->fresh()->person_id)->toBeNull();
});

test('account attachment preserves encrypted answers and old progress byte for byte without replaying either into new work', function () {
    $actor = User::factory()->onboarded()->create();
    $case = BureaucracyCase::factory()->for($actor)->create(['fact_version' => 7]);
    $fact = $case->facts()->create(['key' => 'current_residence_title', 'value' => 'blue_card', 'state' => 'confirmed', 'source' => 'onboarding', 'confirmed_at' => now()->subDay()]);
    $legacy = UserTask::factory()->for($actor)->create(['status' => 'done', 'documents_checked' => ['Synthetic paper']]);
    $factsBefore = $fact->fresh()->getRawOriginal();
    $progressBefore = $legacy->fresh()->getRawOriginal();
    $userBefore = $actor->fresh()->getRawOriginal();
    $planner = app(LegacyMigrationPlanner::class);
    $backfill = app(BackfillPersonDossiers::class);

    $first = $backfill->execute($actor, $planner->for($actor)['fingerprint']);
    $again = $backfill->execute($actor, $planner->for($actor)['fingerprint']);

    expect($first['status'])->toBe('attached')->and($again['status'])->toBe('already_linked')
        ->and($case->fresh()->person->account_user_id)->toBe($actor->id)
        ->and($case->fresh()->fact_version)->toBe(7)
        ->and($fact->fresh()->getRawOriginal())->toBe($factsBefore)
        ->and($legacy->fresh()->getRawOriginal())->toBe($progressBefore)
        ->and($actor->fresh()->getRawOriginal())->toBe($userBefore)
        ->and(BureaucracyPerson::query()->count())->toBe(1)
        ->and(BureaucracyProcess::query()->count())->toBe(0)
        ->and(BureaucracyEvidenceItem::query()->count())->toBe(0)
        ->and(BureaucracyOutboxEvent::query()->where('event_type', 'person.reassessment_requested')->count())->toBe(1);
});

test('changed answers invalidate the reviewed attachment fingerprint without creating a person', function () {
    $actor = User::factory()->onboarded()->create();
    $case = BureaucracyCase::factory()->for($actor)->create();
    $review = app(LegacyMigrationPlanner::class)->for($actor);
    $case->increment('fact_version');
    expect(fn () => app(BackfillPersonDossiers::class)->execute($actor, $review['fingerprint']))->toThrow(ConflictHttpException::class);
    expect(BureaucracyPerson::query()->count())->toBe(0);
});

test('backfill cannot revive erased dossiers or attach another persons identity', function (string $problem) {
    $actor = User::factory()->onboarded()->create($problem === 'unverified' ? ['email_verified_at' => null] : []);
    $case = BureaucracyCase::factory()->for($actor)->create(['status' => $problem === 'erased' ? 'erased' : 'active']);
    if ($problem === 'other_person') {
        $case->update(['person_id' => app(EnsureAccountHolder::class)->person(User::factory()->onboarded()->create())->id]);
    }
    $before = $case->fresh()->getRawOriginal();
    $report = app(LegacyMigrationPlanner::class)->for($actor);
    expect($report['status'])->not->toBeIn(['ready', 'already_linked']);
    expect(fn () => app(BackfillPersonDossiers::class)->execute($actor, $report['fingerprint']))->toThrow(ConflictHttpException::class);
    expect($case->fresh()->getRawOriginal())->toBe($before)
        ->and(BureaucracyPerson::query()->where('account_user_id', $actor->id)->count())->toBe(0);
})->with(['erased', 'other_person', 'unverified']);

test('bounded backfill resumes by account cursor and never grants administrator rights', function () {
    $first = User::factory()->onboarded()->create(['is_admin' => false]);
    $second = User::factory()->onboarded()->create(['is_admin' => false]);
    $this->artisan('bureaucracy:migrate-dossiers', ['--apply' => true, '--limit' => '1'])->assertSuccessful();
    expect(BureaucracyPerson::query()->where('account_user_id', $first->id)->count())->toBe(1)
        ->and(BureaucracyPerson::query()->where('account_user_id', $second->id)->count())->toBe(0);
    $this->artisan('bureaucracy:migrate-dossiers', ['--apply' => true, '--after' => (string) $first->id, '--limit' => '1'])->assertSuccessful();
    $this->artisan('bureaucracy:migrate-dossiers', ['--apply' => true])->assertSuccessful();
    expect(BureaucracyPerson::query()->count())->toBe(2)
        ->and($first->fresh()->is_admin)->toBeFalse()->and($second->fresh()->is_admin)->toBeFalse()
        ->and(BureaucracyProcess::query()->count())->toBe(0);
});

test('attachment review includes question provenance and newly opened conflicts even without a fact revision change', function (string $change) {
    $actor = User::factory()->onboarded()->create();
    $case = BureaucracyCase::factory()->for($actor)->create();
    $question = BureaucracyCaseQuestion::factory()->create(['case_id' => $case->id, 'fact_key' => 'current_residence_title', 'answered_at' => now(), 'outcome' => 'answered']);
    $case->facts()->create(['key' => 'current_residence_title', 'value' => 'blue_card', 'state' => 'confirmed',
        'source' => 'structured_interview', 'source_reference' => 'question:'.$question->id, 'confirmed_at' => now()]);
    $candidate = $case->facts()->create(['key' => 'current_residence_title', 'value' => 'other', 'state' => 'candidate', 'source' => 'manual']);
    $review = app(LegacyMigrationPlanner::class)->for($actor);
    if ($change === 'question') {
        $question->update(['outcome' => 'skipped']);
    } else {
        app(CaseFactStore::class)->confirmCandidate($candidate);
    }
    expect($case->fresh()->fact_version)->toBe($review['fact_revision']);
    expect(fn () => app(BackfillPersonDossiers::class)->execute($actor, $review['fingerprint']))->toThrow(ConflictHttpException::class);
    expect(BureaucracyPerson::query()->where('account_user_id', $actor->id)->count())->toBe(0);
})->with(['question', 'conflict']);
