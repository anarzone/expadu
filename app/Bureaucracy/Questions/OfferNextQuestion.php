<?php

namespace App\Bureaucracy\Questions;

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyQuestionRequest;
use App\Models\BureaucracyQuestionSession;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OfferNextQuestion
{
    public function __construct(private QuestionSessionScope $sessions, private PrepareAssessmentInput $inputs, private QuestionProtocol $protocol, private QuestionInteraction $interactions) {}

    public function execute(User $actor, BureaucracyQuestionSession $session, string $requestId): array
    {
        if (! Str::isUuid($requestId)) {
            throw ValidationException::withMessages(['request_id' => 'Use a request identifier for this question offer.']);
        }

        return $this->sessions->run($actor, $session, function ($session, $person, $case) use ($actor, $requestId): array {
            $input = $this->inputs->for($actor, $person, $session->jurisdiction);
            $candidates = array_column(array_map(fn ($candidate) => $this->interactions->for($actor, $person, $candidate), $this->protocol->candidates($input)), null, 'fact_key');
            $replay = BureaucracyQuestionRequest::query()->where('session_id', $session->id)->where('request_id', $requestId)->first();
            if ($replay !== null) {
                $question = $replay->question_id === null ? null : $session->questions()->findOrFail($replay->question_id);

                return $question === null
                    ? ['status' => $replay->response_status, 'question' => null, 'session_id' => $session->id]
                    : $this->response($session, $question, $candidates[$question->fact_key] ?? null);
            }
            $open = $session->questions()->whereNull('answered_at')->orderByDesc('id')->first();
            if ($open !== null) {
                $response = $this->response($session, $open, $candidates[$open->fact_key] ?? null);
                if (in_array($response['status'], ['offered', 'answer_limit'], true)) {
                    return $this->remember($session, $requestId, $response, $open->id);
                }
                $open->update(['answered_at' => now(), 'outcome' => 'stale']);
            }
            if ($session->consecutive_offers >= config('bureaucracy_questions.consecutive_offers')) {
                $session->update(['status' => 'paused']);

                return $this->remember($session, $requestId, ['status' => 'paused', 'question' => null, 'session_id' => $session->id]);
            }
            $deferred = $session->deferred ?? [];
            foreach ($deferred as $key => $token) {
                if (isset($candidates[$key]) && $candidates[$key]['deferral_token'] !== $token) {
                    unset($deferred[$key]);
                }
            }
            $session->update(['deferred' => $deferred, 'known_fact_revision' => $case->fact_version]);
            $next = null;
            foreach ($candidates as $key => $candidate) {
                if (! array_key_exists($key, $deferred)) {
                    $next = $candidate;
                    break;
                }
            }
            if ($next === null) {
                $session->update(['status' => 'completed']);

                return $this->remember($session, $requestId, ['status' => 'no_more_questions', 'question' => null, 'session_id' => $session->id]);
            }
            $offer = $session->questions()->create(['case_id' => $case->id, 'fact_key' => $next['fact_key'], 'asked_at' => now(),
                'request_id' => $requestId, 'protocol_version' => $next['protocol_version'], 'dependency_token' => $next['dependency_token'],
                'fact_revision' => $case->fact_version, 'offer_token' => Str::random(64), 'offer_expires_at' => now()->utc()->addHours(config('bureaucracy_questions.offer_hours'))]);
            $session->increment('consecutive_offers');
            $session->increment('offered_count');

            return $this->remember($session, $requestId, $this->response($session, $offer->fresh(), $next), $offer->id);
        });
    }

    private function response(BureaucracyQuestionSession $session, BureaucracyCaseQuestion $offer, ?array $candidate): array
    {
        if ($offer->answered_at !== null) {
            return ['status' => 'already_handled', 'question' => null, 'session_id' => $session->id];
        }
        if ($candidate === null || $offer->offer_expires_at->lessThanOrEqualTo(now()) || $offer->dependency_token !== $candidate['dependency_token']) {
            return ['status' => 'refresh_required', 'question' => null, 'session_id' => $session->id];
        }
        if ($offer->malformed_attempts >= config('bureaucracy_questions.malformed_attempts')) {
            return ['status' => 'answer_limit', 'question' => null, 'session_id' => $session->id, 'can_resume' => true];
        }

        return ['status' => 'offered', 'session_id' => $session->id,
            'question' => [...$candidate, 'id' => $offer->id, 'token' => $offer->offer_token, 'expires_at' => $offer->offer_expires_at->toIso8601String(),
                'can_skip' => true, 'can_answer_unknown' => $candidate['kind'] === 'answer', 'remaining_before_pause' => max(0, config('bureaucracy_questions.consecutive_offers') - $session->consecutive_offers)]];
    }

    private function remember(BureaucracyQuestionSession $session, string $requestId, array $response, ?int $questionId = null): array
    {
        BureaucracyQuestionRequest::query()->create(['session_id' => $session->id, 'request_id' => $requestId,
            'question_id' => $questionId, 'response_status' => $response['status']]);

        return $response;
    }
}
