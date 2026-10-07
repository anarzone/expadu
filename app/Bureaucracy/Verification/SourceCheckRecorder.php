<?php

namespace App\Bureaucracy\Verification;

use App\Bureaucracy\GuidancePublication;
use App\Models\BureaucracySourceCheck;
use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * Records source-check results per card.
 *
 * A pass counts for PassDays and is renewed only when fewer than RenewDays remain, so a
 * review window derived from it would change every few weeks rather than daily. An
 * unreachable source changes nothing; the last pass simply runs out if the page stays down.
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
