<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\PersonAccess;
use App\Http\Controllers\Controller;
use App\Models\BureaucracyPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeopleController extends Controller
{
    public function __construct(private PersonAccess $access, private EnsureAccountHolder $holders) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $page = BureaucracyPerson::query()->where('record_status', 'active')
            ->where(fn ($query) => $query->where('account_user_id', $actor->id)
                ->orWhereHas('grants', fn ($grants) => $grants->where('grantee_user_id', $actor->id)->whereNull('revoked_at')->where('expires_at', '>', now()->utc())))
            ->orderBy('id')->cursorPaginate(30);

        return response()->json([
            'people' => $page->getCollection()->map(fn ($person) => $this->present($request, $person))->filter()->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $case = $this->holders->dossier($request->user());

        return response()->json(['person' => $this->present($request, $case->person)])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, BureaucracyPerson $person): JsonResponse
    {
        $data = $this->present($request, $person);
        abort_if($data === null, 404);

        return response()->json(['person' => $data])->header('Cache-Control', 'private, no-store');
    }

    private function present(Request $request, BureaucracyPerson $person): ?array
    {
        $scopes = $this->access->scopesFor($request->user(), $person);
        if ($scopes === []) {
            return null;
        }

        return [
            'id' => $person->id, 'label' => $person->display_label, 'kind' => $person->kind,
            'is_self' => $person->account_user_id === $request->user()->id,
            'workspace_id' => $person->account_user_id === $request->user()->id ? $person->workspace_id : null,
            'record_version' => $person->record_version, 'scopes' => $scopes,
            'can_manage_sharing' => $this->access->canManage($request->user(), $person),
        ];
    }
}
