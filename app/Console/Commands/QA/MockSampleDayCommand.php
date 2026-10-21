<?php

namespace App\Console\Commands\QA;

use App\Composer\WorkspaceStore;
use App\Home\FirstVisit;
use App\Models\ActiveTrip;
use App\Models\ComposerWorkspace;
use App\Models\PlannedTrip;
use App\Support\MockAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Local only: puts the mock's sample day (Wednesday 30 September 2026,
 * prototype/dev/design/connected/sample-day.js) into an account's plans,
 * so tools/mock-compare shows the app and the mock with the same words.
 * Run it before a comparison, with the mock's view:
 *
 *   everyday  nothing of its own: the Blue Card appointment at 14:00 is
 *             Paperwork's record (seed MockSample\PaperworkSeeder too)
 *   busy      and a language class and Meet Maya after it
 *   quiet     nothing on the day (and no Paperwork seed)
 *   travel    the two evening events as plans (Travel's mock)
 *   first-visit  nothing at all, not even Coming up (Today's welcome), on
 *             the account's own first-visit sibling: <name>+first-visit@…,
 *             made when missing, so the account's saves, conversations and
 *             Paperwork record stay as they are and nothing is cleared
 *
 * Every other view also keeps Coming up (1 and 3 October). It replaces only those
 * three dates; the account's other plans stay. A way kept for those dates
 * (Travel's PlannedTrip) and a running trip go too: MockSample\TravelSeeder
 * puts the sample's back where a comparison wants them, and a quiet day
 * shouldn't show the last comparison's. Pair it with the `mock_clock` cookie
 * (App\Support\MockClock).
 */
class MockSampleDayCommand extends Command
{
    protected $signature = 'mock:sample-day
        {view=everyday : everyday, busy, quiet, travel or first-visit}
        {--email= : The account (default: the E2E account); created when missing}';

    protected $description = 'Local only: the mock\'s sample day (30 Sept 2026) in an account\'s plans, for tools/mock-compare';

    private const VIEWS = ['everyday', 'busy', 'quiet', 'travel', 'first-visit'];

    private const DATES = ['2026-09-30', '2026-10-01', '2026-10-03'];

    public function handle(WorkspaceStore $store, FirstVisit $visit): int
    {
        if (! app()->environment('local')) {
            $this->error('Refusing: the sample day is for local mock comparisons only.');

            return self::FAILURE;
        }

        $view = (string) $this->argument('view');

        if (! in_array($view, self::VIEWS, true)) {
            $this->error('The view is one of: '.implode(', ', self::VIEWS).'.');

            return self::FAILURE;
        }

        $email = (string) ($this->option('email') ?: env('E2E_EMAIL', 'e2e@expadu.test'));

        // A first visit needs an account with nothing on it; the compared
        // account has the other configs' data, so it gets a sibling that
        // tools/mock-compare signs in as (login.sibling).
        if ($view === 'first-visit') {
            $email = MockAccounts::sibling($email, 'first-visit');
        }

        $user = MockAccounts::ensure($email, fn (string $made) => $this->info("Created {$made} (verified and onboarded; the E2E password)."));

        if ($view === 'first-visit' && ! $visit->for($user)) {
            $this->error("{$email} isn't a first visit any more: it has a plan, a save, a conversation or a Paperwork record. Compare as another account.");

            return self::FAILURE;
        }

        if (! ComposerWorkspace::query()->where('user_id', $user->id)->exists()) {
            $store->command($user, ['revision' => 0, 'action' => 'fresh']);
        }

        DB::transaction(function () use ($user, $view): void {
            $row = ComposerWorkspace::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $state = $row->state;

            foreach (self::DATES as $date) {
                unset($state['plans'][$date]);
            }

            foreach ($this->plans($view) as $date => $slots) {
                $state['plans'][$date] = [
                    'date' => $date,
                    'slots' => $slots,
                    'constraints' => ['window_start' => "{$date}T08:00:00", 'window_end' => "{$date}T23:59:00"],
                    'feasibility_checked' => false,
                ];
            }

            ksort($state['plans']);
            $row->forceFill(['state' => $state, 'revision' => $row->revision + 1])->save();

            PlannedTrip::query()->where('user_id', $user->id)->whereIn('date', self::DATES)->delete();
            ActiveTrip::query()->where('user_id', $user->id)->delete();
        });

        $this->info("The sample day ({$view}) is in {$email}'s plans.");

        return self::SUCCESS;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function plans(string $view): array
    {
        if ($view === 'first-visit') {
            return [];
        }

        $day = '2026-09-30';
        $plans = [
            '2026-10-01' => [$this->personal('mock:next-class', 'Language class', 'Ehrenfeld', 50.9497, 6.9187, '2026-10-01', '18:00', '19:00')],
            '2026-10-03' => [$this->personal('mock:next-walk', 'Walk by the Rhine', 'Rheinauhafen', 50.92262, 6.96838, '2026-10-03', '10:30', '11:30')],
        ];

        if ($view === 'quiet') {
            return $plans;
        }

        // The Blue Card appointment is Paperwork's record (MockSample\PaperworkSeeder);
        // the day projection reads it from there.
        $slots = [];

        if ($view === 'busy') {
            $slots[] = $this->personal('mock:class', 'Language class', 'Ehrenfeld', 50.9497, 6.9187, $day, '15:30', '16:30');
            $slots[] = $this->personal('mock:friend', 'Meet Maya', 'Stadtgarten', 50.9445, 6.93694, $day, '17:30', '18:15');
        }

        if ($view === 'travel') {
            $slots[] = $this->event('mock:culture', 'Street culture night', 'Rheinauhafen', 'Neustadt-Süd', 50.92262, 6.96838, $day, '18:30', '21:00');
            $slots[] = $this->event('mock:cinema', 'Cinema under the stars', 'Stadtgarten', 'Neustadt-Nord', 50.9445, 6.93694, $day, '20:00', '22:00');
        }

        return ($slots === [] ? [] : [$day => $slots]) + $plans;
    }

    /** @return array<string, mixed> */
    private function personal(string $id, string $name, string $place, float $lat, float $lng, string $date, string $start, string $end): array
    {
        return $this->slot([
            'id' => $id,
            'type' => 'personal',
            'category' => 'appointment',
            'name' => $name,
            'location' => $place,
            'lat' => $lat,
            'lng' => $lng,
            'subtitle' => 'Personal plan',
            'swappable' => false,
        ], $date, $start, $end);
    }

    /** @return array<string, mixed> */
    private function event(string $id, string $name, string $place, string $area, float $lat, float $lng, string $date, string $start, string $end): array
    {
        return $this->slot([
            'id' => $id,
            'type' => 'event',
            'category' => 'event',
            'name' => $name,
            'location' => $place,
            'veedel' => $area,
            'lat' => $lat,
            'lng' => $lng,
            'swappable' => false,
        ], $date, $start, $end);
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private function slot(array $slot, string $date, string $start, string $end): array
    {
        return $slot + [
            'start_time' => $start,
            'end_time' => $end,
            'start_at' => CarbonImmutable::parse("{$date} {$start}", 'Europe/Berlin')->toIso8601String(),
            'end_at' => CarbonImmutable::parse("{$date} {$end}", 'Europe/Berlin')->toIso8601String(),
            'travel_min_from_previous' => 0,
        ];
    }
}
