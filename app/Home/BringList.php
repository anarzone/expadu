<?php

namespace App\Home;

use App\Bureaucracy\Evidence\ConfirmRequirementUse;
use App\Bureaucracy\Evidence\RecordEvidence;
use App\Bureaucracy\Evidence\WithdrawRequirementUse;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcess;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What to bring to a Paperwork appointment on Today: the required documents of one of their own
 * processes, read from the same reviewed plan as Paperwork (AccountHolderPlan), so the count is
 * TodayPaperwork's. Ticking one runs Paperwork's own commands, as its Documents list does ("I have
 * this", then "Mark as ready"; "I don't have it" back), so it's one record in both places.
 */
final class BringList
{
    public function __construct(private AccountHolderPlan $plans, private RecordEvidence $evidence,
        private ConfirmRequirementUse $confirm, private WithdrawRequirementUse $withdraw) {}

    /** @return array{title: ?string, ready: int, total: int, documents: list<array{id: string, title: string, ready: bool, note: ?string}>} */
    public function for(User $user, string $occurrence): array
    {
        [$plan, $process] = $this->process($user, $occurrence);
        $documents = array_map(fn (array $row) => [
            'id' => (string) $row['id'],
            'title' => (string) $row['label'],
            'ready' => $row['readiness'] === 'confirmed_for_use',
            'note' => ($row['note'] ?? '') !== '' ? (string) $row['note'] : null,
        ], $this->rows($plan, $process));

        return ['title' => $process['title'] ?? null, 'ready' => count(array_filter(array_column($documents, 'ready'))),
            'total' => count($documents), 'documents' => $documents];
    }

    /**
     * Tick a document ready or not, then the list as it now stands. Already so, nothing is written.
     *
     * @return array{title: ?string, ready: int, total: int, documents: list<array{id: string, title: string, ready: bool, note: ?string}>}
     */
    public function set(User $user, string $occurrence, string $document, bool $ready): array
    {
        [$plan, $process] = $this->process($user, $occurrence);
        $row = collect($this->rows($plan, $process))->firstWhere('id', $document) ?? throw new NotFoundHttpException;
        if (($row['readiness'] === 'confirmed_for_use') !== $ready) {
            $person = $this->plans->person($user) ?? throw new NotFoundHttpException;
            $model = BureaucracyProcess::query()->findOrFail($process['id']);
            // Both steps or neither: a half-done tick never leaves "You have it" behind on its own.
            DB::transaction(fn () => $ready
                ? $this->ready($user, $person, $model, $plan, $process, $row)
                : $this->notReady($user, $model, $process, $row));
        }

        return $this->for($user, $occurrence);
    }

    /** "I have this" when there's nothing to use yet, then "Mark as ready", as Paperwork's row does. */
    private function ready(User $user, BureaucracyPerson $person, BureaucracyProcess $model, array $plan, array $process, array $row): void
    {
        $suggested = $row['suggested_evidence_ids'] ?? [];
        $evidenceId = in_array($row['evidence_id'], $suggested, true) ? $row['evidence_id'] : ($suggested[0] ?? null);
        // A suggestion may be a document shared with them for this task; ConfirmRequirementUse checks the share.
        $version = collect($plan['paperwork']['evidence'] ?? [])->firstWhere('id', $evidenceId)['version'] ?? null;
        if ($evidenceId === null || $version === null) {
            // One record per document name, so having it once shows everywhere it's asked for.
            $existing = collect($this->items($plan, $person))->first(fn (array $item) => self::same((string) ($item['details']['label'] ?? ''), (string) $row['label']));
            $receipt = $this->evidence->execute($user, $person, $existing['id'] ?? (string) Str::uuid(), [
                'label' => $existing['details']['label'] ?? $row['label'],
                'kind' => $existing['details']['kind'] ?? 'document',
                'reported_available' => true,
                // A record left out of the write loses it: the expiry they gave stays.
                'expires_on' => $existing['details']['expires_on'] ?? null,
                'requirement_refs' => array_values(array_unique([...($existing['details']['requirement_refs'] ?? []), $row['id']])),
            ], (int) ($existing['version'] ?? 0), (string) Str::uuid());
            [$evidenceId, $version] = [$receipt->id, $receipt->version];
        }
        $this->confirm->execute($user, $model, $row['id'], $evidenceId, (int) $process['version'], (int) $version, $row['semantic_hash'], (string) Str::uuid());
    }

    /**
     * Unticked on Today: not ready for this task yet. Only the confirmation goes; the document's
     * record, its expiry and what it answers stay, so ticking it again puts it back as it was.
     * "I don't have it" is Paperwork's own, stronger step.
     */
    private function notReady(User $user, BureaucracyProcess $model, array $process, array $row): void
    {
        if ($row['evidence_id'] !== null) {
            $this->withdraw->execute($user, $model, $row['id'], (int) $process['version'], (string) Str::uuid());
        }
    }

    /**
     * Their own plan and the tracked process Today named. A stopped record (Art. 18) says so,
     * as Paperwork does; anything else is not found. A task's key is the same for everyone
     * (definition, city, context), so it is only ever looked up in the caller's own plan.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function process(User $user, string $occurrence): array
    {
        if (BureaucracyPerson::query()->where('account_user_id', $user->id)->where('record_status', 'active')->whereNotNull('processing_stopped_at')->exists()) {
            throw new ConflictHttpException(PersonAccess::STOPPED);
        }
        try {
            $plan = $this->plans->for($user);
        } catch (AuthorizationException) {
            $plan = null;
        }
        // Only a task they track, as Today names: the same task untracked has no record of theirs to tick.
        $process = $plan === null || ! isset($plan['paperwork']['requirements']) ? null
            : collect($plan['processes'])->first(fn (array $process) => $process['id'] !== null && $process['occurrence_key'] === $occurrence);

        return $process === null ? throw new NotFoundHttpException : [$plan, $process];
    }

    /**
     * The process's required documents in step order: the rows TodayPaperwork counts
     * (ProcessDetails' own filter), so the list and Today's "1 of 5" never disagree.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $plan, array $process): array
    {
        $rows = [];
        foreach ($process['steps'] ?? [] as $step) {
            if (($step['requirements'] ?? null) === null) {
                continue;
            }
            foreach ($plan['paperwork']['requirements'] as $row) {
                if ($row['occurrence_key'] === $process['occurrence_key'] && $row['source_rule_id'] === $step['guidance_id'] && $row['applicability'] === 'required') {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Their own document records in the plan; one shared with them by someone else isn't theirs to change.
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $plan, BureaucracyPerson $person): array
    {
        return array_values(array_filter($plan['paperwork']['evidence'] ?? [], fn (array $item) => (int) $item['person_id'] === $person->id));
    }

    private static function same(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
