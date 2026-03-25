<?php

namespace App\Http\Controllers;

use App\Http\Requests\OnboardingRequest;
use Illuminate\Http\RedirectResponse;

class OnboardingController extends Controller
{
    public function complete(OnboardingRequest $request): RedirectResponse
    {
        $request->user()->update([
            ...$request->validated(),
            'onboarded_at' => now(),
        ]);

        return redirect()->route('dashboard');
    }
}
