<?php

namespace App\Privacy;

enum ProcessingPurpose: string
{
    case FactExtraction = 'extract_case_fact';
    case ComposerParse = 'composer_parse';
    case ComposerRank = 'composer_rank';
    /** Composer's assistant: an open request, answered from what it looks up (events, places, weather). */
    case ComposerAssist = 'composer_assist';
    /** A question about one person's case, answered only from source-checked cards. Bound to that case. */
    case PaperworkAnswer = 'paperwork_answer';

    public function providerVersion(): string
    {
        $prefix = $this->configPrefix();

        return hash('sha256', json_encode([
            'purpose' => $this->value,
            'transport_version' => '2026-09-08.subject-bound.2',
            'endpoint' => $this === self::ComposerRank ? 'https://api.anthropic.com' : config($prefix.'.base_url'),
            'model' => config($prefix.'.model'),
            'prompt_version' => config($prefix.($this === self::ComposerAssist ? '.assistant_prompt_version' : '.prompt_version')),
            'processor_name' => config($prefix.'.processor_name'),
            'privacy_url' => config($prefix.'.processor_privacy_url'),
        ], JSON_THROW_ON_ERROR));
    }

    public function available(): bool
    {
        $prefix = $this->configPrefix();
        $enabled = match ($this) {
            self::ComposerParse => config($prefix.'.driver') === 'anthropic',
            self::ComposerAssist => config($prefix.'.driver') === 'anthropic' && config($prefix.'.assistant') === true,
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
            'response_retention_minutes' => min(15, max(1, (int) config('bureaucracy_privacy.request_minutes', 15))),
            'payload_categories' => match ($this) {
                self::FactExtraction => ['current_question', 'permitted_answer_schema', 'your_text', 'selected_person_reference', 'reference_date'],
                self::ComposerParse => ['your_text', 'home_area', 'reference_time', 'events_listed_today', 'weather_now'],
                // With the opt-in to personalisation only: their taste and their day.
                self::ComposerAssist => ['your_text', 'reference_time', 'events_listed', 'places_listed', 'weather', 'your_taste_if_personalised', 'your_plans_if_personalised'],
                self::ComposerRank => ['planning_constraints', 'preferences', 'candidate_activities'],
                self::PaperworkAnswer => ['your_question', 'checked_card_text', 'confirmed_answers_the_cards_use'],
            },
        ];
    }

    private function configPrefix(): string
    {
        return match ($this) {
            self::FactExtraction => 'services.bureaucracy_llm',
            self::ComposerParse, self::ComposerAssist => 'services.llm',
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
