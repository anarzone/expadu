<?php

use App\Home\TodayDiscovery;
use App\Models\User;
use App\Personalisation\EventPicks;
use App\Personalisation\PersonalisationWording;
use App\Personalisation\TasteFacts;
use Illuminate\Support\Facades\DB;

/*
 * "What would you go to?": the kinds of event a person picks themselves. Personalisation's: only
 * with it on under the wording that names picks, and erased when it's turned off.
 */

function picker(): User
{
    $user = User::factory()->onboarded()->create();
    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])->assertOk();

    return $user->fresh();
}

function putPicks(User $user, array $body)
{
    return test()->withToken($user->createToken('iPhone')->plainTextToken)->putJson('/api/v1/me/picks', $body);
}

test('picks are kept as they tap, and Today stops asking once they say Done', function () {
    $user = picker();

    putPicks($user, ['kinds' => ['music', 'kids'], 'done' => false])->assertOk()
        ->assertJsonPath('picks', ['kinds' => ['music', 'kids'], 'done' => false]);
    $today = test()->withToken($user->createToken('iPhone')->plainTextToken)->getJson('/api/v1/today')->assertOk()->json('picks');
    expect($today['ask'])->toBeTrue()->and($today['picked'])->toBe(['music', 'kids'])
        ->and(collect($today['kinds'])->pluck('key')->all())->toBe(EventPicks::kinds())
        ->and(collect($today['kinds'])->pluck('key')->all())->not->toContain('other');

    putPicks($user, ['kinds' => ['music', 'kids', 'tours'], 'done' => true])->assertOk();
    expect(test()->withToken($user->createToken('iPhone')->plainTextToken)->getJson('/api/v1/today')->json('picks.ask'))->toBeFalse()
        ->and(test()->withToken($user->createToken('iPhone')->plainTextToken)->getJson('/api/v1/memory')->json('picks'))->toBe(['kinds' => ['music', 'kids', 'tours'], 'done' => true]);
});

test('only the kinds there are, each once', function () {
    $user = picker();

    putPicks($user, ['kinds' => ['other'], 'done' => false])->assertUnprocessable();
    putPicks($user, ['kinds' => ['music', 'music'], 'done' => false])->assertUnprocessable();
    putPicks($user, ['kinds' => ['astrology'], 'done' => false])->assertUnprocessable();
});

test('🔒 with personalisation off, nothing is kept and Today doesn’t ask', function () {
    $user = User::factory()->onboarded()->create();

    putPicks($user, ['kinds' => ['music'], 'done' => false])->assertUnprocessable();
    expect(DB::table('users')->where('id', $user->id)->value('interests'))->toBeNull()
        ->and(test()->withToken($user->createToken('iPhone')->plainTextToken)->getJson('/api/v1/today')->json('picks'))->toBeNull();
});

test('🔒 on under an earlier wording, picks wait until they turn it on again', function () {
    $user = picker();
    $user->userSetting()->update(['settings' => ['personalisation' => true, PersonalisationWording::SETTING => EventPicks::WORDING - 1]]);

    putPicks($user->fresh(), ['kinds' => ['music'], 'done' => false])->assertUnprocessable();
    expect(app(EventPicks::class)->of($user->fresh()))->toBeNull();
});

test('🔒 turning personalisation off erases their picks', function () {
    $user = picker();
    putPicks($user, ['kinds' => ['music', 'tours'], 'done' => true])->assertOk();

    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => false]])->assertOk();

    expect(DB::table('users')->where('id', $user->id)->value('interests'))->toBeNull();
    // On again: asked afresh, nothing picked.
    $user = picker();
    expect(app(EventPicks::class)->of($user))->toBe(['kinds' => [], 'done' => false]);
});

/** A listing as Today orders it. */
function pickEvent(int $id, string $category, string $veedel = 'Neustadt-Nord'): array
{
    return ['id' => $id, 'title' => "Event {$id}", 'category' => $category, 'venue' => ['veedel' => $veedel], 'occurrence_start' => '2026-11-19T18:00:00+01:00'];
}

test('the kinds they picked are brought in from later in the day, never an avoided kind, two of a kind at most', function () {
    // The owner's test, 19 Nov 2026: picks of sports and meeting people, but only the first eleven
    // listings of a few hundred were ever ordered, so none of them was a match.
    $later = collect([pickEvent(20, 'tours'), pickEvent(21, 'sports'), pickEvent(22, 'meet_people'), pickEvent(23, 'sports'),
        pickEvent(24, 'sports'), pickEvent(25, 'party')]);
    $leanings = ['strong' => [], 'some' => [], 'avoid' => ['party'], 'lately' => [], 'areas' => [],
        'picked' => [TasteFacts::categoryLabel('sports'), TasteFacts::categoryLabel('meet_people')]];

    expect(TodayDiscovery::leanedLater($later, $leanings)->pluck('id')->all())->toBe([21, 22, 23])
        ->and(TodayDiscovery::leanedLater($later, null)->all())->toBe([]);
});

test('children’s listings come after the rest for someone whose picks leave them out', function () {
    $events = collect([pickEvent(1, 'kids'), pickEvent(2, 'tours'), pickEvent(3, 'sports')]);
    $label = fn (string $kind): string => TasteFacts::categoryLabel($kind);
    $leanings = ['strong' => [], 'some' => [], 'avoid' => [], 'lately' => [], 'areas' => [], 'picked' => [$label('sports')]];

    expect(TodayDiscovery::arranged($events, $leanings, false)->pluck('id')->all())->toBe([3, 2, 1])
        // ...but first for someone who picked them.
        ->and(TodayDiscovery::arranged($events, [...$leanings, 'picked' => [$label('kids')]], false)->pluck('id')->first())->toBe(1);
});
