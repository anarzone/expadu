<?php

namespace App\Http\Controllers\Api\V1;

use App\Composer\WorkspaceStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DayCommandRequest;
use Illuminate\Http\JsonResponse;

class DayController extends Controller
{
    /**
     * One change to the day, as the web's Today makes it (ComposerWorkspaceController::update):
     * the new revision and what changed, so the app can say so with an Undo; a change that can't
     * go in comes back 422 with why, and nothing changes.
     */
    public function command(DayCommandRequest $request, WorkspaceStore $store): JsonResponse
    {
        $workspace = $store->command($request->user(), $request->validated());
        $refusal = $workspace['refusal'] ?? null;
        $change = collect($workspace['changes'] ?? [])->last();

        return $refusal === null
            ? response()->json([
                'revision' => (int) $workspace['revision'],
                /** @var array{id: string, label: string, undoable: bool}|null */
                'change' => is_array($change) ? ['id' => (string) $change['id'], 'label' => (string) $change['label'], 'undoable' => (bool) ($change['undoable'] ?? false)] : null,
            ])
            : response()->json(['message' => $refusal['message'], 'errors' => ['action' => [$refusal['message']]]], 422);
    }
}
