<?php

namespace App\Privacy;

use App\Composer\Actions\ComposerActions;
use App\Composer\Assistant\AssistantTools;

enum ProcessingPurpose: string
{
    case FactExtraction = 'extract_case_fact';
    case ComposerRank = 'composer_rank';
    /** Composer's assistant: every message, answered from what it looks up (where you are, trips, what's on, places, plans). */
    case ComposerAssist = 'composer_assist';
    /** A question about one person's case, answered only from source-checked cards. Bound to that case. */
    case PaperworkAnswer = 'paperwork_answer';
    /**
     * Composer's assistant with the person's own Paperwork record, for one message (owner, 23 Oct
     * 2026: "make sure its accurate"): Composer's own call, so its own notice. Bound to their case;
     * withdrawing Composer's AI withdraws it too.
     */
    case ComposerPaperwork = 'composer_paperwork';

    /** What each kind of data a Composer request sends is, in the notice's words (AssistantTools::CATEGORIES). */
    public const WORDS = [
        'your_text' => 'your message, as you wrote it',
        'your_earlier_messages_in_this_conversation' => 'your earlier messages in this conversation, and Composer’s answers to them',
        'reference_time' => 'the current time',
        'home_area' => 'your home neighbourhood',
        'page_you_asked_on' => 'the page you asked from',
        'your_nearest_stop_and_veedel' => 'where you are, only as your nearest stop and neighbourhood',
        'your_saved_places_names' => 'the names you gave your saved places (Home and Work only as such, with their neighbourhood)',
        'events_listed' => 'event listings',
        'places_listed' => 'places from our catalogue, with what their listings and websites say and, for working there, the anonymous counts of people’s answers about them (never who answered)',
        'weather' => 'the weather',
        'departures' => 'departures',
        'trips_to_places_you_name' => 'routes to places you name',
        'transit_notices' => 'public transport notices',
        'paperwork_guide_titles' => 'the titles of our reviewed paperwork guides',
        'plans_composed' => 'the plans our planner puts together for you',
        'notes_that_your_own_cards_are_shown' => 'that Composer is showing you one of your own cards, never what is on it',
        'changes_you_ask_for' => 'the changes you ask Composer to make (a saved place or event, a reminder, your plans, Home or Work, a favourite stop, how you travel, your alerts), and whether they were made',
        'your_taste_if_personalised' => 'what you tend to like and skip, if you turned on personalisation',
        'your_plans_if_personalised' => 'your own plans and appointments for today and tomorrow, including those recorded in Paperwork, if you turned on personalisation',
        'your_paperwork_answers_and_tasks' => 'your Paperwork answers (such as your residence title and the dates it keeps) and your open Paperwork tasks, with their steps and dates',
        'paperwork_changes_you_ask_for' => 'the changes to your Paperwork you ask for, and what they change in your plan',
    ];

    /** What a Composer request never sends, in the notice's words. */
    public const NEVER = [
        'coordinates' => 'your coordinates',
        'addresses' => 'the addresses of your saved places',
        'documents' => 'your documents or evidence',
        'other_people' => 'anyone else’s Paperwork record',
    ];

    public function providerVersion(): string
    {
        $prefix = $this->configPrefix();

        return hash('sha256', json_encode([
            'purpose' => $this->value,
            'transport_version' => '2026-09-08.subject-bound.2',
            'endpoint' => $this === self::ComposerRank ? 'https://api.anthropic.com' : config($prefix.'.base_url'),
            'model' => config($prefix.'.model'),
            'prompt_version' => config($prefix.'.prompt_version'),
            'processor_name' => config($prefix.'.processor_name'),
            'privacy_url' => config($prefix.'.processor_privacy_url'),
        ], JSON_THROW_ON_ERROR));
    }

    public function available(): bool
    {
        $prefix = $this->configPrefix();
        $enabled = match ($this) {
            self::ComposerAssist, self::ComposerPaperwork => config($prefix.'.driver') === 'anthropic',
            default => config($prefix.'.enabled') === true,
        };
        if (! $enabled) {
            return false;
        }
        foreach (['key', 'model', 'processor_name', 'processor_privacy_url', 'prompt_version'] as $key) {
            if (! is_string(config($prefix.'.'.$key)) || trim(config($prefix.'.'.$key)) === '') {
                return false;
            }
        }
        $endpoint = $this === self::ComposerRank ? 'https://api.anthropic.com' : config($prefix.'.base_url');
        foreach ([$endpoint, config($prefix.'.processor_privacy_url')] as $url) {
            if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
                || parse_url($url, PHP_URL_SCHEME) !== 'https'
                || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
                return false;
            }
        }

        return (int) config($prefix.'.timeout', 10) > 0;
    }

    public function disclosure(): array
    {
        return [
            'purpose' => $this->value, 'scope' => 'single_request', 'available' => $this->available(),
            'notice_version' => config('bureaucracy_privacy.notice_version'), 'provider_version' => $this->providerVersion(),
            'processor_name' => config($this->configPrefix().'.processor_name'),
            'processor_privacy_url' => config($this->configPrefix().'.processor_privacy_url'),
            // Paperwork's own responses; Composer says what it keeps under `kept`.
            ...(in_array($this, [self::ComposerAssist, self::ComposerPaperwork], true) ? [] : ['response_retention_minutes' => self::requestMinutes()]),
            'payload_categories' => match ($this) {
                self::FactExtraction => ['current_question', 'permitted_answer_schema', 'your_text', 'selected_person_reference', 'reference_date'],
                // Everything Composer's request can carry, from its own map of what each part is
                // (AssistantTools::CATEGORIES): ComposerPaperworkNoticeTest checks a real request against it.
                self::ComposerAssist => AssistantTools::categories(false),
                self::ComposerPaperwork => AssistantTools::categories(true),
                self::ComposerRank => ['planning_constraints', 'preferences', 'candidate_activities'],
                self::PaperworkAnswer => ['your_question', 'checked_card_text', 'confirmed_answers_the_cards_use'],
            },
            ...(in_array($this, [self::ComposerAssist, self::ComposerPaperwork], true) ? [
                'model' => config($this->configPrefix().'.model'),
                'never_sent' => array_keys(self::NEVER),
                // What Composer keeps (coordinator, 23 Oct 2026): the conversation in their History until they
                // delete it, Undo for ComposerActions::HOURS, the permission for the request's minutes.
                'kept' => ['history' => 'until_you_delete_it', 'undo_hours' => ComposerActions::HOURS, 'permission_minutes' => self::requestMinutes()],
                'notice' => $this->notice(),
            ] : []),
        ];
    }

    /** How long one request's permission lasts (ProcessingConsentStore: its expiry). */
    public static function requestMinutes(): int
    {
        return min(15, max(1, (int) config('bureaucracy_privacy.request_minutes', 15)));
    }

    /**
     * Composer's notice, in words, from what its request carries: what is sent, to whom, what is
     * never sent, how long a response is kept, and how to withdraw.
     *
     * @return list<string>
     */
    public function notice(): array
    {
        $paperwork = $this === self::ComposerPaperwork;
        $minutes = self::requestMinutes();
        $hours = ComposerActions::HOURS;
        $model = str_contains((string) config($this->configPrefix().'.model'), 'haiku') ? 'Claude Haiku' : 'Claude';

        return [
            ($paperwork ? 'For this message, Composer reads and can change your Paperwork record. To answer, it sends to '
                : 'To answer your message, Composer sends to ').config($this->configPrefix().'.processor_name')." ({$model}), an AI service: "
                .implode('; ', array_map(fn (string $category): string => self::WORDS[$category], self::categoryOrder($paperwork))).'.',
            'It never sends '.self::either([...array_values(self::NEVER), ...($paperwork ? [] : ['your Paperwork record without its own permission'])]).'. Nothing is sent to an authority.',
            ...($paperwork ? ['A change to your Paperwork answers always waits for your Apply.'] : []),
            'The AI service doesn’t use it to train its models.',
            "Composer keeps this conversation, its answers and cards, in your History until you delete it; a change it makes can be undone for {$hours} hours."
                .($paperwork ? ' Deleting your Paperwork record removes Composer’s copies of it; stopping its processing ends this permission.' : ''),
            $paperwork ? "This permission is for this one message and lasts {$minutes} minutes. You can withdraw it in the notice under Composer’s box; withdrawing Composer’s AI withdraws it too."
                : "Sending is your permission for that one message, and it lasts {$minutes} minutes. You can withdraw it in the notice under Composer’s box.",
        ];
    }

    /** "a, b, or c" */
    private static function either(array $items): string
    {
        return count($items) < 2 ? implode('', $items) : implode(', ', array_slice($items, 0, -1)).', or '.end($items);
    }

    /** @return list<string> the categories in the order the notice says them */
    private static function categoryOrder(bool $paperwork): array
    {
        return array_values(array_intersect(array_keys(self::WORDS), AssistantTools::categories($paperwork)));
    }

    private function configPrefix(): string
    {
        return match ($this) {
            self::FactExtraction => 'services.bureaucracy_llm',
            self::ComposerAssist, self::ComposerPaperwork => 'services.llm',
            self::ComposerRank => 'services.composer_llm',
            self::PaperworkAnswer => 'services.paperwork_analyser',
        };
    }

    /**
     * The most requests of this kind one person may send in 24 hours, or null for no limit.
     * No limit is the owner's default (22 Oct 2026: "lift the sanctions"); each can be set
     * again with its env, and 0 or empty means none.
     */
    public function dailyLimit(): ?int
    {
        $limit = (int) match ($this) {
            self::FactExtraction => config('services.bureaucracy_llm.daily_limit'),
            self::PaperworkAnswer => config('services.paperwork_analyser.daily_limit'),
            default => config('bureaucracy_privacy.daily_limits.'.$this->value),
        };

        return $limit > 0 ? $limit : null;
    }
}
