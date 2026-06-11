<?php

namespace App\Console\Commands\Events;

use App\Models\Event;
use Illuminate\Console\Command;

/**
 * Heuristic pass marking the curated "show up alone, not speaking
 * German, leave with a contact" subset: social categories, evening
 * times, expat-relevant tagging. Filament's is_curated toggle is the
 * manual override on top.
 */
class CurateEvents extends Command
{
    protected $signature = 'events:curate {--days=30 : How far ahead to curate}';

    protected $description = 'Mark events matching the meet-people heuristic as curated';

    private const SOCIAL_CATEGORIES = ['language', 'community', 'social'];

    private const TITLE_SIGNALS = [
        'stammtisch', 'meetup', 'meet-up', 'expat', 'international', 'language exchange',
        'sprachcafé', 'sprachcafe', 'tandem', 'newcomer', 'mixer', 'erasmus', 'welcome',
    ];

    public function handle(): int
    {
        $curated = 0;

        Event::query()
            ->whereBetween('starts_at', [now(), now()->addDays((int) $this->option('days'))])
            ->where('is_curated', false)
            ->chunkById(200, function ($events) use (&$curated) {
                foreach ($events as $event) {
                    if ($this->matches($event)) {
                        $event->update(['is_curated' => true]);
                        $curated++;
                    }
                }
            });

        $this->info("Curated {$curated} event(s).");

        return self::SUCCESS;
    }

    private function matches(Event $event): bool
    {
        if (in_array($event->category, self::SOCIAL_CATEGORIES, true)) {
            return true;
        }

        if ($event->is_expat_relevant) {
            return true;
        }

        $haystack = mb_strtolower($event->title.' '.($event->title_en ?? ''));
        foreach (self::TITLE_SIGNALS as $signal) {
            if (str_contains($haystack, $signal)) {
                return true;
            }
        }

        return false;
    }
}
