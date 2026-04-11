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
        $query = Event::query()->where('starts_at', '>', now())->orderBy('starts_at');

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($request->query('free') === 'true') {
            $query->where('is_free', true);
        }

        $events = $query
            ->with('organiser:id,name')
            ->withCount('attendees')
            ->paginate(30);

        // Format events with enriched data for the frontend
        $formattedEvents = $events->through(fn (Event $e) => $this->formatEvent($e, $userId));

        return Inertia::render('events', [
            'dbEvents' => Inertia::defer(fn () => $formattedEvents),
            'filters' => [
                'category' => $request->query('category'),
                'free' => $request->query('free'),
            ],
        ]);
    }

    public function show(Event $event): Response
    {
        $event->loadCount('attendees');
        $event->load('organiser:id,name');

        return Inertia::render('events', [
            'event' => $this->formatEvent($event, request()->user()->id),
            'dbEvents' => Inertia::defer(fn () => Event::where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->with('organiser:id,name')
                ->withCount('attendees')
                ->paginate(30)
                ->through(fn (Event $e) => $this->formatEvent($e, request()->user()->id))),
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
        $events = $request->user()->attendingEvents()
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->with('organiser:id,name')
            ->withCount('attendees')
            ->paginate(30);

        return Inertia::render('events', [
            'dbEvents' => Inertia::defer(fn () => $events->through(fn (Event $e) => $this->formatEvent($e, $userId))),
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
            'going' => $e->attendees()->where('user_id', $userId)->exists(),
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
            'language' => ['l' => 'Language', 'bg' => '#EBF0FD', 'c' => '#1A4CD4'],
            'social' => ['l' => 'Social', 'bg' => '#EDFAF4', 'c' => '#0A7C52'],
            'culture' => ['l' => 'Culture', 'bg' => '#FDF0D4', 'c' => '#C47D0E'],
            'music' => ['l' => 'Music', 'bg' => '#EDE9FE', 'c' => '#7C3AED'],
            'food' => ['l' => 'Food', 'bg' => '#FDF0D4', 'c' => '#C47D0E'],
            'sports' => ['l' => 'Sports', 'bg' => '#EDFAF4', 'c' => '#0A7C52'],
            default => ['l' => ucfirst($category ?? 'Event'), 'bg' => '#EFEDE7', 'c' => '#6B6860'],
        };

        $tags = [$categoryTag];

        // Render source category names from koeln.de (skip internal time/meta tags)
        $skip = ['evening', 'morning', 'weekend', 'free', 'english', 'outdoor', 'family',
            'beginner-friendly', 'language', 'social', 'culture', 'music', 'food', 'sports'];

        foreach ($dbTags as $tag) {
            if (! in_array(mb_strtolower($tag), $skip, true) && mb_strlen($tag) <= 40) {
                $tags[] = ['l' => $tag, 'bg' => '#EFEDE7', 'c' => '#6B6860'];
            }
        }

        return $tags;
    }
}
