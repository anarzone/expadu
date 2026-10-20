<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\People\PersonAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\AcceptFamilyInvitationRequest;
use App\Http\Requests\Bureaucracy\V2\InspectFamilyInvitationRequest;
use App\Http\Requests\Bureaucracy\V2\InviteFamilyMemberRequest;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyInvitation;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DelegationController extends Controller
{
    public function __construct(private ManageDelegation $delegation, private PersonAccess $access) {}

    public function index(Request $request, BureaucracyPerson $person): JsonResponse
    {
        $manages = $this->access->canManage($request->user(), $person);
        $grants = $person->grants()->when(! $manages, fn ($query) => $query->where('grantee_user_id', $request->user()->id))->orderByDesc('id')->get();
        abort_if(! $manages && $grants->isEmpty(), 404);

        return response()->json(['grants' => $grants->map(fn ($grant) => [
            'id' => $grant->id, 'scopes' => $grant->scopes,
            'is_my_access' => $grant->grantee_user_id === $request->user()->id,
            'accepted_at' => $grant->accepted_at->toIso8601String(),
            'expires_at' => $grant->expires_at->toIso8601String(),
            'revoked_at' => $grant->revoked_at?->toIso8601String(),
        ])])->header('Cache-Control', 'private, no-store');
    }

    public function store(InviteFamilyMemberRequest $request): JsonResponse
    {
        $data = $request->validated();
        $workspace = BureaucracyWorkspace::query()->whereKey($data['workspace_id'])->where('owner_user_id', $request->user()->id)->firstOrFail();
        $created = $this->delegation->invite($request->user(), $workspace, $data['email'], $data['scopes']);

        return response()->json(['invitation_id' => $created['invitation']->id, 'token' => $created['token'], 'expires_at' => $created['invitation']->expires_at->toIso8601String()], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function inspect(InspectFamilyInvitationRequest $request): JsonResponse
    {
        return response()->json(['invitation' => $this->delegation->inspect($request->user(), $request->validated('token'))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function accept(AcceptFamilyInvitationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $person = $this->delegation->accept($request->user(), $data['token'], $data['scopes']);

        return response()->json(['person_id' => $person->id, 'record_version' => $person->record_version])
            ->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request, BureaucracyAccessGrant $grant): Response
    {
        $this->delegation->revoke($request->user(), $grant);

        return response()->noContent();
    }

    public function cancel(Request $request, BureaucracyInvitation $invitation): Response
    {
        $this->delegation->cancelInvitation($request->user(), $invitation);

        return response()->noContent();
    }
}
