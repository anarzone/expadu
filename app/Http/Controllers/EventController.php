<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    /**
     * Category → color mapping for event cards.
     */
    private const CATEGORY_COLORS = [
        'language' => '#1A4CD4',
        'social' => '#0A7C52',
        'culture' => '#C4271A',
        'music' => '#7C3AED',
        'food' => '#C47D0E',
        'sports' => '#0A7C52',
    ];

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $category = $request->query('category');
        $freeOnly = $request->query('free') === 'true';

        return Inertia::render('events', [
            'dbEvents' => Inertia::defer(function () use ($userId, $category, $freeOnly) {
                $query = Event::query()->where('starts_at', '>', now())->orderBy('starts_at');
                if ($category) {
                    $query->where('category', $category);
                }
                if ($freeOnly) {
                    $query->where('is_free', true);
                }

                return $query
                    ->with('organiser:id,name')
                    ->withCount('attendees')
                    ->withExists(['attendees as user_going' => fn ($q) => $q->where('user_id', $userId)])
                    ->paginate(30)
                    ->through(fn (Event $e) => $this->formatEvent($e, $userId));
            }),
            'filters' => [
                'category' => $category,
                'free' => $request->query('free'),
            ],
        ]);
    }

    public function show(Request $request, Event $event): Response
    {
        $userId = $request->user()->id;
        $event->loadCount('attendees');
        $event->load('organiser:id,name');
        $event->user_going = $event->attendees()->where('user_id', $userId)->exists();

        return Inertia::render('events', [
            'event' => $this->formatEvent($event, $userId),
            'dbEvents' => Inertia::defer(fn () => Event::where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->with('organiser:id,name')
                ->withCount('attendees')
                ->withExists(['attendees as user_going' => fn ($q) => $q->where('user_id', $userId)])
                ->paginate(30)
                ->through(fn (Event $e) => $this->formatEvent($e, $userId))),
            'filters' => [],
        ]);
    }

    public function join(Request $request, Event $event): RedirectResponse
    {
        $request->user()->attendingEvents()->syncWithoutDetaching([
            $event->id => ['joined_at' => now()],
        ]);

        return back();
    }

    public function leave(Request $request, Event $event): RedirectResponse
    {
        $request->user()->attendingEvents()->detach($event->id);

        return back();
    }

    public function saved(Request $request): Response
    {
        $userId = $request->user()->id;

        return Inertia::render('events', [
            'dbEvents' => Inertia::defer(fn () => $request->user()->attendingEvents()
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->with('organiser:id,name')
                ->withCount('attendees')
                ->withExists(['attendees as user_going' => fn ($q) => $q->where('user_id', $userId)])
                ->paginate(30)
                ->through(fn (Event $e) => $this->formatEvent($e, $userId))),
            'filters' => [],
            'tab' => 'saved',
        ]);
    }

    /**
     * Format an Event model into the rich shape the frontend expects.
     */
    private function formatEvent(Event $e, int $userId): array
    {
        $startsAt = $e->starts_at;
        $color = self::CATEGORY_COLORS[$e->category] ?? '#6B6860';

        $area = '';
        if ($e->address) {
            $parts = explode(',', $e->address);
            $area = trim($parts[1] ?? $parts[0] ?? '');
        }

        return [
            'id' => $e->id,
            'title' => $e->title,
            'emoji' => $e->emoji,
            'category' => $e->category,
            'desc' => $e->description ?? '',
            'date' => $startsAt->format('d'),
            'month' => $startsAt->format('M'),
            'day' => $startsAt->format('D'),
            'fullDate' => $startsAt->toDateString(),
            'time' => $startsAt->format('H:i'),
            'duration' => $e->ends_at ? $startsAt->diffForHumans($e->ends_at, true) : null,
            'venue' => $e->location_name ?? '',
            'area' => $area,
            'distance' => '',
            'address' => $e->address,
            'price' => $e->is_free
                ? 'Free'
                : ($e->price_text ?? ($e->price ? '€'.number_format($e->price, 0) : null)),
            'color' => $color,
            'barColor' => $color,
            'categories' => [$e->category],
            'free' => (bool) $e->is_free,
            'attending' => $e->attendees_count ?? 0,
            'max' => $e->max_attendees,
            'going' => isset($e->user_going) ? (bool) $e->user_going : $e->attendees()->where('user_id', $userId)->exists(),
            'saved' => false,
            'organiser' => $e->organiser?->name ?? 'Expadu',
            'tags' => $this->buildTags($e->category, $e->tags ?? [], $color),
            'attendees' => ['🇬🇧', '🇩🇪', '🇹🇷', '🇫🇷'],
            'englishFriendly' => in_array('english', $e->tags ?? []),
            'featured' => false,
            'karneval' => false,
            'link' => $e->source_url,
        ];
    }

    /**
     * @param  string[]  $dbTags  Tags stored on the event (source categories + time tags)
     * @return array<int, array{l: string, bg: string, c: string}>
     */
    private function buildTags(?string $category, array $dbTags, string $color): array
    {
        $categoryTag = match ($category) {
            'language' => ['l' => 'Language', 'cls' => 'bg-primary/10 text-primary dark:bg-primary/20'],
            'social' => ['l' => 'Social', 'cls' => 'bg-success/10 text-success dark:bg-success/20'],
            'culture' => ['l' => 'Culture', 'cls' => 'bg-warn/10 text-warn dark:bg-warn/20'],
            'music' => ['l' => 'Music', 'cls' => 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300'],
            'food' => ['l' => 'Food', 'cls' => 'bg-warn/10 text-warn dark:bg-warn/20'],
            'sports' => ['l' => 'Sports', 'cls' => 'bg-success/10 text-success dark:bg-success/20'],
            default => ['l' => ucfirst($category ?? 'Event'), 'cls' => 'bg-surface-2 text-muted-foreground'],
        };

        $tags = [$categoryTag];

        // Render source category names from koeln.de (skip internal time/meta tags)
        $skip = ['evening', 'morning', 'weekend', 'free', 'english', 'outdoor', 'family',
            'beginner-friendly', 'language', 'social', 'culture', 'music', 'food', 'sports'];

        foreach ($dbTags as $tag) {
            if (! in_array(mb_strtolower($tag), $skip, true) && mb_strlen($tag) <= 40) {
                $tags[] = ['l' => $tag, 'cls' => 'bg-surface-2 text-muted-foreground'];
            }
        }

        return $tags;
    }
}
