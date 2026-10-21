<?php

namespace App\Privacy;

use App\Bureaucracy\Ai\FactExtractionAccess;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseMessage;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyExtractionCandidate;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProcessingConsentStore
{
    /** @param array<string, mixed> $context @param array<string, mixed> $acceptance */
    public function grant(User $actor, ProcessingPurpose $purpose, array $context, array $acceptance, ?BureaucracyCase $case = null, ?BureaucracyCaseQuestion $question = null): ProcessingPermit
    {
        if (($acceptance['consent'] ?? null) !== true
            || ! is_string($acceptance['request_id'] ?? null) || ! Str::isUuid($acceptance['request_id'])
            || ($acceptance['notice_version'] ?? null) !== config('bureaucracy_privacy.notice_version')
            || ($acceptance['provider_version'] ?? null) !== $purpose->providerVersion()) {
            throw ValidationException::withMessages(['processing' => 'Please review the current permission for this request.']);
        }

        return DB::transaction(function () use ($actor, $purpose, $context, $acceptance, $case, $question): ProcessingPermit {
            User::query()->whereKey($actor->getKey())->lock('for no key update')->firstOrFail();
            if ($case !== null) {
                $case = app(FactExtractionAccess::class)->lockCase($case->id);
                if ($case === null) {
                    throw new AuthorizationException;
                }
            }
            if ($purpose === ProcessingPurpose::FactExtraction) {
                $current = $case && $question ? app(FactExtractionAccess::class)->question($actor, $case, $question->id, $context) : null;
                if ($current === null || $question === null || $current->id !== $question->id || $current->answered_at !== null) {
                    throw new AuthorizationException;
                }
            } elseif ($case !== null || $question !== null) {
                throw new AuthorizationException;
            }

            $acceptance['request_id'] = strtolower($acceptance['request_id']);
            $digest = self::digest($context);
            $existing = BureaucracyProcessingConsent::query()->where('actor_id', $actor->getKey())
                ->where('request_key', $acceptance['request_id'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->purpose !== $purpose->value || ! hash_equals($existing->input_digest, $digest)
                    || $existing->case_id !== $case?->id || $existing->question_id !== $question?->id
                    || $existing->provider_version !== $purpose->providerVersion()
                    || $existing->notice_version !== $acceptance['notice_version']) {
                    throw ValidationException::withMessages(['processing.request_id' => 'This request identifier was already used for different input.']);
                }
                if ($existing->withdrawn_at !== null || $existing->expires_at->lessThanOrEqualTo(now())) {
                    throw new AuthorizationException;
                }

                return new ProcessingPermit($existing->id, $actor->getKey());
            }

            $row = BureaucracyProcessingConsent::query()->create([
                'id' => (string) Str::uuid(), 'actor_id' => $actor->getKey(), 'case_id' => $case?->id,
                'question_id' => $question?->id, 'fact_version' => $case?->fact_version,
                'request_key' => $acceptance['request_id'], 'purpose' => $purpose->value,
                'provider_version' => $purpose->providerVersion(), 'notice_version' => $acceptance['notice_version'],
                'input_digest' => $digest, 'state' => 'pending', 'granted_at' => now()->utc(),
                'expires_at' => now()->utc()->addMinutes(min(15, max(1, (int) config('bureaucracy_privacy.request_minutes', 15)))),
                'delete_after' => now()->utc()->addDays(min(30, max(2, (int) config('bureaucracy_privacy.audit_days', 30)))),
            ]);

            return new ProcessingPermit($row->id, $actor->getKey());
        });
    }

    public function referenceTime(ProcessingPermit $permit): ?CarbonImmutable
    {
        return BureaucracyProcessingConsent::query()->whereKey($permit->id)->where('actor_id', $permit->actorId)->first()?->granted_at;
    }

    public function withdraw(User $actor, ?BureaucracyCase $case = null, ?ProcessingPurpose $purpose = null): int
    {
        return DB::transaction(function () use ($actor, $case, $purpose): int {
            User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            if ($case !== null && ! BureaucracyCase::query()->whereKey($case->id)->where('user_id', $actor->id)->exists()
                && ! BureaucracyProcessingConsent::query()->where('case_id', $case->id)->where('actor_id', $actor->id)->exists()) {
                throw new AuthorizationException;
            }
            $permissions = BureaucracyProcessingConsent::query()->where('actor_id', $actor->id)
                ->when($case !== null, fn ($query) => $query->where('case_id', $case->id))
                ->when($purpose !== null, fn ($query) => $query->where('purpose', $purpose->value));
            $inFlight = (clone $permissions)->where('state', 'processing')->whereNotNull('dispatched_at')->count();
            $permissions->update(['withdrawn_at' => now()->utc(), 'result' => null, 'state' => 'withdrawn']);
            BureaucracyExtractionCandidate::query()->whereIn('consent_id', (clone $permissions)->select('id'))
                ->update(['value' => null, 'confirmation_token' => null, 'state' => 'invalidated']);
            if ($purpose === null || $purpose === ProcessingPurpose::FactExtraction) {
                $caseIds = BureaucracyCase::query()->where('user_id', $actor->id)
                    ->when($case !== null, fn ($query) => $query->whereKey($case->id))->pluck('id')->all();
                app(LegacyProcessingUsage::class)->preserve($actor, $caseIds);
                BureaucracyCaseMessage::query()->whereIn('case_id', $caseIds)->delete();
            }

            return $inFlight;
        });
    }

    /** No raw prompt is retained, and digests resist guessing small personal values. */
    public static function digest(array $context): string
    {
        $normalise = function (array $values) use (&$normalise): array {
            if (! array_is_list($values)) {
                ksort($values);
            }
            foreach ($values as $key => $value) {
                if (is_array($value)) {
                    $values[$key] = $normalise($value);
                }
            }

            return $values;
        };

        return hash_hmac('sha256', json_encode($normalise($context), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
