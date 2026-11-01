<?php

namespace App\Bureaucracy\Verification;

use App\Bureaucracy\GuidancePublication;
use App\Bureaucracy\RuleSourcePolicy;
use App\Models\BureaucracySourceCheck;
use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * Records source-check results and turns them into the review window of a
 * `quote_checked` card — the only way such a card becomes publishable.
 *
 * A pass counts for PassDays and is renewed only when fewer than RenewDays remain, so
 * the published review dates (and the catalogue release) change every few weeks, not
 * daily. A failed check withdraws the card at once. An unreachable source changes
 * nothing; the last pass simply runs out if the page stays unreachable.
 */
class SourceCheckRecorder
{
    public const PassDays = 30;

    public const RenewDays = 14;

    public function __construct(private GuidancePublication $publication) {}

    /** What was checked: the published content plus the claims behind it. */
    public function hash(Task $task): string
    {
        return hash('sha256', $this->publication->contentHash($task).'|'.json_encode($this->canonical($task->claims ?? []), JSON_THROW_ON_ERROR));
    }

    /** @return array{verified_at: string|null, review_due_at: string|null} */
    public function window(Task $task, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today(config('app.timezone'));
        $check = BureaucracySourceCheck::query()->where('task_key', $task->key)->first();
        $hash = $this->hash($task);
        $valid = $check !== null && $check->passed_hash === $hash && $check->valid_until !== null
            && $check->valid_until->toDateString() >= $today->toDateString()
            && ! ($check->outcome === 'failed' && $check->check_hash === $hash);

        return $valid
            ? ['verified_at' => $check->first_passed_on?->toDateString(), 'review_due_at' => $check->valid_until->toDateString()]
            : ['verified_at' => null, 'review_due_at' => null];
    }

    /** Applies the current window to a stored `quote_checked` card. Returns whether its review dates changed. */
    public function apply(Task $task, ?CarbonImmutable $today = null): bool
    {
        if ($task->source_verification !== RuleSourcePolicy::QuoteChecked) {
            return false;
        }
        $window = $this->window($task, $today);
        if ([$task->verified_at?->toDateString(), $task->review_due_at?->toDateString()] === [$window['verified_at'], $window['review_due_at']]) {
            return false;
        }
        $task->forceFill($window)->saveQuietly();

        return true;
    }

    /** @param array{outcome: string, failures: list<string>, unreachable: list<string>} $result */
    public function record(Task $task, array $result, ?CarbonImmutable $today = null): BureaucracySourceCheck
    {
        $today ??= CarbonImmutable::today(config('app.timezone'));
        $hash = $this->hash($task);
        $check = BureaucracySourceCheck::query()->firstOrNew(['task_key' => $task->key]);
        $check->fill(['check_hash' => $hash, 'outcome' => $result['outcome'], 'failures' => $result['failures'],
            'unreachable' => $result['unreachable'], 'checked_at' => now()->utc()]);
        if ($result['outcome'] === 'passed') {
            if ($check->passed_hash !== $hash) {
                $check->fill(['passed_hash' => $hash, 'first_passed_on' => $today->toDateString(), 'valid_until' => $today->addDays(self::PassDays)->toDateString()]);
            } elseif ($check->valid_until === null || $check->valid_until->toDateString() < $today->addDays(self::RenewDays)->toDateString()) {
                $check->valid_until = $today->addDays(self::PassDays)->toDateString();
            }
        }
        $check->save();

        return $check;
    }

    /** @param array<mixed> $values @return array<mixed> */
    private function canonical(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->canonical($value);
            }
        }

        return $values;
    }
}
