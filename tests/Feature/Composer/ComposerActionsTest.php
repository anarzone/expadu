<?php

use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Composer\Actions\ComposerActions;
use App\Composer\Actions\PaperworkActions;
use App\Composer\Answers\EventAnswers;
use App\Composer\Assistant\Ask;
use App\Composer\Assistant\AssistantTools;
use App\Composer\CandidateRepository;
use App\Composer\WorkspaceStore;
use App\Enums\LocationSource;
use App\Models\BureaucracyCaseFact;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\EventReminder;
use App\Models\PlaceWorkAnswer;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Services\GeocodingService;
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

test('🔒 an answer their words don’t settle is asked as choices; their tap is made at once in the card’s place, with Undo', function () {
    withVisa($this->user);
    $title = fn (): ?string => app(ConfirmedFactView::class)->forCase($this->user->bureaucracyCase()->firstOrFail(), now()->toDateString())['values']['current_residence_title'] ?? null;
    $before = $title();
    $actions = app(ComposerActions::class);
    $store = app(WorkspaceStore::class);

    // "Not known" would be proposed: a card asks which instead, with a line on each, and nothing changes.
    $asked = $actions->choose($this->user, 'change_paperwork_fact', ['key' => 'current_residence_title', 'value' => 'settlement_permit_unknown'], CarbonImmutable::now());
    $card = $asked['blocks'][0];
    expect($card)->toMatchArray(['type' => 'proposal', 'area' => 'paperwork', 'title' => 'Which settlement permit do you have?', 'fact_key' => 'current_residence_title'])
        ->and($card)->not->toHaveKey('token')
        ->and(array_column($card['edit']['options'], 'value'))->toBe(['settlement_permit_9', 'settlement_permit_18c'])
        ->and($card['edit']['options'][1]['hint'])->toContain('§18c')
        ->and($card['unsure']['keep'])->toBe(['value' => 'settlement_permit_unknown', 'label' => 'Keep “type unknown”'])
        ->and($asked['said'])->toStartWith('Not done')
        ->and($title())->toBe($before)
        // One answer to propose, or a choice of one: no card.
        ->and($actions->choose($this->user, 'change_paperwork_fact', ['key' => 'current_residence_title', 'value' => 'blue_card'], CarbonImmutable::now()))->toBeNull()
        ->and($actions->choose($this->user, 'change_paperwork_fact', ['key' => 'current_residence_title', 'choices' => ['blue_card', 'astronaut']], CarbonImmutable::now()))->toBeNull()
        // A section their words don't name is asked about, never guessed; named, it's proposed.
        ->and($actions->choose($this->user, 'change_paperwork_fact', ['key' => 'current_residence_title', 'value' => 'settlement_permit_9', '_said' => 'I got my Niederlassungserlaubnis'], CarbonImmutable::now())['blocks'][0]['title'])
        ->toBe('Which settlement permit do you have?')
        ->and($actions->choose($this->user, 'change_paperwork_fact', ['key' => 'current_residence_title', 'value' => 'settlement_permit_18c', '_said' => 'it’s a § 18c one'], CarbonImmutable::now()))->toBeNull()
        // "Not known" among the choices is what "Not sure" keeps, not an option.
        ->and(array_column($actions->choose($this->user, 'change_paperwork_fact', ['key' => 'current_residence_title',
            'choices' => ['settlement_permit_9', 'settlement_permit_unknown']], CarbonImmutable::now())['blocks'][0]['edit']['options'], 'value'))->toBe(['settlement_permit_9', 'settlement_permit_18c']);
    $store->answer($this->user, (string) $store->read($this->user)['active'], Ask::message('', $asked['blocks'], ['written' => 'ai']), null);

    // Their tap, while they ask first for Paperwork (on by default): the answer to Composer's own
    // question, so made at once, its Done with Undo in the card's place.
    $workspace = $this->actingAs($this->user)->postJson('/composer/act', ['action' => 'paperwork_fact', 'key' => 'current_residence_title', 'value' => 'settlement_permit_18c'])
        ->assertSuccessful()->json();
    $blocks = collect($workspace['conversations'][0]['messages'])->flatMap(fn (array $m): array => $m['blocks'] ?? []);
    expect($title())->toBe('settlement_permit_18c')
        ->and($blocks->firstWhere('id', $card['id']))->toMatchArray(['type' => 'done', 'undo' => ['token' => $card['id'], 'until' => $card['until']]])
        ->and($blocks->where('edit.kind', 'choice'))->toBeEmpty();

    $actions->undo($this->user, $card['id'], CarbonImmutable::now());
    expect($title())->toBe($before);

    // Not one of the card's options: an answer typed in, proposed while they ask first.
    $this->postJson('/composer/act', ['action' => 'paperwork_fact', 'key' => 'current_residence_title', 'value' => 'blue_card'])->assertSuccessful();
    expect($title())->toBe($before);
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

test('🔒 their own places: times, other places and Home kept whole, each with an Undo that loses nothing', function () {
    $this->mock(GeocodingService::class, fn ($mock) => $mock->shouldReceive('search')->andReturn([
        ['name' => 'Venloer Straße 210', 'street_name' => 'Venloer Straße', 'housenumber' => '210', 'city' => 'Köln', 'lat' => 50.947, 'lng' => 6.916],
    ]));
    $actions = app(ComposerActions::class);
    $now = CarbonImmutable::now();
    // A Home kept without a point (an older account's), its times set.
    $home = $this->user->places()->create(['emoji' => '🏠', 'name' => 'Home', 'category' => 'home', 'sort_order' => 0, 'arrive_by' => '18:30', 'day_mode' => 'weekdays']);

    // Set, then undone: the point goes, the row and its times stay.
    $set = $actions->request($this->user, 'set_my_place', ['which' => 'home', 'place' => 'Venloer Straße 210, Köln'], $now);
    expect($home->fresh()->lat)->toBe(50.947);
    $actions->undo($this->user, $set['blocks'][0]['id'], $now);
    expect($home->fresh())->not->toBeNull()->lat->toBeNull()->arrive_by->toStartWith('18:30')->day_mode->toBe('weekdays');

    // Work's times and days, then back.
    $work = $this->user->places()->create(['emoji' => '💼', 'name' => 'Work', 'category' => 'work', 'sort_order' => 1, 'lat' => 50.94, 'lng' => 6.95]);
    $times = $actions->request($this->user, 'set_place_times', ['which' => 'work', 'arrive_by' => '09:00', 'leave_at' => '17:30', 'days' => ['mon', 'tue', 'thu']], $now);
    expect($times['blocks'][0]['text'])->toBe('Work: arrive by 09:00, leave at 17:30, Mon, Tue, Thu.')
        ->and($work->fresh())->arrive_by->toStartWith('09:00')->leave_at->toStartWith('17:30')->day_mode->toBe('custom')->active_days->toBe(['mon', 'tue', 'thu']);
    $actions->undo($this->user, $times['blocks'][0]['id'], $now);
    expect($work->fresh())->arrive_by->toBeNull()->leave_at->toBeNull();

    // Another place: added at an address the AI is never told, removed, and put back whole by Undo.
    $added = $actions->request($this->user, 'add_my_place', ['name' => 'Gym', 'place' => 'Venloer Straße 210, Köln'], $now);
    expect($added['said'])->not->toContain('Venloer');
    $actions->request($this->user, 'set_place_times', ['which' => 'Gym', 'arrive_by' => '19:00', 'days' => 'weekends'], $now);
    $removed = $actions->request($this->user, 'remove_my_place', ['which' => 'gym'], $now);
    expect($this->user->places()->where('name', 'Gym')->exists())->toBeFalse();
    $actions->undo($this->user, $removed['blocks'][0]['id'], $now);
    expect($this->user->places()->where('name', 'Gym')->firstOrFail())->arrive_by->toStartWith('19:00')->day_mode->toBe('weekends')->address->toBe('Venloer Straße 210, Köln')
        // Home and Work are never removed.
        ->and($actions->request($this->user, 'remove_my_place', ['which' => 'home'], $now)['said'])->toStartWith('Not done');
});

test('a place been to, with how it was; a laptop answer only for a place they know, and only with their Apply', function () {
    $spot = Spot::factory()->create(['name' => 'Café Nova', 'category' => 'cafe', 'veedel' => 'Ehrenfeld', 'is_active' => true]);
    $actions = app(ComposerActions::class);
    $now = CarbonImmutable::now();

    expect($actions->request($this->user, 'laptop_answer', ['place' => "spot:{$spot->id}", 'laptop' => 'yes'], $now)['said'])->toContain('mark it Been there or Saved first');

    $been = $actions->request($this->user, 'place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'been', 'rating' => 'up'], $now);
    expect($been['blocks'][0]['text'])->toBe('Marked Café Nova as a place you’ve been, and you liked it.')
        ->and(SpotFeedback::query()->where('spot_id', $spot->id)->value('been_rating'))->toBe('up');

    $answer = $actions->request($this->user, 'laptop_answer', ['place' => "spot:{$spot->id}", 'laptop' => 'yes', 'wifi' => 'no'], $now);
    expect($answer['blocks'][0])->toMatchArray(['type' => 'proposal', 'title' => 'Answer “Good for laptop work?” for Café Nova: laptops welcome: yes, Wi-Fi: no?'])
        ->and(PlaceWorkAnswer::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
    $actions->apply($this->user, $answer['blocks'][0]['token'], $now);
    expect(PlaceWorkAnswer::query()->where('user_id', $this->user->id)->firstOrFail())->laptop->toBe('yes')->wifi->toBe('no');

    $unbeen = $actions->request($this->user, 'place_feedback', ['place' => "spot:{$spot->id}", 'state' => 'not_been'], $now);
    expect(SpotFeedback::query()->where('spot_id', $spot->id)->value('been_at'))->toBeNull();
    $actions->undo($this->user, $unbeen['blocks'][0]['id'], $now);
    expect(SpotFeedback::query()->where('spot_id', $spot->id)->value('been_rating'))->toBe('up');
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

test('a listing is saved and its reminder set, each with Undo, as the event sheet does', function () {
    $event = Event::factory()->create(['title' => 'Jazz im Stadtgarten', 'title_en' => null, 'is_curated' => true,
        'starts_at' => CarbonImmutable::parse('2026-09-10 20:00', 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse('2026-09-10 23:00', 'Europe/Berlin')]);
    $id = 'e'.$event->id.'@'.CarbonImmutable::parse('2026-09-10 20:00', 'Europe/Berlin')->getTimestamp();
    $actions = app(ComposerActions::class);

    $saved = $actions->request($this->user, 'save_event', ['event' => $id], CarbonImmutable::now());
    expect($saved['blocks'][0])->toMatchArray(['type' => 'done', 'text' => 'Saved Jazz im Stadtgarten.'])
        ->and(EventBookmark::query()->where('user_id', $this->user->id)->where('event_id', $event->id)->exists())->toBeTrue();
    $actions->undo($this->user, $saved['blocks'][0]['id'], CarbonImmutable::now());
    expect(EventBookmark::query()->where('user_id', $this->user->id)->exists())->toBeFalse();

    $reminded = $actions->request($this->user, 'remind_me', ['event' => $id, 'when' => '3h'], CarbonImmutable::now());
    $reminder = EventReminder::query()->where('user_id', $this->user->id)->sole();
    expect($reminded['blocks'][0]['text'])->toBe('You’ll get a reminder three hours before Jazz im Stadtgarten.')
        ->and($reminder->offset_minutes)->toBe(180);
    $actions->undo($this->user, $reminded['blocks'][0]['id'], CarbonImmutable::now());
    expect(EventReminder::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
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
        ->and($paperwork(true))->toContain('They allowed you to read it.');

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
