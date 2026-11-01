<?php

namespace App\Bureaucracy\Facts;

use DomainException;

final readonly class FactDefinition
{
    public const Sources = ['manual', 'onboarding', 'ai_extracted_user_confirmed', 'attributed_report', 'legacy_profile'];

    /**
     * @param  list<string>  $options
     * @param  array<string, string>  $legacyValues
     */
    public function __construct(
        public string $key,
        public string $type,
        public array $options,
        public string $question,
        public string $why,
        public string $sensitivity,
        public int $priority,
        public int $reconfirmAfterDays,
        public array $legacyValues = [],
        public ?string $dateSemantics = null,
        public string $subjectScope = 'person',
        public bool $allowsNotApplicable = false,
        public array $permissibleSources = self::Sources,
    ) {}

    public function normalize(mixed $value): mixed
    {
        if ($this->type !== 'enum') {
            $valid = match ($this->type) {
                'date' => ($this->dateSemantics === 'historical' ? CalendarDate::historical($value) : CalendarDate::parse($value)) !== null,
                'integer' => is_int($value) && $value >= 0,
                'boolean' => is_bool($value),
                default => false,
            };
            if (! $valid) {
                throw new DomainException("Value for fact [{$this->key}] does not match its registered type or date meaning.");
            }

            return $value;
        }

        if (is_string($value) && in_array($value, $this->options, true)) {
            return $value;
        }

        if (is_string($value) && array_key_exists($value, $this->legacyValues)) {
            return $this->legacyValues[$value];
        }

        throw new DomainException("Value for fact [{$this->key}] is not a registered option.");
    }
}
