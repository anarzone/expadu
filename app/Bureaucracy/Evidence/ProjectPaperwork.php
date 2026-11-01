<?php

namespace App\Bureaucracy\Evidence;

use App\Bureaucracy\Assessment\AssessmentFacts;
use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\Processes\DiscoverProcesses;
use App\Models\BureaucracyEvidenceItem;
use App\Models\BureaucracyEvidenceShare;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyRequirementUse;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ProjectPaperwork
{
    public function __construct(private PersonAccess $access, private DiscoverProcesses $discover,
        private EvidenceRequirements $requirements, private EvidenceAccess $evidenceAccess) {}

    public function for(User $actor, BureaucracyPerson $person, AssessmentInput $input): array
    {
        return DB::transaction(function () use ($actor, $person, $input): array {
            $this->access->authorize($actor, $person, AccessScope::ManageEvidence);
            $proposals = $this->discover->for($input);
            $stored = array_column($input->processes, null, 'occurrence_key');
            $ownItems = BureaucracyEvidenceItem::query()->where('person_id', $person->id)->where('status', 'active')->orderBy('id')->sharedLock()->get();
            $inventory = $ownItems->mapWithKeys(fn ($item) => [$item->id => $this->item($item)])->all();
            $uses = BureaucracyRequirementUse::query()->whereIn('process_id', array_column($input->processes, 'id'))->whereNull('superseded_at')->get()
                ->keyBy(fn ($use) => $use->process_id.':'.$use->requirement_id);
            // Evidence shared for these processes' requirements: the live shares and the items
            // they point at, read once for the plan rather than twice per requirement.
            $shares = BureaucracyEvidenceShare::query()->whereIn('process_id', array_column($input->processes, 'id'))
                ->whereNull('revoked_at')->where('expires_at', '>', $input->at)->get(['process_id', 'requirement_id', 'requirement_hash', 'evidence_id']);
            $sharedItems = $shares->isEmpty() ? collect()
                : BureaucracyEvidenceItem::query()->whereIn('id', $shares->pluck('evidence_id')->unique()->values())->where('status', 'active')->orderBy('id')->sharedLock()->get();
            $facts = (new AssessmentFacts)->combine($input->facts, $input->relationships);
            $rows = [];
            foreach ($proposals as $proposal) {
                $processId = $stored[$proposal['occurrence_key']]['id'] ?? null;
                foreach ($this->requirements->for($proposal['variants'], $facts, $input->at) as $requirement) {
                    $use = $processId === null ? null : ($uses[$processId.':'.$requirement['id']] ?? null);
                    $use = $use?->status === 'confirmed' ? $use : null;
                    $selected = $use?->evidence_id === null ? null : BureaucracyEvidenceItem::query()->whereKey($use->evidence_id)->sharedLock()->first();
                    $share = $selected !== null && $selected->person_id !== $person->id ? $this->evidenceAccess->shareFor($selected, $processId, $requirement['id'], $requirement['semantic_hash']) : null;
                    $permitted = $selected !== null && ($selected->person_id === $person->id || $share !== null);
                    $readiness = 'missing';
                    $suggestions = $ownItems->filter(fn ($item) => $this->evidenceAccess->suggests($item, $input->at->toDateString(), $requirement['id'], $requirement['evidence_kind']))->values()->pluck('id')->all();
                    if ($processId !== null) {
                        $sharedIds = $shares->filter(fn (BureaucracyEvidenceShare $share): bool => (int) $share->process_id === (int) $processId
                            && (string) $share->requirement_id === (string) $requirement['id'] && (string) $share->requirement_hash === (string) $requirement['semantic_hash'])
                            ->pluck('evidence_id')->map(fn ($id): string => (string) $id)->all();
                        foreach ($sharedItems->filter(fn (BureaucracyEvidenceItem $item): bool => in_array((string) $item->id, $sharedIds, true)) as $sharedItem) {
                            if ($this->evidenceAccess->shareFor($sharedItem, $processId, $requirement['id'], $requirement['semantic_hash']) !== null) {
                                $inventory[$sharedItem->id] = $this->item($sharedItem);
                                if ($this->evidenceAccess->fits($sharedItem, $input->at->toDateString(), $requirement['id'], $requirement['evidence_kind'])) {
                                    $suggestions[] = $sharedItem->id;
                                }
                            }
                        }
                        $suggestions = array_values(array_unique($suggestions));
                    }
                    if ($use !== null) {
                        $readiness = $permitted && $use->share_id === $share?->id && $selected->version === $use->evidence_version && $use->requirement_hash === $requirement['semantic_hash']
                            && $requirement['applicability'] === 'required' && $this->evidenceAccess->fits($selected, $input->at->toDateString(), $requirement['id'], $requirement['evidence_kind'])
                            ? 'confirmed_for_use' : 'needs_reconfirmation';
                    } elseif ($suggestions !== []) {
                        $readiness = 'reported_available';
                    }
                    if ($permitted) {
                        $inventory[$selected->id] = $this->item($selected);
                    }
                    $rows[] = [...$requirement, 'process_id' => $processId, 'occurrence_key' => $proposal['occurrence_key'],
                        'readiness' => $readiness, 'evidence_id' => $permitted ? $selected->id : null,
                        'suggested_evidence_ids' => $suggestions, 'confirmation_basis' => 'user_confirmation_only'];
                }
            }

            return ['schema_version' => 'bureaucracy.paperwork.1', 'person_id' => $person->id, 'jurisdiction' => $input->jurisdiction,
                'requirements' => $rows, 'evidence' => array_values($inventory), 'evaluated_at' => $input->at->toIso8601String(),
                'capabilities' => array_fill_keys(['translation', 'email_writing', 'tax_preparation', 'upload', 'ocr'], ['available' => false, 'reason' => 'not_implemented'])];
        });
    }

    private function item(BureaucracyEvidenceItem $item): array
    {
        return ['id' => $item->id, 'person_id' => $item->person_id, 'version' => $item->version, 'details' => $item->details, 'storage' => 'metadata_only'];
    }
}
