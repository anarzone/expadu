<?php

namespace App\Console\Commands\Events;

use App\Enums\EventCategory;
use App\Models\Event;
use App\Services\ListingSorter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sorts listings into What's on's categories (owner, 28 Oct 2026) with Haiku, forty to a request:
 * the rows still on an old category (Culture had no single new home) and, with --all, every
 * upcoming one, plus past ones someone acted on. New listings are sorted as they're classified
 * (ProcessEventJob); this is for the ones that came before. Only the listing's public words go
 * out: title, venue, its source's own categories and the start of its description. Then the
 * recorded actions on events that still name a retired category take the event's new one
 * (signalsFollow()). Each listing is also read for whether it's sensitive (religion, politics, a
 * union, health, sexual orientation and gender identity), which personalisation never learns from.
 */
class RecategoriseEvents extends Command
{
    private const BATCH = 40;

    protected $signature = 'events:recategorise {--all : Sort every upcoming listing again, not only old categories} {--limit=2000}';

    protected $description = "Sort listings into What's on's categories with Haiku";

    public function __construct(private ListingSorter $sorter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! filled(config('services.anthropic.key'))) {
            $this->error('No Anthropic key: nothing was sorted.');

            return self::FAILURE;
        }

        $current = array_map(fn (EventCategory $c) => $c->value, EventCategory::cases());
        $events = Event::query()
            ->where(fn ($query) => $query
                ->where(fn ($listed) => $listed->whereIn('status', ['active', 'hidden'])
                    ->where(fn ($upcoming) => $upcoming->where('starts_at', '>=', now()->startOfDay())
                        ->orWhere(fn ($series) => $series->whereNotNull('recurrence')->where(fn ($until) => $until->whereNull('recurrence_until')->orWhere('recurrence_until', '>=', now())))))
                // Past ones someone acted on, so their recorded actions can follow (signalsFollow()).
                ->orWhereExists(fn ($acted) => $acted->from('user_signals')->where('item_type', 'event')->whereColumn('user_signals.item_id', 'events.id')))
            ->when(! $this->option('all'), fn ($query) => $query->where(fn ($old) => $old->whereNotIn('category', $current)->orWhereNull('category')))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'title', 'title_en', 'description', 'location_name', 'tags', 'category']);

        $sorted = 0;
        $failed = 0;
        foreach ($events->chunk(self::BATCH) as $batch) {
            try {
                $categories = $this->sorter->sort($batch->map(fn (Event $event) => [
                    'id' => $event->id, 'title' => $event->title_en ?: $event->title, 'venue' => (string) $event->location_name,
                    'tags' => (array) ($event->tags ?? []), 'description' => (string) $event->description,
                ])->values()->all())['read'];
            } catch (Throwable $exception) {
                $failed += $batch->count();
                Log::warning('events:recategorise batch failed', ['error' => $exception->getMessage()]);

                continue;
            }
            foreach ($categories as $id => $read) {
                $sorted += Event::query()->whereKey($id)->update($read);
            }
        }

        $this->line((string) json_encode(['listings' => $events->count(), 'sorted' => $sorted, 'failed' => $failed, 'signals' => $this->signalsFollow($current)]));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A signal keeps the category its event had when the person acted (user_signals), and a
     * retired one has no label, so their taste can't use it (TasteFacts). Those, and the ones
     * with none, take their event's category once it has a current one.
     *
     * @param  list<string>  $current
     */
    private function signalsFollow(array $current): int
    {
        $in = implode(', ', array_fill(0, count($current), '?'));

        return DB::update(
            "update user_signals s set category = e.category from events e
             where s.item_type = 'event' and s.item_id = e.id and e.category in ({$in})
               and (s.category is null or s.category not in ({$in}))",
            [...$current, ...$current],
        );
    }
}
