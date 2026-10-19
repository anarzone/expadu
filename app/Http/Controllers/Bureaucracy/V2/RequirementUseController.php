<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\Evidence\ConfirmRequirementUse;
use App\Bureaucracy\Evidence\WithdrawRequirementUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\ConfirmRequirementUseRequest;
use App\Http\Requests\Bureaucracy\V2\WithdrawRequirementUseRequest;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyRequirementUse;
use Illuminate\Http\JsonResponse;

class RequirementUseController extends Controller
{
    public function store(ConfirmRequirementUseRequest $request, BureaucracyProcess $process, string $requirement, ConfirmRequirementUse $confirm): JsonResponse
    {
        $data = $request->validated();

        return $this->receipt($confirm->execute($request->user(), $process, $requirement, $data['evidence_id'], (int) $data['expected_version'],
            (int) $data['evidence_version'], $data['requirement_hash'], $data['request_id']));
    }

    public function destroy(WithdrawRequirementUseRequest $request, BureaucracyProcess $process, string $requirement, WithdrawRequirementUse $withdraw): JsonResponse
    {
        $data = $request->validated();

        return $this->receipt($withdraw->execute($request->user(), $process, $requirement, (int) $data['expected_version'], $data['request_id']));
    }

    private function receipt(BureaucracyRequirementUse $use): JsonResponse
    {
        return response()->json(['use_id' => $use->id, 'process_id' => $use->process_id, 'version' => $use->process_version])->header('Cache-Control', 'private, no-store');
    }
}
