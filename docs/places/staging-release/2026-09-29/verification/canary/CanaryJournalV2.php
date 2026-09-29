<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GuardedCanaryV2.php';

class CanaryJournalV2
{
    public function apply(string $operationId, array $context, array $records, array $baseline, string $actor): array
    {
        $this->guard($operationId, $context, $actor);

        return DB::transaction(function () use ($operationId, $context, $records, $baseline, $actor): array {
            $this->lock($operationId);
            $existing = DB::table('place_catalogue_operations')->where('id', $operationId)->first();
            if ($existing !== null) {
                $saved = $this->decode($existing->context);
                $this->check($this->same($saved, $context) && $existing->actor === $actor, 'Operation identity differs from its journal.');
                $this->check($existing->state === 'applied', 'This operation has been recovered; refuse reapplication.');
                $receipt = $this->decode($existing->receipt);
                (new GuardedCanaryV2)->validateReceipt($receipt);
                $this->check($this->same($receipt['context'], $context), 'Receipt context differs from journal.');
                $this->check($this->same((new GuardedCanaryV2)->snapshot(array_column($receipt['mapping'], 'id')), $receipt['after']), 'Catalogue changed since committed operation; refuse replay.');

                return $receipt;
            }
            $receipt = (new GuardedCanaryV2)->apply($records, $baseline, $context['package_sha256']);
            unset($receipt['sha256']);
            $receipt['context'] = $context;
            $receipt['sha256'] = (new GuardedCanaryV2)->hash($receipt);
            DB::table('place_catalogue_operations')->insert(['id' => $operationId, 'state' => 'applied', 'context' => $this->encode($context), 'receipt' => $this->encode($receipt), 'actor' => $actor, 'created_at' => now(), 'updated_at' => now()]);

            return $receipt;
        });
    }

    public function recover(string $operationId, array $context, string $actor, string $reason): array
    {
        $this->guard($operationId, $context, $actor);

        return DB::transaction(function () use ($operationId, $context, $actor, $reason): array {
            $this->lock($operationId);
            $row = DB::table('place_catalogue_operations')->where('id', $operationId)->first();
            $this->check($row !== null, 'No committed operation journal exists; do not infer it from a file.');
            $this->check($this->same($this->decode($row->context), $context), 'Operation identity differs from its journal.');
            $this->check(in_array($row->state, ['applied', 'recovered'], true), 'Unsupported journal state.');
            $receipt = $this->decode($row->receipt);
            (new GuardedCanaryV2)->validateReceipt($receipt);
            $this->check($this->same($receipt['context'], $context), 'Receipt context differs from journal.');
            $recovery = (new GuardedCanaryV2)->recover($receipt, $actor, $reason, $row->state === 'recovered' ? $this->decode($row->recovery) : null);
            if ($row->state !== 'recovered') {
                DB::table('place_catalogue_operations')->where('id', $operationId)->update(['state' => 'recovered', 'recovery' => $this->encode($recovery), 'updated_at' => now()]);
            }

            return $recovery;
        });
    }

    public function rehearseRecovery(string $operationId, array $context, string $actor, string $reason): array
    {
        $this->guard($operationId, $context, $actor);
        $this->lock($operationId);
        $row = DB::table('place_catalogue_operations')->where('id', $operationId)->first();
        $this->check($row !== null && $row->state === 'applied', 'An applied journal is required before rehearsing recovery.');
        $receipt = $this->decode($row->receipt);
        (new GuardedCanaryV2)->validateReceipt($receipt);
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $recovery = $this->recover($operationId, $context, $actor, $reason);
        } finally {
            DB::rollBack($level);
        }
        $this->check(DB::transactionLevel() === $level, 'Caller transaction boundary was not retained.');
        $this->check($this->same((new GuardedCanaryV2)->snapshot(array_column($receipt['mapping'], 'id')), $receipt['after']), 'Recovery rehearsal did not restore the imported state.');
        $this->check(DB::table('place_catalogue_operations')->where('id', $operationId)->value('state') === 'applied', 'Recovery rehearsal leaked its journal state.');

        return ['restored_streams' => $recovery['restored_source_streams'], 'withdrawn_additions' => $recovery['withdrawn_additions'], 'withdrawn_source_observations' => $recovery['withdrawn_source_observations'], 'retained_alias_history_changes' => count($recovery['alias_deltas']), 'recovery_receipt_sha256' => $recovery['sha256'], 'caller_boundary_retained' => true, 'applied_state_restored' => true];
    }

    private function guard(string $id, array $context, string $actor): void
    {
        $this->check(DB::transactionLevel() > 0, 'A caller-owned transaction is required.');
        $this->check(preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id) === 1 && trim($actor) !== '' && mb_strlen($actor) <= 191, 'Explicit operation UUID and actor are required.');
        foreach (['package_sha256', 'manifest_sha256', 'application_sha256', 'importer_sha256'] as $key) {
            $this->check(preg_match('/^[a-f0-9]{64}$/D', $context[$key] ?? '') === 1, 'Exact reviewed package, manifest, importer and application hashes are required.');
        }
    }

    private function lock(string $id): void
    {
        DB::statement('SET LOCAL lock_timeout = 10000');
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['places-canary:'.$id]);
        DB::statement('LOCK TABLE spots, place_fact_observations, place_fact_corrections, place_fact_revisions, place_destination_reviews, place_reconciliations IN SHARE ROW EXCLUSIVE MODE');
    }

    private function same(array $a, array $b): bool
    {
        return (new GuardedCanaryV2)->hash($a) === (new GuardedCanaryV2)->hash($b);
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function decode(string $value): array
    {
        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new DomainException($message);
        }
    }
}
