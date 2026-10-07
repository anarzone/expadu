<?php

namespace App\Bureaucracy\ReadModel;

use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\Questions\QuestionInteraction;
use App\Bureaucracy\Questions\QuestionProtocol;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyQuestionSession;
use App\Models\User;

final class QuestionPreview
{
    public function __construct(private QuestionProtocol $protocol, private QuestionInteraction $interactions) {}

    /** Loading a page never allocates an offer or consumes interview budget. */
    public function for(User $actor, int $caseId, AssessmentInput $input): array
    {
        $person = BureaucracyCase::query()->findOrFail($caseId)->person;
        $candidates = array_map(fn ($candidate) => $this->interactions->for($actor, $person, $candidate), $this->protocol->candidates($input));
        $session = BureaucracyQuestionSession::query()->where('actor_id', $actor->id)->where('case_id', $caseId)
            ->where('jurisdiction', $input->jurisdiction)->where('expires_at', '>', $input->at->utc())->latest('id')->first();
        $deferred = $session?->deferred ?? [];
        $remaining = array_values(array_filter($candidates, fn ($candidate) => ($deferred[$candidate['fact_key']] ?? null) !== $candidate['deferral_token']));
        $base = ['session_id' => $session?->id, 'remaining_information_count' => count($remaining), 'question' => null];
        $offer = $session?->questions()->whereNull('answered_at')->latest('id')->first();
        $next = $remaining[0] ?? null;
        if ($offer !== null && $offer->offer_expires_at?->greaterThan($input->at)) {
            $candidate = collect($remaining)->firstWhere('fact_key', $offer->fact_key);
            if ($candidate !== null && $candidate['dependency_token'] === $offer->dependency_token) {
                if ($offer->malformed_attempts >= config('bureaucracy_questions.malformed_attempts')) {
                    return [...$base, 'status' => 'answer_limit', 'can_resume' => true];
                }
                $next = [...$candidate, 'id' => $offer->id, 'token' => $offer->offer_token,
                    'expires_at' => $offer->offer_expires_at->toIso8601String(), 'can_skip' => true, 'can_answer_unknown' => $candidate['kind'] === 'answer'];
            }
        }

        if (! isset($next['id']) && ($session?->status === 'paused' || ($session?->consecutive_offers ?? 0) >= config('bureaucracy_questions.consecutive_offers'))) {
            return [...$base, 'status' => 'paused', 'can_resume' => true];
        }

        return [...$base, 'status' => $next === null ? 'no_more_questions' : (isset($next['id']) ? 'offered' : 'preview'), 'question' => $next];
    }
}
