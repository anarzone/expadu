<?php

namespace App\Http\Controllers;

use App\Services\BuergeramtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SlotMonitorController extends Controller
{
    public function toggle(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'office_id' => ['required', 'string', Rule::in(array_keys(BuergeramtService::OFFICES))],
        ]);

        $monitor = $request->user()->slotMonitors()->firstOrCreate(
            ['office_id' => $validated['office_id']],
            ['service_type' => 'anmeldung', 'is_active' => true],
        );

        // If it already existed, toggle the is_active flag
        if (! $monitor->wasRecentlyCreated) {
            $monitor->update(['is_active' => ! $monitor->is_active]);
        }

        return back();
    }
}
