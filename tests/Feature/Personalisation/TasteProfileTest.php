<?php

use App\Models\TasteProfile;
use App\Models\User;
use App\Personalisation\Pseudonym;
use App\Personalisation\TasteFacts;
use App\Personalisation\TasteProfileWriter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.taste_profile.enabled' => true, 'services.anthropic.key' => 'test-key']);
});

function tasteUser(bool $optedIn = true): User
{
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->create(['settings' => ['personalisation' => $optedIn]]);

    return $user->fresh();
}

/**
 * What signals:aggregate would have written: cafes (strong) and bars (some)
 * at weekends, fast food turned down, Ehrenfeld, evenings. 9 taste signals.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: float, 4: int}>|null  $rows  dimension, key, context, score, signals
 */
function tasteAffinities(User $user, ?array $rows = null): void
{
    $rows ??= [
        ['category', 'cafe', 'all', 12.0, 6], ['category', 'cafe', 'weekend', 12.0, 6],
        ['category', 'bar', 'all', 4.0, 2], ['category', 'bar', 'weekend', 4.0, 2],
        ['category', 'fast_food', 'all', -4.0, 1],
        ['veedel', 'Ehrenfeld', 'all', 10.0, 8],
        ['daypart', 'evening', 'all', 9.0, 7], ['daypart', 'morning', 'all', 1.0, 1],
    ];
    DB::table('user_affinities')->where('subject_key', Pseudonym::for($user))->delete();
    DB::table('user_affinities')->insert(array_map(fn (array $row) => [
        'subject_key' => Pseudonym::for($user), 'dimension' => $row[0], 'key' => $row[1], 'context' => $row[2],
        'score' => $row[3], 'signals_count' => $row[4], 'updated_at' => now(),
    ], $rows));
}

/** @param  list<string>  $sentences */
function tasteBody(array $sentences, array $tags = ['café', 'Ehrenfeld'], string $stopReason = 'tool_use'): array
{
    return [
        'stop_reason' => $stopReason,
        'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => TasteProfileWriter::TOOL, 'input' => ['sentences' => $sentences, 'tags' => $tags]]],
        'usage' => ['input_tokens' => 400, 'output_tokens' => 90],
    ];
}

/** @param  list<string>  $sentences */
function tasteReply(array $sentences, array $tags = ['café', 'Ehrenfeld'], string $stopReason = 'tool_use'): void
{
    Http::fake(['api.anthropic.com/*' => Http::response(tasteBody($sentences, $tags, $stopReason))]);
}

function tasteGrounded(): array
{
    return ['You keep coming back to cafes.', 'Bars suit you now and then.', 'Ehrenfeld is your part of town.', 'Your evenings are when you head out, mostly at weekends.'];
}

test('the facts are bucketed in code, and the model gets nothing else', function () {
    $user = tasteUser();
    tasteAffinities($user);

    expect(app(TasteFacts::class)->for($user))->toBe([
        'categories' => [['name' => 'café', 'level' => 'strong'], ['name' => 'bar', 'level' => 'some'], ['name' => 'fast food', 'level' => 'avoid']],
        'veedels' => [['name' => 'Ehrenfeld', 'level' => 'strong']],
        'dayparts' => ['evening'],
        'week' => 'weekend',
        'signals' => 9,
    ]);

    tasteReply(tasteGrounded());
    $this->artisan('taste:profiles')->assertSuccessful();

    Http::assertSent(function (Request $request) use ($user) {
        $body = $request->data();
        $content = $body['messages'][0]['content'];

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $body['model'] === 'claude-haiku-5-5'
            && $body['output_config'] === ['effort' => 'low']
            && ! array_key_exists('temperature', $body)
            && $body['tool_choice'] === ['type' => 'tool', 'name' => TasteProfileWriter::TOOL]
            && $body['tools'][0]['input_schema']['properties']['tags']['items']['enum'] === ['café', 'bar', 'Ehrenfeld', 'evening', 'weekend']
            && ! str_contains($content, 'signals')
            && ! str_contains($content, (string) $user->id)
            && ! str_contains($content, Pseudonym::for($user))
            && ! str_contains(json_encode($body), $user->email);
    });

    $call = DB::table('llm_calls')->where('feature', 'taste_profile')->sole();
    expect($call->outcome)->toBe('success')
        ->and($call->input_tokens)->toBe(400)
        ->and($call->prompt_version)->toBe('2026-10-21.taste.1');
});

test('nothing happens without the opt-in', function () {
    $user = tasteUser(optedIn: false);
    tasteAffinities($user);
    tasteReply(tasteGrounded());

    $this->artisan('taste:profiles')->assertSuccessful();
    $this->artisan('taste:profiles', ['--user' => $user->id])->assertSuccessful();

    Http::assertNothingSent();
    expect(TasteProfile::count())->toBe(0)
        ->and(app(TasteProfileWriter::class)->refresh($user))->toBe('off');

    $this->actingAs($user)->getJson('/api/taste-profile')->assertForbidden();
    $this->putJson('/api/taste-profile', ['sentences' => ['Mine.']])->assertForbidden();
    $this->deleteJson('/api/taste-profile')->assertForbidden();
});

test('the command does nothing while the feature is switched off', function () {
    config(['services.taste_profile.enabled' => false]);
    $user = tasteUser();
    tasteAffinities($user);
    tasteReply(tasteGrounded());

    $this->artisan('taste:profiles')->assertSuccessful();

    Http::assertNothingSent();
    expect(TasteProfile::count())->toBe(0);
});

test('thin facts get no call, and a stale profile is deleted', function () {
    $user = tasteUser();
    tasteAffinities($user, [['category', 'cafe', 'all', 6.0, 3]]);
    TasteProfile::create(['subject_key' => Pseudonym::for($user), 'facts' => [], 'facts_hash' => str_repeat('a', 64), 'sentences' => ['Old.'], 'tags' => []]);
    tasteReply(tasteGrounded());

    expect(app(TasteProfileWriter::class)->refresh($user))->toBe('thin');

    Http::assertNothingSent();
    expect(TasteProfile::count())->toBe(0);
});

test('a sentence naming something the facts do not hold is dropped', function () {
    $user = tasteUser();
    tasteAffinities($user);
    tasteReply([...tasteGrounded(), 'You also love museums.'], ['café', 'Ehrenfeld', 'museum']);

    expect(app(TasteProfileWriter::class)->refresh($user))->toBe('written');

    $profile = TasteProfile::for($user);
    expect($profile->sentences)->toBe(tasteGrounded())
        ->and($profile->tags)->toBe(['café', 'Ehrenfeld'])
        ->and($profile->facts['categories'][0])->toBe(['name' => 'café', 'level' => 'strong'])
        ->and($profile->model)->toBe('claude-haiku-5-5');
});

test('the sentence check drops numbers, times, days, names, prices, company and anything off the facts', function (string $sentence) {
    $facts = [
        'categories' => [['name' => 'café', 'level' => 'strong'], ['name' => 'table tennis', 'level' => 'some'], ['name' => 'fast food', 'level' => 'avoid']],
        'veedels' => [['name' => 'Ehrenfeld', 'level' => 'strong']],
        'dayparts' => ['evening'],
        'week' => 'weekend',
    ];

    expect(TasteProfileWriter::keep(['You love cafés in Ehrenfeld.', 'Table tennis is a weekend thing for you.', 'Fast food is not for you.', $sentence], $facts))
        ->toBe(['You love cafés in Ehrenfeld.', 'Table tennis is a weekend thing for you.', 'Fast food is not for you.']);
})->with([
    'a digit' => 'You go out around 8 in the evening.',
    'a clock word' => 'You like a coffee at noon.',
    'a day' => 'Saturdays are for cafes.',
    'a number word' => 'You visit three cafes a week.',
    'a place name' => 'You like cafes such as Café Reichard.',
    'a name at the start' => 'Früh is a favourite of yours.',
    'another category' => 'Bars are your thing too.',
    'another Veedel' => 'Nippes draws you in.',
    'another daypart' => 'Mornings in a cafe suit you.',
    'the other week lean' => 'On weekdays you head to a cafe.',
    'a price' => 'You like cheap cafes.',
    'company' => 'You go to cafes with friends.',
    'a reason' => 'You like cafes because they are quiet.',
    'about the profile' => 'Your data shows a love of cafes.',
    'too long' => 'You love cafes'.str_repeat(' and cafes', 25).'.',
]);

test('a refusal or a reply cut off stores nothing', function (string $stopReason, string $outcome) {
    $user = tasteUser();
    tasteAffinities($user);
    tasteReply(tasteGrounded(), stopReason: $stopReason);

    expect(app(TasteProfileWriter::class)->refresh($user))->toBe('failed')
        ->and(TasteProfile::count())->toBe(0)
        ->and(DB::table('llm_calls')->where('feature', 'taste_profile')->value('outcome'))->toBe($outcome);
})->with([
    'refusal' => ['refusal', 'refused'],
    'max_tokens' => ['max_tokens', 'malformed:stop_reason'],
]);

test('too few grounded sentences store nothing', function () {
    $user = tasteUser();
    tasteAffinities($user);
    tasteReply(['You keep coming back to cafes.', 'Museums at 9 on Sundays.']);

    expect(app(TasteProfileWriter::class)->refresh($user))->toBe('rejected')
        ->and(TasteProfile::count())->toBe(0)
        ->and(DB::table('llm_calls')->where('feature', 'taste_profile')->value('outcome'))->toBe('rejected:ungrounded');
});

test('unchanged facts skip the call; changed facts write again', function () {
    $user = tasteUser();
    tasteAffinities($user);
    tasteReply(tasteGrounded());
    $writer = app(TasteProfileWriter::class);

    expect($writer->refresh($user))->toBe('written')
        ->and($writer->refresh($user))->toBe('unchanged');
    Http::assertSentCount(1);

    // Another signal of the same kind moves no bucket: still no call.
    DB::table('user_affinities')->where('key', 'cafe')->where('context', 'all')->update(['score' => 13.0, 'signals_count' => 7]);
    expect($writer->refresh($user))->toBe('unchanged');

    DB::table('user_affinities')->where('key', 'bar')->update(['score' => 11.0]);
    expect($writer->refresh($user))->toBe('written');
    Http::assertSentCount(2);
});

test('the daily cap stops the calls', function () {
    config(['services.taste_profile.daily_limit' => 1]);
    [$first, $second] = [tasteUser(), tasteUser()];
    tasteAffinities($first);
    tasteAffinities($second);
    tasteReply(tasteGrounded());

    $this->artisan('taste:profiles')->assertSuccessful();

    Http::assertSentCount(1);
    expect(TasteProfile::count())->toBe(1);
});

test('the person reads, edits and deletes their profile; their edits win', function () {
    $user = tasteUser();
    tasteAffinities($user);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(tasteBody(tasteGrounded(), ['café', 'Ehrenfeld', 'evening']))
        ->push(tasteBody(['You love cafes and bars.', 'Ehrenfeld is your part of town.', 'Evenings are yours.'], ['café', 'bar', 'evening']))]);
    $this->artisan('taste:profiles', ['--user' => $user->id])->assertSuccessful();

    $this->actingAs($user)->getJson('/api/taste-profile')
        ->assertOk()
        ->assertJsonPath('data.sentences', tasteGrounded())
        ->assertJsonPath('data.tags', ['café', 'Ehrenfeld', 'evening'])
        ->assertJsonPath('data.hidden_tags', [])
        ->assertJsonPath('data.edited', false)
        ->assertJsonStructure(['data' => ['sentences', 'tags', 'hidden_tags', 'edited', 'generated_at']]);

    $this->putJson('/api/taste-profile', ['sentences' => ['  I love a quiet cafe.  ', 'Ehrenfeld is home.'], 'hidden_tags' => ['evening']])
        ->assertOk()
        ->assertJsonPath('data.sentences', ['I love a quiet cafe.', 'Ehrenfeld is home.'])
        ->assertJsonPath('data.tags', ['café', 'Ehrenfeld'])
        ->assertJsonPath('data.hidden_tags', ['evening'])
        ->assertJsonPath('data.edited', true);

    // A nightly rewrite keeps the person's own words and hidden tags.
    DB::table('user_affinities')->where('key', 'bar')->update(['score' => 11.0]);
    $this->artisan('taste:profiles')->assertSuccessful();
    $this->getJson('/api/taste-profile')
        ->assertJsonPath('data.sentences', ['I love a quiet cafe.', 'Ehrenfeld is home.'])
        ->assertJsonPath('data.tags', ['café', 'bar']);

    // Back to the written sentences.
    $this->putJson('/api/taste-profile', ['sentences' => null])
        ->assertOk()->assertJsonPath('data.sentences', ['You love cafes and bars.', 'Ehrenfeld is your part of town.', 'Evenings are yours.']);

    $this->deleteJson('/api/taste-profile')->assertNoContent();
    $this->getJson('/api/taste-profile')->assertOk()->assertExactJson(['data' => null]);
    $this->putJson('/api/taste-profile', ['sentences' => ['Mine.']])->assertNotFound();
});

test('edits are plain text, at most five short sentences, and only hide real tags', function (array $payload, string $field) {
    $user = tasteUser();
    TasteProfile::create(['subject_key' => Pseudonym::for($user), 'facts' => [], 'facts_hash' => str_repeat('a', 64), 'sentences' => ['You keep coming back to cafes.'], 'tags' => ['cafe']]);

    $this->actingAs($user)->putJson('/api/taste-profile', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'six sentences' => [['sentences' => array_fill(0, 6, 'Fine.')], 'sentences'],
    'too long' => [['sentences' => [str_repeat('a', 201)]], 'sentences.0'],
    'markup' => [['sentences' => ['<b>Cafes</b>']], 'sentences.0'],
    'empty sentence' => [['sentences' => ['']], 'sentences.0'],
    'unknown tag' => [['hidden_tags' => ['museum']], 'hidden_tags.0'],
]);

test('turning personalisation off deletes the profile', function () {
    $user = tasteUser();
    TasteProfile::create(['subject_key' => Pseudonym::for($user), 'facts' => [], 'facts_hash' => str_repeat('a', 64), 'sentences' => ['You keep coming back to cafes.'], 'tags' => ['cafe']]);

    $this->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => false]])->assertOk();

    expect(TasteProfile::count())->toBe(0);
});

test('deleting the account deletes the profile', function () {
    $user = tasteUser();
    TasteProfile::create(['subject_key' => Pseudonym::for($user), 'facts' => [], 'facts_hash' => str_repeat('a', 64), 'sentences' => ['You keep coming back to cafes.'], 'tags' => ['cafe']]);

    $user->delete();

    expect(TasteProfile::count())->toBe(0);
});
