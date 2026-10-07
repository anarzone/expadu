<?php

namespace App\Bureaucracy;

use App\Models\Task;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class RuleSourcePolicy
{
    public const Approved = 'approved';

    public const Legacy = 'legacy';

    public const DualSource = 'dual_source';

    public const SingleSourceApproved = 'single_source_approved';

    /** Approved by the automated source check, not by a person. See ClaimCheck. */
    public const QuoteChecked = 'quote_checked';

    public const AutomatedReviewer = 'automated_source_check';

    /**
     * Validate raw authored data before the importer writes any catalogue row.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function importErrors(array $data): array
    {
        $status = $data['review_status'] ?? self::Legacy;

        if (! is_string($status) || ! in_array($status, [self::Legacy, self::Approved], true)) {
            return ['review_status must be `legacy` or `approved`'];
        }

        if ($status === self::Legacy) {
            return [];
        }

        $automated = ($data['source_verification'] ?? null) === self::QuoteChecked;
        $errors = $this->approvalMetadataErrors($data, requireSingleSourceFlag: true, requireVerifiedAt: ! $automated);
        $errors = [...$errors, ...$this->automationErrors($data)];

        foreach (['verified_at', 'effective_from', 'effective_to'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && ! $this->isIsoDate($data[$field])) {
                $errors[] = "{$field} must be a YYYY-MM-DD date";
            }
        }

        if (array_key_exists('review_interval_days', $data)
            && ! in_array($data['review_interval_days'], [90, 365], true)) {
            $errors[] = 'review_interval_days must be 90 or 365';
        }

        if ($this->isIsoDate($data['effective_from'] ?? null)
            && $this->isIsoDate($data['effective_to'] ?? null)
            && $data['effective_from'] > $data['effective_to']) {
            $errors[] = 'effective_from must not be after effective_to';
        }

        return array_values(array_unique($errors));
    }

    /**
     * Validate an approved database row defensively for the coverage CI gate.
     *
     * @return list<string>
     */
    public function persistedErrors(Task $task, ?CarbonImmutable $today = null): array
    {
        if ($task->review_status === self::Legacy) {
            return [];
        }

        if ($task->review_status !== self::Approved) {
            return ['review_status must be `legacy` or `approved`'];
        }

        $errors = $this->approvalMetadataErrors([
            'jurisdiction' => $task->jurisdiction,
            'content_version' => $task->content_version,
            'reviewed_by' => $task->reviewed_by,
            'verified_at' => $task->verified_at,
            'source_verification' => $task->source_verification,
            'legal_sources' => $task->legal_sources,
        ], requireSingleSourceFlag: false);

        if (($task->source_verification === self::QuoteChecked) !== ($task->reviewed_by === self::AutomatedReviewer)
            || ($task->source_verification === self::QuoteChecked && ! is_array($task->claims))) {
            $errors[] = '`quote_checked` needs the automated reviewer and source claims';
        }

        $today ??= CarbonImmutable::today(config('app.timezone'));

        if ($task->review_due_at === null) {
            $errors[] = 'review_due_at is required';
        } elseif ($task->review_due_at->startOfDay()->lt($today)) {
            $errors[] = 'review_due_at has expired';
        }

        if ($task->effective_from?->startOfDay()->gt($today)) {
            $errors[] = 'effective_from is in the future';
        }

        if ($task->effective_to?->startOfDay()->lt($today)) {
            $errors[] = 'effective_to has expired';
        }

        if ($task->effective_from !== null
            && $task->effective_to !== null
            && $task->effective_from->gt($task->effective_to)) {
            $errors[] = 'effective_from is after effective_to';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reviewDueAt(array $data): ?CarbonImmutable
    {
        if (($data['review_status'] ?? self::Legacy) !== self::Approved
            || ! $this->isIsoDate($data['verified_at'] ?? null)) {
            return null;
        }

        // Automatically checked cards get their window from passing source checks, never from YAML.
        if (($data['source_verification'] ?? null) === self::QuoteChecked) {
            return null;
        }

        $interval = $data['review_interval_days'] ?? ($this->containsFigure($data) ? 90 : 365);

        if (! in_array($interval, [90, 365], true)) {
            return null;
        }

        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $data['verified_at'],
            config('app.timezone'),
        )->addDays($interval);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function approvalMetadataErrors(array $data, bool $requireSingleSourceFlag, bool $requireVerifiedAt = true): array
    {
        $errors = [];

        foreach (['jurisdiction', 'content_version', 'reviewed_by', 'source_verification'] as $field) {
            if (! $this->isNonBlankString($data[$field] ?? null)) {
                $errors[] = "{$field} is required";
            }
        }

        $verifiedAt = $data['verified_at'] ?? null;
        if ($requireVerifiedAt && ! $this->isNonBlankString($verifiedAt) && ! $verifiedAt instanceof DateTimeInterface) {
            $errors[] = 'verified_at is required';
        }

        $sources = $data['legal_sources'] ?? null;
        if (! is_array($sources) || $sources === []) {
            $errors[] = 'at least one legal_sources entry is required';
            $sources = [];
        }

        [$sourceErrors, $hasPrimary, $hasImplementation] = $this->sourceErrors($sources);
        $errors = [...$errors, ...$sourceErrors];

        if (! $hasPrimary) {
            $errors[] = 'an allowed primary legal source is required';
        }

        $verification = $data['source_verification'] ?? null;
        if (! in_array($verification, [self::DualSource, self::SingleSourceApproved, self::QuoteChecked], true)) {
            $errors[] = 'source_verification must be `dual_source`, `single_source_approved` or `quote_checked`';
        } elseif ($verification === self::DualSource && ! $hasImplementation) {
            $errors[] = 'dual_source requires an allowed implementation source';
        } elseif ($verification === self::SingleSourceApproved
            && $requireSingleSourceFlag
            && ($data['single_source_approved'] ?? null) !== true) {
            $errors[] = 'single_source_approved requires the explicit boolean true';
        }

        return $errors;
    }

    /**
     * The automated stamp and the automated check belong together: a card cannot claim
     * one without the other, and its review dates come only from passing checks.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function automationErrors(array $data): array
    {
        $automated = ($data['source_verification'] ?? null) === self::QuoteChecked;
        $stamped = ($data['reviewed_by'] ?? null) === self::AutomatedReviewer;
        if (! $automated && ! $stamped) {
            return [];
        }
        if ($automated !== $stamped) {
            return ['`quote_checked` and reviewed_by `'.self::AutomatedReviewer.'` must be used together'];
        }
        $errors = [];
        foreach (['verified_at', 'review_interval_days', 'single_source_approved'] as $field) {
            if (array_key_exists($field, $data)) {
                $errors[] = "{$field} is set by the source check, not authored, on `quote_checked` cards";
            }
        }
        if (! is_array($data['claims'] ?? null) || $data['claims'] === []) {
            $errors[] = '`quote_checked` cards need source claims';
        }

        return $errors;
    }

    /**
     * @param  array<mixed>  $sources
     * @return array{0: list<string>, 1: bool, 2: bool}
     */
    private function sourceErrors(array $sources): array
    {
        $errors = [];
        $hasPrimary = false;
        $hasImplementation = false;

        foreach ($sources as $index => $source) {
            $prefix = "legal_sources.{$index}";

            if (! is_array($source)) {
                $errors[] = "{$prefix} must be an object";

                continue;
            }

            $kind = $source['kind'] ?? null;
            $label = $source['label'] ?? null;
            $url = $source['url'] ?? null;

            if (! in_array($kind, ['primary', 'implementation'], true)) {
                $errors[] = "{$prefix}.kind must be `primary` or `implementation`";

                continue;
            }

            if (! $this->isNonBlankString($label)) {
                $errors[] = "{$prefix}.label is required";
            }

            if (! $this->isAllowedUrl($url, $kind)) {
                $errors[] = "{$prefix}.url must be HTTPS on an allowed {$kind} host";

                continue;
            }

            if ($kind === 'primary') {
                $hasPrimary = true;
            } else {
                $hasImplementation = true;
            }
        }

        return [$errors, $hasPrimary, $hasImplementation];
    }

    private function isAllowedUrl(mixed $value, string $kind): bool
    {
        if (! $this->isNonBlankString($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $configuredHosts = config("bureaucracy_sources.{$kind}_hosts", []);

        if (! is_array($configuredHosts)) {
            return false;
        }

        foreach ($configuredHosts as $allowedHost) {
            if (! is_string($allowedHost)) {
                continue;
            }

            $allowedHost = strtolower(trim($allowedHost, '.'));
            if ($host === $allowedHost || str_ends_with($host, ".{$allowedHost}")) {
                return true;
            }
        }

        return false;
    }

    private function containsFigure(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsFigure($item)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($value) && preg_match('/\{\{figure:[a-z0-9_]+\}\}/', $value) === 1;
    }

    private function isIsoDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));

        return $date->format('Y-m-d') === $value;
    }

    private function isNonBlankString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
