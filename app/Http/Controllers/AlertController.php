<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request): Response
    {
        $query = $request->user()->alerts()->orderByDesc('created_at');

        if ($tab = $request->query('tab')) {
            if (in_array($tab, ['system', 'social', 'reminder'])) {
                $query->where('type', $tab);
            }
        }

        return Inertia::render('alerts', [
            'alerts' => $query->paginate(30),
            'unreadCount' => $request->user()->alerts()->whereNull('read_at')->count(),
            'tab' => $request->query('tab', 'all'),
        ]);
    }

    public function markRead(Request $request, Alert $alert): RedirectResponse
    {
        if ($alert->user_id === $request->user()->id) {
            $alert->update(['read_at' => now()]);
        }

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->alerts()->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }
}
