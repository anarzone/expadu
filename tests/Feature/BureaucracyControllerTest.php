<?php

use App\Enums\TaskStatus;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  list<array<string, mixed>>|null  $appliesIf
 * @param  array<string, mixed>  $overrides
 */
function bureaucracyControllerApprovedRule(string $key, ?array $appliesIf, array $overrides = []): Task
{
    return Task::factory()->create(array_replace([
        'key' => $key,
        'title' => 'Apply for your verified residence route',
        'description' => 'Follow the verified application route before your current permission expires.',
        'type' => 'task',
        'situation' => ['non_eu_employee_blue_card'],
        'applies_if' => $appliesIf,
        'phase' => 'arrival',
        'depends_on' => [],
        'deadline_type' => 'none',
        'deadline_days' => null,
        'urgency' => 'high',
        'documents_required' => ['Valid passport'],
        'how_to_steps' => [['title' => 'Check the route', 'body' => 'Read the official procedure before submitting.']],
        'legal_sources' => [
            [
                'kind' => 'primary',
                'label' => 'Residence Act §18g',
                'url' => 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html',
            ],
            [
                'kind' => 'implementation',
                'label' => 'Stadt Köln',
                'url' => 'https://www.stadt-koeln.de/service/produkte/20321/index.html',
            ],
        ],
        'review_status' => 'approved',
        'source_verification' => 'dual_source',
        'reviewed_by' => 'expadu_content_owner',
        'content_version' => '2026-08-03.1',
        'verified_at' => '2026-08-03',
        'review_due_at' => '2027-08-03',
        'conflicts_with' => [],
        'coverage_scope' => 'case',
        'jurisdiction' => 'de-nrw-cologne',
        'is_published' => true,
    ], $overrides));
}

test('bureaucracy page renders for an onboarded user', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $this->get(route('bureaucracy'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('bureaucracy')
            ->has('tasks')
        );
});

test('bureaucracy page requires authentication', function () {
    $this->get(route('bureaucracy'))
        ->assertRedirect(route('login'));
});

test('live bureaucracy page exposes a source-backed verified case plan', function () {
    $this->travelTo('2026-08-04 10:00:00');

    bureaucracyControllerApprovedRule('case.test-action', [[
        'citizenship_group' => 'non_eu',
        'purpose' => 'employment',
        'permit_track' => 'blue_card',
    ]]);

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'bureaucracy_path' => 'non_eu_employee_blue_card',
        'german_level' => 'b1',
        'profile_attributes' => ['entry_mode' => 'd_visa'],
    ]);

    $this->actingAs($user)
        ->get(route('bureaucracy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bureaucracy')
            ->where('casePlan.coverage_state', 'matched')
            ->where('casePlan.sections.do_now.0.key', 'case.test-action')
            ->where('casePlan.sections.do_now.0.legal_sources.0.url', 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html')
            ->where('casePlan.sections.do_now.0.verified_at', '2026-08-03')
            ->where('casePlan.sections.do_now.0.status', 'not_started')
            ->where('casePlan.sections.do_now.0.documents_required.0', 'Valid passport')
            ->where('casePlan.sections.do_now.0.documents_checked', [])
            ->where('casePlan.next_question', null)
            ->has('casePlan.generated_at')
            ->etc());
});

test('live bureaucracy page exposes one server-issued clarification question', function () {
    $this->travelTo('2026-08-04 10:00:00');

    bureaucracyControllerApprovedRule('case.needs-title', [[
        'current_residence_title' => 'blue_card',
    ]], [
        'situation' => ['core'],
    ]);

    $user = User::factory()->onboarded()->create([
        'situation' => 'other',
        'is_eu' => false,
        'german_level' => null,
        'profile_attributes' => [],
    ]);

    $this->actingAs($user)
        ->get(route('bureaucracy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('casePlan.coverage_state', 'needs_information')
            ->has('casePlan.next_question', fn (Assert $question) => $question
                ->has('id')
                ->where('type', 'enum')
                ->where('question', 'Which German visa or residence title do you currently hold?')
                ->where('why', 'Your current title changes the application route and which deadline applies.')
                ->where('sensitivity', 'high')
                ->where('attempt', 1)
                ->where('options.0.value', 'national_d_visa')
                ->where('options.0.label', 'National D visa')
                ->missing('case_id')
                ->missing('fact_key')
                ->missing('source'))
            ->etc());
});

test('live bureaucracy page exposes one sanitized conflict choice and no competing question', function () {
    bureaucracyControllerApprovedRule('case.conflict-route', [['case_goal' => 'blue_card']]);
    $user = User::factory()->onboarded()->create([
        'situation' => 'other',
        'is_eu' => false,
        'german_level' => null,
        'profile_attributes' => [],
    ]);
    $case = BureaucracyCase::factory()->for($user)->create();
    $existing = BureaucracyCaseFact::factory()->for($case, 'case')->create([
        'key' => 'case_goal',
        'value' => 'blue_card',
        'state' => 'confirmed',
        'reconfirm_at' => now()->subDay(),
    ]);
    $candidate = BureaucracyCaseFact::factory()->for($case, 'case')->candidate()->create([
        'key' => 'case_goal',
        'value' => 'settlement_permit',
    ]);
    BureaucracyFactConflict::factory()->for($case, 'case')->create([
        'fact_key' => 'case_goal',
        'existing_fact_id' => $existing->id,
        'candidate_fact_id' => $candidate->id,
        'status' => 'unresolved',
    ]);

    $this->actingAs($user)
        ->get(route('bureaucracy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('casePlan.coverage_state', 'conflict')
            ->where('casePlan.next_question', null)
            ->has('casePlan.active_conflict', fn (Assert $conflict) => $conflict
                ->has('id')
                ->where('question', 'What do you want to do next with your residence status?')
                ->where('options.0.choice', 'existing')
                ->where('options.0.label', 'EU Blue Card')
                ->where('options.0.context', 'Previously confirmed')
                ->where('options.1.choice', 'candidate')
                ->where('options.1.label', 'Apply for a settlement permit')
                ->where('options.1.context', 'New answer')
                ->missing('fact_key')
                ->missing('existing_fact_id')
                ->missing('candidate_fact_id'))
            ->etc());
});

test('an inapplicable historical task is presented as a fresh applicable step', function () {
    $task = bureaucracyControllerApprovedRule('case.reappeared', [['case_goal' => 'blue_card']]);
    $user = User::factory()->onboarded()->create([
        'situation' => 'other',
        'is_eu' => false,
        'german_level' => null,
        'profile_attributes' => [],
    ]);
    $case = BureaucracyCase::factory()->for($user)->create();
    BureaucracyCaseFact::factory()->for($case, 'case')->create([
        'key' => 'case_goal',
        'value' => 'blue_card',
        'state' => 'confirmed',
    ]);
    UserTask::factory()->for($user)->for($task)->create([
        'status' => TaskStatus::Submitted,
        'is_applicable' => false,
    ]);

    $this->actingAs($user)
        ->get(route('bureaucracy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('casePlan.sections.do_now.0.key', 'case.reappeared')
            ->where('casePlan.sections.do_now.0.status', 'not_started')
            ->where('casePlan.sections.waiting', [])
            ->etc());
});
