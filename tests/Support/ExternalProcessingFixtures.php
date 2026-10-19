<?php

namespace Tests\Support;

use App\Bureaucracy\Ai\CaseFactExtractionRequest;
use App\Bureaucracy\Ai\CaseFactExtractionResult;
use App\Bureaucracy\Cases\QuestionSelector;
use App\Bureaucracy\Facts\FactRegistry;
use App\Models\BureaucracyCase;
use App\Models\Task;
use App\Models\User;
use App\Privacy\ExternalProcessingGate;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use Closure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

final class ExternalProcessingFixtures
{
    public static function factRequest(string $key, string $message): CaseFactExtractionRequest
    {
        $user = User::factory()->onboarded()->create(['is_eu' => null, 'situation' => 'other', 'german_level' => null, 'profile_attributes' => [], 'bureaucracy_path' => null]);
        $case = BureaucracyCase::factory()->for($user)->create();
        $definition = app(FactRegistry::class)->definition($key);
        $operand = match ($definition->type) {
            'enum' => $definition->options[0], 'integer' => 1, 'boolean' => true, 'date' => '2026-01-01', default => 'synthetic',
        };
        Task::factory()->approvedFixture()->create(['key' => 'fixture.processing.'.Str::uuid(), 'applies_if' => [[$key => $operand]]]);
        $question = app(QuestionSelector::class)->ask($case, [$key]);
        $request = new CaseFactExtractionRequest($key, $definition->question, $definition->why, $message);
        $permit = app(ProcessingConsentStore::class)->grant($user, ProcessingPurpose::FactExtraction, $request->processingContext(), self::acceptance(ProcessingPurpose::FactExtraction), $case, $question);

        return new CaseFactExtractionRequest($key, $definition->question, $definition->why, $message, $permit);
    }

    /** Test double still traverses reservation/replay/revocation; it does not fake authorisation. */
    public static function fakeExtraction(CaseFactExtractionRequest $request, CaseFactExtractionResult $result, Closure $onDispatch): CaseFactExtractionResult
    {
        $body = app(ExternalProcessingGate::class)->send($request->permit, ProcessingPurpose::FactExtraction, $request->processingContext(), function () use ($result, $onDispatch): Response {
            $onDispatch();

            return new Response(new PsrResponse(200, [], json_encode(['outcome' => $result->outcome, 'value' => $result->value], JSON_THROW_ON_ERROR)));
        });

        return match ($body['outcome'] ?? null) {
            'candidate' => CaseFactExtractionResult::candidate($body['value']),
            'unknown' => CaseFactExtractionResult::unknown(),
            'off_topic' => CaseFactExtractionResult::offTopic(),
            'invalid' => CaseFactExtractionResult::invalid(),
            default => CaseFactExtractionResult::unavailable(),
        };
    }

    public static function acceptance(ProcessingPurpose $purpose, ?string $requestId = null): array
    {
        return [
            'consent' => true, 'request_id' => $requestId ?? (string) Str::uuid(),
            'notice_version' => config('bureaucracy_privacy.notice_version'),
            'provider_version' => $purpose->providerVersion(),
        ];
    }

    public static function configure(): void
    {
        config()->set('services.bureaucracy_llm', [
            'enabled' => true, 'base_url' => 'https://processor.example.test',
            'model' => 'synthetic-extractor', 'key' => 'synthetic-test-key',
            'processor_name' => 'Synthetic processor', 'processor_privacy_url' => 'https://processor.example.test/privacy',
            'prompt_version' => 'synthetic.1', 'timeout' => 8, 'daily_limit' => 20,
        ]);
    }
}
