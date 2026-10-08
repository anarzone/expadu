<?php

namespace App\Privacy;

use App\Bureaucracy\Ai\FactExtractionAccess;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseMessage;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExternalProcessingGate
{
    /**
     * Only trusted provider adapters supply transport closures; client input never does.
     * A reserved/uncertain request is not resent. HTTP runs outside database locks.
     *
     * @param  array<string, mixed>  $context
     * @param  Closure(): Response  $transport
     * @return array<string, mixed>|null
     */
    public function send(?ProcessingPermit $permit, ProcessingPurpose $purpose, array $context, Closure $transport): ?array
    {
        if ($permit === null) {
            return null;
        }

        $reservation = DB::transaction(function () use ($permit, $purpose, $context): array {
            $row = $this->locked($permit);
            if ($row === null || ! $this->current($row, $purpose, $context)) {
                return [];
            }
            if ($row->state === 'completed') {
                return ['cached' => $row->result];
            }
            if ($row->state !== 'pending') {
                return [];
            }
            if ($this->used($permit->actorId, $purpose) >= $purpose->dailyLimit()) {
                $row->update(['state' => 'limited']);

                return [];
            }
            $row->update(['state' => 'processing', 'attempted_at' => now()->utc()]);

            return ['send' => true];
        });

        if (! isset($reservation['send'])) {
            return $reservation['cached'] ?? null;
        }

        // Dispatch admission is distinct from quota reservation. Withdrawal
        // before this boundary prevents sending; after it, the request may be
        // in flight and its result is still discarded on withdrawal. A remote
        // processor cannot be made transactional with our consent database.
        $admitted = DB::transaction(function () use ($permit, $purpose, $context): bool {
            $row = $this->locked($permit);
            if ($row === null || ! $this->current($row, $purpose, $context) || $row->state !== 'processing') {
                return false;
            }
            $row->update(['dispatched_at' => now()->utc()]);

            return true;
        });
        if (! $admitted) {
            return null;
        }

        $body = null;
        try {
            $response = $transport()->throw();
            if ($response->successful() && strlen($response->body()) <= (int) config('bureaucracy_privacy.max_response_bytes', 65536)) {
                $decoded = $response->json();
                $body = is_array($decoded) ? $decoded : null;
            }
        } catch (Throwable $error) {
            Log::warning('External processing request failed.', ['purpose' => $purpose->value, 'error_type' => $error::class]);
        }

        return DB::transaction(function () use ($permit, $purpose, $context, $body): ?array {
            $row = $this->locked($permit);
            if ($row === null) {
                return null;
            }
            if (! $this->current($row, $purpose, $context) || $row->state !== 'processing') {
                $row->update(['result' => null]);

                return null;
            }
            $row->update(['state' => $body === null ? 'failed' : 'completed', 'result' => $body, 'completed_at' => now()->utc()]);

            return $body;
        });
    }

    public function used(int $actorId, ProcessingPurpose $purpose): int
    {
        $used = BureaucracyProcessingConsent::query()->where('actor_id', $actorId)->where('purpose', $purpose->value)
            ->where('attempted_at', '>', now()->utc()->subDay())->count();
        if ($purpose === ProcessingPurpose::FactExtraction) {
            $used += app(LegacyProcessingUsage::class)->used($actorId);
            $used += BureaucracyCaseMessage::query()->whereIn('case_id', BureaucracyCase::query()->select('id')->where('user_id', $actorId))
                ->where('operation', $purpose->value)->where('role', 'user')->where('created_at', '>', now()->subDay())->count();
        }

        return $used;
    }

    private function locked(ProcessingPermit $permit): ?BureaucracyProcessingConsent
    {
        if (! User::query()->whereKey($permit->actorId)->lock('for no key update')->first()) {
            return null;
        }
        $reference = BureaucracyProcessingConsent::query()->whereKey($permit->id)->where('actor_id', $permit->actorId)->first();
        if ($reference?->case_id !== null) {
            // All family mutations acquire the subject/case before consent rows.
            app(FactExtractionAccess::class)->lockCase($reference->case_id);
        }

        return BureaucracyProcessingConsent::query()->whereKey($permit->id)->where('actor_id', $permit->actorId)->lockForUpdate()->first();
    }

    private function current(BureaucracyProcessingConsent $row, ProcessingPurpose $purpose, array $context): bool
    {
        if (! $purpose->available() || $row->purpose !== $purpose->value || $row->withdrawn_at !== null || $row->expires_at->lessThanOrEqualTo(now())
            || $row->provider_version !== $purpose->providerVersion()
            || $row->notice_version !== config('bureaucracy_privacy.notice_version')
            || ! hash_equals($row->input_digest, ProcessingConsentStore::digest($context))) {
            return false;
        }
        if ($purpose !== ProcessingPurpose::FactExtraction) {
            return $row->case_id === null && $row->question_id === null;
        }
        $case = BureaucracyCase::query()->whereKey($row->case_id)->where('status', 'active')->first();
        if ($case === null || $case->fact_version !== $row->fact_version) {
            return false;
        }
        $actor = User::query()->find($row->actor_id);
        $question = $actor !== null && $row->question_id !== null ? app(FactExtractionAccess::class)->question($actor, $case, $row->question_id, $context) : null;

        return $question !== null && $question->id === $row->question_id && $question->answered_at === null;
    }
}
