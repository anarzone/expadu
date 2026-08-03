<?php

namespace App\Bureaucracy\Facts;

use DomainException;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class FactRegistry
{
    /** @var list<string> */
    private const SUPPORTED_TYPES = ['enum', 'date', 'integer', 'boolean'];

    /** @var list<string> */
    private const SUPPORTED_SENSITIVITIES = ['normal', 'high'];

    /** @var Collection<string, FactDefinition> */
    private Collection $definitions;

    public function __construct(?string $path = null)
    {
        $cataloguePath = $path ?? dirname(__DIR__, 3).'/database/seeders/data/bureaucracy/schema/facts.yaml';

        $this->definitions = $this->load($cataloguePath);
    }

    /**
     * @return Collection<string, FactDefinition>
     */
    public function all(): Collection
    {
        return new Collection($this->definitions->all());
    }

    public function definition(string $key): FactDefinition
    {
        $definition = $this->definitions->get($key);

        if (! $definition instanceof FactDefinition) {
            throw new DomainException("Fact [{$key}] is not registered.");
        }

        return $definition;
    }

    /**
     * Validate one authored rule condition against the canonical fact type.
     * Legacy list membership remains supported alongside explicit operators.
     */
    public function validateConditionOperand(string $key, mixed $condition): void
    {
        $definition = $this->definition($key);

        if ($condition === null) {
            throw new DomainException("Condition for fact [{$key}] cannot be null.");
        }

        if (! is_array($condition)) {
            $this->validateConditionValue($definition, $condition);

            return;
        }

        if (array_is_list($condition)) {
            if ($condition === []) {
                throw new DomainException("Membership condition for fact [{$key}] cannot be empty.");
            }

            foreach ($condition as $value) {
                $this->validateConditionValue($definition, $value);
            }

            return;
        }

        if (count($condition) !== 1) {
            throw new DomainException("Operator condition for fact [{$key}] must contain exactly one operator.");
        }

        $operator = array_key_first($condition);
        $operand = $condition[$operator];

        if (! is_string($operator) || ! in_array($operator, ['gte', 'lte', 'in', 'present'], true)) {
            throw new DomainException("Condition for fact [{$key}] uses an unsupported operator.");
        }

        if ($operator === 'present') {
            if (! is_bool($operand)) {
                throw new DomainException("The present operator for fact [{$key}] requires a boolean.");
            }

            return;
        }

        if ($operator === 'in') {
            if (! is_array($operand) || ! array_is_list($operand) || $operand === []) {
                throw new DomainException("The in operator for fact [{$key}] requires a non-empty list.");
            }

            foreach ($operand as $value) {
                $this->validateConditionValue($definition, $value);
            }

            return;
        }

        if ($definition->type !== 'integer' || ! is_int($operand)) {
            throw new DomainException("The {$operator} operator for fact [{$key}] requires an integer fact and operand.");
        }
    }

    /**
     * Require a registered fact whose value can safely anchor a date.
     */
    public function validateDeadlineFact(string $key): void
    {
        if ($this->definition($key)->type !== 'date') {
            throw new DomainException("Deadline fact [{$key}] must be a registered date fact.");
        }
    }

    /**
     * @return Collection<string, FactDefinition>
     */
    private function load(string $path): Collection
    {
        try {
            $facts = Yaml::parseFile($path);
        } catch (Throwable $exception) {
            throw new DomainException("Unable to load fact catalogue [{$path}].", previous: $exception);
        }

        if (! is_array($facts) || $facts === [] || array_is_list($facts)) {
            throw new DomainException("Fact catalogue [{$path}] must contain a keyed fact mapping.");
        }

        $definitions = [];

        foreach ($facts as $key => $attributes) {
            if (! is_string($key) || trim($key) === '') {
                throw new DomainException("Fact catalogue [{$path}] contains an invalid key.");
            }

            if (! is_array($attributes) || array_is_list($attributes)) {
                throw new DomainException("Definition for fact [{$key}] must be a keyed mapping.");
            }

            $definitions[$key] = $this->makeDefinition($key, $attributes);
        }

        return new Collection($definitions);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeDefinition(string $key, array $attributes): FactDefinition
    {
        $type = $this->requiredString($key, $attributes, 'type');

        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new DomainException("Fact [{$key}] has unsupported type [{$type}].");
        }

        $options = $this->options($key, $type, $attributes);
        $legacyValues = $this->legacyValues($key, $type, $options, $attributes);

        if (array_key_exists('default', $attributes)) {
            $this->validateDefault($key, $type, $options, $attributes['default']);
        }

        $sensitivity = $this->requiredString($key, $attributes, 'sensitivity');

        if (! in_array($sensitivity, self::SUPPORTED_SENSITIVITIES, true)) {
            throw new DomainException("Fact [{$key}] has unsupported sensitivity [{$sensitivity}].");
        }

        return new FactDefinition(
            key: $key,
            type: $type,
            options: $options,
            question: $this->requiredString($key, $attributes, 'question'),
            why: $this->requiredString($key, $attributes, 'why'),
            sensitivity: $sensitivity,
            priority: $this->requiredInteger($key, $attributes, 'priority', 0, 100),
            reconfirmAfterDays: $this->requiredInteger($key, $attributes, 'reconfirm_after_days', 1),
            legacyValues: $legacyValues,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function options(string $key, string $type, array $attributes): array
    {
        $options = $attributes['options'] ?? [];

        if ($type !== 'enum') {
            if ($options !== []) {
                throw new DomainException("Only enum fact [{$key}] may define options.");
            }

            return [];
        }

        if (! is_array($options) || ! array_is_list($options) || $options === []) {
            throw new DomainException("Enum fact [{$key}] must define a non-empty option list.");
        }

        foreach ($options as $option) {
            if (! is_string($option) || trim($option) === '') {
                throw new DomainException("Enum fact [{$key}] contains an invalid option.");
            }
        }

        if (count($options) !== count(array_unique($options, SORT_STRING))) {
            throw new DomainException("Enum fact [{$key}] contains duplicate options.");
        }

        return $options;
    }

    /**
     * @param  list<string>  $options
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    private function legacyValues(string $key, string $type, array $options, array $attributes): array
    {
        $legacyValues = $attributes['legacy_values'] ?? [];

        if (! is_array($legacyValues)) {
            throw new DomainException("Legacy values for fact [{$key}] must be a mapping.");
        }

        if ($type !== 'enum' && $legacyValues !== []) {
            throw new DomainException("Only enum fact [{$key}] may define legacy values.");
        }

        foreach ($legacyValues as $legacyValue => $canonicalValue) {
            if (! is_string($legacyValue) || trim($legacyValue) === '' || ! is_string($canonicalValue)) {
                throw new DomainException("Fact [{$key}] contains an invalid legacy value mapping.");
            }

            if (! in_array($canonicalValue, $options, true)) {
                throw new DomainException("Legacy value [{$legacyValue}] for fact [{$key}] maps to an unregistered option.");
            }
        }

        return $legacyValues;
    }

    /**
     * @param  list<string>  $options
     */
    private function validateDefault(string $key, string $type, array $options, mixed $default): void
    {
        $isValid = match ($type) {
            'enum' => is_string($default) && in_array($default, $options, true),
            'date' => is_string($default) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $default) === 1,
            'integer' => is_int($default),
            'boolean' => is_bool($default),
        };

        if (! $isValid) {
            throw new DomainException("Fact [{$key}] has an invalid default value.");
        }
    }

    private function validateConditionValue(FactDefinition $definition, mixed $value): void
    {
        $valid = match ($definition->type) {
            'enum' => is_string($value) && in_array($value, $definition->options, true),
            'date' => is_string($value) && $this->isIsoDate($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
        };

        if (! $valid) {
            throw new DomainException("Condition value for fact [{$definition->key}] does not match its registered type or options.");
        }
    }

    private function isIsoDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function requiredString(string $key, array $attributes, string $field): string
    {
        $value = $attributes[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("Fact [{$key}] must define non-empty [{$field}] text.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function requiredInteger(
        string $key,
        array $attributes,
        string $field,
        int $minimum,
        ?int $maximum = null,
    ): int {
        $value = $attributes[$field] ?? null;

        if (! is_int($value) || $value < $minimum || ($maximum !== null && $value > $maximum)) {
            throw new DomainException("Fact [{$key}] must define a valid integer [{$field}].");
        }

        return $value;
    }
}
