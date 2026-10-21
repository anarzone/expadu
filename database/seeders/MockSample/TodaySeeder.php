<?php

namespace Database\Seeders\MockSample;

use App\Models\ComposerWorkspace;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Today's continuity for tools/mock-compare (today-continuity.json): two
 * saved ideas (Bau, from PlacesSeeder, then "Cinema under the stars", from
 * Events' EventsSeeder) and a Composer conversation to pick up, as that
 * config's mock side seeds into the mock's own store. Local only; it
 * replaces only these sample saves and the sample conversation.
 *
 * E2E_EMAIL=<you>@expadu.test php artisan db:seed --class="Database\Seeders\MockSample\TodaySeeder"
 * (after PlacesSeeder, EventsSeeder and mock:sample-day).
 */
class TodaySeeder extends Seeder
{
    private const CONVERSATION = 'mock-sample-conversation';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('The mock sample is local only.');

            return;
        }

        $user = User::query()->where('email', (string) env('E2E_EMAIL', 'e2e@expadu.test'))->firstOrFail();
        $bau = Spot::query()->where('source', 'osm')->where('source_id', 'node/1593180033')->firstOrFail();
        $cinema = Event::query()->where('source_uid', 'mock:today-review-cinema')->firstOrFail();
        // In UTC: a where binds the time without its offset, so a Berlin
        // time would miss the saved row and insert it again.
        $start = CarbonImmutable::parse('2026-09-30 20:00', 'Europe/Berlin')->utc();

        // Saved in this order, so the cinema is the newer of the two.
        $feedback = SpotFeedback::query()->updateOrCreate(['user_id' => $user->id, 'spot_id' => $bau->id], ['state' => 'saved']);
        $feedback->forceFill(['created_at' => '2026-09-29 18:00:00', 'updated_at' => '2026-09-29 18:00:00'])->saveQuietly();
        $bookmark = EventBookmark::query()->updateOrCreate(
            ['user_id' => $user->id, 'event_id' => $cinema->id, 'occurrence_start' => $start],
            ['title' => $cinema->title],
        );
        $bookmark->forceFill(['created_at' => '2026-09-29 19:00:00'])->saveQuietly();

        DB::transaction(function () use ($user): void {
            $row = ComposerWorkspace::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $state = $row->state;
            $state['conversations'] = array_values(array_filter(
                $state['conversations'] ?? [],
                fn (array $conversation) => $conversation['id'] !== self::CONVERSATION,
            ));
            $state['conversations'][] = [
                'id' => self::CONVERSATION,
                'title' => 'Saturday with Maya',
                'created_at' => '2026-09-30T11:00:00+02:00',
                'messages' => [[
                    'id' => self::CONVERSATION.'-1',
                    'role' => 'user',
                    'text' => 'Plan Saturday with Maya: something outdoors, then dinner.',
                    'created_at' => '2026-09-30T11:00:00+02:00',
                ]],
            ];
            $state['active'] = self::CONVERSATION;
            $row->update(['state' => $state, 'revision' => $row->revision + 1]);
        });

        $this->command?->info("Today's sample saves and conversation are in {$user->email}'s account.");
    }
}
