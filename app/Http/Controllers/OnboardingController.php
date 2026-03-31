<?php

namespace App\Http\Controllers;

use App\Http\Requests\OnboardingRequest;
use Illuminate\Http\RedirectResponse;

class OnboardingController extends Controller
{
    public function complete(OnboardingRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->update([
            ...$request->validated(),
            'onboarded_at' => now(),
        ]);

        // Auto-create required Home + Work places if they don't exist
        if (! $user->places()->where('category', 'home')->exists()) {
            $user->places()->create([
                'emoji' => '🏠',
                'name' => 'Home',
                'category' => 'home',
                'sort_order' => 0,
            ]);
        }

        if (! $user->places()->where('category', 'work')->exists()) {
            $user->places()->create([
                'emoji' => '💼',
                'name' => 'Work',
                'category' => 'work',
                'sort_order' => 1,
            ]);
        }

        return redirect()->route('dashboard');
    }
}
