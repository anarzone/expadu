<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Personalisation\Memory;
use App\Personalisation\TasteFacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "What Expadu knows about you": what Composer remembers (Memory), what personalisation learned
 * they like and skip (TasteFacts), and their situation and German level. Every fact can be added,
 * changed or forgotten here; all of it needs personalisation on, and turning it off erases it.
 */
class MemoryController extends Controller
{
    public function __construct(private Memory $memory) {}

    /**
     * Everything Expadu knows about them.
     */
    public function index(Request $request, TasteFacts $taste): JsonResponse
    {
        $user = $request->user();
        $personal = $user->personalises();

        return response()->json([
            'personalised' => $personal,
            /** @var list<array{id: int, text: string, source: string, created_at: string}> What Composer remembers, oldest first: told (said to Composer) or added (typed here). */
            'facts' => $personal ? $this->memory->facts($user) : [],
            /** @var array{categories: list<array{name: string, level: string}>, veedels: list<array{name: string, level: string}>, dayparts: list<string>, week: string|null, signals: int}|null What they tend to like and skip, as Composer reads it; null when off or too little yet. */
            'taste' => $personal ? $taste->for($user) : null,
            'situation' => $user->situation?->value,
            'german_level' => $user->german_level?->value,
            'limit' => Memory::LIMIT,
        ]);
    }

    /**
     * Something for Composer to remember, typed on the screen. Refused with why (422): personalisation
     * off, a special category, already remembered, or full.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['text' => ['required', 'string', 'max:'.Memory::LENGTH]]);

        return $this->kept($this->memory->remember($request->user(), $validated['text'], 'added'), 201);
    }

    /**
     * A fact's words, changed.
     */
    public function update(Request $request, int $fact): JsonResponse
    {
        $validated = $request->validate(['text' => ['required', 'string', 'max:'.Memory::LENGTH]]);
        $changed = $this->memory->change($request->user(), $fact, $validated['text']);
        abort_if($changed === Memory::GONE, 404);

        return $this->kept($changed, 200);
    }

    /**
     * A fact forgotten; what it was comes back, so Undo can bring it back.
     */
    public function destroy(Request $request, int $fact): JsonResponse
    {
        $gone = $this->memory->forget($request->user(), $fact) ?? abort(404);

        return response()->json([
            /** @var array{id: int, text: string, source: string, created_at: string} */
            'forgotten' => $gone,
        ]);
    }

    /**
     * Undo of a forget: the fact back in its place, under a new id.
     */
    public function restore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:'.Memory::LENGTH],
            'source' => ['nullable', 'string', 'in:told,added'],
            'created_at' => ['nullable', 'date'],
        ]);

        return $this->kept($this->memory->restore($request->user(), $validated), 201);
    }

    /** The fact as kept, or why not (422). */
    private function kept(array|string $kept, int $status): JsonResponse
    {
        if (is_string($kept)) {
            return response()->json(['message' => $kept], 422);
        }

        return response()->json([
            /** @var array{id: int, text: string} */
            'fact' => ['id' => (int) $kept['id'], 'text' => (string) $kept['text']],
        ], $status);
    }
}
