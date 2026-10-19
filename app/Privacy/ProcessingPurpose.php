<?php

namespace App\Privacy;

enum ProcessingPurpose: string
{
    case FactExtraction = 'extract_case_fact';
    case ComposerParse = 'composer_parse';
    case ComposerRank = 'composer_rank';

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
        $enabled = $this === self::ComposerParse ? config($prefix.'.driver') === 'openai' : config($prefix.'.enabled') === true;
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

        return $this->dailyLimit() > 0 && (int) config($prefix.'.timeout', 10) > 0;
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
                self::FactExtraction => ['current_question', 'permitted_answer_schema', 'your_text', 'selected_person_reference'],
                self::ComposerParse => ['your_text', 'home_area', 'reference_time'],
                self::ComposerRank => ['planning_constraints', 'preferences', 'candidate_activities'],
            },
        ];
    }

    private function configPrefix(): string
    {
        return match ($this) {
            self::FactExtraction => 'services.bureaucracy_llm',
            self::ComposerParse => 'services.llm',
            self::ComposerRank => 'services.composer_llm',
        };
    }

    public function dailyLimit(): int
    {
        return max(0, (int) ($this === self::FactExtraction
            ? config('services.bureaucracy_llm.daily_limit', 20)
            : config('bureaucracy_privacy.daily_limits.'.$this->value, 20)));
    }
}
