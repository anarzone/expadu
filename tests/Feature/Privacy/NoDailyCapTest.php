<?php

use App\Composer\AnthropicPromptParser;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use App\Profile\ProfileEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalProcessingFixtures;

/*
 * Owner, 22 Oct 2026: "lift the sanctions". No person has a daily cap on AI processing
 * unless one is set (its env); a cap of 0 or none means no cap.
 */

test('🔒 no purpose caps a person by default', function (ProcessingPurpose $purpose) {
    expect($purpose->dailyLimit())->toBeNull();
})->with(ProcessingPurpose::cases());

test('🔒 a cap of 0 means none; a cap set holds', function () {
    config()->set('bureaucracy_privacy.daily_limits.composer_parse', 0);
    expect(ProcessingPurpose::ComposerParse->dailyLimit())->toBeNull();

    config()->set('bureaucracy_privacy.daily_limits.composer_parse', '25');
    expect(ProcessingPurpose::ComposerParse->dailyLimit())->toBe(25);
});

test('🔒 with no cap, Composer reads every request a person sends in a day', function () {
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'claude-haiku-5-5', 'key' => 'synthetic',
        'processor_name' => 'Synthetic processor', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    Http::fake(['processor.example.test/*' => Http::response(['id' => 'msg', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
        'stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't', 'name' => 'route_prompt', 'input' => ['intent' => 'find', 'query' => 'x']]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5]])]);
    $user = User::factory()->onboarded()->create();
    $profile = app(ProfileEngine::class)->build($user);
    $parser = app(AnthropicPromptParser::class);

    foreach (range(1, 25) as $n) {
        $permit = app(ProcessingConsentStore::class)->grant($user, ProcessingPurpose::ComposerParse,
            AnthropicPromptParser::processingContext("request {$n}", $profile), ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerParse));
        expect($parser->parse("request {$n}", $profile, CarbonImmutable::now('Europe/Berlin'), $permit)->source)->toBe('llm');
    }
    Http::assertSentCount(25);
});
