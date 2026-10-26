<?php

use App\Bureaucracy\Analyser\AnswerVerifier;
use App\Bureaucracy\Analyser\CheckedCard;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyProcessingConsent;
use App\Models\Task;
use App\Models\User;
use App\Privacy\ExternalProcessingGate;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ExternalProcessingFixtures;

const AnalyserQuestion = 'Do I need to register my address after moving into my flat?';

const AnalyserRegistration = 'Registration normally follows moving into a dwelling, with a two-week period under §17 BMG. A rental contract alone is not sufficient.';

beforeEach(function () {
    $this->travelTo('2026-10-21 10:00:00');
    config()->set('services.paperwork_analyser', [
        'enabled' => true, 'base_url' => 'https://api.anthropic.com', 'model' => 'claude-haiku-5-5', 'effort' => 'low',
        'key' => 'analyser-test-key', 'processor_name' => 'Anthropic PBC', 'processor_privacy_url' => 'https://www.anthropic.com/legal/privacy',
        'timeout' => 8, 'prompt_version' => 'analyser-test.1', 'daily_limit' => 20,
    ]);
    Http::preventStrayRequests();
    analyserCatalogue();
});

/** Two quote-checked cards and one human-reviewed card, live in a fresh release. */
function analyserCatalogue(): void
{
    $checked = ['source_verification' => 'quote_checked', 'reviewed_by' => 'automated_source_check', 'deadline_type' => 'none',
        'deadline_days' => null, 'depends_on' => [], 'links' => [], 'type' => 'task'];
    $tasks = [
        Task::factory()->approvedFixture()->create([...$checked, 'key' => 'fixture.registration', 'title' => 'Register your address (Anmeldung)',
            'description' => AnalyserRegistration,
            'applies_if' => ['registration_status' => 'not_registered', 'moved_in_at' => ['present' => true]],
            'how_to_steps' => [['title' => 'Get the move-in confirmation', 'body' => 'Ask the housing provider for the Wohnungsgeberbescheinigung confirming actual move-in.']],
            'documents_required' => [['label' => 'Identity documents appropriate to your nationality', 'note' => 'Check the city list for your case.']],
            'claims' => [['id' => 'period', 'states' => 'two-week period under §17 BMG', 'source' => 'Fixture statute',
                'quote' => 'hat sich innerhalb von zwei Wochen nach dem Einzug bei der Meldebehörde anzumelden']]])->fresh(),
        Task::factory()->approvedFixture()->create([...$checked, 'key' => 'fixture.blue_card', 'title' => 'Prepare your EU Blue Card application',
            'description' => 'The Blue Card application fee is €100 for the first card.', 'applies_if' => ['citizenship_group' => 'non_eu'],
            'how_to_steps' => [], 'documents_required' => [],
            'claims' => [['id' => 'fee', 'states' => '€100', 'source' => 'Fixture statute', 'quote' => 'Gebühr 100 Euro']]])->fresh(),
        // Approved by a person, not by the automated source check: never a source for the analyser.
        Task::factory()->approvedFixture()->create(['key' => 'fixture.unchecked', 'title' => 'Address registration tips', 'type' => 'task',
            'description' => 'You can always register your address later at no cost.', 'applies_if' => [], 'deadline_type' => 'none',
            'deadline_days' => null, 'depends_on' => [], 'links' => [], 'how_to_steps' => [], 'documents_required' => []])->fresh(),
    ];
    $mapping = [
        'fixture.registration' => ['process_id' => 'address.fixture-registration', 'topic' => 'address', 'kind' => 'preparation', 'coverage' => 'partial'],
        'fixture.blue_card' => ['process_id' => 'residence.fixture-blue-card', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial'],
        'fixture.unchecked' => ['process_id' => 'address.fixture-tips', 'topic' => 'address', 'kind' => 'context', 'coverage' => 'partial'],
    ];
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile($tasks, $mapping));
    $store->activate($release->id, null);
}

/** @return array{0: User, 1: BureaucracyCase} */
function analyserCase(array $facts = []): array
{
    $user = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($user);
    $facts = $facts ?: ['registration_status' => 'not_registered', 'moved_in_at' => '2026-09-01', 'citizenship_group' => 'eu',
        'purpose' => 'employment', 'arrival_date' => '2026-08-20'];
    foreach ($facts as $key => $value) {
        app(RecordFactChange::class)->execute($user, $case->person, $key, $value, null, $case->fresh()->fact_version);
    }

    return [$user, $case->fresh()];
}

function analyserReply(array $input, string $stop = 'tool_use', array $before = []): array
{
    return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => $stop,
        'content' => [...$before, ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'answer_from_cards', 'input' => $input]],
        'usage' => ['input_tokens' => 900, 'output_tokens' => 120]];
}

function analyserGood(): array
{
    return ['text' => 'The card says registration normally follows moving into a dwelling, with a two-week period under §17 BMG.',
        'card_id' => 'fixture.registration', 'quote' => 'Registration normally follows moving into a dwelling, with a two-week period under §17 BMG.'];
}

function analyserAsk(User $user, int $personId, array $body = []): TestResponse
{
    return test()->actingAs($user)->postJson('/bureaucracy/v2/people/'.$personId.'/ask', array_filter([
        'question' => AnalyserQuestion, 'jurisdiction' => 'de-nrw-cologne',
        'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::PaperworkAnswer), ...$body,
    ], fn ($value) => $value !== null));
}

/** @return array<string, CheckedCard> */
function analyserCards(): array
{
    $card = new CheckedCard('fixture.registration', 'Register your address (Anmeldung)', 'address.fixture-registration', 'address',
        ['Register your address (Anmeldung)', AnalyserRegistration, 'Keep the confirmation after registration.',
            'If confirmation is refused, notify the registration authority without delay.'],
        ['registration_status'], true, 'supported_preparation', [], [], [], [], 300);

    return ['fixture.registration' => $card];
}

test('a sentence survives only when every check passes', function (array $sentence, ?string $reason) {
    expect(app(AnswerVerifier::class)->reason([...analyserGood(), ...$sentence], analyserCards()))->toBe($reason);
})->with([
    'restates its quote' => [[], null],
    'restates a quote that keeps its negation' => [['text' => 'The card says a rental contract alone is not sufficient.',
        'quote' => 'A rental contract alone is not sufficient.'], null],
    'quote not in the card' => [['text' => 'Registration is done at the Bürgeramt.', 'quote' => 'Registration is done at the Bürgeramt.'], 'quote_not_in_card'],
    'quote paraphrased rather than copied' => [['quote' => 'Registration usually follows moving into a dwelling.'], 'quote_not_in_card'],
    'quote too short to support anything' => [['text' => 'Registration matters.', 'quote' => 'Registration'], 'quote_length'],
    'unknown card id' => [['card_id' => 'fixture.unchecked'], 'unknown_card'],
    'figure not in the quote' => [['text' => 'Registration normally follows moving into a dwelling within 14 days.',
        'quote' => 'Registration normally follows moving into a dwelling'], 'figure_not_in_quote'],
    'amount not in the quote' => [['text' => 'Registration normally follows moving into a dwelling and costs €12.',
        'quote' => 'Registration normally follows moving into a dwelling'], 'figure_not_in_quote'],
    'converted figure' => [['text' => 'Registration normally follows moving into a dwelling, with a 14-day period under §17 BMG.'], 'figure_not_in_quote'],
    'weekday not in the quote' => [['text' => 'Registration normally follows moving into a dwelling on Monday.',
        'quote' => 'Registration normally follows moving into a dwelling'], 'figure_not_in_quote'],
    'deadline word not in the quote' => [['text' => 'The deadline: registration normally follows moving into a dwelling.',
        'quote' => 'Registration normally follows moving into a dwelling'], 'figure_not_in_quote'],
    'entitlement not in the quote' => [['text' => 'You are entitled to registration when moving into a dwelling.',
        'quote' => 'Registration normally follows moving into a dwelling'], 'legal_outcome'],
    'outcome promise not in the quote' => [['text' => 'You will get registration when moving into a dwelling.',
        'quote' => 'Registration normally follows moving into a dwelling'], 'legal_outcome'],
    'obligation not in the quote' => [['text' => 'You must notify the registration authority.',
        'quote' => 'notify the registration authority without delay'], 'legal_outcome'],
    'fact about the person' => [['text' => 'Because you moved, registration normally follows moving into a dwelling.',
        'quote' => 'Registration normally follows moving into a dwelling'], 'case_fact'],
    'negation added' => [['text' => 'Registration does not normally follow moving into a dwelling.',
        'quote' => 'Registration normally follows moving into a dwelling'], 'negation_or_condition_changed'],
    'negation dropped' => [['text' => 'A rental contract alone is sufficient.', 'quote' => 'A rental contract alone is not sufficient.'], 'negation_or_condition_changed'],
    'condition dropped' => [['text' => 'Notify the registration authority without delay.',
        'quote' => 'If confirmation is refused, notify the registration authority without delay.'], 'negation_or_condition_changed'],
    'word from outside the card' => [['text' => 'Registration normally follows moving into a dwelling; bring your passport.',
        'quote' => 'Registration normally follows moving into a dwelling'], 'ungrounded_words'],
    'words reordered into another meaning' => [['text' => 'The card says a dwelling normally follows registration under §17 BMG, with a two-week period, moving into it.'], 'reordered'],
    'swapped timing' => [['text' => 'Keep the confirmation before registration.', 'quote' => 'Keep the confirmation after registration.'], 'figure_not_in_quote'],
    'invented link' => [['text' => 'Registration normally follows moving into a dwelling, see www.example.test.',
        'quote' => 'Registration normally follows moving into a dwelling'], 'link_or_markup'],
    'extra keys' => [['extra' => 'x'], 'malformed'],
]);

test('only verified sentences reach the person, with card titles and the claim source', function () {
    [$user, $case] = analyserCase();
    Http::fake(['api.anthropic.com/*' => Http::response(analyserReply(['answerable' => true, 'related_card_ids' => [], 'sentences' => [
        analyserGood(),
        ['text' => 'Registration is done at the Bürgeramt.', 'card_id' => 'fixture.registration', 'quote' => 'Registration is done at the Bürgeramt.'],
        ['text' => 'Registration normally follows moving into a dwelling within 14 days.', 'card_id' => 'fixture.registration',
            'quote' => 'Registration normally follows moving into a dwelling'],
        ['text' => 'You can always register your address later at no cost.', 'card_id' => 'fixture.unchecked',
            'quote' => 'You can always register your address later at no cost.'],
        ['text' => 'You are entitled to registration when moving into a dwelling.', 'card_id' => 'fixture.registration',
            'quote' => 'Registration normally follows moving into a dwelling'],
    ]]))]);

    $response = analyserAsk($user, $case->person_id)->assertSuccessful()
        ->assertJsonPath('status', 'answered')->assertJsonPath('mode', 'answer')
        ->assertJsonPath('checks.sentences_dropped', 4)
        ->assertJsonCount(1, 'sentences')
        ->assertJsonPath('sentences.0.card_id', 'fixture.registration')
        ->assertJsonPath('sentences.0.card_title', 'Register your address (Anmeldung)')
        ->assertJsonPath('sentences.0.source.url', 'https://www.gesetze-im-internet.de/bmg/__17.html')
        ->assertJsonPath('cards.0.id', 'fixture.registration')->assertJsonPath('cards.0.cited', true)
        ->assertJsonPath('cards.0.applies_to_case', true);

    // The case's own answers come back from templates, not from the model.
    expect($response->json('cards.0.explanation'))
        ->toBe('This card is part of your plan because you confirmed: Address registration: Not yet; Move-in date: 1 September 2026.')
        ->and(collect($response->json('cards'))->pluck('id'))->not->toContain('fixture.unchecked');
    Http::assertSentCount(1);

    $call = DB::table('llm_calls')->sole();
    expect($call->feature)->toBe('paperwork_analyser')->and($call->outcome)->toBe('answered:1/5')
        ->and($call->input_tokens)->toBe(900)->and(json_encode($call))->not->toContain('register my address');
});

test('the processor sees only the question, checked card text and the answers those cards read', function () {
    [$user, $case] = analyserCase();
    Http::fake(['api.anthropic.com/*' => Http::response(analyserReply(['answerable' => true, 'related_card_ids' => [], 'sentences' => [analyserGood()]]))]);

    analyserAsk($user, $case->person_id)->assertSuccessful();

    Http::assertSent(function (Request $request) use ($user): bool {
        $payload = $request->data();
        $content = json_decode($payload['messages'][0]['content'], true, 512, JSON_THROW_ON_ERROR);
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'analyser-test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $payload['tool_choice'] === ['type' => 'tool', 'name' => 'answer_from_cards']
            && $payload['tools'][0]['strict'] === true
            && $payload['tools'][0]['input_schema']['properties']['sentences']['items']['properties']['card_id']['enum'] === ['fixture.registration']
            && array_keys($content) === ['question', 'cards', 'confirmed_answers']
            && $content['question'] === AnalyserQuestion
            && array_column($content['cards'], 'id') === ['fixture.registration']
            && $content['confirmed_answers'] === [
                ['key' => 'registration_status', 'question' => 'Address registration', 'answer' => 'Not yet'],
                ['key' => 'moved_in_at', 'question' => 'Move-in date', 'answer' => 'recorded (value not shared)'],
            ]
            // Answers no candidate card reads, dates, the account and other cards never leave.
            && ! str_contains($raw, '2026-09-01') && ! str_contains($raw, 'Employment') && ! str_contains($raw, 'citizenship')
            && ! str_contains($raw, 'arrival') && ! str_contains($raw, $user->email) && ! str_contains($raw, $user->name)
            && ! str_contains($raw, 'at no cost') && ! str_contains($raw, 'binding') && ! str_contains($raw, 'fact_version');
    });
});

test('a refusal, a cut-off or a reply without the tool call gives the related cards only', function (array $reply) {
    [$user, $case] = analyserCase();
    Http::fake(['api.anthropic.com/*' => Http::response($reply)]);

    analyserAsk($user, $case->person_id)->assertSuccessful()
        ->assertJsonPath('status', 'unavailable')->assertJsonPath('mode', 'cards_only')
        ->assertJsonPath('sentences', [])->assertJsonPath('cards.0.id', 'fixture.registration');
    Http::assertSentCount(1);
})->with([
    'refusal' => [analyserReply(['answerable' => true, 'related_card_ids' => [], 'sentences' => [analyserGood()]], 'refusal')],
    'max tokens' => [analyserReply(['answerable' => true, 'related_card_ids' => [], 'sentences' => [analyserGood()]], 'max_tokens')],
    'no tool call' => [['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Yes, register within 14 days.']]]],
    'prose beside the tool call' => [analyserReply(['answerable' => true, 'related_card_ids' => [], 'sentences' => [analyserGood()]],
        before: [['type' => 'text', 'text' => 'Sure, here is the answer.']])],
    'extra argument' => [analyserReply(['answerable' => true, 'related_card_ids' => [], 'sentences' => [analyserGood()], 'advice' => 'x'])],
]);

test('when no sentence survives the reply is the related cards, not a guess', function () {
    [$user, $case] = analyserCase();
    Http::fake(['api.anthropic.com/*' => Http::response(analyserReply(['answerable' => true, 'related_card_ids' => ['fixture.registration'],
        'sentences' => [['text' => 'Yes, within 14 days.', 'card_id' => 'fixture.registration', 'quote' => 'within 14 days']]]))]);

    analyserAsk($user, $case->person_id)->assertSuccessful()
        ->assertJsonPath('status', 'not_answerable')->assertJsonPath('sentences', [])
        ->assertJsonPath('cards.0.id', 'fixture.registration');
    expect(DB::table('llm_calls')->value('outcome'))->toBe('all_dropped:0/1');
});

test('without permission or with the purpose switched off nothing is sent', function (array $body, array $config, string $status) {
    [$user, $case] = analyserCase();
    config()->set('services.paperwork_analyser', [...config('services.paperwork_analyser'), ...$config]);
    Http::fake();

    analyserAsk($user, $case->person_id, $body)->assertSuccessful()->assertJsonPath('status', $status)
        ->assertJsonPath('mode', 'cards_only')->assertJsonPath('cards.0.id', 'fixture.registration');
    Http::assertNothingSent();
    expect(BureaucracyProcessingConsent::query()->count())->toBe(0);
})->with([
    'no consent' => [['processing' => null], [], 'consent_required'],
    'flag off' => [[], ['enabled' => false], 'unavailable'],
    'no key' => [[], ['key' => ''], 'unavailable'],
]);

test('another account cannot ask about someone else\'s case', function () {
    [, $case] = analyserCase();
    $stranger = User::factory()->onboarded()->create();
    Http::fake();

    analyserAsk($stranger, $case->person_id)->assertForbidden();
    Http::assertNothingSent();
});

test('a member who may only view the plan gets the cards without answers and nothing is sent', function () {
    [$owner, $case] = analyserCase();
    $helper = User::factory()->onboarded()->create();
    // Accepting an invitation grants the inviter access to the accepting person's plan.
    $invite = app(ManageDelegation::class)->invite($helper, app(EnsureAccountHolder::class)->dossier($helper)->person->workspace, $owner->email, ['view_plan']);
    app(ManageDelegation::class)->accept($owner, $invite['token'], ['view_plan']);
    Http::fake();

    $response = analyserAsk($helper, $case->person_id)->assertSuccessful()->assertJsonPath('status', 'permission_required');
    expect($response->json('cards.0.facts'))->toBe([])->and($response->json('cards.0.explanation'))->toBe('This card is part of this plan.')
        ->and(json_encode($response->json()))->not->toContain('September');
    Http::assertNothingSent();
});

test('a permission given for another purpose or another input sends nothing', function () {
    [$user, $case] = analyserCase();
    Http::fake(['api.anthropic.com/*' => Http::response(analyserReply(['answerable' => true, 'related_card_ids' => [], 'sentences' => [analyserGood()]]))]);

    // A request id already granted for Composer cannot carry a Paperwork question.
    $composer = ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist);
    app(ProcessingConsentStore::class)->grant($user, ProcessingPurpose::ComposerAssist, ['text' => 'museum', 'veedel' => null], $composer);
    analyserAsk($user, $case->person_id, ['processing' => [...ExternalProcessingFixtures::acceptance(ProcessingPurpose::PaperworkAnswer),
        'request_id' => $composer['request_id']]])->assertUnprocessable();
    // A permission for one question cannot be reused for a different one.
    $once = ExternalProcessingFixtures::acceptance(ProcessingPurpose::PaperworkAnswer);
    analyserAsk($user, $case->person_id, ['processing' => $once])->assertSuccessful();
    analyserAsk($user, $case->person_id, ['processing' => $once, 'question' => 'What does the Blue Card cost?'])->assertUnprocessable();
    // A Composer permission is not a Paperwork permission.
    analyserAsk($user, $case->person_id, ['processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)])->assertUnprocessable();
    Http::assertSentCount(1);

    // At the transport boundary: the granted input, and only it, may be sent.
    $context = ['question' => 'q', 'cards' => [], 'confirmed_answers' => [], 'binding' => ['case_id' => $case->id]];
    $permit = app(ProcessingConsentStore::class)->grant($user, ProcessingPurpose::PaperworkAnswer, $context,
        ExternalProcessingFixtures::acceptance(ProcessingPurpose::PaperworkAnswer), $case);
    $sent = false;
    $transport = function () use (&$sent): Response {
        $sent = true;

        return new Response(new PsrResponse(200, [], '{}'));
    };
    $gate = app(ExternalProcessingGate::class);
    expect($gate->send($permit, ProcessingPurpose::PaperworkAnswer, [...$context, 'question' => 'changed'], $transport))->toBeNull()
        ->and($gate->send($permit, ProcessingPurpose::ComposerAssist, $context, $transport))->toBeNull()
        ->and($sent)->toBeFalse();
});

test('a permission ends when the case answers change, and is withdrawn with the person', function () {
    [$user, $case] = analyserCase();
    $context = ['question' => 'q', 'cards' => [], 'confirmed_answers' => [], 'binding' => ['case_id' => $case->id]];
    $grant = fn () => app(ProcessingConsentStore::class)->grant($user, ProcessingPurpose::PaperworkAnswer, $context,
        ExternalProcessingFixtures::acceptance(ProcessingPurpose::PaperworkAnswer), $case->fresh());
    $sent = 0;
    $transport = function () use (&$sent): Response {
        $sent++;

        return new Response(new PsrResponse(200, [], '{}'));
    };
    $gate = app(ExternalProcessingGate::class);

    $stale = $grant();
    app(RecordFactChange::class)->execute($user, $case->person, 'german_level', 'b1', null, $case->fresh()->fact_version);
    expect($gate->send($stale, ProcessingPurpose::PaperworkAnswer, $context, $transport))->toBeNull();

    $withdrawn = $grant();
    test()->actingAs($user)->deleteJson('/bureaucracy/v2/people/'.$case->person_id.'/processing')->assertSuccessful();
    expect($gate->send($withdrawn, ProcessingPurpose::PaperworkAnswer, $context, $transport))->toBeNull()->and($sent)->toBe(0)
        ->and(BureaucracyProcessingConsent::query()->where('case_id', $case->id)->where('purpose', 'paperwork_answer')->count())->toBe(2);

    // A permission cannot be granted for a case the actor does not own.
    $stranger = User::factory()->onboarded()->create();
    expect(fn () => app(ProcessingConsentStore::class)->grant($stranger, ProcessingPurpose::PaperworkAnswer, $context,
        ExternalProcessingFixtures::acceptance(ProcessingPurpose::PaperworkAnswer), $case))->toThrow(AuthorizationException::class);
});

test('the disclosure names what this purpose sends', function () {
    [$user] = analyserCase();

    test()->actingAs($user)->getJson('/privacy/processing/paperwork_answer')->assertSuccessful()
        ->assertJsonPath('purpose', 'paperwork_answer')->assertJsonPath('available', true)
        ->assertJsonPath('payload_categories', ['your_question', 'checked_card_text', 'confirmed_answers_the_cards_use']);
    expect(Str::length(ProcessingPurpose::PaperworkAnswer->providerVersion()))->toBe(64);
});
