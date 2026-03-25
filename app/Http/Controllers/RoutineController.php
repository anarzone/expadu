<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RoutineController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'emoji' => ['nullable', 'string', 'max:20'],
            'from_stop' => ['required', 'string', 'max:100'],
            'to_stop' => ['required', 'string', 'max:100'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'departure_time' => ['required', 'string', 'date_format:H:i'],
        ]);

        $request->user()->routines()->create($validated);

        return back();
    }

    public function update(Request $request, Routine $routine): RedirectResponse
    {
        Gate::authorize('update', $routine);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'emoji' => ['nullable', 'string', 'max:20'],
            'from_stop' => ['sometimes', 'string', 'max:100'],
            'to_stop' => ['sometimes', 'string', 'max:100'],
            'days' => ['sometimes', 'array', 'min:1'],
            'days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'departure_time' => ['sometimes', 'string', 'date_format:H:i'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $routine->update($validated);

        return back();
    }

    public function destroy(Routine $routine): RedirectResponse
    {
        Gate::authorize('delete', $routine);

        $routine->delete();

        return back();
    }
}
