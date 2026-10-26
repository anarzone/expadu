<?php

namespace App\Http\Controllers;

use App\Http\Requests\OnboardingRequest;
use App\Onboarding\ApplyOnboardingAnswers;
use Illuminate\Http\RedirectResponse;

class OnboardingController extends Controller
{
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
