<?php

namespace App\Bureaucracy\ReadModel;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyEvidenceShare;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyRelationship;

final class ReassessmentTargets
{
    /** Internal dependency routing only. Never returns family facts to a caller. */
    public function for(BureaucracyOutboxEvent $event): array
    {
        $personId = match ($event->aggregate_type) {
            'person' => $event->aggregate_id,
            'case' => BureaucracyCase::query()->whereKey($event->aggregate_id)->value('person_id'),
            'process' => BureaucracyCase::query()->whereIn('id', BureaucracyProcess::query()->whereKey($event->aggregate_id)->select('case_id'))->value('person_id'),
            default => null,
        };
        if ($personId === null) {
            return [];
        }
        $ids = [$personId];
        if (in_array($event->event_type, ['facts.changed', 'access.changed'], true)) {
            $ids = [...$ids, ...BureaucracyRelationship::query()->where('related_person_id', $personId)
                ->where('type', 'sponsor')->whereNull('revoked_at')->pluck('person_id')->all()];
        }
        $processIds = isset($event->payload['process_id']) ? [$event->payload['process_id']] : [];
        if ($event->event_type === 'evidence.changed' && isset($event->payload['evidence_id'])) {
            $processIds = [...$processIds, ...BureaucracyEvidenceShare::query()->where('evidence_id', $event->payload['evidence_id'])->pluck('process_id')->all()];
        }
        if ($processIds !== []) {
            $ids = [...$ids, ...BureaucracyCase::query()->whereIn('id', BureaucracyProcess::query()->whereKey($processIds)->select('case_id'))->pluck('person_id')->all()];
        }

        return BureaucracyPerson::query()->whereKey(array_unique($ids))->where('record_status', 'active')->orderBy('id')->pluck('id')->all();
    }
}
