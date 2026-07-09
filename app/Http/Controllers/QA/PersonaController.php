<?php

namespace App\Http\Controllers\QA;

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\PathGenerator;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Admin-only "become this persona" QA switcher: flips the CURRENT
 * logged-in account's profile columns to a BureaucracyPersonas roster entry
 * with REAL writes, so the whole app (bureaucracy, Today, onboarding) can be
 * exercised as that expat type. Unlike BureaucracyDemoController — which
 * only ever renders an in-memory persona and writes nothing — this mutates
 * the acting user's own row.
 */
class PersonaController extends Controller
{
    /**
     * Persist a roster persona's profile onto the current user and
     * materialise their bureaucracy path.
     */
    public function become(Request $request, string $persona): RedirectResponse
    {
        abort_unless($request->user()?->is_admin || app()->environment('local'), 403);

        $match = collect(BureaucracyPersonas::demo())->firstWhere('key', $persona);
        abort_if($match === null, 404);

        $user = $request->user();
        $user->forceFill(BureaucracyPersonas::persistableProfile($match))->save();

        // Mirrors OnboardingController::complete() — the rest of the app
        // (commute tiles, "take me there") assumes Home + Work exist.
        $user->places()->firstOrCreate(
            ['category' => 'home'],
            ['emoji' => '🏠', 'name' => 'Home', 'sort_order' => 0],
        );
        $user->places()->firstOrCreate(
            ['category' => 'work'],
            ['emoji' => '💼', 'name' => 'Work', 'sort_order' => 1],
        );

        try {
            app(PathGenerator::class)->ensure($user);
        } catch (Throwable $e) {
            report($e);
        }

        return back()->with('status', "Now testing as: {$match['label']}");
    }

    /**
     * Wipe the current user's task progress so a persona switch (or a
     * re-test of the same persona) starts from a clean checklist.
     */
    public function resetTasks(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->is_admin || app()->environment('local'), 403);

        $request->user()->userTasks()->delete();

        return back()->with('status', 'Task progress reset.');
    }
}
