<?php

use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\CaseMatchResult;
use App\Bureaucracy\Cases\CasePlanComposer;
use App\Bureaucracy\Cases\QuestionSelector;
use App\Enums\BureaucracyCoverageState;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyCaseQuestion;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->travelTo('2026-08-03 10:00:00');
});

/**
 * @param  list<array<string, mixed>>|null  $appliesIf
 * @param  array<string, mixed>  $overrides
 */
function task5MatcherRule(string $key, ?array $appliesIf = null, array $overrides = []): Task
{
    return Task::factory()->create(array_replace([
        'key' => $key,
        'title' => "Rule {$key}",
        'description' => "Verified guidance for {$key}.",
        'type' => 'task',
        'applies_if' => $appliesIf,
        'phase' => 'arrival',
        'depends_on' => [],
        'deadline_type' => 'none',
        'deadline_days' => null,
        'urgency' => 'medium',
        'is_published' => true,
        'jurisdiction' => 'de-nrw-cologne',
        'legal_sources' => [
            [
                'kind' => 'primary',
                'label' => 'AufenthG',
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
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $facts
 * @param  array<string, mixed>  $userOverrides
 */
function task5MatcherCase(array $facts = [], array $userOverrides = []): BureaucracyCase
{
    $user = User::factory()->onboarded()->create(array_replace([
        'situation' => 'other',
        'is_eu' => false,
        'german_level' => null,
        'profile_attributes' => [],
    ], $userOverrides));

    $case = BureaucracyCase::factory()->for($user)->create();

    foreach ($facts as $key => $value) {
        BureaucracyCaseFact::factory()->create([
            'case_id' => $case->id,
            'key' => $key,
            'value' => $value,
            'state' => 'confirmed',
            'confirmed_at' => now(),
            'reconfirm_at' => now()->addYear(),
            'superseded_at' => null,
        ]);
    }

    return $case;
}

test('an approved case rule produces matched while universal guidance stays independently visible', function () {
    task5MatcherRule('case.blue-card', [[
        'current_residence_title' => 'blue_card',
    ]]);
    task5MatcherRule('universal.keep-documents', null, [
        'coverage_scope' => 'universal',
        'content_version' => '2026-08-03.2',
    ]);

    $result = app(CaseMatcher::class)->match(task5MatcherCase([
        'current_residence_title' => 'blue_card',
    ]));

    expect($result->coverageState)->toBe(BureaucracyCoverageState::Matched)
        ->and($result->matchedRuleKeys)->toBe(['case.blue-card'])
        ->and($result->safeRuleKeys)->toBe(['case.blue-card'])
        ->and($result->universalRuleKeys)->toBe(['universal.keep-documents'])
        ->and($result->ruleVersions)->toMatchArray([
            'case.blue-card' => '2026-08-03.1',
            'universal.keep-documents' => '2026-08-03.2',
        ]);
});

test('an unresolved approved case rule produces needs information with its registered fact', function () {
    task5MatcherRule('case.renewal', [[
        'current_residence_title' => 'family_reunification',
        'case_goal' => 'renew_current_title',
    ]]);

    $result = app(CaseMatcher::class)->match(task5MatcherCase([
        'current_residence_title' => 'family_reunification',
    ]));

    expect($result->coverageState)->toBe(BureaucracyCoverageState::NeedsInformation)
        ->and($result->matchedRuleKeys)->toBe([])
        ->and($result->unknownRuleKeys)->toBe(['case.renewal'])
        ->and($result->missingFactKeys)->toBe(['case_goal']);
});

test('unresolved high impact branches keep safe matches visible without claiming full coverage', function () {
    task5MatcherRule('case.safe-registration', [['purpose' => 'family']]);
    task5MatcherRule('case.sponsor-dependent', [[
        'purpose' => 'family',
        'sponsor_current_title' => 'blue_card',
    ]]);

    $case = task5MatcherCase(['purpose' => 'family']);
    $result = app(CaseMatcher::class)->match($case);
    $sections = app(CasePlanComposer::class)->compose($case, $result);

    expect($result->coverageState)->toBe(BureaucracyCoverageState::NeedsInformation)
        ->and($result->safeRuleKeys)->toBe(['case.safe-registration'])
        ->and(collect($sections['do_now'])->pluck('key')->all())->toBe(['case.safe-registration'])
        ->and(collect($sections['information_needed'])->pluck('key')->all())->toBe(['case.sponsor-dependent']);
});

test('matcher accepts exact trusted predicates compiled from a persona branch', function () {
    task5MatcherRule('case.family-branch', [[
        'purpose' => 'family',
        'sponsor' => 'non_eu',
        'case_goal' => 'renew_current_title',
    ]]);

    $result = app(CaseMatcher::class)->match(task5MatcherCase([
        'case_goal' => 'renew_current_title',
    ], [
        'situation' => 'family_reunification',
    ]));

    expect($result->coverageState)->toBe(BureaucracyCoverageState::Matched)
        ->and($result->matchedRuleKeys)->toBe(['case.family-branch']);
});

test('composer routes informational phases and exposes fact-date deadlines', function () {
    task5MatcherRule('case.timeline', [['case_goal' => 'settlement_permit']], [
        'type' => 'info',
        'phase' => 'ongoing',
    ]);
    task5MatcherRule('case.pending', [['case_goal' => 'settlement_permit']], [
        'type' => 'info',
        'phase' => 'waiting',
    ]);
    task5MatcherRule('case.renew', [['case_goal' => 'settlement_permit']], [
        'deadline_type' => 'fact_date',
        'deadline_fact_key' => 'residence_title_expires_at',
    ]);

    $case = task5MatcherCase([
        'case_goal' => 'settlement_permit',
        'residence_title_expires_at' => '2026-09-01',
    ]);
    $sections = app(CasePlanComposer::class)->compose($case, app(CaseMatcher::class)->match($case));

    expect(collect($sections['coming_up'])->pluck('key')->all())->toBe(['case.timeline'])
        ->and(collect($sections['waiting'])->pluck('key')->all())->toBe(['case.pending'])
        ->and(collect($sections['do_now'])->firstWhere('key', 'case.renew')['deadline'])->toBe('2026-09-01');
});

test('universal guidance cannot turn an unsupported case into matched', function () {
    task5MatcherRule('case.blue-card', [[
        'current_residence_title' => 'blue_card',
    ]]);
    task5MatcherRule('universal.keep-documents', null, [
        'coverage_scope' => 'universal',
    ]);

    $result = app(CaseMatcher::class)->match(task5MatcherCase([
        'current_residence_title' => 'other',
    ]));

    expect($result->coverageState)->toBe(BureaucracyCoverageState::NotCovered)
        ->and($result->matchedRuleKeys)->toBe([])
        ->and($result->universalRuleKeys)->toBe(['universal.keep-documents']);
});

test('conflicting matches are quarantined while independent safe rules remain composable', function () {
    task5MatcherRule('case.route-a', [['current_residence_title' => 'blue_card']], [
        'conflicts_with' => ['case.route-b'],
    ]);
    task5MatcherRule('case.route-b', [['current_residence_title' => 'blue_card']], [
        'conflicts_with' => ['case.route-a'],
    ]);
    task5MatcherRule('case.safe-registration', [['current_residence_title' => 'blue_card']]);
    task5MatcherRule('universal.keep-documents', null, [
        'coverage_scope' => 'universal',
    ]);

    $case = task5MatcherCase(['current_residence_title' => 'blue_card']);
    $result = app(CaseMatcher::class)->match($case);
    $sections = app(CasePlanComposer::class)->compose($case, $result);
    $composedRuleKeys = collect($sections)
        ->flatten(1)
        ->pluck('key')
        ->filter()
        ->sort()
        ->values()
        ->all();

    expect($result->coverageState)->toBe(BureaucracyCoverageState::Conflict)
        ->and($result->conflictPairs)->toBe([['case.route-a', 'case.route-b']])
        ->and($result->safeRuleKeys)->toBe(['case.safe-registration'])
        ->and(array_keys($sections))->toBe([
            'current_status',
            'do_now',
            'next',
            'coming_up',
            'options',
            'waiting',
            'information_needed',
            'not_covered',
        ])
        ->and($composedRuleKeys)->toBe(['case.safe-registration', 'universal.keep-documents']);
});

test('matcher ignores unpublished unapproved stale source-invalid and unregistered rules', function () {
    task5MatcherRule('valid.rule', [['case_goal' => 'blue_card']]);
    task5MatcherRule('draft.rule', [['case_goal' => 'blue_card']], ['is_published' => false]);
    task5MatcherRule('legacy.rule', [['case_goal' => 'blue_card']], ['review_status' => 'legacy']);
    task5MatcherRule('stale.rule', [['case_goal' => 'blue_card']], ['review_due_at' => '2026-08-02']);
    task5MatcherRule('bad-source.rule', [['case_goal' => 'blue_card']], [
        'legal_sources' => [[
            'kind' => 'primary',
            'label' => 'Lookalike',
            'url' => 'https://gesetze-im-internet.de.example.com/rule',
        ]],
        'source_verification' => 'single_source_approved',
    ]);
    task5MatcherRule('bad-fact.rule', [['not_registered' => 'value']]);

    $result = app(CaseMatcher::class)->match(task5MatcherCase(['case_goal' => 'blue_card']));

    expect($result->coverageState)->toBe(BureaucracyCoverageState::Matched)
        ->and($result->matchedRuleKeys)->toBe(['valid.rule'])
        ->and(array_keys($result->ruleVersions))->toBe(['valid.rule']);
});

test('stale confirmed facts do not decide a high impact branch', function () {
    task5MatcherRule('case.blue-card', [['current_residence_title' => 'blue_card']]);
    $case = task5MatcherCase();

    BureaucracyCaseFact::factory()->create([
        'case_id' => $case->id,
        'key' => 'current_residence_title',
        'value' => 'blue_card',
        'state' => 'confirmed',
        'confirmed_at' => now()->subYear(),
        'reconfirm_at' => now()->subSecond(),
    ]);

    $result = app(CaseMatcher::class)->match($case);

    expect($result->coverageState)->toBe(BureaucracyCoverageState::NeedsInformation)
        ->and($result->missingFactKeys)->toBe(['current_residence_title']);
});

test('stale durable facts cannot fall back to an older onboarding value', function () {
    task5MatcherRule('case.language-route', [['german_level' => 'b1']]);
    $case = task5MatcherCase([], ['german_level' => 'b1']);

    BureaucracyCaseFact::factory()->create([
        'case_id' => $case->id,
        'key' => 'german_level',
        'value' => 'b1',
        'state' => 'confirmed',
        'confirmed_at' => now()->subYear(),
        'reconfirm_at' => now()->subSecond(),
    ]);

    $result = app(CaseMatcher::class)->match($case);

    expect($result->coverageState)->toBe(BureaucracyCoverageState::NeedsInformation)
        ->and($result->matchedRuleKeys)->toBe([])
        ->and($result->missingFactKeys)->toBe(['german_level']);
});

test('question ranking follows risk branch elimination action unlock sensitivity and reuse', function () {
    task5MatcherRule('unknown.sponsor', [['sponsor_current_title' => 'blue_card']], [
        'urgency' => 'critical',
    ]);
    task5MatcherRule('unknown.goal-a', [['case_goal' => 'blue_card']], ['urgency' => 'high']);
    task5MatcherRule('unknown.goal-b', [['case_goal' => 'settlement_permit']], ['urgency' => 'high']);
    task5MatcherRule('unknown.hours', [['weekly_work_hours' => ['gte' => 20]]], ['urgency' => 'high']);
    task5MatcherRule('unknown.language', [['german_level' => ['in' => ['b1', 'b2', 'c1', 'c2']]]], [
        'urgency' => 'high',
        'type' => 'info',
        'phase' => 'ongoing',
    ]);
    task5MatcherRule('unknown.housing', [['housing_sufficient' => 'yes']], [
        'urgency' => 'high',
        'type' => 'info',
        'phase' => 'ongoing',
    ]);
    task5MatcherRule('unknown.title', [['current_residence_title' => 'blue_card']], [
        'urgency' => 'high',
        'type' => 'info',
        'phase' => 'ongoing',
    ]);
    task5MatcherRule('known-no.reuses-housing', [[
        'citizenship_group' => 'eu',
        'housing_sufficient' => 'yes',
    ]], [
        'urgency' => 'low',
        'type' => 'info',
    ]);

    $case = task5MatcherCase();
    $result = app(CaseMatcher::class)->match($case);

    expect(app(QuestionSelector::class)->rankedFactKeys($case, $result))->toBe([
        'sponsor_current_title',
        'case_goal',
        'weekly_work_hours',
        'housing_sufficient',
        'german_level',
        'current_residence_title',
    ]);
});

test('question selection reuses an unanswered question and stops after three attempts for one branch', function () {
    task5MatcherRule('unknown.goal', [['case_goal' => 'blue_card']]);
    $case = task5MatcherCase();
    $result = app(CaseMatcher::class)->match($case);
    $selector = app(QuestionSelector::class);

    $first = $selector->select($case, $result);
    $reloaded = $selector->select($case, $result);

    expect($first)->not->toBeNull()
        ->and($reloaded?->id)->toBe($first?->id)
        ->and(BureaucracyCaseQuestion::where('case_id', $case->id)->count())->toBe(1);

    $first?->update(['answered_at' => now(), 'outcome' => 'still_unknown']);
    $second = $selector->select($case, $result);
    $second?->update(['answered_at' => now(), 'outcome' => 'still_unknown']);
    $third = $selector->select($case, $result);
    $third?->update(['answered_at' => now(), 'outcome' => 'still_unknown']);

    expect($second?->attempt)->toBe(2)
        ->and($third?->attempt)->toBe(3)
        ->and($selector->select($case, $result))->toBeNull()
        ->and(BureaucracyCaseQuestion::where('case_id', $case->id)->count())->toBe(3);
});

test('question selection never exceeds the twelve-question case budget', function () {
    task5MatcherRule('unknown.goal', [['case_goal' => 'blue_card']]);
    $case = task5MatcherCase();

    foreach (range(1, 12) as $attempt) {
        BureaucracyCaseQuestion::factory()->create([
            'case_id' => $case->id,
            'fact_key' => 'citizenship_group',
            'attempt' => $attempt,
            'asked_at' => now()->subMinutes(20 - $attempt),
            'answered_at' => now()->subMinutes(19 - $attempt),
            'outcome' => 'answered',
        ]);
    }

    $result = app(CaseMatcher::class)->match($case);

    expect(app(QuestionSelector::class)->select($case, $result))->toBeNull()
        ->and(BureaucracyCaseQuestion::where('case_id', $case->id)->count())->toBe(12);
});

test('question selector rejects a fabricated unregistered missing fact', function () {
    $case = task5MatcherCase();
    $result = new CaseMatchResult(
        coverageState: BureaucracyCoverageState::NeedsInformation,
        matchedRuleKeys: [],
        ruleVersions: [],
        missingFactKeys: ['fabricated_fact'],
        conflictPairs: [],
        safeRuleKeys: [],
        universalRuleKeys: [],
        unknownRuleKeys: [],
    );

    expect(app(QuestionSelector::class)->select($case, $result))->toBeNull();
});
