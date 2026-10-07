<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\Api\EventReminderController;
use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\JourneySuggestController;
use App\Http\Controllers\Api\LocationConfirmController;
use App\Http\Controllers\Api\NearbyDeparturesController;
use App\Http\Controllers\Api\PlaceContextController;
use App\Http\Controllers\Api\PlaceFeedbackController;
use App\Http\Controllers\Api\PlacesController;
use App\Http\Controllers\Api\ReverseGeocodeController;
use App\Http\Controllers\Api\SpotSearchController;
use App\Http\Controllers\Api\StopSearchController;
use App\Http\Controllers\Api\TakeMeThereController;
use App\Http\Controllers\Api\TrackEventController;
use App\Http\Controllers\Api\TransportModeController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Bureaucracy\AiConsentController;
use App\Http\Controllers\Bureaucracy\CaseMessageController;
use App\Http\Controllers\Bureaucracy\V2\AccountPlanController;
use App\Http\Controllers\Bureaucracy\V2\DelegationController;
use App\Http\Controllers\Bureaucracy\V2\DependentAuthorityController;
use App\Http\Controllers\Bureaucracy\V2\EvidenceController;
use App\Http\Controllers\Bureaucracy\V2\EvidenceSharingController;
use App\Http\Controllers\Bureaucracy\V2\FactConflictController;
use App\Http\Controllers\Bureaucracy\V2\FactExtractionController;
use App\Http\Controllers\Bureaucracy\V2\FactSchemaController;
use App\Http\Controllers\Bureaucracy\V2\OnboardingDraftController;
use App\Http\Controllers\Bureaucracy\V2\PeopleController;
use App\Http\Controllers\Bureaucracy\V2\PersonDataController;
use App\Http\Controllers\Bureaucracy\V2\PersonFactController;
use App\Http\Controllers\Bureaucracy\V2\PersonPlanController;
use App\Http\Controllers\Bureaucracy\V2\ProcessController;
use App\Http\Controllers\Bureaucracy\V2\QuestionSessionController;
use App\Http\Controllers\Bureaucracy\V2\RelationshipController;
use App\Http\Controllers\Bureaucracy\V2\RequirementUseController;
use App\Http\Controllers\Bureaucracy\V2\ScenarioPreviewController;
use App\Http\Controllers\BureaucracyCaseConflictController;
use App\Http\Controllers\BureaucracyCaseQuestionController;
use App\Http\Controllers\BureaucracyCaseTaskController;
use App\Http\Controllers\BureaucracyCaseTaskDocumentsController;
use App\Http\Controllers\BureaucracyController;
use App\Http\Controllers\BureaucracyDemoController;
use App\Http\Controllers\ComposerController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeFeedController;
use App\Http\Controllers\Marketing\BlogController;
use App\Http\Controllers\Marketing\LandingController;
use App\Http\Controllers\Marketing\SitemapController;
use App\Http\Controllers\Marketing\ToolsController;
use App\Http\Controllers\Marketing\WaitlistController;
use App\Http\Controllers\MuteController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProcessingNoticeController;
use App\Http\Controllers\ProfileAttributeController;
use App\Http\Controllers\ProfilePageController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\QA\PersonaController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ServicesController;
use App\Http\Controllers\SocialLoginController;
use App\Http\Controllers\SpotController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TileTriageController;
use App\Http\Controllers\TimetableController;
use App\Http\Controllers\UserPlaceController;
use App\Http\Controllers\UserSettingController;
use App\Http\Controllers\UserTaskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Subdomain Routing
|--------------------------------------------------------------------------
|
| Production: marketing routes on expadu.com, app routes on app.expadu.com.
| Local dev: APP_DOMAIN is null, so no subdomain enforcement — all routes
| respond to localhost.
|
*/

$appDomain = config('app.app_domain');
$marketingDomain = config('app.marketing_domain');

// ── Marketing site (expadu.com) ──────────────────────────────────────────
// Server-rendered Blade, not Inertia: Google gets full HTML with zero
// hydration, and the pages stay up even if the app bundle breaks.
$marketingRoutes = function () {
    Route::get('/', LandingController::class)->name('home');

    Route::view('impressum', 'marketing.impressum')->name('impressum');
    Route::view('datenschutz', 'marketing.datenschutz')->name('datenschutz');

    // Free tools — client-side calculators, constants from the app's engines.
    Route::get('tools', [ToolsController::class, 'index'])->name('tools.index');
    Route::get('tools/deutschlandticket-break-even', [ToolsController::class, 'dticket'])->name('tools.dticket');
    Route::get('tools/permanent-residency-timeline', [ToolsController::class, 'residency'])->name('tools.residency');
    Route::get('tools/citizenship-quiz', [ToolsController::class, 'citizenship'])->name('tools.citizenship');
    Route::get('tools/netto-brutto-calculator', [ToolsController::class, 'nettoBrutto'])->name('tools.netto');

    // Blog — markdown guides derived from the sourced bureaucracy catalogue.
    Route::get('blog', [BlogController::class, 'index'])->name('blog.index');
    Route::get('blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

    Route::get('sitemap.xml', SitemapController::class)->name('sitemap');

    // City waitlist — double opt-in: store sends a signed confirmation link.
    Route::post('waitlist', [WaitlistController::class, 'store'])
        ->middleware('throttle:10,1')->name('waitlist.store');
    Route::get('waitlist/confirm/{signup}', [WaitlistController::class, 'confirm'])
        ->middleware('signed')->name('waitlist.confirm');
};

// ── App (app.expadu.com) ─────────────────────────────────────────────────
// Social login — responds on all domains (same as Fortify login/register)
Route::middleware(['guest', 'throttle:social'])->group(function () {
    Route::get('auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])->name('social.redirect');
    Route::get('auth/{provider}/callback', [SocialLoginController::class, 'callback'])->name('social.callback');
});

$appRoutes = function () use ($appDomain) {
    // App root redirects to dashboard (only in production with subdomain)
    if ($appDomain) {
        Route::get('/', fn () => redirect('/dashboard'));
    }

    // throttle:app-writes caps every mutation in the group per user; GETs are
    // exempt inside the limiter, so pages and polling stay unthrottled here.
    Route::middleware(['auth', 'verified', 'throttle:app-writes'])->group(function () {
        // APIs — typeahead/geocoding lookups hit MOTIS/Photon per request,
        // so they carry their own per-user budget on top.
        Route::get('api/geocode', GeocodeController::class)->middleware('throttle:search')->name('api.geocode');
        Route::get('api/reverse-geocode', ReverseGeocodeController::class)->middleware('throttle:search')->name('api.reverse-geocode');
        Route::get('api/stops', StopSearchController::class)->middleware('throttle:search')->name('api.stops');
        Route::get('api/spots', SpotSearchController::class)->middleware('throttle:search')->name('api.spots');
        Route::get('api/places', [PlacesController::class, 'index'])->name('api.places');
        Route::get('api/places/{spot}/context', PlaceContextController::class)->name('api.places.context');
        Route::get('api/places/{spot}/events', [EventsController::class, 'place'])->name('api.places.events');
        Route::get('api/places/{spot}', [PlacesController::class, 'show'])->name('api.places.show');
        Route::post('api/places/{spot}/feedback', PlaceFeedbackController::class)->name('api.places.feedback');
        Route::get('api/events', [EventsController::class, 'index'])->name('api.events');
        Route::get('api/reminders', [EventReminderController::class, 'index'])->name('api.reminders.index');
        Route::post('api/reminders', [EventReminderController::class, 'store'])->name('api.reminders.store');
        Route::delete('api/reminders/{event}', [EventReminderController::class, 'destroy'])->name('api.reminders.destroy');
        Route::post('api/track', TrackEventController::class)->name('api.track');
        // Today "Right now" tile triage — done / snooze / dismiss + per-tile undo.
        Route::post('api/tiles/triage', [TileTriageController::class, 'store'])->name('api.tiles.triage');
        Route::post('api/tiles/triage/undo', [TileTriageController::class, 'undo'])->name('api.tiles.triage.undo');
        Route::get('api/nearby-departures', NearbyDeparturesController::class)->name('api.nearby-departures');
        Route::get('api/journey', TakeMeThereController::class)->middleware('throttle:journey')->name('api.journey');
        Route::get('api/journey/suggest', JourneySuggestController::class)->middleware('throttle:search')->name('api.journey.suggest');
        // Live trip session — persists the chosen journey across screens/closes.
        Route::post('api/trip/start', [TripController::class, 'start'])->name('api.trip.start');
        Route::post('api/trip/end', [TripController::class, 'end'])->name('api.trip.end');
        Route::post('api/location/confirm', LocationConfirmController::class)->name('api.location.confirm');
        Route::post('api/preferences/transport-mode', TransportModeController::class)->name('api.preferences.transport-mode');

        Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding');
        Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
        Route::post('onboarding/restart', [OnboardingController::class, 'restart'])->name('onboarding.restart');

        Route::get('dashboard', HomeFeedController::class)->name('dashboard');

        Route::prefix('bureaucracy/v2')->name('bureaucracy.v2.')->middleware('throttle:bureaucracy-v2')->group(function () {
            Route::get('plan', AccountPlanController::class)->name('plan');
            Route::get('preview/{persona}', ScenarioPreviewController::class)->name('preview');
            Route::get('people', [PeopleController::class, 'index'])->name('people.index');
            Route::get('people/{person}/paperwork', [EvidenceController::class, 'index'])->name('paperwork.index');
            Route::get('evidence/{evidence}/shares', [EvidenceSharingController::class, 'index'])->whereUuid('evidence')->name('evidence.shares.index');
            Route::post('evidence/{evidence}/shares', [EvidenceSharingController::class, 'store'])->whereUuid('evidence')->name('evidence.shares.store');
            Route::delete('evidence/{evidence}/shares/{share}', [EvidenceSharingController::class, 'destroy'])->whereUuid('evidence')->whereNumber('share')->name('evidence.shares.destroy');
            Route::put('people/{person}/evidence/{evidenceId}', [EvidenceController::class, 'update'])->whereUuid('evidenceId')->name('evidence.update');
            Route::post('processes/{process}/requirements/{requirement}/confirm', [RequirementUseController::class, 'store'])->whereNumber('process')->name('requirements.confirm');
            Route::delete('processes/{process}/requirements/{requirement}/confirmation', [RequirementUseController::class, 'destroy'])->whereNumber('process')->name('requirements.withdraw');
            Route::get('processes/{process}', [ProcessController::class, 'show'])->whereNumber('process')->name('processes.show');
            Route::post('processes/{process}/events', [ProcessController::class, 'store'])->whereNumber('process')->name('processes.events');
            Route::post('processes/{process}/review', [ProcessController::class, 'review'])->whereNumber('process')->name('processes.review');
            Route::post('processes/{process}/events/{event}/corrections', [ProcessController::class, 'correct'])->whereNumber(['process', 'event'])->name('processes.correct');
            Route::post('people/self', [PeopleController::class, 'store'])->name('people.self');
            Route::get('people/{person}', [PeopleController::class, 'show'])->name('people.show');
            Route::get('people/{person}/sharing', [DelegationController::class, 'index'])->name('people.sharing');
            Route::get('people/{person}/facts', [PersonFactController::class, 'index'])->name('facts.index');
            Route::get('facts/schema', FactSchemaController::class)->name('facts.schema');
            Route::get('people/{person}/facts/{key}/history', [PersonFactController::class, 'history'])->where('key', '[a-z][a-z0-9_]*')->name('facts.history');
            Route::get('people/{person}/fact-conflicts', [FactConflictController::class, 'index'])->name('fact-conflicts.index');
            Route::post('people/{person}/fact-conflicts/{key}/resolve', [FactConflictController::class, 'resolve'])->name('fact-conflicts.resolve');
            Route::put('people/{person}/facts/{key}', [PersonFactController::class, 'change'])->name('facts.change');
            Route::post('people/{person}/facts/{fact}/corrections', [PersonFactController::class, 'correct'])->whereNumber('fact')->name('facts.correct');
            Route::get('people/{person}/question-preview', [QuestionSessionController::class, 'preview'])->name('questions.preview');
            Route::get('people/{person}/plan', PersonPlanController::class)->name('people.plan');
            Route::post('people/{person}/processes', [ProcessController::class, 'start'])->name('processes.start');
            Route::post('people/{person}/question-sessions', [QuestionSessionController::class, 'store'])->name('question-sessions.store');
            Route::post('question-sessions/{session}/next', [QuestionSessionController::class, 'next'])->name('question-sessions.next');
            Route::post('question-sessions/{session}/answers/{question}', [QuestionSessionController::class, 'answer'])->whereNumber('question')->name('question-sessions.answer');
            Route::post('question-sessions/{session}/defer/{question}', [QuestionSessionController::class, 'defer'])->whereNumber('question')->name('question-sessions.defer');
            Route::post('question-sessions/{session}/resume', [QuestionSessionController::class, 'resume'])->name('question-sessions.resume');
            Route::post('question-sessions/{session}/extract/{question}', [FactExtractionController::class, 'extract'])->whereNumber('question')->middleware('throttle:bureaucracy-extract')->name('questions.extract');
            Route::post('question-sessions/{session}/candidates/{candidate}/confirm', [FactExtractionController::class, 'confirm'])->whereUuid('candidate')->name('questions.confirm-extraction');
            Route::delete('question-sessions/{session}/candidates/{candidate}', [FactExtractionController::class, 'reject'])->whereUuid('candidate')->name('questions.reject-extraction');
            Route::delete('people/{person}/processing', [FactExtractionController::class, 'withdraw'])->name('people.processing.withdraw');
            Route::get('people/{person}/onboarding/draft', [OnboardingDraftController::class, 'show'])->name('onboarding.draft.show');
            Route::put('people/{person}/onboarding/draft', [OnboardingDraftController::class, 'update'])->name('onboarding.draft.update');
            Route::delete('people/{person}/onboarding/draft', [OnboardingDraftController::class, 'destroy'])->name('onboarding.draft.destroy');
            Route::get('people/{person}/onboarding/review', [OnboardingDraftController::class, 'review'])->name('onboarding.review');
            Route::post('people/{person}/onboarding/complete', [OnboardingDraftController::class, 'complete'])->name('onboarding.complete');
            Route::get('people/{person}/relationships', [RelationshipController::class, 'index'])->name('relationships.index');
            Route::post('people/{person}/relationships', [RelationshipController::class, 'store'])->name('relationships.store');
            Route::delete('people/{person}/relationships/{relationship}', [RelationshipController::class, 'destroy'])->whereNumber('relationship')->name('relationships.destroy');
            Route::post('invitations', [DelegationController::class, 'store'])->middleware('throttle:bureaucracy-invitations')->name('invitations.store');
            Route::post('invitations/inspect', [DelegationController::class, 'inspect'])->name('invitations.inspect');
            Route::post('invitations/accept', [DelegationController::class, 'accept'])->name('invitations.accept');
            Route::delete('invitations/{invitation}', [DelegationController::class, 'cancel'])->name('invitations.cancel');
            Route::delete('grants/{grant}', [DelegationController::class, 'revoke'])->name('grants.revoke');
            Route::post('dependents', [DependentAuthorityController::class, 'store'])->middleware('throttle:bureaucracy-dependents')->name('dependents.store');
            Route::post('authorities/{authority}/approve', [DependentAuthorityController::class, 'approve'])->middleware('password.confirm')->name('authorities.approve');
            Route::delete('authorities/{authority}', [DependentAuthorityController::class, 'revoke'])->name('authorities.revoke');
            Route::post('people/{person}/export', [PersonDataController::class, 'export'])->middleware('password.confirm')->name('people.export');
            Route::delete('people/{person}', [PersonDataController::class, 'destroy'])->middleware('password.confirm')->name('people.destroy');
        });

        // Live departures (KVB-style board)
        Route::get('timetable', TimetableController::class)->name('timetable');

        // Day Composer
        Route::get('composer', [ComposerController::class, 'page'])->name('composer');
        Route::post('composer/parse', [ComposerController::class, 'parse'])
            ->middleware('throttle:composer-parse')
            ->name('composer.parse');
        Route::get('privacy/processing/{purpose}', ProcessingNoticeController::class)
            ->middleware('throttle:privacy-processing')->name('privacy.processing.show');
        Route::delete('privacy/processing/{purpose}', [ProcessingNoticeController::class, 'withdraw'])
            ->middleware('throttle:privacy-processing')->name('privacy.processing.withdraw');
        Route::post('composer/compose', [ComposerController::class, 'compose'])
            ->middleware('throttle:composer-compose')
            ->name('composer.compose');
        Route::post('composer/swap', [ComposerController::class, 'swap'])->name('composer.swap');
        // Pin / un-pin the composed plan to the Today screen.
        Route::post('composer/save', [ComposerController::class, 'save'])->name('composer.save');
        Route::delete('composer/today', [ComposerController::class, 'clearToday'])->name('composer.today.clear');

        // Explore / Spots
        Route::get('explore', [SpotController::class, 'index'])->name('explore');

        // Card showcase — dev-only reference for ContentCard variants
        if (! app()->environment('production')) {
            Route::get('dev/cards', fn () => Inertia::render('dev/cards'))->name('dev.cards');
        }

        // v4 design-system reference — visible on local + staging, hidden on
        // production. Staging runs APP_ENV=production, so gate on the host.
        Route::get('dev/design-system', function () {
            abort_if(
                app()->isProduction() && ! str_contains((string) request()->getHost(), 'staging'),
                404,
            );

            return Inertia::render('dev/design-system');
        })->name('dev.design-system');

        // Events
        Route::get('events', [EventController::class, 'index'])->name('events');
        Route::get('events/saved', [EventController::class, 'saved'])->name('events.saved');
        Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');
        Route::post('events/{event}/join', [EventController::class, 'join'])->name('events.join');
        Route::delete('events/{event}/join', [EventController::class, 'leave'])->name('events.leave');

        // Alerts
        Route::get('alerts', [AlertController::class, 'index'])->name('alerts');
        Route::post('alerts/{alert}/read', [AlertController::class, 'markRead'])->name('alerts.read');
        Route::post('alerts/read-all', [AlertController::class, 'markAllRead'])->name('alerts.read-all');
        Route::post('alerts/{alert}/dismiss', [AlertController::class, 'dismiss'])->name('alerts.dismiss');

        // Mute + thumbs-down (Roadmap #6 + #7)
        Route::get('mutes', [MuteController::class, 'index'])->name('mutes.index');
        Route::post('mutes', [MuteController::class, 'store'])->name('mutes.store');
        Route::delete('mutes', [MuteController::class, 'destroy'])->name('mutes.destroy');
        Route::post('mutes/thumbs-down', [MuteController::class, 'thumbsDown'])->name('mutes.thumbs-down');

        // Push subscriptions
        Route::post('push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
        Route::post('push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

        // Notification preferences
        Route::get('notification-preferences', [NotificationPreferenceController::class, 'show'])->name('notification-preferences.show');
        Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])->name('notification-preferences.update');

        // User settings
        Route::get('user-settings', [UserSettingController::class, 'show'])->name('user-settings.show');
        Route::put('user-settings', [UserSettingController::class, 'update'])->name('user-settings.update');

        // Spot reviews — public-facing content, so the tight ugc budget
        // stacks under the group-wide app-writes one.
        Route::get('explore/{spot}/reviews', [ReviewController::class, 'index'])->name('reviews.index');
        Route::post('explore/{spot}/reviews', [ReviewController::class, 'store'])->middleware('throttle:ugc')->name('reviews.store');

        // Slot monitoring

        // User places
        Route::post('user-places', [UserPlaceController::class, 'store'])->name('user-places.store');
        Route::put('user-places/{userPlace}', [UserPlaceController::class, 'update'])->name('user-places.update');
        Route::delete('user-places/{userPlace}', [UserPlaceController::class, 'destroy'])->name('user-places.destroy');

        Route::get('services', [ServicesController::class, 'index'])->name('services');
        Route::get('bureaucracy', [BureaucracyController::class, 'index'])->name('bureaucracy');
        Route::put('bureaucracy/case/ai-consent', AiConsentController::class)
            ->name('bureaucracy.case.ai-consent.update');
        Route::post('bureaucracy/case/messages', CaseMessageController::class)
            ->middleware('throttle:bureaucracy-ai-burst')
            ->name('bureaucracy.case.messages.store');
        Route::post('bureaucracy/case/questions/{question}', BureaucracyCaseQuestionController::class)
            ->name('bureaucracy.case-question.answer');
        Route::patch('bureaucracy/case/conflicts/{conflict}', BureaucracyCaseConflictController::class)
            ->name('bureaucracy.case-conflict.resolve');
        Route::patch('bureaucracy/case/tasks/{task:key}', BureaucracyCaseTaskController::class)
            ->name('bureaucracy.case-task.update');
        Route::patch('bureaucracy/case/tasks/{task:key}/documents', BureaucracyCaseTaskDocumentsController::class)
            ->name('bureaucracy.case-task.documents.update');
        // Admin/local-only: view the real bureaucracy page as any synthetic persona.
        Route::get('bureaucracy/demo', BureaucracyDemoController::class)->name('bureaucracy.demo');
        Route::post('bureaucracy/path', [BureaucracyController::class, 'setPath'])->name('bureaucracy.set-path');
        Route::post('bureaucracy/settle', [BureaucracyController::class, 'settle'])->name('bureaucracy.settle');
        // Retired QA writes: explicit 410 for stale clients; use the v2 read-only preview.
        Route::post('qa/become/{persona}', [PersonaController::class, 'become'])->name('qa.become');
        Route::post('qa/reset-tasks', [PersonaController::class, 'resetTasks'])->name('qa.reset-tasks');
        Route::post('profile/attributes', [ProfileAttributeController::class, 'store'])->name('profile.attributes');
        Route::post('tasks/{task}/toggle', [TaskController::class, 'toggle'])->name('tasks.toggle');
        Route::post('tasks/{task}/report-outdated', [TaskController::class, 'reportOutdated'])->name('tasks.report-outdated');
        Route::patch('user-tasks/{userTask}', [UserTaskController::class, 'update'])->name('user-tasks.update');
        Route::get('profile', ProfilePageController::class)->name('profile');
    });
};

// Register routes — with or without subdomain enforcement
if ($appDomain && $marketingDomain) {
    // Production: enforce subdomains
    Route::domain($marketingDomain)->group($marketingRoutes);
    Route::domain($appDomain)->group($appRoutes);
} else {
    // Local dev: no subdomain enforcement, all routes on localhost
    $marketingRoutes();
    $appRoutes();
}

require __DIR__.'/settings.php';
