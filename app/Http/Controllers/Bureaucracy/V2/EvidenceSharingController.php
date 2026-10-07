<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\Evidence\ShareEvidence;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\People\PersonCommandScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\EvidenceOwnerRequest;
use App\Http\Requests\Bureaucracy\V2\ShareEvidenceRequest;
use App\Models\BureaucracyEvidenceItem;
use App\Models\BureaucracyEvidenceShare;
use App\Models\BureaucracyProcess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class EvidenceSharingController extends Controller
{
    public function index(EvidenceOwnerRequest $request, BureaucracyEvidenceItem $evidence, PersonCommandScope $scope, PersonAccess $access): JsonResponse
    {
        $shares = $scope->run($request->user(), $evidence->person, AccessScope::ManageEvidence, function ($person) use ($request, $evidence, $access): array {
            abort_unless($access->canManage($request->user(), $person), 403);

            return BureaucracyEvidenceShare::query()->where('evidence_id', $evidence->id)->orderBy('id')->get()->map(fn ($share) => [
                'id' => $share->id, 'process_id' => $share->process_id, 'requirement_id' => $share->requirement_id,
                'evidence_version' => $share->evidence_version, 'expires_at' => $share->expires_at->toIso8601String(),
                'revoked_at' => $share->revoked_at?->toIso8601String(),
            ])->all();
        });

        return response()->json(['notice_version' => ShareEvidence::NoticeVersion,
            'notice' => 'Share this document record only for the selected person, process and requirement until the date you choose. Their authorised helpers may see it for that use. No file is uploaded or sent to AI. You can withdraw sharing here.',
            'shares' => $shares])->header('Cache-Control', 'private, no-store');
    }

    public function store(ShareEvidenceRequest $request, BureaucracyEvidenceItem $evidence, ShareEvidence $sharing): JsonResponse
    {
        $data = $request->validated();
        $share = $sharing->execute($request->user(), $evidence, BureaucracyProcess::query()->findOrFail($data['process_id']), $data['requirement_id'],
            (int) $data['evidence_version'], $data['requirement_hash'], $data['expires_on'], $data['request_id']);

        return response()->json(['share_id' => $share->id, 'expires_at' => $share->expires_at->toIso8601String(), 'revoked_at' => $share->revoked_at?->toIso8601String()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function destroy(EvidenceOwnerRequest $request, BureaucracyEvidenceItem $evidence, BureaucracyEvidenceShare $share, ShareEvidence $sharing): Response
    {
        abort_unless($share->evidence_id === $evidence->id, 404);
        $sharing->revoke($request->user(), $share);

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }
}
