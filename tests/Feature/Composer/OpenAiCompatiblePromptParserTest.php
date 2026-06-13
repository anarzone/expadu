<?php

use App\Composer\HeuristicPromptParser;
use App\Composer\OpenAiCompatiblePromptParser;
use App\Composer\PromptIntent;
use App\Enums\GermanLevel;
use App\Enums\Situation;
use App\Profile\Profile;
use App\Profile\TicketAdvice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

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
    config()->set('services.llm.base_url', 'https://api.deepseek.com');
    config()->set('services.llm.model', 'deepseek-chat');
    config()->set('services.llm.key', 'test-key');

    return new OpenAiCompatiblePromptParser(new HeuristicPromptParser);
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

    $result = llmParser()->parse('plans for later', llmProfile(), $now);

    expect($result->intent)->toBe(PromptIntent::PlanDay)
        ->and($result->source)->toBe('llm')
        ->and($result->plan->areas)->toContain('Ehrenfeld')
        ->and($result->plan->companions)->toBe('friends');
});

test('the driver maps a non-plan tool call into intent + query', function () {
    fakeToolCall(['intent' => 'bureaucracy_q', 'query' => 'anmeldung appointment days']);

    $result = llmParser()->parse('when can I register?', llmProfile(), CarbonImmutable::now('Europe/Berlin'));

    expect($result->intent)->toBe(PromptIntent::BureaucracyQ)
        ->and($result->source)->toBe('llm')
        ->and($result->query)->toBe('anmeldung appointment days');
});

test('a provider failure degrades to the heuristic, never throws', function () {
    Http::fake(['api.deepseek.com/*' => Http::response('overloaded', 503)]);

    $result = llmParser()->parse('do I need an appointment for Anmeldung?', llmProfile(), CarbonImmutable::now('Europe/Berlin'));

    // Heuristic took over and still classified correctly.
    expect($result->intent)->toBe(PromptIntent::BureaucracyQ)
        ->and($result->source)->toBe('heuristic');
});
