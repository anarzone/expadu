<?php

namespace App\Http\Controllers;

use App\Http\Requests\OnboardingRequest;
use App\Onboarding\ApplyOnboardingAnswers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('onboarding', [
            'veedels' => config('veedels'),
        ]);
    }

    /**
     * Self-serve "redo my onboarding" — the safe variant of the admin reset.
     * Only onboarded_at is cleared: answers are re-asked and overwritten,
     * the engine recomputes the path, and NOTHING the user did is deleted
     * (out-of-path progress lands in the "no longer relevant" lane).
     */
    public function restart(Request $request): RedirectResponse
    {
        $request->user()->update(['onboarded_at' => null]);

        return redirect()->route('onboarding');
    }

    public function complete(OnboardingRequest $request, ApplyOnboardingAnswers $applyOnboardingAnswers): RedirectResponse
    {
        $applyOnboardingAnswers->execute($request->user(), $request->validated());
        $request->user()->refresh();

        $intended = $request->session()->pull('url.intended');
        $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;
        $host = is_string($intended) ? parse_url($intended, PHP_URL_HOST) : null;
        $scheme = is_string($intended) ? parse_url($intended, PHP_URL_SCHEME) : null;

        if (is_string($path)
            && preg_match('#^/(dashboard|events|explore|services|timetable|bureaucracy|composer|profile)(?:/|$)#', $path)
            && ($host === null || $host === $request->getHost())
            && ($scheme === null || in_array($scheme, ['http', 'https'], true))) {
            $query = parse_url($intended, PHP_URL_QUERY);

            return redirect($path.($query ? '?'.$query : ''));
        }

        return redirect()->route('dashboard');
    }
}
