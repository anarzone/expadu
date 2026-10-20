<?php

namespace Database\Seeders\MockSample;

use App\Composer\WorkspaceStore;
use App\Enums\SpotCategory;
use App\Models\ComposerWorkspace;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local only: the listings Composer's mock plans around on its sample day
 * (prototype/dev/design/connected/sample-day.js, Wednesday 30 September 2026), so
 * tools/mock-compare shows Composer's Your day and starters with the same words:
 *
 *  - tonight's two sample events, Street culture night (18:30, Rheinauhafen) and Cinema
 *    under the stars (20:00, Stadtgarten), both labelled as sample programme;
 *  - a pharmacy beside Wiener Platz, near the sample's 14:00 appointment;
 *  - a new conversation on that account, so Composer opens on its welcome as the mock
 *    does (earlier conversations stay in History, plans stay as they are).
 *
 * The person's own day comes from MockSample\PaperworkSeeder (the appointment) and
 * `mock:sample-day` (plans). These rows are fictional and exist on this machine only;
 * re-running replaces them.
 *
 *   php artisan db:seed --class='Database\Seeders\MockSample\ComposerSeeder'
 *
 * IMPORTANT: never registered in DatabaseSeeder; it refuses to run outside local.
 */
class ComposerSeeder extends Seeder
{
    private const SOURCE = 'mock-sample';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('Refusing: the Composer sample is for local mock comparisons only.');

            return;
        }

        // Listings need an organiser; on this machine the comparison's own account will do.
        $organiser = User::query()->where('email', (string) env('E2E_EMAIL', 'e2e@expadu.test'))->first();

        if ($organiser === null) {
            $this->command?->error('No E2E account. Run the E2ETestUserSeeder first.');

            return;
        }

        $evening = fn (string $time) => CarbonImmutable::parse("2026-09-30 {$time}", 'Europe/Berlin');

        foreach ([
            ['today-review-culture', 'Street culture night', '18:30', '21:00', 'Rheinauhafen', 50.92262, 6.96838,
                'An illustrative evening of music by the Rhine. Sample event for this design preview.'],
            ['today-review-cinema', 'Cinema under the stars', '20:00', '22:00', 'Stadtgarten', 50.9445, 6.93694,
                'An illustrative outdoor film night. Sample event for this design preview.'],
        ] as [$uid, $title, $start, $end, $venue, $lat, $lng, $description]) {
            $event = Event::query()->updateOrCreate(['source' => self::SOURCE, 'source_uid' => $uid], [
                'title' => $title, 'title_en' => $title, 'category' => 'culture',
                'description' => $description, 'description_en' => $description,
                'starts_at' => $evening($start), 'ends_at' => $evening($end),
                'location_name' => $venue, 'is_free' => true, 'is_curated' => true,
                'status' => 'active', 'source_url' => null, 'organiser_id' => $organiser->id,
            ]);
            DB::update('UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $event->id]);
        }

        Spot::query()->updateOrCreate(['source' => self::SOURCE, 'source_id' => 'pharmacy-wiener-platz'], [
            'name' => 'Apotheke am Wiener Platz (sample)', 'category' => SpotCategory::Pharmacy,
            'address' => 'Wiener Platz, Mülheim', 'veedel' => 'Mülheim',
            'lat' => 50.96118, 'lng' => 7.00502, 'is_active' => true, 'is_recommendable' => false,
        ]);

        $revision = (int) ComposerWorkspace::query()->where('user_id', $organiser->id)->value('revision');
        app(WorkspaceStore::class)->command($organiser, ['revision' => $revision, 'action' => 'fresh']);

        $this->command?->info('The Composer sample listings are in place (2 events tonight, 1 pharmacy), on a new conversation.');
    }
}
