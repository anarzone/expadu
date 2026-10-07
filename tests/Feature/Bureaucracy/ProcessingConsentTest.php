<?php

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseMessage;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use App\Privacy\ExternalProcessingGate;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPermit;
use App\Privacy\ProcessingPurpose;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ExternalProcessingFixtures;

beforeEach(function () {
    $this->travelTo('2026-09-08 12:00:00');
    config()->set('services.llm.base_url', 'https://processor.example.test');
    config()->set('services.llm.model', 'synthetic-parser');
    config()->set('services.llm.driver', 'openai');
    config()->set('services.llm.key', 'synthetic-key');
    config()->set('services.llm.processor_name', 'Synthetic processor');
    config()->set('services.llm.processor_privacy_url', 'https://processor.example.test/privacy');
    config()->set('services.llm.prompt_version', 'synthetic.1');
    config()->set('bureaucracy_privacy.daily_limits.composer_parse', 2);
    $this->processingResponseStatus = 200;
    $this->processingResponseHook = null;
    Http::fake(function () {
        if ($this->processingResponseHook !== null) {
            ($this->processingResponseHook)();
        }

        return Http::response(['answer' => 'PRIVATE-CANDIDATE'], $this->processingResponseStatus);
    });
});

function processingAcceptance(ProcessingPurpose $purpose, ?string $requestId = null): array
{
    return [
        'consent' => true,
        'request_id' => $requestId ?? (string) Str::uuid(),
        'notice_version' => config('bureaucracy_privacy.notice_version'),
        'provider_version' => $purpose->providerVersion(),
    ];
}

function consentTransport(): Closure
{
    return fn () => Http::post('https://processor.example.test/parse', ['text' => 'PRIVATE-PROMPT']);
}

test('transport requires a current single request permit even if a case has old consent', function () {
    $user = User::factory()->create();
    BureaucracyCase::factory()->for($user)->create(['ai_consent_at' => now()]);

    expect(app(ExternalProcessingGate::class)->send(null, ProcessingPurpose::ComposerParse, ['text' => 'PRIVATE-PROMPT'], consentTransport()))->toBeNull();
    Http::assertNothingSent();
});

test('permission rejects absent consent and stale notices before creating a grant', function (string $field, mixed $value) {
    $user = User::factory()->create();
    $purpose = ProcessingPurpose::ComposerParse;
    $acceptance = array_replace(processingAcceptance($purpose), [$field => $value]);

    expect(fn () => app(ProcessingConsentStore::class)->grant($user, $purpose, ['text' => 'PRIVATE-PROMPT'], $acceptance))
        ->toThrow(ValidationException::class);
    expect(BureaucracyProcessingConsent::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'not consented' => ['consent', false],
    'coerced consent' => ['consent', 'true'],
    'old notice' => ['notice_version', 'old'],
    'different provider' => ['provider_version', 'other'],
    'unbounded request key' => ['request_id', 'not-a-uuid'],
]);

test('an identical retry returns its encrypted result without another send or quota charge', function () {
    $user = User::factory()->create();
    $purpose = ProcessingPurpose::ComposerParse;
    $context = ['text' => 'PRIVATE-PROMPT'];
    $acceptance = processingAcceptance($purpose);
    $store = app(ProcessingConsentStore::class);
    $permit = $store->grant($user, $purpose, $context, $acceptance);
    $gate = app(ExternalProcessingGate::class);

    $first = $gate->send($permit, $purpose, $context, consentTransport());
    $retry = $store->grant($user, $purpose, $context, $acceptance);
    expect($gate->send($retry, $purpose, $context, consentTransport()))->toBe($first)
        ->and($first)->toBe(['answer' => 'PRIVATE-CANDIDATE']);
    Http::assertSentCount(1);
    expect(BureaucracyProcessingConsent::query()->count())->toBe(1);
    $row = BureaucracyProcessingConsent::query()->sole();
    expect($row->toArray())->not->toHaveKeys(['result', 'input_digest'])
        ->and(json_encode(DB::table('bureaucracy_processing_consents')->first()))
        ->not->toContain('PRIVATE-PROMPT')->not->toContain('PRIVATE-CANDIDATE');
});

test('a reused request key cannot authorise a changed payload or purpose', function () {
    $user = User::factory()->create();
    $purpose = ProcessingPurpose::ComposerParse;
    $acceptance = processingAcceptance($purpose);
    $store = app(ProcessingConsentStore::class);
    $store->grant($user, $purpose, ['text' => 'first'], $acceptance);

    expect(fn () => $store->grant($user, $purpose, ['text' => 'changed'], $acceptance))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

test('transport withholds permission after withdrawal expiry provider changes and mismatched inputs', function (string $change) {
    $user = User::factory()->create();
    $purpose = ProcessingPurpose::ComposerParse;
    $context = ['text' => 'PRIVATE-PROMPT'];
    $store = app(ProcessingConsentStore::class);
    $permit = $store->grant($user, $purpose, $context, processingAcceptance($purpose));

    match ($change) {
        'withdrawn' => $store->withdraw($user),
        'expired' => $this->travel(15)->minutes(),
        'provider' => config()->set('services.llm.model', 'different-model'),
        'notice' => config()->set('bureaucracy_privacy.notice_version', 'different-notice'),
        'disabled' => config()->set('services.llm.driver', 'heuristic'),
        'input' => $context['text'] = 'different',
    };

    expect(app(ExternalProcessingGate::class)->send($permit, $purpose, $context, consentTransport()))->toBeNull();
    Http::assertNothingSent();
})->with(['withdrawn', 'expired', 'provider', 'notice', 'input', 'disabled']);

test('quota survives withdrawal and cannot be bypassed with a new request key', function () {
    $user = User::factory()->create();
    $purpose = ProcessingPurpose::ComposerParse;
    $context = ['text' => 'PRIVATE-PROMPT'];
    $store = app(ProcessingConsentStore::class);
    $gate = app(ExternalProcessingGate::class);

    foreach (range(1, 2) as $attempt) {
        $permit = $store->grant($user, $purpose, $context, processingAcceptance($purpose));
        expect($gate->send($permit, $purpose, $context, consentTransport()))->not->toBeNull();
        $store->withdraw($user);
    }
    $permit = $store->grant($user, $purpose, $context, processingAcceptance($purpose));
    expect($gate->send($permit, $purpose, $context, consentTransport()))->toBeNull();
    Http::assertSentCount(2);
    expect(BureaucracyProcessingConsent::query()->whereNotNull('attempted_at')->count())->toBe(2);
});

test('a delayed withdrawn result is discarded and cannot be replayed', function () {
    $user = User::factory()->create();
    $purpose = ProcessingPurpose::ComposerParse;
    $context = ['text' => 'PRIVATE-PROMPT'];
    $store = app(ProcessingConsentStore::class);
    $permit = $store->grant($user, $purpose, $context, processingAcceptance($purpose));
    $this->processingResponseHook = fn () => $store->withdraw($user);

    expect(app(ExternalProcessingGate::class)->send($permit, $purpose, $context, consentTransport()))->toBeNull()
        ->and(BureaucracyProcessingConsent::query()->sole()->result)->toBeNull();
    Http::assertSentCount(1);
});

test('an uncertain or failed provider response is never automatically resent', function () {
    $user = User::factory()->create();
    $purpose = ProcessingPurpose::ComposerParse;
    $context = ['text' => 'PRIVATE-PROMPT'];
    $permit = app(ProcessingConsentStore::class)->grant($user, $purpose, $context, processingAcceptance($purpose));
    $this->processingResponseStatus = 503;
    $gate = app(ExternalProcessingGate::class);

    expect($gate->send($permit, $purpose, $context, consentTransport()))->toBeNull()
        ->and($gate->send($permit, $purpose, $context, consentTransport()))->toBeNull();
    Http::assertSentCount(1);
});

test('subject permission cannot be borrowed from another account or a closed dossier', function () {
    $user = User::factory()->create();
    $case = BureaucracyCase::factory()->create();

    expect(fn () => app(ProcessingConsentStore::class)->grant($user, ProcessingPurpose::FactExtraction, [], processingAcceptance(ProcessingPurpose::FactExtraction), $case))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

test('withdrawal removes cached results and old raw messages but leaves confirmed facts alone', function () {
    $user = User::factory()->create();
    $case = BureaucracyCase::factory()->for($user)->create();
    $case->messages()->create(['role' => 'user', 'content' => 'OLD-PRIVATE-TEXT', 'operation' => 'extract_case_fact', 'prompt_version' => 'legacy', 'expires_at' => now()->addDay()]);
    $fact = $case->facts()->create(['key' => 'german_level', 'value' => 'b1', 'state' => 'confirmed', 'source' => 'user']);
    app(ProcessingConsentStore::class)->withdraw($user);

    expect($case->messages()->count())->toBe(0)->and($fact->fresh()->value)->toBe('b1');
});

test('cleanup expires encrypted responses while retaining only bounded request metadata', function () {
    $user = User::factory()->create();
    $purpose = ProcessingPurpose::ComposerParse;
    $context = ['text' => 'PRIVATE-PROMPT'];
    $permit = app(ProcessingConsentStore::class)->grant($user, $purpose, $context, processingAcceptance($purpose));
    app(ExternalProcessingGate::class)->send($permit, $purpose, $context, consentTransport());
    $this->travel(16)->minutes();
    Artisan::call('bureaucracy:prune-processing');

    expect(BureaucracyProcessingConsent::query()->sole()->result)->toBeNull();
    $this->travel(31)->days();
    Artisan::call('bureaucracy:prune-processing');
    expect(BureaucracyProcessingConsent::query()->count())->toBe(0);
});

test('a permission handle cannot be borrowed by another actor', function () {
    $purpose = ProcessingPurpose::ComposerParse;
    $context = ['text' => 'PRIVATE-PROMPT'];
    $permit = app(ProcessingConsentStore::class)->grant(User::factory()->create(), $purpose, $context, processingAcceptance($purpose));
    $forged = new ProcessingPermit($permit->id, User::factory()->create()->id);

    expect(app(ExternalProcessingGate::class)->send($forged, $purpose, $context, consentTransport()))->toBeNull();
    Http::assertNothingSent();
});

test('removing pre migration raw messages does not reset the rolling quota', function () {
    ExternalProcessingFixtures::configure();
    config()->set('services.bureaucracy_llm.daily_limit', 2);
    $request = ExternalProcessingFixtures::factRequest('german_level', 'B1');
    $row = BureaucracyProcessingConsent::query()->findOrFail($request->permit->id);
    $user = User::query()->findOrFail($row->actor_id);
    $case = BureaucracyCase::query()->findOrFail($row->case_id);
    BureaucracyCaseMessage::factory()->count(2)->for($case, 'case')->create([
        'operation' => 'extract_case_fact', 'role' => 'user', 'created_at' => now()->subHour(),
    ]);
    app(ProcessingConsentStore::class)->withdraw($user);

    expect($case->messages()->count())->toBe(0)
        ->and(app(ExternalProcessingGate::class)->used($user->id, ProcessingPurpose::FactExtraction))->toBe(2);
    $this->travel(24)->hours();
    expect(app(ExternalProcessingGate::class)->used($user->id, ProcessingPurpose::FactExtraction))->toBe(0);
});

test('withdrawal between reservation and dispatch prevents the transport from starting', function () {
    $actor = User::factory()->create();
    $purpose = ProcessingPurpose::ComposerParse;
    $context = ['text' => 'PRIVATE-PROMPT'];
    $store = app(ProcessingConsentStore::class);
    $permit = $store->grant($actor, $purpose, $context, processingAcceptance($purpose));
    $withdrawn = false;
    DB::listen(function ($query) use ($store, $actor, &$withdrawn): void {
        if (! $withdrawn && str_contains($query->sql, 'update "bureaucracy_processing_consents"')
            && in_array('processing', $query->bindings, true)) {
            $withdrawn = true;
            $store->withdraw($actor);
        }
    });

    expect(app(ExternalProcessingGate::class)->send($permit, $purpose, $context, consentTransport()))->toBeNull()
        ->and($withdrawn)->toBeTrue();
    Http::assertNothingSent();
});
