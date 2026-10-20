<?php

namespace App\Media;

use App\Models\MediaAcquisitionAttempt;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Stringable;

class MediaAcquisitionScheduler
{
    private const OUTCOMES = ['captured', 'no_result', 'ambiguous', 'rate_limited', 'failed'];

    private int $lastDeferred = 0;

    private int $lastRemainingDue = 0;

    /**
     * @param  Builder<Model>  $query
     * @param  callable(Model): array<string, mixed>  $snapshot
     * @return Collection<int, ScheduledMediaAcquisition>
     */
    public function select(
        Builder $query,
        string $provider,
        string $strategy,
        int $limit,
        callable $snapshot,
        bool $force = false,
    ): Collection {
        $provider = trim($provider);
        $strategy = trim($strategy);
        if ($provider === '' || $strategy === '' || $limit < 1) {
            throw new DomainException('Media acquisition scheduling requires provider, strategy and a positive limit.');
        }

        $selected = collect();
        $deferred = 0;
        $remainingDue = 0;
        $model = $query->getModel();
        $morphType = $model->getMorphClass();
        $query = clone $query;
        $query->reorder($model->qualifyColumn($model->getKeyName()));

        $query->chunkById(200, function (Collection $targets) use (
            $provider,
            $strategy,
            $limit,
            $snapshot,
            $selected,
            $morphType,
            $force,
            &$deferred,
            &$remainingDue,
        ): void {
            $latest = MediaAcquisitionAttempt::query()
                ->where('target_type', $morphType)
                ->where('provider', $provider)
                ->where('strategy', $strategy)
                ->whereIn('target_id', $targets->modelKeys())
                ->orderByDesc('id')
                ->get()
                ->unique('target_id')
                ->keyBy('target_id');

            foreach ($targets as $target) {
                $input = $this->inputSnapshot($target, $snapshot($target));
                $fingerprint = hash('sha256', json_encode(
                    $input,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ));
                $previous = $latest->get($target->getKey());
                if ($previous?->outcome === 'in_progress' && $previous->next_attempt_at?->gt(now())) {
                    $deferred++;

                    continue;
                }
                if ($previous?->outcome === 'in_progress') {
                    $previous->update([
                        'outcome' => 'failed',
                        'error_code' => 'claim_expired',
                        'active_key' => null,
                    ]);
                }
                $eligible = $force
                    || $previous === null
                    || $previous->input_fingerprint !== $fingerprint
                    || $previous->next_attempt_at === null
                    || $previous->next_attempt_at->lte(now());

                if (! $eligible) {
                    $deferred++;

                    continue;
                }

                if ($selected->count() >= $limit) {
                    $remainingDue++;

                    continue;
                }

                $activeKey = hash('sha256', implode('|', [
                    $morphType,
                    $target->getKey(),
                    $provider,
                    $strategy,
                    $fingerprint,
                ]));
                try {
                    $attempt = MediaAcquisitionAttempt::query()->create([
                        'target_type' => $morphType,
                        'target_id' => $target->getKey(),
                        'provider' => $provider,
                        'strategy' => $strategy,
                        'input_fingerprint' => $fingerprint,
                        'input_snapshot' => $input,
                        'outcome' => 'in_progress',
                        'attempted_at' => now()->utc(),
                        'next_attempt_at' => now()->utc()->addMinutes((int) config('media.acquisition.claim_minutes', 30)),
                        'candidate_count' => 0,
                        'active_key' => $activeKey,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    $deferred++;

                    continue;
                }

                $selected->push(new ScheduledMediaAcquisition(
                    target: $target,
                    provider: $provider,
                    strategy: $strategy,
                    inputFingerprint: $fingerprint,
                    inputSnapshot: $input,
                    attemptId: $attempt->id,
                    activeKey: $activeKey,
                ));
            }
        }, $model->getKeyName(), $model->getKeyName());

        $this->lastDeferred = $deferred;
        $this->lastRemainingDue = $remainingDue;

        return $selected;
    }

    /**
     * @param  Collection<int, ScheduledMediaAcquisition>  $scheduled
     * @return array{attempted: int, captured: int, no_result: int, ambiguous: int, rate_limited: int, failed: int, in_progress: int, deferred: int, remaining_due: int}
     */
    public function summary(Collection $scheduled): array
    {
        $counts = MediaAcquisitionAttempt::query()
            ->whereIn('id', $scheduled->pluck('attemptId'))
            ->selectRaw('outcome, count(*) as aggregate')
            ->groupBy('outcome')
            ->pluck('aggregate', 'outcome')
            ->map(fn ($count): int => (int) $count);

        return [
            'attempted' => $scheduled->count(),
            'captured' => $counts->get('captured', 0),
            'no_result' => $counts->get('no_result', 0),
            'ambiguous' => $counts->get('ambiguous', 0),
            'rate_limited' => $counts->get('rate_limited', 0),
            'failed' => $counts->get('failed', 0),
            'in_progress' => $counts->get('in_progress', 0),
            'deferred' => $this->lastDeferred,
            'remaining_due' => $this->lastRemainingDue,
        ];
    }

    /**
     * @param  list<int>  $selectedAssetIds
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        ScheduledMediaAcquisition $scheduled,
        string $outcome,
        ?string $errorCode = null,
        int $candidateCount = 0,
        array $selectedAssetIds = [],
        ?int $retryAfterSeconds = null,
        ?array $metadata = null,
    ): MediaAcquisitionAttempt {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new DomainException('Unsupported media acquisition outcome.');
        }

        $now = now()->utc();
        $nextAttemptAt = match ($outcome) {
            'captured', 'no_result', 'ambiguous' => $now->copy()->addDays(max(
                1,
                (int) config('media.acquisition.outcome_cooldown_days', 30),
            )),
            'rate_limited', 'failed' => $now->copy()->addSeconds($this->transientDelay(
                $scheduled,
                $retryAfterSeconds,
            )),
        };
        $assetIds = array_values(array_unique(array_filter(
            array_map('intval', $selectedAssetIds),
            fn (int $id): bool => $id > 0,
        )));

        return DB::transaction(function () use (
            $scheduled,
            $outcome,
            $nextAttemptAt,
            $errorCode,
            $candidateCount,
            $assetIds,
            $metadata,
        ): MediaAcquisitionAttempt {
            $attempt = MediaAcquisitionAttempt::query()
                ->whereKey($scheduled->attemptId)
                ->where('active_key', $scheduled->activeKey)
                ->lockForUpdate()
                ->first();
            if ($attempt === null) {
                throw new DomainException('Media acquisition claim is no longer active.');
            }

            $attempt->update([
                'outcome' => $outcome,
                'next_attempt_at' => $nextAttemptAt,
                'error_code' => $errorCode === null ? null : mb_substr(trim($errorCode), 0, 120),
                'candidate_count' => max(0, $candidateCount),
                'selected_asset_ids' => $assetIds === [] ? null : $assetIds,
                'metadata' => $metadata,
                'active_key' => null,
            ]);

            return $attempt->refresh();
        });
    }

    private function transientDelay(ScheduledMediaAcquisition $scheduled, ?int $retryAfterSeconds): int
    {
        $previousTransientCount = MediaAcquisitionAttempt::query()
            ->where('target_type', $scheduled->target->getMorphClass())
            ->where('target_id', $scheduled->target->getKey())
            ->where('provider', $scheduled->provider)
            ->where('strategy', $scheduled->strategy)
            ->where('input_fingerprint', $scheduled->inputFingerprint)
            ->whereIn('outcome', ['rate_limited', 'failed'])
            ->count();
        $delays = array_values(array_filter(
            array_map('intval', (array) config('media.acquisition.transient_retry_seconds', [3600, 21600, 86400])),
            fn (int $delay): bool => $delay > 0,
        ));
        if ($delays === []) {
            $delays = [3600];
        }
        $default = $delays[min($previousTransientCount, count($delays) - 1)];

        return max($default, max(0, (int) $retryAfterSeconds));
    }

    public function retryAfterSeconds(?string $providerError): ?int
    {
        if ($providerError === null
            || preg_match('/(?:^|;)retry_after=(?<value>[^;]+)/', $providerError, $match) !== 1) {
            return null;
        }

        $value = trim($match['value']);
        if (ctype_digit($value)) {
            return max(0, (int) $value);
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - now()->utc()->getTimestamp());
    }

    /** @param array<string, mixed> $input */
    private function inputSnapshot(Model $target, array $input): array
    {
        $canonicalId = $target->getAttributes()['canonical_spot_id'] ?? $target->getKey();

        return [
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'canonical_identity' => [
                'type' => $target->getMorphClass(),
                'id' => $canonicalId ?: $target->getKey(),
            ],
            'input' => $this->canonicalize($input),
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        throw new DomainException('Media acquisition inputs must contain only reproducible scalar values.');
    }
}
