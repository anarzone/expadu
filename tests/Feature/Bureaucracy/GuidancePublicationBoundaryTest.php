<?php

use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\PendingAnswers;
use App\Bureaucracy\Cases\QuestionSelector;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\PathGenerator;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\PermanentResidencyEligibility;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\ContextEngine\ActionBus;
use App\ContextEngine\ContextNotificationFactory;
use App\ContextEngine\Evaluators\BureaucracyEvaluator;
use App\Home\CurrentBureaucracyPlan;
use App\Home\DiscoveryFeed;
use App\Models\BureaucracyCase;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use App\Profile\ProfileEngine;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    Notification::fake();
});

function publicationBoundaryRule(array $overrides = []): Task
{
    // Synthetic content: approval metadata is a test fixture, not a legal review.
    return Task::factory()->create(array_replace([
        'key' => 'fixture.publication',
        'title' => 'Synthetic approved task',
        'description' => 'Synthetic test guidance.',
        'applies_if' => [['citizenship_group' => 'non_eu']],
        'is_published' => true,
        'review_status' => 'approved',
        'jurisdiction' => 'de-nrw-cologne',
        'content_version' => 'fixture.1',
        'reviewed_by' => 'synthetic_test_fixture',
        'source_verification' => 'dual_source',
        'verified_at' => '2026-09-01',
        'review_due_at' => '2026-12-01',
        'legal_sources' => [
            ['kind' => 'primary', 'label' => 'Fixture statute', 'url' => 'https://www.gesetze-im-internet.de/bmg/__17.html'],
            ['kind' => 'implementation', 'label' => 'Fixture authority', 'url' => 'https://www.stadt-koeln.de/service/produkte/00415/index.html'],
        ],
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 14,
        'phase' => 'first_weeks',
    ], $overrides));
}

function publicationBoundaryUser(): User
{
    return User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'arrival_date' => '2026-09-01',
        'german_level' => null,
        'profile_attributes' => [],
    ]);
}

function activatePublicationBoundary(User $user, Task $task): BureaucracyCase
{
    $user->update(['city' => 'Köln']);
    $case = app(EnsureAccountHolder::class)->dossier($user);
    app(RecordFactChange::class)->execute($user, $case->person, 'citizenship_group', 'non_eu', null, $case->fresh()->fact_version);
    app(RecordFactChange::class)->execute($user, $case->person, 'arrival_date', '2026-09-01', null, $case->fresh()->fact_version);
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$task->fresh()], [
        $task->key => ['process_id' => $task->key, 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial'],
    ]));
    $store->activate($release->id, null);

    return $case->fresh();
}

dataset('withdrawn guidance states', [
    'legacy' => [['review_status' => 'legacy']],
    'unpublished' => [['is_published' => false]],
    'expired review' => [['review_due_at' => '2026-09-07']],
    'not effective yet' => [['effective_from' => '2026-09-09']],
    'no longer effective' => [['effective_to' => '2026-09-07']],
    'unapproved source host' => [['legal_sources' => [
        ['kind' => 'primary', 'label' => 'Untrusted', 'url' => 'https://example.com/not-law'],
    ]]],
]);

test('path generation materialises only authoritative guidance', function (array $state) {
    $user = publicationBoundaryUser();
    $task = publicationBoundaryRule($state);

    app(PathGenerator::class)->ensure($user);

    expect($user->userTasks()->where('task_id', $task->id)->exists())->toBeFalse();
})->with('withdrawn guidance states');

test('valid activated guidance creates a reminder without materialising legacy task rows', function () {
    $user = publicationBoundaryUser();
    $task = publicationBoundaryRule();
    activatePublicationBoundary($user, $task);
    app(BureaucracyEvaluator::class)->evaluate($user);
    expect(app(ActionBus::class)->topK($user->id))->toHaveCount(1)
        ->and($user->userTasks()->count())->toBe(0);
});

test('warm actions are rechecked after a source review expires without a fact change', function () {
    $user = publicationBoundaryUser();
    $task = publicationBoundaryRule(['review_due_at' => '2026-09-08']);
    activatePublicationBoundary($user, $task);
    $userTask = UserTask::factory()->for($user)->for($task)->create(['is_applicable' => true]);
    app(BureaucracyEvaluator::class)->evaluate($user, $userTask);
    $actions = app(ActionBus::class)->topK($user->id);
    expect($actions)->toHaveCount(1);

    $this->travelTo('2026-09-09 10:00:00');

    expect(app(ActionBus::class)->topK($user->id))->toBeEmpty();
    expect(app(ContextNotificationFactory::class)->build($actions[0]))->toBeNull();
    expect($userTask->fresh())->not->toBeNull();
});

test('duration alone never yields a personalised permanent residence eligibility claim', function () {
    $user = publicationBoundaryUser();
    $user->update(['profile_attributes' => ['permit_held_since' => '2016-01-01']]);

    expect(app(PermanentResidencyEligibility::class)->for(app(ProfileEngine::class)->build($user)))->toBeNull();
});

test('unapproved rules cannot trigger a legal interview', function () {
    $user = publicationBoundaryUser();
    $case = BureaucracyCase::factory()->for($user)->create();
    publicationBoundaryRule([
        'review_status' => 'legacy',
        'applies_if' => [['german_level' => 'b1']],
    ]);

    expect(app(PendingAnswers::class)->forCase($case))->not->toContain('german_level');
});

test('a missing fact needed by an approved universal rule remains askable', function () {
    $user = publicationBoundaryUser();
    $user->update(['german_level' => null]);
    $case = BureaucracyCase::factory()->for($user)->create();
    publicationBoundaryRule([
        'coverage_scope' => 'universal',
        'applies_if' => [['german_level' => 'b1']],
    ]);

    $result = app(CaseMatcher::class)->match($case);
    expect($result->missingFactKeys)->toContain('german_level');
    expect(app(QuestionSelector::class)->rankedFactKeys($case, $result))->toContain('german_level');
});

test('a queued notification rechecks approval when it is eventually sent', function () {
    $user = publicationBoundaryUser();
    $task = publicationBoundaryRule();
    activatePublicationBoundary($user, $task);
    $row = UserTask::factory()->for($user)->for($task)->create(['is_applicable' => true]);
    app(BureaucracyEvaluator::class)->evaluate($user, $row);
    $action = app(ActionBus::class)->topK($user->id)[0];
    $notification = app(ContextNotificationFactory::class)->build($action);
    expect($notification->shouldSend($user, 'database'))->toBeTrue();

    $task->update(['review_status' => 'legacy']);

    expect($notification->shouldSend($user, 'database'))->toBeFalse();
});

test('saved alerts retain history but withdraw stale legal wording', function () {
    $user = publicationBoundaryUser();
    $task = publicationBoundaryRule();
    activatePublicationBoundary($user, $task);
    $row = UserTask::factory()->for($user)->for($task)->create(['is_applicable' => true]);
    app(BureaucracyEvaluator::class)->evaluate($user, $row);
    expect($user->alerts()->where('subtype', 'bureaucracy_deadline')->count())->toBe(1);
    $task->update(['review_status' => 'legacy']);

    $this->actingAs($user)->get('/alerts')->assertInertia(function ($page) {
        $alert = $page->toArray()['props']['alerts'][0];
        expect($alert['title'])->toBe('Saved guidance needs review')
            ->and($alert['guidance_status'])->toBe('review_required')
            ->and($alert['body'])->not->toContain('Synthetic approved task');

        return true;
    });
    expect($user->alerts()->where('subtype', 'bureaucracy_deadline')->count())->toBe(1);
});

test('legacy permanent residence alerts are not replayed as current eligibility', function () {
    $user = publicationBoundaryUser();
    $user->alerts()->create([
        'type' => 'system', 'subtype' => 'permanent_residency', 'lane' => 'good', 'severity' => 'success',
        'title' => 'You may now qualify for permanent residency', 'body' => 'Duration-only legacy claim.',
    ]);

    // Duration-only estimates are hidden outright (launch: Alert::publishable), not re-labelled.
    $this->actingAs($user)->get('/alerts')->assertInertia(fn ($page) => $page->has('alerts', 0)->where('unreadCount', 0));
    expect($user->alerts()->where('subtype', 'permanent_residency')->count())->toBe(1);
});

test('a queued bureaucracy notification cannot be sent to a different account or after completion', function () {
    $user = publicationBoundaryUser();
    $task = publicationBoundaryRule();
    $case = activatePublicationBoundary($user, $task);
    $row = UserTask::factory()->for($user)->for($task)->create(['is_applicable' => true]);
    app(BureaucracyEvaluator::class)->evaluate($user, $row);
    $notification = app(ContextNotificationFactory::class)->build(app(ActionBus::class)->topK($user->id)[0]);

    expect($notification->shouldSend(publicationBoundaryUser(), 'database'))->toBeFalse();
    $process = app(ReconcileProcesses::class)->execute($user, $case->person, 'de-nrw-cologne')[0];
    app(RecordProcessEvent::class)->execute($user, $process, 'step_completed', ['step_id' => $task->key.'.complete'],
        $process->version, (string) Str::uuid());
    expect($notification->shouldSend($user, 'database'))->toBeFalse();
});

test('a dry run neither materialises tasks nor reopens completed recurring work', function () {
    $user = publicationBoundaryUser();
    publicationBoundaryRule();
    $recurring = publicationBoundaryRule(['key' => 'fixture.recurring', 'recurrence_months' => 12]);
    $row = UserTask::factory()->for($user)->for($recurring)->create([
        'status' => 'done', 'completed_at' => now()->subYear(), 'next_due_at' => now()->subDay(),
    ]);

    $this->artisan('bureaucracy:remind', ['--user' => $user->id, '--dry-run' => true])->assertSuccessful();

    expect($user->userTasks()->count())->toBe(1)
        ->and($row->fresh()->status->value)->toBe('done')
        ->and($row->fresh()->completed_at)->not->toBeNull();
});

test('a prebuilt home context cannot replay withdrawn rule text', function (string $surface, string $change) {
    $user = publicationBoundaryUser();
    $task = publicationBoundaryRule();
    $case = activatePublicationBoundary($user, $task);
    UserTask::factory()->for($user)->for($task)->create(['is_applicable' => true]);
    $context = homeContext($user, ['bureaucracyPlan' => app(AccountHolderPlan::class)->for($user)]);
    $visible = fn () => match ($surface) {
        'paperwork' => collect(app(DiscoveryFeed::class)->for($context))->contains('key', 'paperwork'),
    };
    expect($visible())->toBeTrue();
    match ($change) {
        'withdrawal' => $task->update(['review_status' => 'legacy']),
        'content edit' => $task->update(['description' => 'Unreviewed replacement text.']),
        'city' => $user->update(['city' => 'Uncovered city']),
        'verification' => $user->forceFill(['email_verified_at' => null])->save(),
        'inactive dossier' => $case->update(['status' => 'closed']),
        'retired person' => $case->person->update(['record_status' => 'erased', 'erased_at' => now()]),
        'facts' => app(RecordFactChange::class)->execute($user, $case->person, 'citizenship_group', 'eu', null, $case->fresh()->fact_version),
    };

    expect($visible())->toBeFalse();
})->with(['paperwork'])->with(['withdrawal', 'content edit', 'city', 'verification', 'inactive dossier', 'retired person', 'facts']);

test('home never trusts a foreign or tampered plan snapshot', function (string $change) {
    $user = publicationBoundaryUser();
    $task = publicationBoundaryRule();
    activatePublicationBoundary($user, $task);
    $plan = app(AccountHolderPlan::class)->for($user);
    if ($change === 'foreign subject') {
        $plan['person_id'] = (string) Str::uuid();
    } else {
        $plan['overview']['next_actions'][0]['title'] = 'Injected unreviewed guidance';
        $plan['actions'][0]['title'] = 'Injected unreviewed guidance';
    }
    $context = homeContext($user, ['bureaucracyPlan' => $plan]);
    $current = app(CurrentBureaucracyPlan::class)->for($context);

    if ($change === 'foreign subject') {
        expect($current)->toBeNull();
    } else {
        expect($current)->not->toBeNull()
            ->and(json_encode($current))->not->toContain('Injected unreviewed guidance')
            ->and($current['actions'][0]['title'])->toBe($task->title);
    }
})->with(['foreign subject', 'tampered text']);

test('same-version content edits withdraw old actions alerts and queued wording', function () {
    $user = publicationBoundaryUser();
    $task = publicationBoundaryRule();
    activatePublicationBoundary($user, $task);
    $row = UserTask::factory()->for($user)->for($task)->create(['is_applicable' => true]);
    app(BureaucracyEvaluator::class)->evaluate($user, $row);
    $action = app(ActionBus::class)->topK($user->id)[0];
    $notification = app(ContextNotificationFactory::class)->build($action);
    $task->update(['title' => 'Corrected synthetic task']);

    expect($notification->shouldSend($user, 'database'))->toBeFalse()
        ->and(app(ActionBus::class)->topK($user->id))->toBeEmpty();
    $this->actingAs($user)->get('/alerts')->assertInertia(function ($page) {
        expect($page->toArray()['props']['alerts'][0]['guidance_status'])->toBe('review_required');

        return true;
    });
});
