<?php

namespace App\Http\Controllers;

use App\Models\BureaucracyPerson;
use App\Models\User;
use App\Privacy\ProcessingOptIns;
use App\Privacy\ProcessingPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Composer's remembered Allow for their Paperwork record (owner, 27 Oct 2026: "It should remember
 * it"), for Settings › Composer: whether it's on, whether it can be, and the notice it's on under;
 * on with the notice they were shown, off at once. Composer's own card turns it on too.
 */
class ComposerPaperworkPermissionController extends Controller
{
    public function show(Request $request, ProcessingOptIns $optIns): JsonResponse
    {
        return response()->json(self::state($request->user(), $optIns));
    }

    /** @return array{enabled: bool, available: bool, has_record: bool, disclosure: mixed} */
    public static function state(User $user, ProcessingOptIns $optIns): array
    {
        $purpose = ProcessingPurpose::ComposerPaperwork;

        return [
            'enabled' => $optIns->active($user, $purpose),
            'available' => $purpose->available(),
            // Nothing for Composer to read without a record in use.
            'has_record' => BureaucracyPerson::query()->where('account_user_id', $user->id)->where('record_status', 'active')
                ->whereNull('processing_stopped_at')->exists(),
            'disclosure' => $purpose->disclosure(),
        ];
    }

    public function update(Request $request, ProcessingOptIns $optIns): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            // The notice they were shown (show()'s disclosure), to turn it on.
            'notice_version' => ['required_if:enabled,true', 'nullable', 'string', 'max:100'],
            'provider_version' => ['required_if:enabled,true', 'nullable', 'string', 'max:64'],
        ]);
        $purpose = ProcessingPurpose::ComposerPaperwork;
        if ($validated['enabled']) {
            $optIns->turnOn($request->user(), $purpose, $validated['notice_version'], $validated['provider_version']);

            return response()->json(['enabled' => true]);
        }
        $inFlight = $optIns->turnOff($request->user(), $purpose);

        return response()->json(['enabled' => false, 'in_flight' => $inFlight,
            'message' => 'Composer no longer reads or changes your Paperwork record. A request already sent may finish at the AI service, but it won’t be used.']);
    }
}
