<?php

namespace Database\Seeders\MockSample;

use App\Composer\WorkspaceStore;
use App\Enums\SpotFeedbackState;
use App\Models\ComposerWorkspace;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Local only: what Composer's mock plans around on its sample day
 * (prototype/dev/design/connected/sample-day.js, Wednesday 30 September 2026), so
 * tools/mock-compare shows Composer's Your day and starters with the same words. Each kind
 * of sample has one source, shared with the other pages' comparisons:
 *
 *  - tonight's two sample events (Street culture night at 18:30, Cinema under the stars at
 *    20:00) are Events' own sample: MockSample\EventsSeeder, called here;
 *  - the pharmacy near the sample's 14:00 appointment is Eck-Apotheke by Wiener Platz,
 *    the same OpenStreetMap row MockSample\PlacesSeeder (#157) adds with the mock's other
 *    places, keyed alike, so whichever runs first makes it and the other leaves it be;
 *  - that account's conversation history, cleared to one new conversation started at the
 *    sample's moment, so Composer opens on its welcome and History lists it as the mock
 *    does ("New conversation · 30 Sept · 0 messages"); plans stay as they are;
 *  - nothing saved on that account (its event bookmarks and saved places, all that
 *    App\Saved\SavedCollection reads), so Saved ideas is the mock's empty state. Another
 *    page's sample can leave a save behind; a comparison that needs one adds it itself.
 *
 * The person's own day comes from MockSample\PaperworkSeeder (the appointment) and
 * `mock:sample-day` (plans).
 *
 *   php artisan db:seed --class='Database\Seeders\MockSample\ComposerSeeder'
 *
 * IMPORTANT: never registered in DatabaseSeeder; it refuses to run outside local.
 */
class ComposerSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('Refusing: the Composer sample is for local mock comparisons only.');

            return;
        }

        $user = User::query()->where('email', (string) env('E2E_EMAIL', 'e2e@expadu.test'))->first();

        if ($user === null) {
            $this->command?->error('No E2E account. Run the E2ETestUserSeeder first.');

            return;
        }

        // Earlier versions of this seeder made their own copies; Events' sample has them.
        Event::query()->where('source', 'mock-sample')->whereIn('source_uid', ['today-review-culture', 'today-review-cinema'])->delete();
        Spot::query()->where('source', 'mock-sample')->where('source_id', 'pharmacy-wiener-platz')->delete();

        $this->call(EventsSeeder::class);

        // MockSample\PlacesSeeder's row for it, word for word (until #157 is in launch and
        // this becomes $this->call(PlacesSeeder::class)).
        Spot::query()->firstOrCreate(['source' => 'osm', 'source_id' => 'node/3354736381'], [
            'name' => 'Eck-Apotheke', 'category' => 'pharmacy', 'lat' => 50.961116, 'lng' => 7.005841,
            'veedel' => 'Mülheim', 'tags' => ['opening_hours' => 'Mo-Fr 08:30-18:30; Sa 09:00-14:00'],
            'is_active' => true, 'public_access_allowed' => true,
        ]);

        // Saved ideas starts empty, as the mock's does.
        EventBookmark::query()->where('user_id', $user->id)->delete();
        SpotFeedback::query()->where('user_id', $user->id)->where('state', SpotFeedbackState::Saved)->delete();

        // The mock's history is one conversation from its sample day; on the sample clock,
        // so it is dated 30 September (just before the mock's 13:10).
        $moment = CarbonImmutable::parse('2026-09-30 13:00', 'Europe/Berlin');
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);

        try {
            $revision = (int) ComposerWorkspace::query()->where('user_id', $user->id)->value('revision');
            app(WorkspaceStore::class)->command($user, ['revision' => $revision, 'action' => 'delete_history']);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        $this->command?->info('The Composer sample is in place (Events\' sample, the pharmacy by Wiener Platz), with one new conversation and nothing saved.');
    }
}
