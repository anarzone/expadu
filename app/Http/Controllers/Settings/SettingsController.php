<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\ComposerPaperworkPermissionController;
use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Personalisation\Learned;
use App\Personalisation\TodayRankPause;
use App\Privacy\ProcessingOptIns;
use App\Privacy\ProcessingPurpose;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings (owner, 26 Oct 2026: direction A): one page, /settings and
 * /settings/{section}. On a laptop a side menu lists every section with
 * where it stands and the section opens beside it (Your account when none
 * is named); on a phone /settings is that list and a section opens on its
 * own. The panels read the shared auth and userSettings props; this adds
 * what only Settings shows.
 */
class SettingsController extends Controller
{
    /** Every section, in the menu's order. */
    public const SECTIONS = ['account', 'city', 'privacy', 'composer', 'notifications', 'transit', 'data', 'delete'];

    /** Sections whose endpoints need a verified email ('verified'), so they do too. */
    private const VERIFIED = ['data', 'delete'];

    public function __invoke(Request $request, ProcessingOptIns $optIns, ?string $section = null): Response|RedirectResponse
    {
        $user = $request->user();
        if (in_array($section, self::VERIFIED, true) && $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return Inertia::render('settings', [
            'section' => $section,
            'notifications' => fn () => NotificationPreference::stateFor($user),
            // The way home: Work's usual leaving time, which sets the evening's
            // delay and weather window (CommuteWindows); null without a Work place.
            'work' => function () use ($user): ?array {
                $work = $user->places()->where('category', 'work')->first(['leave_at']);

                return $work === null ? null : ['leave_at' => $work->leave_at === null ? null : substr((string) $work->leave_at, 0, 5)];
            },
            // Today put in order by AI: a standing opt-in of its own (TodayRank).
            'todayRank' => fn () => $optIns->active($user, ProcessingPurpose::TodayRank),
            // Or waiting for them to agree again after its notice changed (TodayRankPause): what changed.
            'todayRankPaused' => fn () => app(TodayRankPause::class)->for($user),
            // Privacy: what personalisation learned, as its page says it (Learned); null when it's off.
            'learned' => fn () => app(Learned::class)->for($user),
            // Composer's remembered Allow for their Paperwork record, so its row comes with the page.
            'composerPaperwork' => fn () => ComposerPaperworkPermissionController::state($user, $optIns),
        ]);
    }
}
