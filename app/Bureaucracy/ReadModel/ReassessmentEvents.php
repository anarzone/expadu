<?php

namespace App\Bureaucracy\ReadModel;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyEvidenceItem;
use App\Models\BureaucracyEvidenceShare;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyRelationship;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class ReassessmentEvents
{
    public function accessChanged(BureaucracyPerson $person): void
    {
        $this->requireTransaction();
        BureaucracyOutboxEvent::query()->create([
            'event_type' => 'access.changed', 'aggregate_type' => 'person', 'aggregate_id' => $person->id,
            'aggregate_version' => $person->record_version, 'dedupe_key' => "access.changed:{$person->id}:{$person->record_version}",
            'payload' => [], 'available_at' => now()->utc(),
        ]);
    }

    /** Capture affected people before erasure cascades remove the dependency, not the deleted values. */
    public function beforeErasure(BureaucracyPerson $person): void
    {
        $this->requireTransaction();
        $ids = BureaucracyRelationship::query()->where('related_person_id', $person->id)->where('type', 'sponsor')
            ->whereNull('revoked_at')->pluck('person_id')->all();
        $sharedProcesses = BureaucracyEvidenceShare::query()->whereNull('revoked_at')
            ->whereIn('evidence_id', BureaucracyEvidenceItem::query()->where('person_id', $person->id)->select('id'))->select('process_id');
        $ids = [...$ids, ...BureaucracyCase::query()->whereIn('id', BureaucracyProcess::query()->whereIn('id', $sharedProcesses)->select('case_id'))->pluck('person_id')->all()];
        $batch = (string) Str::uuid();
        foreach (BureaucracyPerson::query()->whereKey(array_unique($ids))->where('id', '!=', $person->id)->where('record_status', 'active')->get() as $target) {
            // No erased person's ID or relationship survives in the recipient's work item.
            BureaucracyOutboxEvent::query()->create([
                'event_type' => 'person.reassessment_requested', 'aggregate_type' => 'person', 'aggregate_id' => $target->id,
                'aggregate_version' => $target->record_version, 'dedupe_key' => "person.reassess:{$batch}:{$target->id}",
                'payload' => [], 'available_at' => now()->utc(),
            ]);
        }
    }

    public function atBoundary(BureaucracyPerson $person, ?string $boundary): void
    {
        $this->requireTransaction();
        if ($boundary === null) {
            return;
        }
        $at = CarbonImmutable::parse($boundary)->utc();
        if ($at->lessThanOrEqualTo(now())) {
            throw new LogicException('A future assessment boundary is required.');
        }
        BureaucracyOutboxEvent::query()->firstOrCreate([
            'dedupe_key' => 'person.boundary:'.$person->id.':'.hash('sha256', $at->toIso8601String()),
        ], [
            'event_type' => 'person.reassessment_requested', 'aggregate_type' => 'person', 'aggregate_id' => $person->id,
            'aggregate_version' => $person->record_version, 'payload' => [], 'available_at' => $at,
        ]);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reassessment must be recorded in the change transaction.');
        }
    }
}
