<?php

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Questions\AnswerQuestion;
use App\Bureaucracy\Questions\DeferQuestion;
use App\Bureaucracy\Questions\OfferNextQuestion;
use App\Bureaucracy\Questions\QuestionProtocol;
use App\Bureaucracy\Questions\QuestionSessions;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyQuestionSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function sessionFixture(): array
{
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $task = Task::factory()->approvedFixture()->create([
        'key' => 'fixture.questions', 'type' => 'task', 'deadline_type' => 'none', 'depends_on' => [],
        'applies_if' => [['citizenship_group' => 'non_eu', 'german_level' => 'b1', 'livelihood_secured' => 'yes', 'housing_sufficient' => 'yes']],
        'documents_required' => [], 'how_to_steps' => [], 'links' => [],
    ])->fresh();
    $artifact = app(CatalogueCompiler::class)->compile([$task], [
        $task->key => ['process_id' => 'fixture.questions', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial'],
    ]);
    $release = app(CatalogueReleaseStore::class)->stage($artifact);
    app(CatalogueReleaseStore::class)->activate($release->id, null);

    return [$actor, $case, $task];
}

test('reading suggested questions never creates an offer or consumes interview budget', function () {
    [$actor, $case] = sessionFixture();
    $protocol = app(QuestionProtocol::class);
    for ($i = 0; $i < 5; $i++) {
        $input = app(PrepareAssessmentInput::class)->for($actor, $case->person, 'de-nrw-cologne');
        expect($protocol->candidates($input))->not->toBeEmpty();
    }
    expect(BureaucracyCaseQuestion::query()->count())->toBe(0)->and(BureaucracyQuestionSession::query()->count())->toBe(0);
});

test('next-question retries are idempotent and answers use the canonical dossier', function () {
    [$actor, $case] = sessionFixture();
    $sessions = app(QuestionSessions::class);
    $startKey = (string) Str::uuid();
    $session = $sessions->start($actor, $case->person, 'de-nrw-cologne', $startKey);
    expect($sessions->start($actor, $case->person, 'de-nrw-cologne', $startKey)->id)->toBe($session->id);
    $request = (string) Str::uuid();
    $offer = app(OfferNextQuestion::class)->execute($actor, $session, $request);
    expect($offer['question']['fact_key'])->toBe('citizenship_group');
    expect(app(OfferNextQuestion::class)->execute($actor, $session, $request)['question']['id'])->toBe($offer['question']['id']);
    $answer = app(AnswerQuestion::class)->execute($actor, $session, $offer['question']['id'], $offer['question']['token'], 'non_eu');
    expect($answer['fact_revision'])->toBe(2)->and($case->facts()->where('key', 'citizenship_group')->sole()->value)->toBe('non_eu')
        ->and($session->fresh()->offered_count)->toBe(1);
    $again = app(AnswerQuestion::class)->execute($actor, $session, $offer['question']['id'], $offer['question']['token'], 'non_eu');
    expect($again['fact_id'])->toBe($answer['fact_id'])->and($case->fresh()->fact_version)->toBe(2);
});

test('three offers pause the session but do not impose a lifetime question limit', function () {
    [$actor, $case] = sessionFixture();
    $sessions = app(QuestionSessions::class);
    $session = $sessions->start($actor, $case->person, 'de-nrw-cologne', (string) Str::uuid());
    foreach (range(1, 3) as $number) {
        $offer = app(OfferNextQuestion::class)->execute($actor, $session, (string) Str::uuid());
        app(DeferQuestion::class)->execute($actor, $session, $offer['question']['id'], $offer['question']['token']);
    }
    expect(app(OfferNextQuestion::class)->execute($actor, $session, (string) Str::uuid())['status'])->toBe('paused')
        ->and($case->facts()->count())->toBe(0);
    $sessions->resume($actor, $session, false);
    expect(app(OfferNextQuestion::class)->execute($actor, $session, (string) Str::uuid())['status'])->toBe('offered');
});

test('a stale answer or another persons session cannot overwrite newer facts', function () {
    [$actor, $case] = sessionFixture();
    $session = app(QuestionSessions::class)->start($actor, $case->person, 'de-nrw-cologne', (string) Str::uuid());
    $offer = app(OfferNextQuestion::class)->execute($actor, $session, (string) Str::uuid());
    app(RecordFactChange::class)->execute($actor, $case->person, 'citizenship_group', 'eu', null, 1);
    expect(fn () => app(AnswerQuestion::class)->execute($actor, $session, $offer['question']['id'], $offer['question']['token'], 'non_eu'))->toThrow(ConflictHttpException::class);
    $stranger = User::factory()->onboarded()->create();
    expect(fn () => app(OfferNextQuestion::class)->execute($stranger, $session, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect($case->facts()->where('state', 'confirmed')->sole()->value)->toBe('eu');
});

test('unknown is recorded explicitly and skipped questions do not come back immediately', function () {
    [$actor, $case] = sessionFixture();
    $session = app(QuestionSessions::class)->start($actor, $case->person, 'de-nrw-cologne', (string) Str::uuid());
    $offer = app(OfferNextQuestion::class)->execute($actor, $session, (string) Str::uuid());
    app(AnswerQuestion::class)->execute($actor, $session, $offer['question']['id'], $offer['question']['token'], null, 'unknown');
    expect($case->facts()->where('key', 'citizenship_group')->sole()->answer_state)->toBe('unknown');
    $next = app(OfferNextQuestion::class)->execute($actor, $session, (string) Str::uuid());
    expect($next['question']['fact_key'])->not->toBe('citizenship_group');
});

test('invalid answers are bounded per offer and never become confirmed facts', function () {
    [$actor, $case] = sessionFixture();
    $session = app(QuestionSessions::class)->start($actor, $case->person, 'de-nrw-cologne', (string) Str::uuid());
    $offer = app(OfferNextQuestion::class)->execute($actor, $session, (string) Str::uuid());
    foreach (range(1, 3) as $number) {
        expect(fn () => app(AnswerQuestion::class)->execute($actor, $session, $offer['question']['id'], $offer['question']['token'], 'invented'))
            ->toThrow(ValidationException::class);
    }
    expect($case->facts()->count())->toBe(0)->and(BureaucracyCaseQuestion::query()->findOrFail($offer['question']['id'])->malformed_attempts)->toBe(3);
});

test('a withdrawn rule stops offering its follow-up questions', function () {
    [$actor, $case, $task] = sessionFixture();
    app(RecordFactChange::class)->execute($actor, $case->person, 'citizenship_group', 'non_eu', null, 1);
    $input = app(PrepareAssessmentInput::class)->for($actor, $case->person, 'de-nrw-cologne');
    expect(array_column(app(QuestionProtocol::class)->candidates($input), 'fact_key'))->toContain('german_level');
    $task->update(['is_published' => false]);
    $input = app(PrepareAssessmentInput::class)->for($actor, $case->person, 'de-nrw-cologne');
    expect(array_column(app(QuestionProtocol::class)->candidates($input), 'fact_key'))->not->toContain('german_level');
});

test('every next request is idempotent even when it reused another request offer', function () {
    [$actor, $case] = sessionFixture();
    $session = app(QuestionSessions::class)->start($actor, $case->person, 'de-nrw-cologne', (string) Str::uuid());
    $next = app(OfferNextQuestion::class);
    $one = $next->execute($actor, $session, (string) Str::uuid());
    $requestB = (string) Str::uuid();
    expect($next->execute($actor, $session, $requestB)['question']['id'])->toBe($one['question']['id']);
    app(DeferQuestion::class)->execute($actor, $session, $one['question']['id'], $one['question']['token']);
    expect($next->execute($actor, $session, $requestB)['status'])->toBe('already_handled')
        ->and($session->fresh()->offered_count)->toBe(1);
});

test('stale defer returns a conflict without changing the offer or pacing', function () {
    [$actor, $case] = sessionFixture();
    $session = app(QuestionSessions::class)->start($actor, $case->person, 'de-nrw-cologne', (string) Str::uuid());
    $offer = app(OfferNextQuestion::class)->execute($actor, $session, (string) Str::uuid())['question'];
    app(RecordFactChange::class)->execute($actor, $case->person, 'german_level', 'b1', null, 1);
    expect(fn () => app(DeferQuestion::class)->execute($actor, $session, $offer['id'], $offer['token']))->toThrow(ConflictHttpException::class);
    expect($session->fresh()->deferred_count)->toBe(0)->and(BureaucracyCaseQuestion::query()->findOrFail($offer['id'])->answered_at)->toBeNull();
});

test('a changed guidance dependency can reopen a deferred question without a subject fact edit', function () {
    [$actor, $case, $task] = sessionFixture();
    $session = app(QuestionSessions::class)->start($actor, $case->person, 'de-nrw-cologne', (string) Str::uuid());
    $offer = app(OfferNextQuestion::class)->execute($actor, $session, (string) Str::uuid())['question'];
    app(DeferQuestion::class)->execute($actor, $session, $offer['id'], $offer['token']);
    // The old reviewed process is withdrawn; citizenship remains useful orientation.
    $task->update(['is_published' => false]);
    $keys = [];
    foreach (range(1, 2) as $step) {
        $next = app(OfferNextQuestion::class)->execute($actor, $session, (string) Str::uuid());
        if ($next['question'] !== null) {
            $keys[] = $next['question']['fact_key'];
            app(DeferQuestion::class)->execute($actor, $session, $next['question']['id'], $next['question']['token']);
        }
    }
    expect($keys)->toContain('citizenship_group')->and($case->fresh()->fact_version)->toBe(1);
});

test('exhausted answer attempts are visible and explicit resume issues a usable offer', function () {
    [$actor, $case] = sessionFixture();
    $session = app(QuestionSessions::class)->start($actor, $case->person, 'de-nrw-cologne', (string) Str::uuid());
    $next = app(OfferNextQuestion::class);
    $offer = $next->execute($actor, $session, (string) Str::uuid())['question'];
    foreach (range(1, 3) as $number) {
        expect(fn () => app(AnswerQuestion::class)->execute($actor, $session, $offer['id'], $offer['token'], 'invalid'))->toThrow(ValidationException::class);
    }
    expect($next->execute($actor, $session, (string) Str::uuid())['status'])->toBe('answer_limit');
    app(QuestionSessions::class)->resume($actor, $session, false);
    $usable = $next->execute($actor, $session, (string) Str::uuid())['question'];
    expect($usable['id'])->not->toBe($offer['id']);
    expect(app(AnswerQuestion::class)->execute($actor, $session, $usable['id'], $usable['token'], 'non_eu')['status'])->toBe('recorded');
});
