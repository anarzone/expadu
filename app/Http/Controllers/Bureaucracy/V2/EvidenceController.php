<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\Evidence\PaperworkReadModel;
use App\Bureaucracy\Evidence\RecordEvidence;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\ReadPaperworkRequest;
use App\Http\Requests\Bureaucracy\V2\RecordEvidenceRequest;
use App\Models\BureaucracyPerson;
use Illuminate\Http\JsonResponse;

class EvidenceController extends Controller
{
    public function index(ReadPaperworkRequest $request, BureaucracyPerson $person, PaperworkReadModel $paperwork): JsonResponse
    {
        return response()->json($paperwork->for($request->user(), $person, $request->validated('jurisdiction')))->header('Cache-Control', 'private, no-store');
    }

    public function update(RecordEvidenceRequest $request, BureaucracyPerson $person, string $evidenceId, RecordEvidence $evidence): JsonResponse
    {
        $data = $request->validated();
        $item = $evidence->execute($request->user(), $person, $evidenceId, $data['details'], (int) $data['expected_version'], $data['request_id'], $data['status'] ?? 'active');

        return response()->json(['id' => $item->id, 'version' => $item->version, 'status' => $item->status])->header('Cache-Control', 'private, no-store');
    }
}
