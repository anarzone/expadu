<?php

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\CasePlanComposer;
use App\Bureaucracy\Cases\CasePlanPresenter;
use App\Bureaucracy\Cases\CurrentCasePlan;
use App\Bureaucracy\QA\ScenarioFactSynchronizer;
use App\Models\BureaucracyPlanSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The demo page claims it "can never diverge from production rendering". It
 * did: `casePlan` is attached outside buildPayload, so the preview showed the
 * unreviewed catalogue and omitted the verified plan entirely — the half that
 * carries the legal content.
 *
 * It renders from an unsaved case now, so these pin the two things that could
 * quietly go wrong: the preview must agree with what the real page computes for
 * the same person, and it must never write a row while doing so.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks', ['--prune' => true])->assertSuccessful();
});

dataset('case personas', collect(BureaucracyPersonas::caseScenarios())
    ->map(fn (array $persona): array => [$persona['key']])
    ->values()
    ->all());

it('previews the same plan the real page would compute', function (string $key) {
    $persona = collect(BureaucracyPersonas::demo())->firstWhere('key', $key);

    // The real thing: a persisted case for a real user.
    $user = User::factory()->onboarded()->create(BureaucracyPersonas::persistableProfile($persona));
    $storedCase = app(ScenarioFactSynchronizer::class)->sync($user, $persona);
    $storedResult = app(CaseMatcher::class)->match($storedCase);
    $storedSections = app(CasePlanComposer::class)->compose($storedCase, $storedResult);

    // The preview: an admin viewing the same persona, nothing written.
    $admin = User::factory()->onboarded()->create(['is_admin' => true]);
    $response = $this->actingAs($admin)->get("/bureaucracy/demo?persona={$key}");
    $response->assertOk();

    $preview = $response->viewData('page')['props']['casePlan'];

    expect($preview['coverage_state'])->toBe($storedResult->coverageState->value, $key);

    foreach (array_keys($storedSections) as $section) {
        expect(collect($preview['sections'][$section])->pluck('key')->all())
            ->toBe(
                collect($storedSections[$section])->pluck('key')->all(),
                "{$key}: {$section}"
            );
    }
})->with('case personas');

it('writes nothing while previewing a persona', function () {
    $admin = User::factory()->onboarded()->create(['is_admin' => true]);

    $before = [
        'cases' => DB::table('bureaucracy_cases')->count(),
        'facts' => DB::table('bureaucracy_case_facts')->count(),
        'snapshots' => BureaucracyPlanSnapshot::count(),
        'user_tasks' => DB::table('user_tasks')->count(),
    ];

    foreach (BureaucracyPersonas::demo() as $persona) {
        $this->actingAs($admin)->get("/bureaucracy/demo?persona={$persona['key']}")->assertOk();
    }

    expect([
        'cases' => DB::table('bureaucracy_cases')->count(),
        'facts' => DB::table('bureaucracy_case_facts')->count(),
        'snapshots' => BureaucracyPlanSnapshot::count(),
        'user_tasks' => DB::table('user_tasks')->count(),
    ])->toBe($before);
});

it('hands the preview the same payload shape as the live page', function () {
    $persona = collect(BureaucracyPersonas::demo())->firstWhere('key', 'case-blue-card-first');
    $user = User::factory()->onboarded()->create(BureaucracyPersonas::persistableProfile($persona));
    $case = app(ScenarioFactSynchronizer::class)->sync($user, $persona);

    $live = app(CurrentCasePlan::class)->for($user);
    $preview = app(CasePlanPresenter::class)->preview($case, [], 'not_covered');

    // A key the live page carries and the preview does not is a card that
    // renders on one surface and breaks on the other.
    expect(array_keys($preview))->toBe(array_keys($live));
});
