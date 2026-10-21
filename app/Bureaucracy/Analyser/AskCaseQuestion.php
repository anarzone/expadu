<?php

namespace App\Bureaucracy\Analyser;

use App\Ai\LlmCalls;
use App\Bureaucracy\Assessment\AssessmentFacts;
use App\Bureaucracy\Assessment\AssessPerson;
use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyPerson;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;

/**
 * Answers a question about one person's case from source-checked cards only.
 *
 * Retrieval, the facts shown back and every check on the answer are code. Claude
 * only picks and restates card text, and only when the purpose is switched on, the
 * reader may request AI for this person and has given single-request permission.
 * Otherwise the reply is the related cards alone, so Paperwork still helps.
 *
 * The question and the answers are never logged; llm_calls gets metadata only.
 */
final class AskCaseQuestion
{
    public const Schema = 'bureaucracy.answer.1';

    private const RelatedShown = 4;

    public function __construct(private PrepareAssessmentInput $inputs, private PersonAccess $access, private CardCorpus $corpus,
        private CardRetrieval $retrieval, private CaseFactExplainer $explainer, private ProcessingConsentStore $consents,
        private AnthropicCardAnswerer $answerer, private AnswerVerifier $verifier, private LlmCalls $calls) {}

    /** @param array<string, mixed>|null $processing the single-request permission, as the fact extractor takes it */
    public function execute(User $actor, BureaucracyPerson $person, string $question, string $jurisdiction, ?array $processing): array
    {
        $input = $this->inputs->for($actor, $person, $jurisdiction);
        $scopes = $this->access->scopesFor($actor, $person);
        $mayReadFacts = in_array(AccessScope::ViewFacts->value, $scopes, true);
        $assessment = (new AssessPerson)->assess($input)->toArray();
        $cards = $this->corpus->for($input, $assessment, (new AssessmentFacts)->combine($input->facts, $input->relationships));
        $candidates = $this->retrieval->candidates($question, $cards);
        $byId = [];
        foreach ($candidates as $card) {
            $byId[$card->id] = $card;
        }
        $facts = $mayReadFacts ? $input->facts : null;

        if ($candidates === []) {
            return $this->reply($person, 'no_cards', [], [], $facts);
        }
        if (! ProcessingPurpose::PaperworkAnswer->available()) {
            return $this->reply($person, 'unavailable', [], $candidates, $facts);
        }
        if (! in_array(AccessScope::RequestAi->value, $scopes, true)) {
            return $this->reply($person, 'permission_required', [], $candidates, $facts);
        }
        if ($processing === null) {
            return $this->reply($person, 'consent_required', [], $candidates, $facts);
        }

        $case = BureaucracyCase::query()->where('person_id', $person->id)->where('status', 'active')->firstOrFail();
        // Only the confirmed answers the candidate cards' conditions read, and only for a reader who may see them.
        $keys = array_merge(...array_map(fn (CheckedCard $card) => $card->conditionKeys, $candidates));
        $confirmed = $facts === null ? [] : $this->explainer->forPrompt($this->explainer->confirmed($facts, $keys));
        $context = [
            'question' => $question,
            'cards' => array_map(fn (CheckedCard $card) => ['id' => $card->id, 'title' => $card->title,
                'applies_to_case' => match ($card->appliesToCase) {
                    true => 'yes', false => 'no', null => 'unknown',
                }, 'text' => $card->segments], $candidates),
            'confirmed_answers' => $confirmed,
            // Bound into the permission's digest, never sent to the processor.
            'binding' => ['case_id' => $case->id, 'fact_version' => $case->fact_version, 'jurisdiction' => $jurisdiction,
                'release' => $input->catalogue['release_hash'] ?? null, 'prompt_version' => config('services.paperwork_analyser.prompt_version')],
        ];
        $permit = $this->consents->grant($actor, ProcessingPurpose::PaperworkAnswer, $context, $processing, $case);
        $draft = $this->answerer->answer($permit, $context);

        if ($draft->status !== 'ok') {
            $this->record($draft, $draft->status);

            return $this->reply($person, $draft->status === 'limited' ? 'limited' : 'unavailable', [], $candidates, $facts);
        }
        $checked = $draft->answerable ? $this->verifier->verify($draft->sentences, $byId) : ['kept' => [], 'dropped' => []];
        $kept = $checked['kept'];
        $total = $draft->answerable ? count($draft->sentences) : 0;
        $this->record($draft, ! $draft->answerable ? 'unanswerable' : ($kept === [] ? 'all_dropped' : 'answered').':'.count($kept).'/'.$total);

        // Cited cards first, then the model's related picks, then retrieval order.
        $order = [...array_column($kept, 'card_id'), ...$draft->relatedCardIds, ...array_keys($byId)];
        $related = array_map(fn (string $id) => $byId[$id], array_values(array_unique($order)));

        return $this->reply($person, $kept === [] ? 'not_answerable' : 'answered', $kept, $related, $facts, count($checked['dropped']));
    }

    /**
     * @param  list<array{text: string, card_id: string, quote: string}>  $sentences
     * @param  list<CheckedCard>  $cards
     * @param  array<string, mixed>|null  $facts
     */
    private function reply(BureaucracyPerson $person, string $status, array $sentences, array $cards, ?array $facts, int $dropped = 0): array
    {
        $byId = [];
        foreach ($cards as $card) {
            $byId[$card->id] = $card;
        }
        $cited = array_values(array_unique(array_column($sentences, 'card_id')));
        $shown = array_slice(array_values(array_unique([...$cited, ...array_keys($byId)])), 0, max(self::RelatedShown, count($cited)));

        return [
            'schema_version' => self::Schema,
            'person_id' => $person->id,
            'status' => $status,
            'mode' => $sentences === [] ? 'cards_only' : 'answer',
            'sentences' => array_map(fn (array $sentence) => [
                'text' => $sentence['text'], 'card_id' => $sentence['card_id'], 'card_title' => $byId[$sentence['card_id']]->title,
                'quote' => $sentence['quote'], 'source' => $byId[$sentence['card_id']]->sourceFor($sentence['quote']),
            ], $sentences),
            'cards' => array_map(fn (string $id) => [
                'id' => $id, 'title' => $byId[$id]->title, 'definition_id' => $byId[$id]->definitionId, 'topic' => $byId[$id]->topic,
                'cited' => in_array($id, $cited, true), 'sources' => $byId[$id]->sources,
                ...$this->explainer->forCard($byId[$id], $facts),
            ], $shown),
            'checks' => ['sentences_dropped' => $dropped],
            'ai' => [
                'purpose' => ProcessingPurpose::PaperworkAnswer->value,
                'available' => ProcessingPurpose::PaperworkAnswer->available(),
                'consent_scope' => 'single_request',
                'notice_version' => config('bureaucracy_privacy.notice_version'),
                'provider_version' => ProcessingPurpose::PaperworkAnswer->providerVersion(),
            ],
        ];
    }

    private function record(AnswerDraft $draft, string $outcome): void
    {
        if (! $draft->dispatched) {
            return;
        }
        $this->calls->record('paperwork_analyser', (string) config('services.paperwork_analyser.model'), $draft->latencyMs, $outcome,
            $draft->inputTokens, $draft->outputTokens, (string) config('services.paperwork_analyser.prompt_version'));
    }
}
