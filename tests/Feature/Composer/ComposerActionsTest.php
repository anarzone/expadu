<?php

use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Composer\Actions\ComposerActions;
use App\Composer\Actions\PaperworkActions;
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

function withVisa(User $user): void
{
    ReviewedHomePlan::activate($user, ['fixture.entry' => ['title' => 'Synthetic preparation']],
        ['arrival_date' => '2026-09-01', 'visa_expires_at' => '2026-10-01']);
}

test('a Paperwork answer is proposed, applied as a confirmed extraction, and undone through Paperwork’s correction', function () {
    withVisa($this->user);
    // Even when they don't ask first: an answer read from their words is theirs to confirm.
    $this->user->userSetting()->updateOrCreate(['user_id' => $this->user->id], ['settings' => ['composer_ask_first_paperwork' => false]]);
    $actions = app(ComposerActions::class);
    $store = app(WorkspaceStore::class);

    $made = $actions->request($this->user, 'change_paperwork_fact', ['key' => 'visa_expires_at', 'value' => '2027-02-12'], CarbonImmutable::now());
    $token = $made['blocks'][0]['token'];
    expect($made['blocks'])->toHaveCount(1)
        ->and($made['blocks'][0])->toMatchArray(['type' => 'proposal', 'area' => 'paperwork', 'title' => 'Change when your visa runs out?',
            'from' => '1 Oct 2026', 'to' => '12 Feb 2027', 'fact_key' => 'visa_expires_at', 'edit' => ['kind' => 'date', 'value' => '2027-02-12']])
        ->and($made['said'])->toStartWith('Proposed, not done yet')
        ->and(visaOn($this->user))->toBe('2026-10-01');

    expect($actions->apply($this->user, $token, CarbonImmutable::now())['refusal'])->toBeNull();
    $done = $store->action($this->user, $token)['blocks'][0];
    $fact = BureaucracyCaseFact::query()->where('key', 'visa_expires_at')->latest('id')->firstOrFail();
    expect($done)->toMatchArray(['type' => 'done', 'text' => 'Your visa now runs until 12 Feb 2027.'])
        ->and($done['undo']['token'])->toBe($token)
        ->and(visaOn($this->user))->toBe('2027-02-12')
        ->and($fact->provenance['input_method'])->toBe('confirmed_extraction');

    expect($actions->undo($this->user, $token, CarbonImmutable::now())['refusal'])->toBeNull();
    expect($store->action($this->user, $token)['blocks'])->toHaveCount(1)
        ->and($store->action($this->user, $token)['blocks'][0])->toMatchArray(['type' => 'undone'])
        ->and($store->action($this->user, $token)['blocks'][0]['text'])->toStartWith('Undone. Your visa runs until 1 Oct 2026 again')
        ->and(visaOn($this->user))->toBe('2026-10-01');
});

test('a date typed into its record row is their own answer, proposed while they ask first', function () {
    withVisa($this->user);

    $this->actingAs($this->user)->postJson('/composer/act', ['action' => 'paperwork_fact', 'key' => 'visa_expires_at', 'value' => '2027-02-12'])
        ->assertSuccessful()->assertJsonPath('conversations.0.messages.0.blocks.0.type', 'proposal');
    expect(visaOn($this->user))->toBe('2026-10-01');

    $this->user->userSetting()->updateOrCreate(['user_id' => $this->user->id], ['settings' => ['composer_ask_first_paperwork' => false]]);
    $this->actingAs($this->user->fresh())->postJson('/composer/act', ['action' => 'paperwork_fact', 'key' => 'visa_expires_at', 'value' => '2027-03-01'])
        ->assertSuccessful()->assertJsonPath('conversations.0.messages.1.blocks.0.type', 'done');
    expect(visaOn($this->user))->toBe('2027-03-01')
        ->and(BureaucracyCaseFact::query()->where('key', 'visa_expires_at')->latest('id')->firstOrFail()->provenance['input_method'])->toBe('structured');
});

test('every Paperwork record row changes in place: a date, or the answer\'s own choices, saved as their own answer', function () {
    ReviewedHomePlan::activate($this->user, ['fixture.entry' => ['title' => 'Synthetic preparation']],
        ['arrival_date' => '2026-09-01', 'visa_expires_at' => '2026-10-01', 'registration_status' => 'not_registered', 'health_coverage_confirmed' => false]);

    $rows = collect(app(PaperworkActions::class)->record($this->user, CarbonImmutable::now())['block']['rows'])->keyBy('key');
    expect($rows['visa_expires_at']['edit'])->toBe(['kind' => 'date', 'value' => '2026-10-01'])
        ->and($rows['registration_status']['edit'])->toBe(['kind' => 'choice', 'type' => 'enum', 'value' => 'not_registered', 'options' => ['registered', 'not_registered']])
        ->and($rows['health_coverage_confirmed']['edit'])->toMatchArray(['kind' => 'choice', 'type' => 'boolean', 'value' => false])
        ->and($rows->every(fn (array $row): bool => isset($row['edit'])))->toBeTrue();

    // A tap on a choice: proposed while they ask first (the default for Paperwork), made with Undo when they don't.
    $this->actingAs($this->user)->postJson('/composer/act', ['action' => 'paperwork_fact', 'key' => 'registration_status', 'value' => 'registered'])
        ->assertSuccessful()->assertJsonPath('conversations.0.messages.0.blocks.0.type', 'proposal');
    $this->user->userSetting()->updateOrCreate(['user_id' => $this->user->id], ['settings' => ['composer_ask_first_paperwork' => false]]);
    $this->actingAs($this->user->fresh())->postJson('/composer/act', ['action' => 'paperwork_fact', 'key' => 'health_coverage_confirmed', 'value' => 'true'])
        ->assertSuccessful()->assertJsonPath('conversations.0.messages.1.blocks.0.type', 'done');
    $values = app(ConfirmedFactView::class)->forCase($this->user->bureaucracyCase()->firstOrFail(), now()->toDateString())['values'];
    expect($values['health_coverage_confirmed'])->toBeTrue()->and($values['registration_status'])->toBe('not_registered');

    // A value that isn't one of its choices changes nothing.
    $this->actingAs($this->user->fresh())->postJson('/composer/act', ['action' => 'paperwork_fact', 'key' => 'registration_status', 'value' => 'maybe'])
        ->assertStatus(409);
});

test('a saved place is made at once with Undo, and proposed when they ask first', function () {
    $spot = Spot::factory()->create(['name' => 'Bau', 'category' => 'cafe', 'veedel' => 'Mülheim', 'is_active' => true]);
    $actions = app(ComposerActions::class);

    $made = $actions->request($this->user, 'place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'saved'], CarbonImmutable::now());
    expect($made['blocks'][0])->toMatchArray(['type' => 'done', 'area' => 'everyday', 'text' => 'Saved Bau.'])
        ->and(savedState($this->user, $spot))->toBe('saved');
    $actions->undo($this->user, $made['blocks'][0]['id'], CarbonImmutable::now());
    expect(savedState($this->user, $spot))->toBeNull();

    $this->user->userSetting()->updateOrCreate(['user_id' => $this->user->id], ['settings' => ['composer_ask_first_everyday' => true]]);
    $proposed = $actions->request($this->user->fresh(), 'place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'saved'], CarbonImmutable::now());
    expect($proposed['blocks'][0])->toMatchArray(['type' => 'proposal', 'title' => 'Save Bau for later?'])
        ->and(savedState($this->user, $spot))->toBeNull();
    $this->actingAs($this->user)->postJson('/composer/apply', ['token' => $proposed['blocks'][0]['token']])->assertSuccessful();
    expect(savedState($this->user, $spot))->toBe('saved');
});

test('Been there is set on its own and undone on its own, the saved choice untouched', function () {
    $spot = Spot::factory()->create(['name' => 'Bau', 'category' => 'cafe', 'is_active' => true]);
    $actions = app(ComposerActions::class);
    $actions->request($this->user, 'place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'saved'], CarbonImmutable::now());

    $been = $actions->request($this->user, 'place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'been'], CarbonImmutable::now());
    $row = SpotFeedback::query()->where('user_id', $this->user->id)->where('spot_id', $spot->id)->sole();
    expect($been['blocks'][0]['type'])->toBe('done')
        ->and($row->been_at)->not->toBeNull()
        ->and($row->state?->value)->toBe('saved');

    $actions->undo($this->user, $been['blocks'][0]['id'], CarbonImmutable::now());
    $row = SpotFeedback::query()->where('user_id', $this->user->id)->where('spot_id', $spot->id)->sole();
    expect($row->been_at)->toBeNull()
        ->and($row->state?->value)->toBe('saved');
});

test('only the person who asked can apply or undo a change, and only for 24 hours', function () {
    $spot = Spot::factory()->create(['name' => 'Bau', 'category' => 'cafe', 'is_active' => true]);
    $made = app(ComposerActions::class)->request($this->user, 'place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'saved'], CarbonImmutable::now());
    $token = $made['blocks'][0]['id'];

    $this->actingAs(User::factory()->onboarded()->create())->postJson('/composer/undo', ['token' => $token])->assertNotFound();
    expect(savedState($this->user, $spot))->toBe('saved');

    $this->travel(25)->hours();
    $this->actingAs($this->user)->postJson('/composer/undo', ['token' => $token])->assertConflict()
        ->assertJsonPath('message', ComposerActions::TOO_LATE)
        ->assertJsonStructure(['workspace' => ['conversations', 'revision']]);
    expect(savedState($this->user, $spot))->toBe('saved');
});

test('without their Paperwork permission for the message, Paperwork is neither read nor changed', function () {
    withVisa($this->user);
    $paperwork = fn (bool $permitted): string => collect(AssistantTools::definitions(false, false, $permitted))->firstWhere('name', 'paperwork')['description'];
    expect($paperwork(false))->toContain('You don\'t get its contents')
        ->and($paperwork(true))->toContain('They allowed you to read it for this message');

    $tools = new AssistantTools($this->user, CarbonImmutable::now(), new LocationContext(null, null, LocationSource::None),
        app(CandidateRepository::class), app(EventAnswers::class), app(WeatherService::class));
    expect($tools->run('change_paperwork_fact', ['key' => 'visa_expires_at', 'value' => '2027-02-12']))->toContain('Not done')
        // Nothing proposed or made; at most the card asking for the permission.
        ->and(collect($tools->cards)->pluck('type')->diff(['permission'])->all())->toBe([])
        ->and(visaOn($this->user))->toBe('2026-10-01');
});

test('erasing Paperwork removes the copies Composer keeps of it', function () {
    withVisa($this->user);
    $spot = Spot::factory()->create(['name' => 'Bau', 'category' => 'cafe', 'is_active' => true]);
    $actions = app(ComposerActions::class);
    $paperwork = $actions->request($this->user, 'change_paperwork_fact', ['key' => 'visa_expires_at', 'value' => '2027-02-12'], CarbonImmutable::now())['blocks'][0];
    $place = $actions->request($this->user, 'place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'saved'], CarbonImmutable::now())['blocks'][0];
    $store = app(WorkspaceStore::class);
    $store->answer($this->user, 'initial', ['text' => 'Your visa runs until 1 Oct 2026.', 'extra' => ['blocks' => [$paperwork, $place]]], null);

    $store->purgePaperworkCopies($this->user->id);

    $message = collect($store->read($this->user)['conversations'][0]['messages'])->last();
    expect($store->action($this->user, $paperwork['id']))->toBeNull()
        ->and($store->action($this->user, $place['id']))->not->toBeNull()
        ->and(array_column($message['blocks'], 'id'))->toBe([$place['id']])
        ->and($message['text'])->not->toContain('1 Oct 2026');
});
