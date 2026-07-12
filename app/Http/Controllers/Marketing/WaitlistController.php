<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Mail\WaitlistConfirmation;
use App\Models\WaitlistSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class WaitlistController extends Controller
{
    /**
     * Store a city-waitlist signup and send the double-opt-in mail.
     *
     * Idempotent per e-mail: resubmitting updates the city and re-sends the
     * confirmation while unconfirmed; a confirmed address gets a friendly
     * "already on the list" and no further mail.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'source' => ['nullable', 'string', 'max:60'],
        ]);

        $signup = WaitlistSignup::query()->firstOrNew(['email' => mb_strtolower($validated['email'])]);

        if ($signup->isConfirmed()) {
            return response()->json([
                'message' => 'You’re already on the list — we’ll be in touch.',
            ]);
        }

        $signup->fill([
            'city' => $validated['city'],
            'source' => $validated['source'] ?? null,
        ])->save();

        Mail::to($signup->email)->send(new WaitlistConfirmation($signup));

        return response()->json([
            'message' => 'Check your inbox to confirm — then you’re on the list.',
        ], 201);
    }

    /**
     * Complete the double opt-in via the signed link from the mail.
     */
    public function confirm(WaitlistSignup $signup): View
    {
        if (! $signup->isConfirmed()) {
            $signup->forceFill(['confirmed_at' => now()])->save();
        }

        return view('marketing.waitlist-confirmed', ['signup' => $signup]);
    }
}
