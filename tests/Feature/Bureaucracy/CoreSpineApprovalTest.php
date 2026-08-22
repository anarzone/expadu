<?php

use App\Bureaucracy\RuleSourcePolicy;
use App\Models\Task;

/**
 * The civilian spine — Anmeldung, Steuer-ID, health insurance, bank account —
 * was published but never reviewed, so `Task::authoritative()` refused it. The
 * checklist showed it anyway (PathGenerator filters on `is_published` alone),
 * but QuestionSelector only ever asks for facts an *approved* rule is blocked
 * on. With eleven approved rules, all Blue Card and family reunification, a
 * student who skipped a question was never asked again.
 *
 * Two of the six could not be approved and are deliberately not:
 * the Rundfunkbeitrag rests on a treaty between the Länder, which appears on
 * no allow-listed primary host, and private liability insurance rests on no
 * statute at all.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
});

it('admits the four core tasks that have a statute behind them', function (string $key, string $primary) {
    $task = Task::authoritative()->where('key', $key)->first();

    expect($task)->not->toBeNull("{$key} must pass the authoritative gate");

    $urls = collect($task->legal_sources)
        ->where('kind', 'primary')
        ->pluck('url');

    expect($urls)->toContain($primary)
        ->and($task->jurisdiction)->toBe('de-nrw-cologne')
        ->and($task->reviewed_by)->not->toBeEmpty();
})->with([
    'Anmeldung' => ['core.anmeldung', 'https://www.gesetze-im-internet.de/bmg/__17.html'],
    'Steuer-ID' => ['core.steuer_id', 'https://www.gesetze-im-internet.de/ao_1977/__139b.html'],
    'health insurance' => ['core.health_insurance', 'https://www.gesetze-im-internet.de/sgb_5/__5.html'],
    'bank account' => ['core.bank_account', 'https://www.gesetze-im-internet.de/zkg/__31.html'],
]);

it('keeps every approved core task inside the source policy', function () {
    $policy = new RuleSourcePolicy;

    $approved = Task::query()
        ->where('key', 'like', 'core.%')
        ->where('review_status', RuleSourcePolicy::Approved)
        ->get();

    expect($approved)->toHaveCount(4);

    foreach ($approved as $task) {
        expect($policy->persistedErrors($task))->toBe([], "{$task->key} violates the source policy");
    }
});

it('carries the tax ID on an explicit single-source approval', function () {
    $task = Task::query()->where('key', 'core.steuer_id')->firstOrFail();

    // No allow-listed implementation host covers the Steuer-ID: bzst.de is not
    // on the list, and Cologne's only page mentioning it is about ELStAM. The
    // flag is the sanctioned way to say "the statute alone, reviewed by a human".
    expect($task->source_verification)->toBe(RuleSourcePolicy::SingleSourceApproved)
        ->and(collect($task->legal_sources)->where('kind', 'implementation'))->toBeEmpty();
});

it('refuses to approve the broadcasting fee while its treaty is off the allowlist', function () {
    $task = Task::query()->where('key', 'core.rundfunkbeitrag')->firstOrFail();

    // Deliberate: the RBStV is Landesrecht. Approving it would mean widening
    // config/bureaucracy_sources.php, which is a publication-policy decision.
    // It stays a task with a checkbox — the obligation is real either way.
    expect($task->review_status)->toBe(RuleSourcePolicy::Legacy)
        ->and($task->type)->toBe('task')
        ->and(Task::authoritative()->where('key', 'core.rundfunkbeitrag')->exists())->toBeFalse();
});

it('demotes voluntary liability insurance to an info card', function () {
    $task = Task::query()->where('key', 'core.liability_insurance')->firstOrFail();

    // Privathaftpflicht is not required by any statute, so it must not carry a
    // checkbox or count towards "x of y done".
    expect($task->isInfo())->toBeTrue()
        ->and($task->review_status)->toBe(RuleSourcePolicy::Legacy);
});

it('resolves the broadcasting fee from the figures config, not hardcoded copy', function () {
    $task = Task::query()->where('key', 'core.rundfunkbeitrag')->firstOrFail();

    expect($task->description)
        ->not->toContain('{{figure:')
        ->and($task->description)->toContain(config('bureaucracy_figures.rundfunkbeitrag_monthly.value'));
});
