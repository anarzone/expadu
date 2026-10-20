<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\Questions\OfferNextQuestion;
use App\Bureaucracy\Questions\QuestionSessions;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use App\Privacy\ExternalProcessingGate;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ExternalProcessingFixtures;

beforeEach(function () {
    ExternalProcessingFixtures::configure();
    $this->actor = User::factory()->create();
    $this->subject = User::factory()->create();
    $one = app(EnsureAccountHolder::class)->dossier($this->actor);
    $this->case = app(EnsureAccountHolder::class)->dossier($this->subject);
    $share = app(ManageDelegation::class)->invite($this->actor, $one->person->workspace, $this->subject->email, ['view_plan', 'edit_facts', 'request_ai']);
    app(ManageDelegation::class)->accept($this->subject, $share['token'], ['view_plan', 'edit_facts', 'request_ai']);
    $this->session = app(QuestionSessions::class)->start($this->actor, $this->case->person, 'de-nrw-cologne', (string) Str::uuid());
    $offered = app(OfferNextQuestion::class)->execute($this->actor, $this->session, (string) Str::uuid())['question'];
    $this->question = BureaucracyCaseQuestion::query()->findOrFail($offered['id']);
    $this->context = ['fact_key' => $this->question->fact_key, 'question' => $offered['question'], 'why' => $offered['why'], 'message' => 'Synthetic selected-person answer',
        'selection' => ['person_id' => $this->case->person_id, 'actor_id' => $this->actor->id, 'session_id' => $this->session->id,
            'question_id' => $this->question->id, 'dependency_token' => $this->question->dependency_token,
            'authority_token' => app(PersonAccess::class)->authorityToken($this->actor, $this->case->person)]];
    $this->acceptance = ExternalProcessingFixtures::acceptance(ProcessingPurpose::FactExtraction);
});

test('an explicitly delegated actor can process only the selected person and offered question', function () {
    $permit = app(ProcessingConsentStore::class)->grant($this->actor, ProcessingPurpose::FactExtraction, $this->context, $this->acceptance, $this->case, $this->question);
    Http::fake(['processor.example.test/*' => Http::response(['synthetic' => 'response'])]);
    $result = app(ExternalProcessingGate::class)->send($permit, ProcessingPurpose::FactExtraction, $this->context,
        fn () => Http::post('https://processor.example.test/extract'));
    expect($result)->toBe(['synthetic' => 'response'])->and($this->case->facts()->count())->toBe(0);
    Http::assertSentCount(1);
});

test('editing permission alone and a forged selected person cannot authorise AI processing', function () {
    $forged = [...$this->context, 'selection' => [...$this->context['selection'], 'person_id' => $this->case->person_id + 1000]];
    expect(fn () => app(ProcessingConsentStore::class)->grant($this->actor, ProcessingPurpose::FactExtraction, $forged, $this->acceptance, $this->case, $this->question))
        ->toThrow(AuthorizationException::class);
    BureaucracyAccessGrant::query()->where('person_id', $this->case->person_id)->update(['scopes' => ['view_plan', 'edit_facts']]);
    expect(fn () => app(ProcessingConsentStore::class)->grant($this->actor, ProcessingPurpose::FactExtraction, $this->context, $this->acceptance, $this->case, $this->question))
        ->toThrow(AuthorizationException::class);
    expect(BureaucracyProcessingConsent::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('revoking delegation during transport withholds the family response', function () {
    $permit = app(ProcessingConsentStore::class)->grant($this->actor, ProcessingPurpose::FactExtraction, $this->context, $this->acceptance, $this->case, $this->question);
    Http::fake(['processor.example.test/*' => function () {
        $grant = BureaucracyAccessGrant::query()->where('person_id', $this->case->person_id)->sole();
        app(ManageDelegation::class)->revoke($this->subject, $grant);

        return Http::response(['synthetic' => 'late private response']);
    }]);
    $result = app(ExternalProcessingGate::class)->send($permit, ProcessingPurpose::FactExtraction, $this->context,
        fn () => Http::post('https://processor.example.test/extract'));
    expect($result)->toBeNull()->and(BureaucracyProcessingConsent::query()->sole()->result)->toBeNull();
    Http::assertSentCount(1);
});
