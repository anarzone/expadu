<?php

namespace App\Bureaucracy\Evidence;

use App\Bureaucracy\People\PersonAccess;
use App\Models\BureaucracyEvidenceItem;
use App\Models\BureaucracyEvidenceShare;
use App\Models\User;

final class EvidenceAccess
{
    public function __construct(private PersonAccess $access) {}

    /** Caller already has the target's manage_evidence access. Family membership alone grants no reuse. */
    public function shareFor(BureaucracyEvidenceItem $item, int $processId, string $requirementId, string $requirementHash): ?BureaucracyEvidenceShare
    {
        if ($item->person->record_status !== 'active') {
            return null;
        }

        return BureaucracyEvidenceShare::query()->where('evidence_id', $item->id)->where('evidence_version', $item->version)
            ->where('process_id', $processId)->where('requirement_id', $requirementId)->whereNull('revoked_at')
            ->where('requirement_hash', $requirementHash)->where('notice_version', ShareEvidence::NoticeVersion)
            ->where('expires_at', '>', now()->utc())->where('confirmed_at', '<=', now()->utc())->orderByDesc('id')->get()
            ->first(function ($share) use ($item): bool {
                $grantor = $share->grantor_id === null ? null : User::query()->find($share->grantor_id);

                return $grantor !== null && $this->access->canManage($grantor, $item->person);
            });
    }

    /**
     * Usable for one requirement: the document kind matches the reviewed evidence kind, or the person
     * explicitly attached the item to that requirement. Neither is a confirmation of use.
     */
    public function fits(BureaucracyEvidenceItem $item, string $today, string $requirementId, ?string $kind): bool
    {
        return $this->usable($item, $today, null)
            && ($kind === null || ($item->details['kind'] ?? null) === $kind || in_array($requirementId, $item->details['requirement_refs'] ?? [], true));
    }

    /** Explicitly attached by the person, or a reviewed evidence-kind match: worth suggesting. */
    public function suggests(BureaucracyEvidenceItem $item, string $today, string $requirementId, ?string $kind): bool
    {
        return $this->usable($item, $today, null)
            && (($kind !== null && ($item->details['kind'] ?? null) === $kind) || in_array($requirementId, $item->details['requirement_refs'] ?? [], true));
    }

    public function usable(BureaucracyEvidenceItem $item, string $today, ?string $kind): bool
    {
        $details = $item->details;

        return $item->status === 'active' && ($details['reported_available'] ?? false) === true
            && ($kind === null || ($details['kind'] ?? null) === $kind)
            && (($details['expires_on'] ?? null) === null || $details['expires_on'] >= $today);
    }
}
