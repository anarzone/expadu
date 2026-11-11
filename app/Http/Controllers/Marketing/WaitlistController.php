<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Mail\WaitlistConfirmation;
use App\Marketing\WaitlistGuard;
use App\Models\WaitlistSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class WaitlistController extends Controller
{
    /**
     * Store a launch-waitlist signup and send the double-opt-in mail.
     *
     * Idempotent per e-mail: resubmitting updates the city and re-sends the
     * confirmation while unconfirmed, at most once a day; a confirmed address
     * gets a friendly "already on the list" and no further mail. A post the
     * guard takes for a script gets the usual reply and changes nothing.
     */
    public function store(Request $request, WaitlistGuard $guard): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            // A city, not an ad: scripts paste links and markup in here.
            'city' => ['required', 'string', 'max:120', 'not_regex:/https?:|www\.|[<>]/i'],
            'source' => ['nullable', 'string', 'max:60'],
        ]);

        $suspicion = $guard->suspicion($request);

        if ($suspicion !== null) {
            Log::info('waitlist.blocked', ['reason' => $suspicion, 'source' => $validated['source'] ?? null]);

            return $this->checkYourInbox();
        }

        if ($guard->expired($request)) {
            return response()->json([
                'message' => 'This page has been open a long time. Reload it and try again.',
            ], 422);
        }

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

        if ($guard->claimMail($signup->email)) {
            Mail::to($signup->email)->send(new WaitlistConfirmation($signup));
        }

        return $this->checkYourInbox();
    }

    private function checkYourInbox(): JsonResponse
    {
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
