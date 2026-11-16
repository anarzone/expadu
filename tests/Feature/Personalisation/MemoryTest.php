<?php

use App\Composer\Actions\ComposerActions;
use App\Models\User;
use App\Personalisation\Memory;
use App\Personalisation\Pseudonym;
use App\Privacy\Export\AccountData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * What Composer remembers about a person (owner, 16 Nov 2026: "the engine which knows the user"):
 * only with personalisation on, under its pseudonym, never a special category (Art. 9), all of it
 * theirs to see, change and forget, and erased with personalisation or the account.
 */

function rememberer(bool $on = true): User
{
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => $on], 'sixteen_or_older' => true]);

    return $user->fresh();
}

function rememberedRows(User $user): int
{
    return DB::table('user_facts')->where('subject_key', Pseudonym::for($user))->count();
}

test('🔒 nothing is remembered without personalisation', function () {
    $user = rememberer(on: false);

    expect(app(Memory::class)->remember($user, 'Vegetarian'))->toBe('Remembering things about you needs personalisation turned on.')
        ->and(rememberedRows($user))->toBe(0);
});

test('🔒 a fact about health, religion, sexuality, politics or a union is never kept', function (string $text) {
    $user = rememberer();

    expect(app(Memory::class)->remember($user, $text))->toBe('Expadu never keeps anything about health, religion or beliefs, sexuality, politics or a union.')
        ->and(rememberedRows($user))->toBe(0);
})->with([
    'Eats halal', 'Muslim', 'Goes to church on Sundays', 'Fasting for Ramadan', 'Uses a wheelchair', 'Has diabetes', 'Allergic to nuts', 'Ich bin schwanger',
    'Sees a therapist on Mondays', 'Gay', 'Member of the AfD', 'In the IG Metall union', 'Ist in der Gewerkschaft',
]);

test('everyday facts are kept, words that only look like a special category too', function (string $text) {
    $user = rememberer();

    expect(app(Memory::class)->remember($user, $text))->toBeArray()->text->toBe($text);
})->with(['Vegetarian', 'Has a 3-year-old', 'Loves Christmas markets', 'Psychology student', 'Goes to the gym bi-weekly', 'Works from home on Fridays', 'Collects jewellery']);

test('a fact is kept in its own words, once; at the limit nothing is dropped for it', function () {
    $user = rememberer();
    $memory = app(Memory::class);

    expect($memory->remember($user, '  vegetarian. '))->text->toBe('Vegetarian')
        ->and($memory->remember($user, 'VEGETARIAN'))->toBe('Already remembered.')
        ->and($memory->remember($user, str_repeat('a', Memory::LENGTH + 1)))->toContain('too long');

    foreach (range(2, Memory::LIMIT) as $i) {
        $memory->remember($user, "Fact number {$i}");
    }
    expect($memory->remember($user, 'One too many'))->toBe('Memory is full. Forget something first.')
        ->and($memory->facts($user))->toHaveCount(Memory::LIMIT)
        ->and($memory->facts($user)[0]['text'])->toBe('Vegetarian');
});

test('🔒 turning personalisation off erases what Composer remembers, and so does deleting the account', function () {
    $user = rememberer();
    $other = rememberer();
    app(Memory::class)->remember($user, 'Vegetarian');
    app(Memory::class)->remember($other, 'Has a dog');

    $this->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => false]])->assertOk();
    expect(rememberedRows($user))->toBe(0)->and(rememberedRows($other))->toBe(1);

    $subject = Pseudonym::for($other);
    $other->delete();
    expect(DB::table('user_facts')->where('subject_key', $subject)->count())->toBe(0);
});

test('🔒 the phone shows, adds, changes and forgets their own facts, and never anyone else\'s', function () {
    $user = rememberer();
    $other = rememberer();
    $theirs = app(Memory::class)->remember($other, 'Has a dog');
    $as = $this->withToken($user->createToken('iPhone')->plainTextToken);

    $added = $as->postJson('/api/v1/memory', ['text' => 'Vegetarian'])->assertCreated()->json('fact');
    $as->postJson('/api/v1/memory', ['text' => 'Eats halal'])->assertUnprocessable()->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'never keeps'));
    $as->getJson('/api/v1/memory')->assertOk()
        ->assertJsonPath('personalised', true)
        ->assertJsonPath('limit', Memory::LIMIT)
        ->assertJsonCount(1, 'facts')
        ->assertJsonPath('facts.0.text', 'Vegetarian')
        ->assertJsonPath('facts.0.source', 'added');

    $as->patchJson("/api/v1/memory/{$added['id']}", ['text' => 'Vegan'])->assertOk()->assertJsonPath('fact.text', 'Vegan');
    $as->patchJson("/api/v1/memory/{$theirs['id']}", ['text' => 'Has a cat'])->assertNotFound();
    $as->deleteJson("/api/v1/memory/{$theirs['id']}")->assertNotFound();
    expect(app(Memory::class)->facts($other)[0]['text'])->toBe('Has a dog');

    $gone = $as->deleteJson("/api/v1/memory/{$added['id']}")->assertOk()->json('forgotten');
    expect(rememberedRows($user))->toBe(0);
    $back = $as->postJson('/api/v1/memory/restore', $gone)->assertCreated()->json('fact');
    expect($back['text'])->toBe('Vegan')->and($back['id'])->not->toBe($theirs['id'])
        ->and(app(Memory::class)->facts($user)[0]['source'])->toBe('added');
});

test('🔒 Undo of a forget never takes its id from the phone, and the fact keeps its place', function () {
    $user = rememberer();
    $memory = app(Memory::class);
    $first = $memory->remember($user, 'Vegetarian');
    $this->travel(2)->seconds();
    $memory->remember($user, 'Has a 3-year-old');
    $gone = $memory->forget($user, $first['id']);

    $back = $this->withToken($user->createToken('iPhone')->plainTextToken)
        ->postJson('/api/v1/memory/restore', [...$gone, 'id' => 999999])->assertCreated()->json('fact');

    expect($back['id'])->not->toBe(999999)
        ->and(array_column($memory->facts($user), 'text'))->toBe(['Vegetarian', 'Has a 3-year-old'])
        ->and($memory->remember($user, 'Works on Saturdays'))->toBeArray();
});

test('🔒 without personalisation the phone sees nothing kept and can add nothing', function () {
    $this->getJson('/api/v1/memory')->assertUnauthorized();
    $user = rememberer(on: false);
    $as = $this->withToken($user->createToken('iPhone')->plainTextToken);

    $as->getJson('/api/v1/memory')->assertOk()->assertJsonPath('personalised', false)->assertJsonPath('facts', [])->assertJsonPath('taste', null);
    $as->postJson('/api/v1/memory', ['text' => 'Vegetarian'])->assertUnprocessable();
});

test('Composer remembers with Undo, and forgets by the id it read', function () {
    $user = rememberer();
    $actions = app(ComposerActions::class);

    $made = $actions->request($user, 'remember', ['fact' => 'vegetarian'], CarbonImmutable::now());
    expect($made['blocks'][0])->toMatchArray(['type' => 'done', 'text' => 'Remembered: Vegetarian.'])
        ->and($made['blocks'][0]['link']['label'])->toBe('See what Expadu knows →')
        ->and(rememberedRows($user))->toBe(1);
    $actions->undo($user, $made['blocks'][0]['id'], CarbonImmutable::now());
    expect(rememberedRows($user))->toBe(0);

    $actions->request($user, 'remember', ['fact' => 'Vegetarian'], CarbonImmutable::now());
    $forgot = $actions->request($user, 'forget', ['fact' => 'k1'], CarbonImmutable::now());
    expect($forgot['blocks'][0]['text'])->toBe('Forgotten: Vegetarian.')->and(rememberedRows($user))->toBe(0);
    $actions->undo($user, $forgot['blocks'][0]['id'], CarbonImmutable::now());
    expect(array_column(app(Memory::class)->facts($user), 'text'))->toBe(['Vegetarian']);

    $refused = $actions->request($user, 'remember', ['fact' => 'Eats halal'], CarbonImmutable::now());
    expect($refused['blocks'])->toBe([])->and($refused['said'])->toContain('never keeps')
        ->and($actions->request($user, 'forget', ['fact' => 'k9'], CarbonImmutable::now())['blocks'])->toBe([]);
});

test('🔒 what Composer remembers is in their data export', function () {
    $user = rememberer();
    app(Memory::class)->remember($user, 'Vegetarian');

    $data = json_decode(app(AccountData::class)->files($user)['personalisation.json'], true)['data'];

    expect(array_column($data['remembered'], 'text'))->toBe(['Vegetarian']);
});
