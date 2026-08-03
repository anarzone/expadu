<?php

namespace App\Bureaucracy\Facts;

use DomainException;

final readonly class FactDefinition
{
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
    ) {}

    public function normalize(mixed $value): mixed
    {
        if ($this->type !== 'enum') {
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
