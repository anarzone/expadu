<?php

namespace App\Composer\Assistant;

use App\Composer\Answers\AroundNow;
use App\Composer\WorkspaceStore;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPermit;
use App\Privacy\ProcessingPurpose;
use App\Profile\ProfileEngine;
use App\Services\UserLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One message, answered (POST composer/ask; owner, 22 Oct 2026: one assistant for every
 * message). The person's last message in their conversation is answered by the assistant,
 * under their say-so for this request; the answer goes into the conversation, and a plan it
 * showed into their plans. Without the AI (no say-so, the service off or failing), Composer
 * says so and shows what's on around them: it never guesses what the words meant.
 */
final class Ask
{
    /** Why a message was answered without the AI, in the words the answer starts with. */
    private const WITHOUT_AI = [
        'off' => 'Composer’s AI is off right now, so I can’t answer that.',
        'limited' => 'Composer’s AI has reached today’s limit, so I can’t answer that.',
        'unpermitted' => 'I answer with an AI service, and this message was sent without it, so I can’t answer that.',
        'failed' => 'The AI service didn’t answer this time, so I can’t answer that.',
        // It answered, but nothing it said held against what it found, and it had nothing to show.
        'unchecked' => 'I didn’t find anything I can vouch for there.',
    ];

    public function __construct(
        private Assistant $assistant,
        private WorkspaceStore $workspace,
        private AroundNow $around,
        private ProfileEngine $profiles,
        private UserLocationService $locations,
        private ProcessingConsentStore $consents,
    ) {}

    /**
     * Answers the person's last message in their active conversation.
     *
     * @param  array<string, mixed>|null  $processing  their say-so for this request (the AI notice under the box)
     * @param  string|null  $page  the page Composer was opened on
     * @param  Request  $request  where they start from (UserLocationService::context): the page's From, or their browser's position
     * @return array<string, mixed> the workspace, with the answer in it
     */
    public function answer(User $user, ?array $processing, ?string $page, Request $request): array
    {
        $workspace = $this->workspace->read($user);
        $conversation = (string) $workspace['active'];
        $messages = collect($workspace['conversations'])->firstWhere('id', $conversation)['messages'] ?? [];
        $asked = array_key_last(array_filter($messages, fn (mixed $m): bool => is_array($m) && ($m['role'] ?? null) === 'user'));
        if ($asked === null) {
            throw ValidationException::withMessages(['text' => 'Ask something first.']);
        }
        $text = (string) $messages[$asked]['text'];
        $now = CarbonImmutable::now('Europe/Berlin');
        $profile = $this->profiles->build($user);
        $permit = $processing !== null && ProcessingPurpose::ComposerAssist->available()
            ? $this->consents->grant($user, ProcessingPurpose::ComposerAssist, Assistant::processingContext($text, $profile, $page), $processing)
            : null;

        $answer = $permit === null ? null : $this->assistant->answer($user, $text, $profile, $now, $this->locations->context($user, $request, $user->veedel),
            $permit, $workspace['plans'] ?? [], self::progressKey($user, (string) ($processing['request_id'] ?? '')),
            Assistant::history(array_slice($messages, 0, $asked)), $page);
        if ($answer !== null) {
            return $this->workspace->answer($user, $conversation, self::message($answer['text'], $answer['blocks'], [
                'written' => 'ai',
                // What the AI was given (the fine print, in its words), and the line inviting the opt-in without it.
                'read' => $answer['looked'] ?: null,
                'looked_at' => self::lookedAt($answer['looked'], $now) ?: null,
                'invite' => $answer['invite'] ?: null,
                'taste' => $answer['taste'] ?: null,
            ]), $answer['token']);
        }

        $reason = $permit !== null && $this->assistant->written !== null ? 'unchecked' : $this->withoutAi($permit, $processing);
        $around = $this->around->answer($user, $workspace['plans'] ?? [], $now, self::WITHOUT_AI[$reason]);

        return $this->workspace->answer($user, $conversation, self::message($around['text'], $around['blocks'], [
            'written' => 'code', 'without_ai' => $reason, 'suggestions' => $around['suggestions'],
        ]), null);
    }

    /**
     * An answer as the conversation keeps it: the AI's checked words (`lead`), the cards in
     * its order (`blocks`, which the page draws by type; a card's own `text` is said when it
     * is `private` or the lead is empty), and, until the page draws them, the same cards in
     * the fields today's answer view reads, with `text` the words in one voice.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $meta
     * @return array{text: string, extra: array<string, mixed>}
     */
    public static function message(string $lead, array $blocks, array $meta = []): array
    {
        $first = fn (string $type): ?array => collect($blocks)->firstWhere('type', $type);
        $trip = $first('trip');
        $record = $first('checklist');
        $guidance = $first('guidance');
        // One voice: Composer's own words for a card only when the AI couldn't say it (the
        // person's own records, which it never reads) or said nothing itself.
        $own = collect($blocks)->filter(fn (array $b): bool => filled($b['text'] ?? null) && (($b['private'] ?? false) || $lead === ''))->pluck('text');
        $sources = collect($blocks)->flatMap(fn (array $b): array => $b['sources'] ?? [])->unique('href')->values()->all();
        $suggestions = collect([...collect($blocks)->flatMap(fn (array $b): array => $b['type'] === 'choices' ? $b['options'] : ($b['suggestions'] ?? []))->all(),
            ...($meta['suggestions'] ?? [])])->unique('label')->values()->all();

        return ['text' => trim(implode(' ', [$lead, ...$own->all()])), 'extra' => array_filter([
            'request' => 'ask',
            'kind' => $trip !== null ? 'trip' : ($record !== null ? 'checklist' : ($guidance !== null ? 'bureaucracy' : 'around')),
            'lead' => $lead,
            'blocks' => $blocks,
            'title' => $trip['title'] ?? $record['title'] ?? null,
            'trip' => $trip['trip'] ?? null,
            // The place a trip went to, and the start named, for a follow-up ("how far is it?").
            'destination' => $trip['destination'] ?? null,
            'start' => $trip['start'] ?? null,
            'record' => $record['record'] ?? null,
            // Reviewed guides matching these words (the page's Guidance).
            'query' => $guidance['query'] ?? null,
            'events' => $first('events')['events'] ?? null,
            'places' => $first('places')['places'] ?? null,
            'facts' => collect($blocks)->flatMap(fn (array $b): array => $b['facts'] ?? [])->values()->all() ?: null,
            'sources' => $sources ?: null,
            ...array_diff_key($meta, ['suggestions' => true]),
            'suggestions' => $suggestions ?: null,
        ], fn ($value) => $value !== null && $value !== '')];
    }

    /**
     * What the AI read to answer, as the fine print names it ("Written by AI from your nearest
     * stop and the trip planner"). The person's own cards aren't among them: it never reads those.
     *
     * @param  list<string>  $looked  AssistantTools::looked()
     * @return list<string>
     */
    public static function lookedAt(array $looked, CarbonImmutable $now): array
    {
        $words = [
            'listings' => $now->setTimezone('Europe/Berlin')->format('H') >= 17 ? 'tonight’s listings' : 'today’s listings',
            'places' => 'places', 'weather' => 'the weather', 'location' => 'your nearest stop and Veedel', 'departures' => 'departures',
            'trip' => 'the trip planner', 'planner' => 'the day planner', 'notices' => 'KVB notices', 'guidance' => 'reviewed paperwork guides',
            'my_places' => 'your saved places’ names', 'taste' => 'what you like', 'plans' => 'your plans',
        ];

        return array_values(array_unique(array_filter(array_map(fn (string $key): ?string => $words[$key] ?? null, $looked))));
    }

    /** Why the AI didn't answer: it's off, it wasn't asked to, the day's limit, or it failed. */
    private function withoutAi(?ProcessingPermit $permit, ?array $processing): string
    {
        if (! ProcessingPurpose::ComposerAssist->available()) {
            return 'off';
        }
        if ($permit === null || $processing === null) {
            return 'unpermitted';
        }

        return BureaucracyProcessingConsent::query()->whereKey($permit->id)->value('state') === 'limited' ? 'limited' : 'failed';
    }

    /** Where a request's progress is kept for the page (its permit's request id); null without one. */
    public static function progressKey(User $user, string $requestId): ?string
    {
        return Str::isUuid($requestId) ? "composer:progress:{$user->id}:".strtolower($requestId) : null;
    }
}
