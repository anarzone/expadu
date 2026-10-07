<?php

use App\Composer\HeuristicPromptParser;
use App\Composer\OpenAiCompatiblePromptParser;
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
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\ExternalProcessingFixtures;

/**
 * The OpenAI-compatible driver is dormant until a key lands, but its
 * mapping and degradation path are proven here against a faked provider —
 * so flipping LLM_DRIVER=openai is a config change, not a leap of faith.
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

function llmParser(): OpenAiCompatiblePromptParser
{
    config()->set('services.llm.driver', 'openai');
    config()->set('services.llm.processor_name', 'Synthetic processor');
    config()->set('services.llm.processor_privacy_url', 'https://processor.example.test/privacy');
    config()->set('services.llm.prompt_version', 'synthetic.1');
    config()->set('services.llm.base_url', 'https://api.deepseek.com');
    config()->set('services.llm.model', 'deepseek-chat');
    config()->set('services.llm.key', 'test-key');

    return new OpenAiCompatiblePromptParser(new HeuristicPromptParser);
}

function parseWithConsent(string $text, Profile $profile, CarbonImmutable $now): ParsedPrompt
{
    test()->travelTo($now);
    $parser = llmParser();
    $permit = app(ProcessingConsentStore::class)->grant(User::factory()->create(), ProcessingPurpose::ComposerParse,
        OpenAiCompatiblePromptParser::processingContext($text, $profile), ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerParse));

    return $parser->parse($text, $profile, $now, $permit);
}

function fakeToolCall(array $arguments): void
{
    Http::fake([
        'api.deepseek.com/*' => Http::response([
            'choices' => [
                ['message' => ['tool_calls' => [
                    ['function' => ['name' => 'route_prompt', 'arguments' => json_encode($arguments)]],
                ]]],
            ],
        ]),
    ]);
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
    Http::fake(['api.deepseek.com/*' => Http::response('overloaded', 503)]);

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
    Http::fake(['api.deepseek.com/*' => Http::response('Private permit reference SECRET-1234', 503)]);

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
        $values = $request['tools'][0]['function']['parameters']['properties']['categories']['items']['enum'] ?? [];

        return in_array('shopping', $values, true) && in_array('pharmacy', $values, true) && in_array('hairdresser', $values, true);
    });
});
