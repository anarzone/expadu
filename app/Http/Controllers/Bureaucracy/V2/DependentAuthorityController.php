<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\People\ManageDependents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\RequestDependentAuthorityRequest;
use App\Http\Requests\Bureaucracy\V2\ReviewDependentAuthorityRequest;
use App\Models\BureaucracyGuardianAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DependentAuthorityController extends Controller
{
    public function __construct(private ManageDependents $dependents) {}

    public function store(RequestDependentAuthorityRequest $request): JsonResponse
    {
        $authority = $this->dependents->request($request->user(), $request->validated('label'));

        return response()->json(['request_id' => $authority->id, 'status' => 'pending_authority_review', 'access_granted' => false], 202)
            ->header('Cache-Control', 'private, no-store');
    }

    public function approve(ReviewDependentAuthorityRequest $request, BureaucracyGuardianAuthority $authority): Response
    {
        $data = $request->validated();
        $this->dependents->review($request->user(), $authority, $data['policy_version'], $data['evidence_reference'], CarbonImmutable::parse($data['expires_at']));

        return response()->noContent();
    }

    public function revoke(Request $request, BureaucracyGuardianAuthority $authority): Response
    {
        $this->dependents->revoke($request->user(), $authority);

        return response()->noContent();
    }
}
