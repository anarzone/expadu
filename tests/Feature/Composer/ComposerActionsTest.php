<?php

use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Composer\Actions\ComposerActions;
use App\Composer\Answers\EventAnswers;
use App\Composer\Assistant\AssistantTools;
use App\Composer\CandidateRepository;
use App\Composer\WorkspaceStore;
use App\Enums\LocationSource;
use App\Models\BureaucracyCaseFact;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Services\LocationContext;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Tests\Support\ReviewedHomePlan;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->user = User::factory()->onboarded()->create();
});

function visaOn(User $user): ?string
{
    $case = $user->bureaucracyCase()->firstOrFail();

    return app(ConfirmedFactView::class)->forCase($case, now()->toDateString())['values']['visa_expires_at'] ?? null;
}

function savedState(User $user, Spot $spot): ?string
{
    $state = SpotFeedback::query()->where('user_id', $user->id)->where('spot_id', $spot->id)->value('state');

    return $state?->value ?? $state;
}

test('a Paperwork answer is proposed, applied as a confirmed extraction, and undone through Paperwork’s correction', function () {
    ReviewedHomePlan::activate($this->user, ['fixture.entry' => ['title' => 'Synthetic preparation']],
        ['arrival_date' => '2026-09-01', 'visa_expires_at' => '2026-10-01']);
    // Even when they don't ask first: an answer read from their words is theirs to confirm.
    $this->user->userSetting()->updateOrCreate(['user_id' => $this->user->id], ['settings' => ['composer_ask_first_paperwork' => false]]);
    $actions = app(ComposerActions::class);

    $made = $actions->request($this->user, 'change_paperwork_fact', ['key' => 'visa_expires_at', 'value' => '2027-02-12'], CarbonImmutable::now());
    expect($made['block'])->toMatchArray(['type' => 'changed', 'domain' => 'paperwork', 'state' => 'proposed', 'apply' => true,
        'title' => 'Change when your visa runs out?', 'from' => '1 Oct 2026', 'to' => '12 Feb 2027'])
        ->and($made['said'])->toStartWith('Proposed, not done yet')
        ->and(visaOn($this->user))->toBe('2026-10-01');

    $applied = $actions->apply($this->user, $made['block']['id'], CarbonImmutable::now());
    $fact = BureaucracyCaseFact::query()->where('key', 'visa_expires_at')->latest('id')->firstOrFail();
    expect($applied['refusal'])->toBeNull()
        ->and($applied['block'])->toMatchArray(['state' => 'done', 'undo' => true, 'title' => 'Your visa now runs until 12 Feb 2027'])
        ->and($applied['block'])->not->toHaveKey('apply')
        ->and(visaOn($this->user))->toBe('2027-02-12')
        ->and($fact->provenance['input_method'])->toBe('confirmed_extraction');

    $undone = $actions->undo($this->user, $made['block']['id'], CarbonImmutable::now());
    expect($undone['refusal'])->toBeNull()
        ->and($undone['block']['state'])->toBe('undone')
        ->and($undone['block']['title'])->toStartWith('Undone. Your visa runs until 1 Oct 2026 again')
        ->and($undone['block'])->not->toHaveKeys(['undo', 'apply'])
        ->and(visaOn($this->user))->toBe('2026-10-01');
});

test('a saved place is made at once with Undo, and proposed when they ask first', function () {
    $spot = Spot::factory()->create(['name' => 'Bau', 'category' => 'cafe', 'veedel' => 'Mülheim', 'is_active' => true]);
    $actions = app(ComposerActions::class);

    $made = $actions->request($this->user, 'place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'saved'], CarbonImmutable::now());
    expect($made['block'])->toMatchArray(['state' => 'done', 'undo' => true, 'title' => 'Saved Bau'])
        ->and(savedState($this->user, $spot))->toBe('saved');
    $actions->undo($this->user, $made['block']['id'], CarbonImmutable::now());
    expect(savedState($this->user, $spot))->toBeNull();

    $this->user->userSetting()->updateOrCreate(['user_id' => $this->user->id], ['settings' => ['composer_ask_first_everyday' => true]]);
    $proposed = $actions->request($this->user->fresh(), 'place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'saved'], CarbonImmutable::now());
    expect($proposed['block'])->toMatchArray(['state' => 'proposed', 'title' => 'Save Bau for later?'])
        ->and(savedState($this->user, $spot))->toBeNull();
    $actions->apply($this->user, $proposed['block']['id'], CarbonImmutable::now());
    expect(savedState($this->user, $spot))->toBe('saved');
});

test('only the person who asked can apply or undo a change, and only for 24 hours', function () {
    $spot = Spot::factory()->create(['name' => 'Bau', 'category' => 'cafe', 'is_active' => true]);
    $made = app(ComposerActions::class)->request($this->user, 'place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'saved'], CarbonImmutable::now());
    $token = $made['block']['id'];

    $this->actingAs(User::factory()->onboarded()->create())->postJson("/composer/actions/{$token}/undo")->assertNotFound();
    expect(savedState($this->user, $spot))->toBe('saved');

    $this->travel(25)->hours();
    $this->actingAs($this->user)->postJson("/composer/actions/{$token}/undo")->assertSuccessful()
        ->assertJsonPath('block.state', 'done')
        ->assertJsonPath('refusal', 'This change can no longer be undone here. Change it back where it’s kept.');
    expect(savedState($this->user, $spot))->toBe('saved');
});

test('without their Paperwork permission for the message, Paperwork is neither read nor changed', function () {
    ReviewedHomePlan::activate($this->user, ['fixture.entry' => ['title' => 'Synthetic preparation']],
        ['arrival_date' => '2026-09-01', 'visa_expires_at' => '2026-10-01']);
    $paperwork = fn (bool $permitted): string => collect(AssistantTools::definitions(false, false, $permitted))->firstWhere('name', 'paperwork')['description'];
    expect($paperwork(false))->toContain('You don\'t get its contents')
        ->and($paperwork(true))->toContain('They allowed you to read it for this message');

    $tools = new AssistantTools($this->user, CarbonImmutable::now(), new LocationContext(null, null, LocationSource::None),
        app(CandidateRepository::class), app(EventAnswers::class), app(WeatherService::class));
    expect($tools->run('change_paperwork_fact', ['key' => 'visa_expires_at', 'value' => '2027-02-12']))->toContain('Not done')
        ->and($tools->cards)->toBeEmpty()
        ->and(visaOn($this->user))->toBe('2026-10-01');
});

test('erasing Paperwork removes the copies Composer keeps of it', function () {
    ReviewedHomePlan::activate($this->user, ['fixture.entry' => ['title' => 'Synthetic preparation']],
        ['arrival_date' => '2026-09-01', 'visa_expires_at' => '2026-10-01']);
    $spot = Spot::factory()->create(['name' => 'Bau', 'category' => 'cafe', 'is_active' => true]);
    $actions = app(ComposerActions::class);
    $paperwork = $actions->request($this->user, 'change_paperwork_fact', ['key' => 'visa_expires_at', 'value' => '2027-02-12'], CarbonImmutable::now())['block'];
    $place = $actions->request($this->user, 'place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'saved'], CarbonImmutable::now())['block'];
    $store = app(WorkspaceStore::class);
    $store->answer($this->user, 'initial', ['text' => 'Your visa runs until 1 Oct 2026.', 'extra' => ['blocks' => [$paperwork, $place]]], null);

    $store->purgePaperworkCopies($this->user->id);

    $message = collect($store->read($this->user)['conversations'][0]['messages'])->last();
    expect($store->action($this->user, $paperwork['id']))->toBeNull()
        ->and($store->action($this->user, $place['id']))->not->toBeNull()
        ->and(array_column($message['blocks'], 'id'))->toBe([$place['id']])
        ->and($message['text'])->not->toContain('1 Oct 2026');
});
