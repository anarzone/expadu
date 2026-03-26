<?php

namespace App\Http\Controllers;

use App\Services\BuergeramtService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BureaucracyController extends Controller
{
    public function index(Request $request, BuergeramtService $buergeramtService): Response
    {
        $slots = $buergeramtService->checkSlots();

        $monitors = $request->user()
            ->slotMonitors()
            ->where('is_active', true)
            ->pluck('office_id')
            ->all();

        return Inertia::render('bureaucracy', [
            'slots' => $slots,
            'monitors' => $monitors,
        ]);
    }
}
