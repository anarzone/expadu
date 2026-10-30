<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use App\Privacy\ExternalProcessingGate;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExternalProcessingFixtures;

/*
 * A request about someone else's Paperwork record (Composer's Paperwork permission) only for an
 * actor they shared it with to read and to ask AI about, bound to that one record, and withheld
 * when the sharing ends while it's on its way.
 */
beforeEach(function () {
    config()->set('services.llm', ['driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'synthetic-parser', 'key' => 'synthetic-key',
        'processor_name' => 'Synthetic processor', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'synthetic.1', 'timeout' => 8]);
    $this->actor = User::factory()->create();
    $this->subject = User::factory()->create();
    $one = app(EnsureAccountHolder::class)->dossier($this->actor);
    $this->case = app(EnsureAccountHolder::class)->dossier($this->subject);
    $share = app(ManageDelegation::class)->invite($this->actor, $one->person->workspace, $this->subject->email, ['view_plan', 'edit_facts', 'request_ai']);
    app(ManageDelegation::class)->accept($this->subject, $share['token'], ['view_plan', 'edit_facts', 'request_ai']);
    $this->context = ['text' => 'Synthetic question about the shared record', 'person_id' => $this->case->person_id];
    $this->acceptance = ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerPaperwork);
});

test('🔒 an explicitly delegated actor can process only the record shared with them', function () {
    $permit = app(ProcessingConsentStore::class)->grant($this->actor, ProcessingPurpose::ComposerPaperwork, $this->context, $this->acceptance, $this->case);
    Http::fake(['processor.example.test/*' => Http::response(['synthetic' => 'response'])]);
    $result = app(ExternalProcessingGate::class)->send($permit, ProcessingPurpose::ComposerPaperwork, $this->context,
        fn () => Http::post('https://processor.example.test/messages'));
    expect($result)->toBe(['synthetic' => 'response'])->and($this->case->facts()->count())->toBe(0);
    Http::assertSentCount(1);

    // Someone else's record, never shared with them.
    $other = app(EnsureAccountHolder::class)->dossier(User::factory()->create());
    expect(fn () => app(ProcessingConsentStore::class)->grant($this->actor, ProcessingPurpose::ComposerPaperwork, $this->context,
        ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerPaperwork), $other))->toThrow(AuthorizationException::class);
});

test('🔒 sharing without asking AI cannot authorise AI processing', function () {
    BureaucracyAccessGrant::query()->where('person_id', $this->case->person_id)->update(['scopes' => ['view_plan', 'edit_facts']]);

    expect(fn () => app(ProcessingConsentStore::class)->grant($this->actor, ProcessingPurpose::ComposerPaperwork, $this->context, $this->acceptance, $this->case))
        ->toThrow(AuthorizationException::class);
    expect(BureaucracyProcessingConsent::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('🔒 revoking delegation during transport withholds the family response', function () {
    $permit = app(ProcessingConsentStore::class)->grant($this->actor, ProcessingPurpose::ComposerPaperwork, $this->context, $this->acceptance, $this->case);
    Http::fake(['processor.example.test/*' => function () {
        $grant = BureaucracyAccessGrant::query()->where('person_id', $this->case->person_id)->sole();
        app(ManageDelegation::class)->revoke($this->subject, $grant);

        return Http::response(['synthetic' => 'late private response']);
    }]);
    $result = app(ExternalProcessingGate::class)->send($permit, ProcessingPurpose::ComposerPaperwork, $this->context,
        fn () => Http::post('https://processor.example.test/messages'));
    expect($result)->toBeNull()->and(BureaucracyProcessingConsent::query()->sole()->result)->toBeNull();
    Http::assertSentCount(1);
});
