<?php

use App\Bureaucracy\Cases\AnswerCaseQuestion;
use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\QuestionSelector;
use App\Enums\BureaucracyCoverageState;
use App\Models\BureaucracyCase;
use App\Models\Task;
use App\Models\User;

test('an unknown family citizenship condition asks a real question instead of losing the rule', function (string $answer, bool $applies) {
    $user = User::factory()->onboarded()->create([
        'situation' => 'family_reunification', 'is_eu' => false,
        'bureaucracy_path' => 'family_reunification', 'profile_attributes' => [],
    ]);
    $case = BureaucracyCase::factory()->for($user)->create();
    Task::factory()->approvedFixture()->create([
        'key' => 'fixture.family-citizenship',
        'applies_if' => [['purpose' => 'family', 'sponsor' => 'non_eu']],
    ]);

    $match = app(CaseMatcher::class)->match($case);
    expect($match->coverageState)->toBe(BureaucracyCoverageState::NeedsInformation)
        ->and($match->missingFactKeys)->toBe(['sponsor'])
        ->and($match->matchedRuleKeys)->toBe([]);

    $question = app(QuestionSelector::class)->select($case, $match);
    expect($question->fact_key)->toBe('sponsor');
    app(AnswerCaseQuestion::class)->answer($user, $question, $answer);

    $after = app(CaseMatcher::class)->match($case);
    expect($after->matchedRuleKeys)->toBe($applies ? ['fixture.family-citizenship'] : [])
        ->and($after->missingFactKeys)->toBe([])
        ->and($case->facts()->where('state', 'confirmed')->sole()->value)->toBe($answer);
})->with([
    'joining a non-EU citizen' => ['non_eu', true],
    'joining a German citizen' => ['german', false],
    'joining another EU citizen' => ['eu_citizen', false],
]);
