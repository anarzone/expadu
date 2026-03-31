<?php

namespace App\Http\Controllers;

use App\Models\UserPlace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserPlaceController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'arrive_by' => ['nullable', 'date_format:H:i'],
            'day_mode' => ['nullable', 'in:all,weekdays,weekends,custom'],
            'active_days' => ['nullable', 'array'],
            'active_days.*' => ['in:mon,tue,wed,thu,fri,sat,sun'],
        ]);

        $request->user()->places()->create([
            ...$validated,
            'sort_order' => $request->user()->places()->count(),
        ]);

        return back();
    }

    public function update(Request $request, UserPlace $userPlace): RedirectResponse
    {
        if ($userPlace->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'emoji' => ['sometimes', 'string', 'max:10'],
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'arrive_by' => ['nullable', 'date_format:H:i'],
            'day_mode' => ['nullable', 'in:all,weekdays,weekends,custom'],
            'active_days' => ['nullable', 'array'],
            'active_days.*' => ['in:mon,tue,wed,thu,fri,sat,sun'],
        ]);

        // Prevent changing category of protected places
        if ($userPlace->isProtected()) {
            unset($validated['category']);
        }

        $userPlace->update($validated);

        return back();
    }

    public function destroy(Request $request, UserPlace $userPlace): RedirectResponse
    {
        if ($userPlace->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($userPlace->isProtected()) {
            return back()->withErrors(['place' => 'Home and Work places cannot be removed — they are needed for route recommendations.']);
        }

        $userPlace->delete();

        return back();
    }
}
