<?php

use App\Bureaucracy\Ai\CaseFactExtractionRequest;
use App\Bureaucracy\Ai\DeepSeekCaseFactExtractor;
use App\Composer\AnthropicCandidateRanker;
use App\Composer\Candidate;
use App\Composer\Constraints;
use App\Composer\HeuristicPromptParser;
use App\Composer\OpenAiCompatiblePromptParser;
use App\Models\User;
use App\Profile\ProfileEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

test('each configured personal input client stays local without a request permit', function (string $client) {
    config()->set('services.bureaucracy_llm.key', 'synthetic');
    config()->set('services.llm.key', 'synthetic');
    config()->set('services.composer_llm.enabled', true);
    config()->set('services.composer_llm.key', 'synthetic');
    Http::fake(fn () => Http::response([]));

    if ($client === 'extraction') {
        app(DeepSeekCaseFactExtractor::class)->extract(new CaseFactExtractionRequest('german_level', 'Which level?', 'Synthetic question', 'B1'));
    } elseif ($client === 'parser') {
        (new OpenAiCompatiblePromptParser(new HeuristicPromptParser))->parse('Anmeldung?', app(ProfileEngine::class)->build(User::factory()->create()), CarbonImmutable::now());
    } else {
        $candidate = new Candidate('spot:1', 'spot', 'Synthetic park', 50.9, 6.9, null, 'park', true, 30, 'free', null, null);
        app(AnthropicCandidateRanker::class)->rank(new Constraints(CarbonImmutable::now(), CarbonImmutable::now()->addHours(3)), [$candidate]);
    }

    Http::assertNothingSent();
})->with(['extraction', 'parser', 'ranker']);
