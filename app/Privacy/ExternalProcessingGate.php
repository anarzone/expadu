<?php

namespace App\Privacy;

use App\Bureaucracy\Analyser\AnalyserAccess;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
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
            $limit = $purpose->dailyLimit();
            if ($limit !== null && $this->used($permit->actorId, $purpose) >= $limit) {
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

    /**
     * Several calls under one permit (Composer's assistant: it looks things up, then answers).
     * The permit is reserved once, as send() does, and every call is admitted only while it's
     * still current: a withdrawal stops the next call, and the result is then discarded.
     * `$run` gets a `$call(Closure $transport): ?array` and returns the final result.
     *
     * @param  array<string, mixed>  $context
     * @param  Closure(Closure(Closure(): Response): ?array): ?array  $run
     * @return array<string, mixed>|null
     */
    public function session(?ProcessingPermit $permit, ProcessingPurpose $purpose, array $context, Closure $run): ?array
    {
        if ($permit === null) {
            return null;
        }

        $reservation = DB::transaction(function () use ($permit, $purpose, $context): array {
            $row = $this->locked($permit);
            if ($row === null || ! $this->current($row, $purpose, $context)) {
                return [];
            }
            // A retry of a finished exchange gets its result; nothing is sent again.
            if ($row->state === 'completed') {
                return ['cached' => $row->result];
            }
            if ($row->state !== 'pending') {
                return [];
            }
            $limit = $purpose->dailyLimit();
            if ($limit !== null && $this->used($permit->actorId, $purpose) >= $limit) {
                $row->update(['state' => 'limited']);

                return [];
            }
            $row->update(['state' => 'processing', 'attempted_at' => now()->utc()]);

            return ['run' => true];
        });
        if (! isset($reservation['run'])) {
            return $reservation['cached'] ?? null;
        }

        $call = function (Closure $transport) use ($permit, $purpose, $context): ?array {
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
            try {
                $response = $transport()->throw();
                if ($response->successful() && strlen($response->body()) <= (int) config('bureaucracy_privacy.max_response_bytes', 65536)) {
                    $decoded = $response->json();

                    return is_array($decoded) ? $decoded : null;
                }
            } catch (Throwable $error) {
                Log::warning('External processing request failed.', ['purpose' => $purpose->value, 'error_type' => $error::class,
                    // The transport's error number (cURL's: 28 a timeout, 7 no connection, 56 a dropped receive), never what was sent.
                    'curl_errno' => self::curlErrno($error)]);
            }

            return null;
        };

        try {
            $result = $run($call);
        } catch (Throwable $error) {
            Log::warning('External processing session failed.', ['purpose' => $purpose->value, 'error_type' => $error::class]);
            $result = null;
        }

        return DB::transaction(function () use ($permit, $purpose, $context, $result): ?array {
            $row = $this->locked($permit);
            if ($row === null) {
                return null;
            }
            if (! $this->current($row, $purpose, $context) || $row->state !== 'processing') {
                $row->update(['result' => null]);

                return null;
            }
            $row->update(['state' => $result === null ? 'failed' : 'completed', 'result' => $result, 'completed_at' => now()->utc()]);

            return $result;
        });
    }

    public function used(int $actorId, ProcessingPurpose $purpose): int
    {
        return BureaucracyProcessingConsent::query()->where('actor_id', $actorId)->where('purpose', $purpose->value)
            ->where('attempted_at', '>', now()->utc()->subDay())->count();
    }

    private function locked(ProcessingPermit $permit): ?BureaucracyProcessingConsent
    {
        if (! User::query()->whereKey($permit->actorId)->lock('for no key update')->first()) {
            return null;
        }
        $reference = BureaucracyProcessingConsent::query()->whereKey($permit->id)->where('actor_id', $permit->actorId)->first();
        if ($reference?->case_id !== null) {
            // All family mutations acquire the subject/case before consent rows.
            CaseLock::lock($reference->case_id);
        }

        return BureaucracyProcessingConsent::query()->whereKey($permit->id)->where('actor_id', $permit->actorId)->lockForUpdate()->first();
    }

    private function current(BureaucracyProcessingConsent $row, ProcessingPurpose $purpose, array $context): bool
    {
        if (! $purpose->available() || $row->purpose !== $purpose->value || $row->withdrawn_at !== null || $row->expires_at->lessThanOrEqualTo(now())
            || $row->provider_version !== $purpose->providerVersion()
            || $row->notice_version !== $purpose->noticeVersion()
            || ! hash_equals($row->input_digest, ProcessingConsentStore::digest($context))) {
            return false;
        }
        if ($purpose === ProcessingPurpose::ComposerPaperwork) {
            // Their own record, still in use, read by someone still allowed to: Composer's changes to it
            // (a report) may move its version within the exchange.
            $case = $row->case_id === null ? null : BureaucracyCase::query()->whereKey($row->case_id)->where('status', 'active')->first();
            $actor = User::query()->find($row->actor_id);

            return $row->question_id === null && $case !== null && $actor !== null && app(AnalyserAccess::class)->allows($actor, $case);
        }

        return $row->case_id === null && $row->question_id === null;
    }

    /** The cURL error number behind a failed request, when the transport says one. */
    private static function curlErrno(Throwable $error): ?int
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof RequestException || $e instanceof ConnectException) {
                $errno = $e->getHandlerContext()['errno'] ?? null;

                return is_int($errno) ? $errno : null;
            }
        }

        return null;
    }
}
