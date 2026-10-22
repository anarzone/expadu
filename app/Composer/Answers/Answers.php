<?php

namespace App\Composer\Answers;

use App\Bureaucracy\ReadModel\AccountPlanEntry;
use App\Bureaucracy\ReadModel\RecordedAppointmentsBetween;
use App\Composer\PromptIntent;
use App\Models\ActiveTrip;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What Composer says to a request, kind by kind, as the approved Composer does
 * (prototype/dev/design/composer-studio/app.js `submitNow`), but from the person's own
 * day, records and the city's listings, never a sample. Each answer is the reply's
 * text plus what its message carries: a short verdict (`title`), next steps
 * (`suggestions`) and, for some kinds, a block (your checklist, a listing's facts).
 *
 * Only the kinds below are answered here (KINDS); the others keep the page's current
 * replies until their part of CO3 lands. Whatever has no answer of its own gets what's
 * around you now (AroundNow), never a dead end (owner, 22 Oct 2026). Nothing here
 * changes a plan.
 */
final class Answers
{
    /** The kinds this class answers (and, for a journey, only the appointment's: handles()). */
    public const KINDS = ['help', 'around', 'clarify', 'next', 'checklist', 'access', 'service', 'unavailable', 'onway', 'journey', 'event', 'places'];

    /** What the router says when the words name nothing in particular: a trip the AI read may stand in. */
    public const UNSPECIFIC = ['clarify', 'nearby', 'unavailable', 'discovery', 'journey', 'around'];

    /** Questions about your own day, trip and records, answered even when the words read as a plan. */
    private const ABOUT_YOUR_DAY = ['next', 'checklist', 'journey', 'onway'];

    /** What the listings answer, whatever the parser made of it: "What's on tonight?" lists
     *  tonight's events, and "somewhere to play football" the places for it, rather than
     *  becoming a plan (owner's staging test, 21 Oct 2026). */
    private const LISTINGS = ['event', 'places'];

    /** The four everyday requests (app.js `everyday`). */
    private const EVERYDAY = [
        ['label' => 'What’s on tonight?', 'prompt' => 'What’s on tonight?'],
        ['label' => 'Plan my Saturday', 'prompt' => 'Help me plan Saturday'],
        ['label' => 'Somewhere outside', 'prompt' => 'Find something outdoors for Saturday afternoon.'],
        ['label' => 'Paperwork to sort first', 'prompt' => 'Help me settle in: which paperwork should I sort out first?'],
    ];

    /** Paperwork's own words for each document state, as Composer says them. */
    private const READINESS = [
        'missing' => 'Still to get',
        'reported_available' => 'You have it · not checked yet',
        'confirmed_for_use' => 'Checked and ready',
        'needs_reconfirmation' => 'Check again',
    ];

    private const SERVICES = ['doctors' => 'doctors', 'dentists' => 'dentists', 'pharmacies' => 'pharmacies',
        'banks' => 'banks', 'tax' => 'tax advisers', 'lawyers' => 'lawyers'];

    private const ACCESS = ['yes' => 'Step-free access', 'limited' => 'Partly step-free', 'no' => 'Not step-free'];

    public function __construct(
        private RequestRouter $router,
        private RecordedAppointmentsBetween $appointments,
        private AccountPlanEntry $plans,
        private TripAnswers $trips,
        private EventAnswers $events,
        private PlaceAnswers $places,
        private AroundNow $around,
    ) {}

    /**
     * Whether Composer answers this request itself, given what the prompt parser made of it.
     * A plan the parser understood is planned: only questions about your own day, trip and
     * records answer instead. A journey when it's the appointment's ("When should I leave
     * for my 14:00?", "Is my tram on time?") or to a place named ("show me departures to
     * Cafe Nova"); other journeys keep the page's reply until their part of CO3. A casual
     * or open message ("What's up?", "I'm bored", or what the parser reads as open: "wat
     * do") is answered with what's around you now. What nothing understood is answered too
     * (what's around you, and what Composer can do), as is a search nothing more specific
     * matched; "the listings can't vouch for it" only when the parser understood nothing.
     * A preference about a listing is the places' to answer.
     *
     * @param  array<string, mixed>  $request
     */
    public static function handles(array $request, PromptIntent $intent): bool
    {
        $kind = $request['kind'];

        return in_array($intent, [PromptIntent::Open, PromptIntent::Unknown], true) || $kind === 'around' || (in_array($kind, self::KINDS, true)
            && ($kind !== 'journey' || ($request['to'] ?? null) === 'appointment' || isset($request['place']) || ($request['again'] ?? false))
            && ($intent !== PromptIntent::PlanDay || in_array($kind, [...self::ABOUT_YOUR_DAY, ...self::LISTINGS], true))
            && ($kind !== 'clarify' || $intent === PromptIntent::Find)
            && $kind !== 'unavailable');
    }

    /** What the router needs to know about this person's day right now. */
    public function context(User $user, CarbonImmutable $now, ?string $focus): RouterContext
    {
        $now = $now->setTimezone('Europe/Berlin');
        $today = $now->toDateString();
        $appointment = collect($this->appointments->for($user, $now->startOfDay(), $now->startOfDay()->addDay()))
            ->first(fn (array $row): bool => CarbonImmutable::parse($row['starts_at'])->greaterThan($now));
        $tonight = Event::occurringBetween($now, $now->startOfDay()->addDay())
            ->map(fn (array $occurrence): array => ['id' => $occurrence['event']->id,
                'title' => (string) ($occurrence['event']->title_en ?: $occurrence['event']->title),
                'venue' => $occurrence['event']->location_name])
            ->values()->all();

        return new RouterContext(
            today: $today,
            date: $focus ?? $today,
            appointmentStart: $appointment === null ? null : CarbonImmutable::parse($appointment['starts_at'])->setTimezone('Europe/Berlin')->format('H:i'),
            tonight: $tonight,
            onTheWay: ActiveTrip::currentFor($user) !== null,
        );
    }

    /** @return array{kind: string, date: string}&array<string, mixed> */
    public function route(User $user, string $text, CarbonImmutable $now, ?string $focus): array
    {
        $routed = $this->router->route($text, $this->context($user, $now, $focus));
        // A trip the AI read where the words alone didn't say so ("how cn i get 2 cafe nova").
        $trip = in_array($routed['kind'], self::UNSPECIFIC, true) && ! isset($routed['place']) ? Cache::get(self::tripKey($user, $text)) : null;

        return is_array($trip) ? ['kind' => 'journey', 'date' => $now->setTimezone('Europe/Berlin')->toDateString(), ...$trip] : $routed;
    }

    /**
     * Keeps the place the AI read a trip to ("take_me_there" and its query), for the answer
     * to this request; the words alone may not say it's a trip.
     */
    public static function tripHint(User $user, string $text, string $place): void
    {
        Cache::put(self::tripKey($user, $text), ['place' => $place, 'area' => null, 'at' => null, 'again' => false], now()->addMinutes(15));
    }

    private static function tripKey(User $user, string $text): string
    {
        return "composer:trip:{$user->id}:".hash('sha256', trim($text));
    }

    /**
     * The reply to a request this class answers: its text and the message's other fields.
     *
     * @param  array<string, array<string, mixed>>  $plans  the person's Composer plans, by date
     * @return array{text: string, extra: array<string, mixed>}|null null when the kind has no answer of its own (then: around())
     */
    public function answer(User $user, string $text, array $request, array $plans, CarbonImmutable $now): ?array
    {
        $now = $now->setTimezone('Europe/Berlin');

        return match ($request['kind']) {
            'help' => $this->reply('I can help you plan a day, find events and places, check a journey, and point you to reviewed paperwork information.',
                suggestions: self::EVERYDAY),
            'around', 'clarify' => $this->around($user, $text, $request, $plans, $now),
            'next' => $this->next($user, $request, $plans, $now),
            'checklist' => $this->checklist($user, $now),
            'access' => $this->access($request, $now),
            'service' => $this->around($user, $text, $request, $plans, $now,
                'Expadu doesn’t list '.(self::SERVICES[$request['service']] ?? 'services').' yet.'),
            // A preference the listings can't vouch for. (Listings questions go to places.)
            'unavailable' => ($request['topic'] ?? null) === 'listing' ? null
                : $this->around($user, $text, $request, $plans, $now,
                    'The listings don’t hold enough verified information to answer that, and I won’t guess about prices, access or availability.'),
            'onway' => $this->trips->onTheWay($user, (string) ($request['ask'] ?? 'next'), $now),
            'journey' => $this->journey($user, $request, $now),
            'event' => $this->events->whatsOn($request, $now),
            'places' => $this->places->near($user, $request, $now),
            default => null,
        };
    }

    /**
     * What's around you now (AroundNow), for a casual or open message and for whatever
     * has no answer of its own, said after `$first` when that's given.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, array<string, mixed>>  $plans
     * @return array{text: string, extra: array<string, mixed>}
     */
    public function around(User $user, string $text, array $request, array $plans, CarbonImmutable $now, ?string $first = null): array
    {
        return $this->around->answer($user, $text, $request, $plans, $now, $first);
    }

    /**
     * The appointment's trip (app.js): on your way to it, the trip you're on answers;
     * before you set off, when to leave.
     *
     * @param  array<string, mixed>  $request
     */
    private function journey(User $user, array $request, CarbonImmutable $now): ?array
    {
        if (isset($request['place'])) {
            return $this->trips->toPlace($user, $request, $now);
        }
        if (($request['to'] ?? null) !== 'appointment') {
            return null;
        }
        $start = $this->context($user, $now, null)->appointmentStart;

        return $this->trips->onWayTo($user, $start)
            ? $this->trips->onTheWay($user, ($request['status'] ?? false) ? 'status' : 'leave', $now)
            : $this->trips->appointment($user, $request, $now, $start);
    }

    /**
     * "When's my next appointment?": from the person's own plans and recorded appointments,
     * from now on. It changes nothing.
     *
     * @param  array<string, array<string, mixed>>  $plans
     */
    private function next(User $user, array $request, array $plans, CarbonImmutable $now): array
    {
        $today = $now->toDateString();
        $items = [];
        foreach ($plans as $plan) {
            foreach ($plan['slots'] ?? [] as $slot) {
                $items[] = ['start' => CarbonImmutable::parse($slot['start_at'])->setTimezone('Europe/Berlin'), 'title' => (string) $slot['name'],
                    'location' => $slot['location'] ?? $slot['veedel'] ?? null,
                    'appointment' => ($slot['type'] ?? null) === 'appointment' || ($slot['category'] ?? null) === 'appointment' || ($slot['is_appointment'] ?? false) || str_contains(mb_strtolower((string) $slot['name']), 'appointment')];
            }
        }
        foreach ($this->appointments->for($user, $now, $now->addDays(90)) as $row) {
            $items[] = ['start' => CarbonImmutable::parse($row['starts_at'])->setTimezone('Europe/Berlin'),
                'title' => $row['process_title'] !== null ? "{$row['process_title']} appointment" : 'Your recorded appointment',
                'location' => $row['location']['label'] ?? null, 'appointment' => true];
        }
        $appointment = (bool) ($request['appointment'] ?? false);
        $next = collect($items)
            ->filter(fn (array $item): bool => $item['start']->greaterThanOrEqualTo($now->startOfMinute()) && (! $appointment || $item['appointment']))
            ->sortBy(fn (array $item): int => $item['start']->getTimestamp())
            ->first();
        $what = $appointment ? 'appointment' : 'plan';

        if ($next === null) {
            return $this->reply("You have no {$what}s from today onwards.", 'next', suggestions: [self::EVERYDAY[1]]);
        }

        $date = $next['start']->toDateString();
        $when = $date === $today ? 'today' : ($date === $now->addDay()->toDateString() ? 'tomorrow' : 'on '.$next['start']->format('l j F'));

        return $this->reply("Your next {$what}: {$next['title']}, {$when} at {$next['start']->format('H:i')}".($next['location'] ? " in {$next['location']}" : '').'.', 'next',
            suggestions: [$date === $today ? ['label' => 'Open Today', 'href' => '/dashboard'] : ['label' => 'My Plans', 'nav' => 'plans']]);
    }

    /**
     * "What do I need to bring?": the person's own checklist for their next appointment's
     * task (otherwise their first task with documents), from their v2 records. Never what
     * an office requires; with no checklist, it's a paperwork question.
     */
    private function checklist(User $user, CarbonImmutable $now): ?array
    {
        $plan = $this->plans->for($user)['plan'] ?? null;
        if (! is_array($plan)) {
            return null;
        }
        $requirements = collect($plan['paperwork']['requirements'] ?? []);
        $processes = collect($plan['processes'] ?? [])->filter(fn (array $process): bool => $process['id'] !== null && ! ($process['is_closed'] ?? false));
        $next = collect($this->appointments->for($user, $now, $now->addDays(90)))->first();
        $process = ($next !== null ? $processes->firstWhere('id', $next['process_id']) : null)
            ?? $processes->first(fn (array $process): bool => $requirements->contains(fn (array $r): bool => $r['process_id'] === $process['id'] && $r['applicability'] === 'required'));
        if ($process === null) {
            return null;
        }
        $own = $requirements->where('process_id', $process['id']);
        $required = $own->where('applicability', 'required')->values();
        if ($required->isEmpty()) {
            return null;
        }
        $prepared = $required->where('readiness', 'confirmed_for_use')->count();
        $optional = $own->whereIn('applicability', ['conditional', 'unknown'])->count();

        return $this->reply('That’s from your own checklist. I can’t give verified guidance on what the office requires; the checklist and its official sources are in Paperwork.'
            .($optional > 0 ? " It also lists {$optional} more that only apply in some cases." : ''), 'checklist',
            "{$prepared} of {$required->count()} documents marked ready.",
            extra: ['record' => [
                'title' => (string) ($process['title'] ?? 'Paperwork'),
                'prepared' => $prepared,
                'total' => $required->count(),
                'href' => '/bureaucracy?view=paperwork&process='.rawurlencode((string) $process['occurrence_key']),
                'documents' => $required->map(fn (array $r): array => ['label' => (string) $r['label'],
                    'ready' => $r['readiness'] === 'confirmed_for_use', 'state' => self::READINESS[$r['readiness']] ?? self::READINESS['missing']])->all(),
            ]]);
    }

    /**
     * "Is the cinema step-free?": what an upcoming event's venue listing records, or
     * "Not listed". Never a guess.
     */
    private function access(array $request, CarbonImmutable $now): array
    {
        $about = (string) ($request['about'] ?? '');
        $upcoming = Event::occurringBetween($now, $now->startOfDay()->addDays(14))
            ->sortBy(fn (array $occurrence) => $occurrence['starts_at'])->values();
        $title = fn (array $o): string => mb_strtolower((string) ($o['event']->title_en ?: $o['event']->title));
        $venue = fn (array $o): string => mb_strtolower((string) $o['event']->location_name);
        $today = $now->toDateString();
        $found = $upcoming->first(fn (array $o): bool => $title($o) !== '' && str_contains($about, $title($o)))
            ?? $upcoming->first(fn (array $o): bool => $venue($o) !== '' && str_contains($about, $venue($o)))
            ?? (preg_match('/\b(cinema|film|movie)\b/u', $about) ? $upcoming->first(fn (array $o): bool => (bool) preg_match('/cinema|film/u', $title($o))) : null)
            ?? (preg_match('/\b(tonight|evening|event)\b/u', $about) ? $upcoming->first(fn (array $o): bool => $o['starts_at']->setTimezone('Europe/Berlin')->toDateString() === $today
                && $o['starts_at']->setTimezone('Europe/Berlin')->format('H:i') >= '17:00') : null);

        if ($found === null) {
            return $this->reply('Step-free access is only recorded for some places and events. Tell me which one you mean and I’ll check its listing.', 'access',
                'Which place do you mean?', suggestions: [self::EVERYDAY[0]]);
        }

        $event = $found['event'];
        $placeId = $event->venue?->place_id;
        $wheelchair = $placeId === null ? null : DB::table('place_features')->where('spot_id', $placeId)->value('wheelchair');
        $access = self::ACCESS[$wheelchair] ?? null;
        $name = (string) ($event->title_en ?: $event->title);
        $place = (string) ($event->location_name ?: 'the venue');
        $starts = $found['starts_at']->setTimezone('Europe/Berlin');
        $price = $event->is_free ? 'Free' : ($event->price_text ?: null);

        return $this->reply($access !== null
            ? "That’s what {$place}’s listing records. It’s worth checking with the organiser before you go."
            : "Neither {$name} nor {$place}’s listing records step-free access, so I won’t guess. It’s worth asking the organiser before you go.",
            'facts', $access !== null ? "{$access}." : 'Not listed.',
            extra: ['event_id' => $event->id, 'facts' => [
                ['label' => 'Accessibility', 'value' => $access ?? 'Not listed', 'known' => $access !== null],
                ['label' => 'Admission', 'value' => $price ?? 'Not listed', 'known' => $price !== null],
                ['label' => 'Starts', 'value' => $starts->toDateString() === $today ? $starts->format('H:i') : $starts->format('D j M H:i'), 'known' => true],
            ]]);
    }

    /**
     * @param  list<array<string, string>>  $suggestions
     * @param  array<string, mixed>  $extra
     * @return array{text: string, extra: array<string, mixed>}
     */
    private function reply(string $text, string $kind = 'text', ?string $title = null, array $suggestions = [], array $extra = []): array
    {
        return ['text' => $text, 'extra' => array_filter(['kind' => $kind, 'title' => $title, 'suggestions' => $suggestions ?: null,
            ...$extra], fn ($value) => $value !== null)];
    }
}
