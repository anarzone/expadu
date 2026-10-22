<?php

use App\Composer\AnthropicPromptParser;
use App\Composer\HeuristicPromptParser;
use App\Composer\ParsedPrompt;
use App\Composer\PromptIntent;
use App\Enums\GermanLevel;
use App\Enums\Situation;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use App\Profile\Profile;
use App\Profile\TicketAdvice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\ExternalProcessingFixtures;

/**
 * The Anthropic driver is dormant until a key lands, but its mapping and
 * degradation path are proven here against a faked provider — so flipping
 * LLM_DRIVER=anthropic is a config change, not a leap of faith.
 */
function llmProfile(): Profile
{
    return new Profile(
        situation: Situation::Student,
        isEu: true,
        arrivalDate: null,
        veedel: 'Ehrenfeld',
        bureaucracyBranch: 'student',
        ticketAdvice: TicketAdvice::SemesterTicket,
        defaultAreas: ['Ehrenfeld'],
        germanLevel: GermanLevel::B1,
    );
}

function llmParser(): AnthropicPromptParser
{
    config()->set('services.llm.driver', 'anthropic');
    config()->set('services.llm.processor_name', 'Synthetic processor');
    config()->set('services.llm.processor_privacy_url', 'https://processor.example.test/privacy');
    config()->set('services.llm.prompt_version', 'synthetic.1');
    config()->set('services.llm.base_url', 'https://api.anthropic.com');
    config()->set('services.llm.model', 'claude-haiku-5-5');
    config()->set('services.llm.key', 'test-key');

    return new AnthropicPromptParser(new HeuristicPromptParser);
}

function parseWithConsent(string $text, Profile $profile, CarbonImmutable $now): ParsedPrompt
{
    test()->travelTo($now);
    $parser = llmParser();
    $permit = app(ProcessingConsentStore::class)->grant(User::factory()->create(), ProcessingPurpose::ComposerParse,
        AnthropicPromptParser::processingContext($text, $profile), ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerParse));

    return $parser->parse($text, $profile, $now, $permit);
}

function fakeToolReply(array $content): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-5-5',
            'stop_reason' => 'tool_use',
            'content' => $content,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
        ]),
    ]);
}

function fakeToolCall(array $arguments): void
{
    fakeToolReply([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'route_prompt', 'input' => $arguments]]);
}

test('the driver maps a plan_day tool call into clamped constraints', function () {
    $now = CarbonImmutable::parse('2026-06-10 09:00', 'Europe/Berlin');
    fakeToolCall([
        'intent' => 'plan_day',
        'window_start' => $now->addHours(2)->toIso8601String(),
        'window_end' => $now->addHours(6)->toIso8601String(),
        'areas' => ['Ehrenfeld'],
        'categories' => ['park'],
        'companions' => 'friends',
    ]);

    $result = parseWithConsent('plans for later', llmProfile(), $now);

    expect($result->intent)->toBe(PromptIntent::PlanDay)
        ->and($result->source)->toBe('llm')
        ->and($result->plan->areas)->toContain('Ehrenfeld')
        ->and($result->plan->companions)->toBe('friends');
});

test('the driver maps a non-plan tool call into intent + query', function () {
    fakeToolCall(['intent' => 'bureaucracy_q', 'query' => 'anmeldung appointment days']);

    $result = parseWithConsent('when can I register?', llmProfile(), CarbonImmutable::now('Europe/Berlin'));

    expect($result->intent)->toBe(PromptIntent::BureaucracyQ)
        ->and($result->source)->toBe('llm')
        ->and($result->query)->toBe('anmeldung appointment days');
});

test('a provider failure degrades to the heuristic, never throws', function () {
    Http::fake(['api.anthropic.com/*' => Http::response('overloaded', 503)]);

    $result = parseWithConsent('do I need an appointment for Anmeldung?', llmProfile(), CarbonImmutable::now('Europe/Berlin'));

    // Heuristic took over and still classified correctly.
    expect($result->intent)->toBe(PromptIntent::BureaucracyQ)
        ->and($result->source)->toBe('heuristic');
});

test('hallucinated and malformed model constraints are removed before composing', function () {
    $now = CarbonImmutable::parse('2026-06-10 09:00', 'Europe/Berlin');
    fakeToolCall([
        'intent' => 'plan_day',
        'window_start' => $now->addHours(2)->toIso8601String(),
        'window_end' => $now->addHours(6)->toIso8601String(),
        'areas' => ['ehrenfeld', 'Gotham', ['nested']],
        'categories' => ['park', 'moonwalk', 42],
        'companions' => 'coworkers',
        'budget' => ['free'],
    ]);

    $result = parseWithConsent('plans for later', llmProfile(), $now);

    expect($result->source)->toBe('llm')
        ->and($result->plan->areas)->toBe(['Ehrenfeld'])
        ->and($result->plan->categories)->toBe(['park'])
        ->and($result->plan->companions)->toBeNull()
        ->and($result->plan->budget)->toBeNull();
});

test('an invalid model date falls back to the heuristic parser', function () {
    $now = CarbonImmutable::parse('2026-06-10 09:00', 'Europe/Berlin');
    fakeToolCall([
        'intent' => 'plan_day',
        'window_start' => 'definitely not a date',
        'window_end' => $now->addHours(6)->toIso8601String(),
    ]);

    $result = parseWithConsent('tomorrow afternoon in Ehrenfeld', llmProfile(), $now);

    expect($result->source)->toBe('heuristic')
        ->and($result->plan->areas)->toBe(['Ehrenfeld']);
});

test('provider failure logging excludes echoed private input and response bodies', function () {
    Log::spy();
    Http::fake(['api.anthropic.com/*' => Http::response('Private permit reference SECRET-1234', 503)]);

    $result = parseWithConsent('My private permit reference SECRET-1234', llmProfile(), CarbonImmutable::now());

    expect($result->source)->toBe('heuristic');
    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
        expect(json_encode([$message, $context]))->not->toContain('SECRET-1234')
            ->and($context['error_type'] ?? null)->toBe(RequestException::class);

        return true;
    });
});

test('the driver preserves the food and drink category filter', function () {
    $now = CarbonImmutable::parse('2026-06-10 09:00', 'Europe/Berlin');
    fakeToolCall([
        'intent' => 'plan_day',
        'window_start' => $now->addHours(2)->toIso8601String(),
        'window_end' => $now->addHours(6)->toIso8601String(),
        'categories' => ['food_drink'],
    ]);

    $result = parseWithConsent('find somewhere to eat or drink', llmProfile(), $now);

    expect($result->plan->categories)->toBe(['food_drink']);
});

test('the model parser retains explicit activity requirements even when the model omits them', function () {
    $now = CarbonImmutable::parse('2026-09-28 09:00', 'Europe/Berlin');
    fakeToolCall([
        'intent' => 'plan_day', 'window_start' => $now->addHour()->toIso8601String(),
        'window_end' => $now->addHours(6)->toIso8601String(), 'categories' => ['pitch'],
        'activities' => ['moonwalk', ['nested']], 'radius_km' => 999,
    ]);
    $result = parseWithConsent('recommend free football nearby within 2 km', llmProfile(), $now);
    expect($result->plan->activities)->toBe(['soccer'])
        ->and($result->plan->radiusKm)->toBe(2.0)
        ->and($result->plan->budget)->toBe('free');
});

test('the model parser retains everyday category families and specific venue types', function () {
    $now = CarbonImmutable::parse('2026-10-01 09:00', 'Europe/Berlin');
    $categories = ['shopping', 'services', 'health', 'community', 'stay', 'fitness', 'pharmacy', 'hairdresser'];
    fakeToolCall(['intent' => 'plan_day', 'window_start' => $now->addHour()->toIso8601String(), 'window_end' => $now->addHours(6)->toIso8601String(), 'categories' => [...$categories, 'invented_place_type']]);
    $result = parseWithConsent('find nearby everyday places', llmProfile(), $now);
    expect($result->plan->categories)->toBe($categories);
});

test('the model receives the complete supported venue category vocabulary', function () {
    $now = CarbonImmutable::parse('2026-10-01 09:00', 'Europe/Berlin');
    fakeToolCall(['intent' => 'plan_day', 'window_start' => $now->addHour()->toIso8601String(), 'window_end' => $now->addHours(6)->toIso8601String(), 'categories' => ['pharmacy']]);
    parseWithConsent('recommend a nearby pharmacy', llmProfile(), $now);
    Http::assertSent(function ($request) {
        $values = $request['tools'][0]['input_schema']['properties']['categories']['items']['enum'] ?? [];

        return in_array('shopping', $values, true) && in_array('pharmacy', $values, true) && in_array('hairdresser', $values, true);
    });
});

test('the driver posts one forced route_prompt tool call in the Messages API shape', function () {
    $now = CarbonImmutable::parse('2026-06-10 09:00', 'Europe/Berlin');
    fakeToolCall(['intent' => 'bureaucracy_q', 'query' => 'anmeldung appointment days']);

    parseWithConsent('when can I register?', llmProfile(), $now);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && ! $request->hasHeader('Authorization')
            && array_keys($payload) === ['model', 'max_tokens', 'output_config', 'tools', 'tool_choice', 'system', 'messages']
            && $payload['model'] === 'claude-haiku-5-5'
            && $payload['max_tokens'] === 600
            && $payload['output_config'] === ['effort' => 'low']
            && count($payload['tools']) === 1
            && ($payload['tools'][0]['name'] ?? null) === 'route_prompt'
            && $payload['tool_choice'] === ['type' => 'tool', 'name' => 'route_prompt']
            && is_string($payload['system'][0]['text'] ?? null)
            && ($payload['system'][0]['cache_control'] ?? null) === ['type' => 'ephemeral']
            && count($payload['messages']) === 1
            && ($payload['messages'][0]['role'] ?? null) === 'user'
            && str_contains((string) ($payload['messages'][0]['content'] ?? ''), 'when can I register?');
    });
});

test('a reply without a usable route_prompt tool call degrades to the heuristic', function (array $content) {
    fakeToolReply($content);

    $result = parseWithConsent('do I need an appointment for Anmeldung?', llmProfile(), CarbonImmutable::now('Europe/Berlin'));

    expect($result->intent)->toBe(PromptIntent::BureaucracyQ)
        ->and($result->source)->toBe('heuristic');
})->with([
    'prose only' => [[['type' => 'text', 'text' => 'You need an appointment.']]],
    'wrong tool' => [[['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'another_tool', 'input' => ['intent' => 'find']]]],
    'non-object input' => [[['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'route_prompt', 'input' => 'find']]],
    'missing input' => [[['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'route_prompt']]],
]);

test('a reply cut off at max_tokens falls back to the heuristic, even with a call in it', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'route_prompt', 'input' => ['intent' => 'find', 'query' => 'partial']]],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 600],
        ]),
    ]);

    $parsed = parseWithConsent('Museum this afternoon', llmProfile(), CarbonImmutable::parse('2026-06-12 10:00', 'Europe/Berlin'));

    expect($parsed->source)->not->toBe('llm');
});

it('reads the order of stops, moods, exclusions and needs, and keeps only known values', function () {
    fakeToolCall([
        'intent' => 'plan_day',
        'window_start' => '2026-10-24T13:00:00+02:00',
        'window_end' => '2026-10-24T18:00:00+02:00',
        'categories' => ['museum'],
        'sequence' => ['museum', 'cafe'],
        'avoid' => ['bar', 'nightclub-ish'],
        'moods' => ['quiet', 'mysterious'],
        'needs' => ['step_free', 'jetpack'],
    ]);

    $plan = parseWithConsent('A quiet museum and then coffee, step-free, no bars', llmProfile(), CarbonImmutable::parse('2026-10-24 10:00', 'Europe/Berlin'))->plan;

    expect($plan->sequence)->toBe(['museum', 'cafe'])
        ->and($plan->categories)->toBe(['museum', 'cafe'])
        ->and($plan->avoid)->toBe(['bar'])
        ->and($plan->moods)->toBe(['quiet'])
        ->and($plan->vibe)->toBe('chill')
        ->and($plan->needs)->toBe(['step_free']);
});

it('ignores a one-stop sequence', function () {
    fakeToolCall([
        'intent' => 'plan_day',
        'window_start' => '2026-10-24T13:00:00+02:00',
        'window_end' => '2026-10-24T18:00:00+02:00',
        'sequence' => ['park'],
    ]);

    expect(parseWithConsent('A park', llmProfile(), CarbonImmutable::parse('2026-10-24 10:00', 'Europe/Berlin'))->plan->sequence)->toBe([]);
});

it('plans from now when a plan names no time', function () {
    fakeToolCall(['intent' => 'plan_day', 'categories' => ['pitch']]);
    $now = CarbonImmutable::parse('2026-10-24 14:07', 'Europe/Berlin');

    $plan = parseWithConsent('I want to play football somewhere public', llmProfile(), $now)->plan;

    expect($plan)->not->toBeNull()
        ->and($plan->windowStart->format('H:i'))->toBe('14:15')
        ->and($plan->categories)->toBe(['pitch']);
});

test('an area named the everyday way is its Veedel ("Südstadt" is Neustadt-Süd)', function () {
    $now = CarbonImmutable::parse('2026-06-10 17:00', 'Europe/Berlin');
    fakeToolCall([
        'intent' => 'plan_day',
        'window_start' => $now->addHour()->toIso8601String(),
        'window_end' => $now->addHours(4)->toIso8601String(),
        'areas' => ['Südstadt', 'Belgisches Viertel'],
    ]);

    expect(parseWithConsent('Something in the Südstadt this evening', llmProfile(), $now)->plan->areas)->toBe(['Neustadt-Süd', 'Neustadt-Nord']);
});
