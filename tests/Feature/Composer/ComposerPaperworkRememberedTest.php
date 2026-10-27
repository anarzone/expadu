<?php

use App\Bureaucracy\People\RestrictPersonProcessing;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use App\Privacy\ProcessingOptIns;
use App\Privacy\ProcessingPurpose;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalProcessingFixtures;
use Tests\Support\ReviewedHomePlan;

/*
 * Composer remembers their Allow for their Paperwork record (owner, 27 Oct 2026: "Composer
 * shouldn't ask for allow every time for the same task. It should remember it."): one Allow is a
 * standing yes for the notice and model they saw; each message still gets its own permit through
 * the gate; a new notice or model asks again; turning it off, in any of its places, stops it.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 10:00', 'Europe/Berlin'));
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'claude-haiku-5-5', 'key' => 'synthetic',
        'processor_name' => 'Anthropic PBC', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    app()->instance(WeatherService::class, new class extends WeatherService
    {
        public function __construct() {}

        public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
        {
            return ['available' => false];
        }
    });
    $this->user = User::factory()->onboarded()->create(['veedel' => 'Ehrenfeld']);
    ReviewedHomePlan::activate($this->user, ['fixture.entry' => ['title' => 'Synthetic preparation']],
        ['arrival_date' => '2026-09-01', 'visa_expires_at' => '2026-10-01']);
    // Each message: the AI reads their record, then answers.
    $this->sent = [];
    Http::fake(['processor.example.test/*' => function (Request $request) {
        $this->sent[] = $request->data();
        $last = collect($request->data()['messages'])->last();
        $answered = is_array($last['content']) && collect($last['content'])->contains('type', 'tool_result');
        $use = $answered ? ['answer', ['sentences' => [['text' => 'Here’s your record.', 'uses' => []]], 'show' => []]] : ['paperwork', []];

        return Http::response(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_'.count($this->sent), 'name' => $use[0], 'input' => $use[1]]],
            'usage' => ['input_tokens' => 900, 'output_tokens' => 60]]);
    }]);
});

/**
 * One message about their Paperwork, with their Allow or without it; what the AI was told its
 * record tool gave back.
 */
function paperworkMessage(bool $allow = false): string
{
    test()->sent = [];
    $revision = test()->actingAs(test()->user)->getJson('/composer/workspace')->assertOk()->json('revision');
    test()->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => 'When does my visa run out?'])->assertOk();
    test()->postJson(route('composer.ask'), ['processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist),
        ...($allow ? ['paperwork_processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerPaperwork)] : [])])->assertOk();

    return json_encode(test()->sent[1]['messages'] ?? [], JSON_UNESCAPED_UNICODE);
}

function paperworkPermits(): int
{
    return BureaucracyProcessingConsent::query()->where('actor_id', test()->user->id)->where('purpose', 'composer_paperwork')->count();
}

test('🔒 one Allow is remembered: the next Paperwork message needs none, and gets its own permit', function () {
    expect(paperworkMessage())->toContain('a card asks them for it')->not->toContain('Your visa runs until');

    expect(paperworkMessage(allow: true))->toContain('Your visa runs until: 1 Oct 2026')
        ->and($this->getJson('/composer/paperwork-permission')->assertOk()->json())->toMatchArray(['enabled' => true, 'available' => true, 'has_record' => true])
        ->and($this->getJson('/composer/paperwork-permission')->json('disclosure.scope'))->toBe('until_you_turn_it_off');

    // Asked again later without Allow: read under the remembered yes, through its own permit.
    expect(paperworkMessage())->toContain('Your visa runs until: 1 Oct 2026')->not->toContain('a card asks them for it')
        ->and(BureaucracyProcessingConsent::query()->where('actor_id', $this->user->id)->where('purpose', 'composer_paperwork')->pluck('state')->all())
        ->toBe(['completed', 'completed']);
});

test('🔒 a new notice or a new model asks once more', function (Closure $change) {
    paperworkMessage(allow: true);
    $change();

    expect(paperworkMessage())->toContain('a card asks them for it')->not->toContain('Your visa runs until')
        ->and(paperworkPermits())->toBe(1)
        ->and($this->getJson('/composer/paperwork-permission')->json('enabled'))->toBeFalse();

    // Their Allow under the new one is remembered again.
    paperworkMessage(allow: true);
    expect(paperworkMessage())->toContain('Your visa runs until');
})->with([
    'notice' => [fn () => config()->set('bureaucracy_privacy.notice_version', 'composer-paperwork-remembered.test')],
    'model' => [fn () => config()->set('services.llm.model', 'claude-haiku-5-6')],
]);

test('🔒 turning it off, wherever, stops it at once', function (Closure $off) {
    paperworkMessage(allow: true);
    $off();

    // Their record isn't sent (with processing stopped there's nothing to ask for either).
    expect(paperworkMessage())->not->toContain('Your visa runs until')
        ->and(app(ProcessingOptIns::class)->active($this->user, ProcessingPurpose::ComposerPaperwork))->toBeFalse()
        ->and(BureaucracyProcessingConsent::query()->where('actor_id', $this->user->id)->where('purpose', 'composer_paperwork')->pluck('state')->all())
        ->toBe(['withdrawn']);
})->with([
    // The notice under Composer's box: its Paperwork Withdraw, and Composer's AI.
    'Paperwork withdraw' => [fn () => test()->deleteJson('/privacy/processing/composer_paperwork')->assertOk()],
    'Composer’s AI withdraw' => [fn () => test()->deleteJson('/privacy/processing/composer_assist')->assertOk()],
    'Settings › Composer' => [fn () => test()->putJson('/composer/paperwork-permission', ['enabled' => false])->assertOk()->assertJsonPath('enabled', false)],
    'stopping the record’s processing' => [fn () => app(RestrictPersonProcessing::class)->stop(test()->user,
        BureaucracyPerson::query()->where('account_user_id', test()->user->id)->firstOrFail())],
]);

test('🔒 Settings turns it on only under the notice shown now', function () {
    $stale = ['enabled' => true, 'notice_version' => 'an-old-notice', 'provider_version' => ProcessingPurpose::ComposerPaperwork->providerVersion()];
    $this->actingAs($this->user)->putJson('/composer/paperwork-permission', $stale)->assertUnprocessable();

    $disclosure = $this->getJson('/composer/paperwork-permission')->json('disclosure');
    $this->putJson('/composer/paperwork-permission', ['enabled' => true, 'notice_version' => $disclosure['notice_version'], 'provider_version' => $disclosure['provider_version']])
        ->assertOk();
    expect(paperworkMessage())->toContain('Your visa runs until');
});
