import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    IconAdjustmentsHorizontal,
    IconArrowsShuffle,
    IconBus,
    IconChecklist,
    IconCloudRain,
    IconCurrencyEuro,
    IconPin,
    IconPlus,
    IconRipple,
    IconSearch,
    IconSparkles,
    IconUsers,
    IconX,
} from '@tabler/icons-react';
import type { IconProps } from '@tabler/icons-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ComponentType, CSSProperties, ReactNode } from 'react';
import { categoryClass } from '@/components/ds/category';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { AreaPickerPanel } from '@/components/places/area-picker';
import type { BezirkOption } from '@/components/places/area-picker';
import { categoryEmoji } from '@/components/places/category-illustration';
import { FromPanel } from '@/components/places/from-bar';
import type {
    FromTarget,
    GeoResult,
    SavedPlace,
} from '@/components/places/from-bar';
import type {
    PlacesOrigin,
    TransportMode,
} from '@/components/places/from-control';
import { PlaceDetailModal } from '@/components/places/place-detail-modal';
import { FeedbackToast } from '@/components/places/place-feedback-menu';
import { PlacesMap } from '@/components/places/places-map';
import type { Place } from '@/components/places/types';
import { ICON_STROKE } from '@/constants/icons';
import { useFeedback } from '@/hooks/use-feedback';
import { useIsMobile } from '@/hooks/use-mobile';
import { useTracker } from '@/hooks/use-tracker';
import AppLayout from '@/layouts/app-layout';
import { berlinHour, berlinTimeOnDay } from '@/lib/berlin-time';

type Constraints = {
    window_start: string;
    window_end: string;
    areas: string[];
    categories: string[];
    companions: string | null;
    budget: string | null;
    archetype?: string | null;
    vibe?: string | null;
};

type PlanSlot = {
    id: string;
    type: string;
    name: string;
    subtitle: string | null;
    category: string;
    veedel: string | null;
    lat: number;
    lng: number;
    outdoor: boolean;
    cost_tier: string;
    is_appointment: boolean;
    swappable: boolean;
    start_time: string;
    end_time: string;
    travel_min_from_previous: number;
    leave_by: string | null;
    closes_at: string | null;
    band: string;
    duration_label: string;
    why: string | null;
    is_landmark: boolean;
};

type Plan = {
    constraints: Constraints;
    slots: PlanSlot[];
};

type Notice = { type: string; text: string };

/** The single cyan "locating" weather line above the plan (v4 mock). */
type WeatherNote = { text: string; rain: boolean };

type Intent =
    | 'plan_day'
    | 'bureaucracy_q'
    | 'find'
    | 'take_me_there'
    | 'unknown';

type ParseResult = {
    intent: Intent;
    source: string;
    constraints: Constraints | null;
    query: string | null;
};

function slotEmoji(slot: PlanSlot): string {
    return categoryEmoji(slot.is_appointment ? 'appointment' : slot.category);
}

/** Meta line for the detail modal — same shape as the Places / Today cards. */
function detailMeta(place: Place): string {
    const label =
        place.fine_label ??
        place.category.charAt(0).toUpperCase() + place.category.slice(1);

    return [
        label,
        place.park ?? place.veedel,
        place.distance_min != null ? `${place.distance_min} min away` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

async function post<T>(url: string, body: unknown): Promise<T> {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    if (!res.ok) {
        throw new Error(`${res.status}`);
    }

    return res.json() as Promise<T>;
}

/**
 * The compose payload for an explicit "start from" pick: a saved place travels
 * by id (coordinates resolve server-side), a searched / pinned point carries
 * its own coordinates + label. No pick → the resolved origin (live/area) stands.
 */
function fromParams(
    target: FromTarget | null,
): Record<string, string | number> {
    if (target?.kind === 'place') {
        return { from_place: target.id, from_label: target.label };
    }

    if (target?.kind === 'point') {
        return {
            lat: target.lat,
            lng: target.lng,
            from_label: target.label,
        };
    }

    return {};
}

type Facets = {
    categories: { value: string; label: string }[];
    areas: string[];
};

const COMPANION_OPTS = [
    { value: '', label: 'Anyone' },
    { value: 'alone', label: 'Just me' },
    { value: 'partner', label: 'My partner' },
    { value: 'friends', label: 'Friends' },
    { value: 'kids', label: 'The kids' },
];

const BUDGET_OPTS = [
    { value: '', label: 'Any budget' },
    { value: 'free', label: 'Free only' },
    { value: 'low', label: 'Low cost' },
    { value: 'normal', label: 'Any price' },
];

const VIBE_OPTS = [
    { value: '', label: 'Any vibe' },
    { value: 'chill', label: 'Chill' },
    { value: 'active', label: 'Active' },
];

const TIME_OPTS = [
    { value: 'morning', label: 'Morning' },
    { value: 'midday', label: 'Midday' },
    { value: 'afternoon', label: 'Afternoon' },
    { value: 'evening', label: 'Evening' },
    { value: 'allday', label: 'All day' },
];

const TIME_RANGES: Record<string, [number, number]> = {
    morning: [9, 12],
    midday: [11, 15],
    afternoon: [12, 18],
    evening: [18, 22],
    allday: [10, 20],
};

// Activities you do once, not all day — picking one of these reads as "find me
// a pitch", so the result defaults to the browse list. Day-composable buckets
// (park, culture, playground) default to the composed day instead.
const BROWSE_FIRST = new Set(['pitch', 'court', 'swimming', 'dog_park']);

function defaultAmount(categories: string[]): 'just' | 'few' | 'full' {
    return categories.length === 1 && BROWSE_FIRST.has(categories[0])
        ? 'few'
        : 'full';
}

/**
 * Only meaningful cost tiers reach the card. "free" and "budget" tell you
 * something; "normal" is just the default "no known price" bucket — noise, so
 * it's hidden rather than shown as a confusing word.
 */
function costLabel(tier: string): string | null {
    return tier === 'free' ? 'free' : tier === 'low' ? 'budget' : null;
}

/**
 * A time preset → a window on the plan's existing day. The daypart is Cologne
 * wall-clock time (berlinTimeOnDay), not the device's local time — so a phone
 * still on another timezone composes the afternoon the user actually means.
 */
function timeWindowPatch(preset: string, c: Constraints): Partial<Constraints> {
    const [s, e] = TIME_RANGES[preset] ?? TIME_RANGES.afternoon;

    return {
        window_start: berlinTimeOnDay(c.window_start, s),
        window_end: berlinTimeOnDay(c.window_start, e),
    };
}

/** Which preset the current window reads as — for the chip + highlight. */
function timePresetKey(c: Constraints): string {
    const h = berlinHour(c.window_start);
    const end = berlinHour(c.window_end);

    if (end - h >= 9) {
        return 'allday';
    }

    if (h < 11) {
        return 'morning';
    }

    if (h < 12) {
        return 'midday';
    }

    if (h < 17) {
        return 'afternoon';
    }

    return 'evening';
}

function NoticeChips({ notices }: { notices: Notice[] }) {
    if (notices.length === 0) {
        return null;
    }

    return (
        <div className="mb-4 flex flex-wrap gap-2">
            {notices.map((notice, i) => (
                <span
                    key={i}
                    className={`rounded-full px-3 py-1.5 text-[12px] font-semibold ${
                        notice.type === 'info'
                            ? 'bg-accent-soft text-primary'
                            : 'bg-warn-soft text-warn'
                    }`}
                >
                    {notice.text}
                </span>
            ))}
        </div>
    );
}

/**
 * Interim routing for non-plan intents. Search and verified answers are
 * separate slices; until they land, the box still understands the intent
 * and points the user at the right page rather than composing a nonsense
 * plan or dead-ending.
 */
function InterimRoute({ intent, query }: { intent: Intent; query: string }) {
    const config: Record<
        string,
        {
            Icon: ComponentType<IconProps>;
            title: string;
            body: string;
            href: string;
            cta: string;
        }
    > = {
        bureaucracy_q: {
            Icon: IconChecklist,
            title: 'That looks like a paperwork question',
            body: 'Answers come from your verified checklist — never guessed. Open it to find the task.',
            href: '/bureaucracy',
            cta: 'Open your checklist →',
        },
        find: {
            Icon: IconSearch,
            title: 'Looks like you’re searching for a place',
            body: 'The universal search lands next. For now, browse places and events.',
            href: '/explore',
            cta: 'Browse places →',
        },
        take_me_there: {
            Icon: IconBus,
            title: 'Looks like you want directions',
            body: 'Find the place first and tap “take me there” on it.',
            href: '/explore',
            cta: 'Find a place →',
        },
    };

    const c = config[intent] ?? config.find;
    const RouteIcon = c.Icon;

    return (
        <div className="rounded-[14px] border border-border bg-card p-6 text-center">
            <RouteIcon
                size={32}
                stroke={ICON_STROKE}
                className="mx-auto mb-2 text-muted-foreground"
            />
            <p className="mb-1 text-[15px] font-semibold">{c.title}</p>
            <p className="mb-4 text-sm text-muted-foreground">
                {query ? `“${query}” — ${c.body}` : c.body}
            </p>
            <Link
                href={c.href}
                className="inline-block rounded-[9px] bg-primary px-4 py-2.5 text-[14px] font-semibold text-white transition-colors hover:bg-accent-hover"
            >
                {c.cta}
            </Link>
        </div>
    );
}

/**
 * Position a token's option popover directly under the tapped word, but clamp
 * it to the viewport so it never spills off a phone edge (the inline word can
 * sit anywhere on a wrapped line). Opens upward when the word is low on screen.
 */
function tokenPopoverStyle(
    btn: HTMLElement,
    desiredWidth = 300,
): CSSProperties {
    const r = btn.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const width = Math.min(desiredWidth, vw - 24);
    const left = Math.max(12, Math.min(r.left, vw - width - 12));
    // Word in the lower part of the screen → open the menu above it.
    const openUp = r.bottom > vh * 0.62;

    return openUp
        ? {
              position: 'fixed',
              left,
              bottom: vh - r.top + 8,
              maxHeight: Math.max(180, r.top - 24),
              width,
          }
        : {
              position: 'fixed',
              left,
              top: r.bottom + 8,
              // leave room for the floating mobile dock at the bottom.
              maxHeight: Math.max(180, vh - r.bottom - 96),
              width,
          };
}

export default function Composer() {
    const {
        prompt,
        pins,
        savedPlaces,
        homeBezirk,
        bezirke,
        veedelsByBezirk,
        auth,
    } = usePage<{
        prompt?: string;
        pins?: string[];
        savedPlaces?: SavedPlace[];
        homeVeedel: string | null;
        homeBezirk: string | null;
        bezirke?: BezirkOption[];
        veedelsByBezirk?: Record<string, string[]>;
        auth: { user: { transport_mode: TransportMode | null } };
    }>().props;
    const { track } = useTracker();

    const [constraints, setConstraints] = useState<Constraints | null>(null);
    const [facets, setFacets] = useState<Facets>({
        categories: [],
        areas: [],
    });
    const [plan, setPlan] = useState<Plan | null>(null);
    // The browse list for "A few options" — for a solo activity these are the
    // nearby picks of that category; for a broad day they mirror the itinerary.
    const [options, setOptions] = useState<PlanSlot[]>([]);
    const [notices, setNotices] = useState<Notice[]>([]);
    const [weather, setWeather] = useState<WeatherNote | null>(null);
    const [intent, setIntent] = useState<Intent>('plan_day');
    const [parsing, setParsing] = useState(false);
    const [composing, setComposing] = useState(false);
    const [swappingSlot, setSwappingSlot] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const [destination, setDestination] = useState<Destination | null>(null);
    const [detail, setDetail] = useState<Place | null>(null);
    const [error, setError] = useState<string | null>(null);
    // Archetype dropped in the v4 sentence model — compose with null.
    const [archetype] = useState<string | null>(null);
    const [locked, setLocked] = useState<string[]>([]);
    // Picks the user has shuffled away — accumulated so Shuffle keeps rolling
    // forward instead of re-offering something it discarded two shuffles ago.
    const [excluded, setExcluded] = useState<string[]>([]);
    const [origin, setOrigin] = useState<PlacesOrigin | null>(null);
    const [locating, setLocating] = useState(false);
    // Places-style area picker: the selected Bezirk ('all' | 'near' | a name).
    // The Veedel drill rides inside constraints.areas (a single area === a Veedel).
    const [bezirk, setBezirk] = useState(homeBezirk ?? 'all');
    // The chosen travel mode, persisted globally (shared with Places). Drives
    // the From pill glyph and the "min away" distances on every pick.
    const [mode, setMode] = useState<TransportMode | null>(
        auth.user.transport_mode,
    );
    // From picker: address search query + results (reuses /api/geocode).
    const [fromQuery, setFromQuery] = useState('');
    const [fromResults, setFromResults] = useState<GeoResult[]>([]);
    // The pick-on-map overlay (the area + From panels open from sentence words
    // via openToken, like the other tappable words).
    const [mapPickOpen, setMapPickOpen] = useState(false);
    // v4 editable-sentence model — which token/extra popover is open, the
    // add-filter panel, and how much day to show (a client view over the plan).
    const [openToken, setOpenToken] = useState<string | null>(null);
    // Computed at open time so a token popover sits under the tapped word and
    // stays on-screen (set only for the sentence tokens, not the extra chips).
    const [tokenPopStyle, setTokenPopStyle] = useState<CSSProperties | null>(
        null,
    );
    const [addOpen, setAddOpen] = useState(false);
    const [amount, setAmount] = useState<'just' | 'few' | 'full'>('full');
    const { stateFor, ratingFor, setFeedback, toast } = useFeedback();
    const isMobile = useIsMobile();
    // Guards against parsing the same prompt twice (React strict-mode
    // double-invoke would otherwise fire two parse + compose round-trips).
    const parsedPrompt = useRef<string | null>(null);
    // The user's explicit "start from" pick (a saved place by id, or a searched
    // / map-tapped point). Mirrored in a ref so every recompose closure reads
    // the current value without re-creating runCompose. Cleared by live GPS.
    const [fromOverride, setFromOverride] = useState<FromTarget | null>(null);
    const fromOverrideRef = useRef<FromTarget | null>(null);
    // Set the From pick + its ref together, so the next recompose sees it.
    const setFrom = useCallback((next: FromTarget | null) => {
        fromOverrideRef.current = next;
        setFromOverride(next);
    }, []);
    // Debounce handle for the From address search.
    const fromSearchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    // Bumped on every compose so a slower earlier request can't overwrite a
    // newer one — rapid chip edits otherwise raced and could leave a plan that
    // didn't match the chips on screen.
    const composeGenRef = useRef(0);

    const runCompose = useCallback(
        async (
            next: Constraints,
            opts: {
                archetype?: string | null;
                locked?: string[];
                excluded?: string[];
            } = {},
        ) => {
            const gen = ++composeGenRef.current;
            setComposing(true);
            setError(null);

            try {
                const json = await post<{
                    plan: Plan;
                    options?: PlanSlot[];
                    notices: Notice[];
                    weather?: WeatherNote | null;
                    facets?: Facets;
                    origin?: PlacesOrigin;
                }>('/composer/compose', {
                    constraints: {
                        ...next,
                        archetype: opts.archetype ?? null,
                    },
                    pins: pins ?? [],
                    locked: opts.locked ?? [],
                    excluded: opts.excluded ?? [],
                    // An explicit From wins as the origin: a saved place by id
                    // (coords resolved server-side) or a searched / pinned point.
                    ...fromParams(fromOverrideRef.current),
                });

                // A newer compose has started — drop this stale response.
                if (gen !== composeGenRef.current) {
                    return;
                }

                setPlan(json.plan);
                setOptions(json.options ?? json.plan.slots);
                setNotices(json.notices ?? []);
                setWeather(json.weather ?? null);
                setOrigin(json.origin ?? null);

                if (json.facets) {
                    setFacets(json.facets);
                }
            } catch {
                if (gen !== composeGenRef.current) {
                    return;
                }

                setError(
                    'Could not compose your day. Try adjusting the chips.',
                );
            } finally {
                // Only the latest compose clears the spinner, so a stale one
                // finishing mid-flight can't hide that a newer one is running.
                if (gen === composeGenRef.current) {
                    setComposing(false);
                }
            }
        },
        [pins],
    );

    // Parse the prompt handed over from the Today screen, then — for a
    // plan — compose straight away. A picked suggestion is high-confidence
    // intent; making the user press a second "compose" button was a v1
    // holdover. The chips stay above the plan for correction.
    useEffect(() => {
        if (!prompt || parsedPrompt.current === prompt) {
            return;
        }

        parsedPrompt.current = prompt;

        setParsing(true);
        post<ParseResult>('/composer/parse', { text: prompt })
            .then((result) => {
                setIntent(result.intent);

                if (result.intent === 'plan_day' && result.constraints) {
                    setConstraints(result.constraints);
                    setAmount(defaultAmount(result.constraints.categories));
                    void runCompose(result.constraints);
                }
            })
            .catch(() =>
                setError('Could not understand that — edit the chips below.'),
            )
            .finally(() => setParsing(false));
    }, [prompt, runCompose]);

    // Editing any chip is an explicit "redo it this way" — merge the change and
    // recompose (which also retries if the initial auto-compose failed).
    function updateConstraint(patch: Partial<Constraints>) {
        if (!constraints) {
            return;
        }

        const next: Constraints = { ...constraints, ...patch };
        setConstraints(next);
        void runCompose(next, { archetype, locked, excluded });
    }

    async function swap(index: number) {
        setSwappingSlot(index);

        try {
            const json = await post<{ plan: Plan; notices: Notice[] }>(
                '/composer/swap',
                { slot: index },
            );
            setPlan(json.plan);
            setNotices(json.notices ?? []);
        } catch {
            setError('No alternative fits that slot.');
        } finally {
            setSwappingSlot(null);
        }
    }

    function takeMeThere(slot: PlanSlot) {
        // Strongest intent signal — only for leisure, not appointments.
        if (!slot.is_appointment) {
            track('take_me_there', {
                category: slot.category,
                veedel: slot.veedel,
            });
        }

        setDestination({
            name: slot.name,
            emoji: slotEmoji(slot),
            lat: slot.lat,
            lng: slot.lng,
            arriveBy: slot.is_appointment ? slot.start_time : undefined,
            // Start the journey from the plan's OWN origin (e.g. "from Home"),
            // the same point the plan measured "same area / N min" against — not
            // a re-resolved live GPS fix that would silently make a 12-min hop
            // read as an hour. "I'm here →" still overrides to the real position.
            fromLat: origin?.lat ?? null,
            fromLng: origin?.lng ?? null,
            fromName: origin?.label ?? null,
        });
    }

    // Tapping a plan card opens the same rich detail modal as the Places / Today
    // cards. Only real places (spot:*) have a detail page; appointments and
    // fixed events aren't tappable. Falls back to the route sheet if the fetch
    // fails.
    function openSlotDetail(slot: PlanSlot) {
        if (slot.is_appointment || !slot.id.startsWith('spot:')) {
            return;
        }

        const spotId = slot.id.replace('spot:', '');
        fetch(`/api/places/${spotId}`, { credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
            .then((json) => setDetail(json.data))
            .catch(() => takeMeThere(slot));
    }

    function toggleLock(id: string) {
        setLocked((cur) =>
            cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id],
        );
    }

    function shuffle() {
        if (!constraints || !plan) {
            return;
        }

        // Re-roll the unlocked picks, accumulating them into the excluded set so
        // repeated shuffles keep finding NEW picks instead of cycling back to one
        // discarded earlier (keeping locked picks in place).
        const unlocked = plan.slots
            .filter((s) => s.swappable && !locked.includes(s.id))
            .map((s) => s.id);
        const next = [...new Set([...excluded, ...unlocked])];
        setExcluded(next);
        void runCompose(constraints, { archetype, locked, excluded: next });
    }

    // "My location": drop any explicit pick, take a fresh GPS fix, persist it as
    // the shared "I'm here" anchor, then recompose so the plan starts from where
    // you actually are.
    function pickMyLocation() {
        if (!('geolocation' in navigator) || !constraints) {
            return;
        }

        // Live location overrides any previously picked place / point.
        setFrom(null);
        setOpenToken(null);
        setFromQuery('');
        setFromResults([]);
        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const { latitude: lat, longitude: lng } = pos.coords;
                post('/api/location/confirm', { lat, lng })
                    .then(() =>
                        runCompose(constraints, {
                            archetype,
                            locked,
                            excluded,
                        }),
                    )
                    .catch(() => {})
                    .finally(() => setLocating(false));
            },
            () => setLocating(false),
            { enableHighAccuracy: false, maximumAge: 120_000, timeout: 8000 },
        );
    }

    // "Save to Today": persist the live plan to the home screen (carrying the
    // prompt so it can be reopened), then land on Today where it now shows.
    async function saveToToday() {
        setSaving(true);

        try {
            await post('/composer/save', { prompt: prompt ?? null });
            router.visit('/dashboard');
        } catch {
            setError('Could not save to Today — try again.');
            setSaving(false);
        }
    }

    // Apply an explicit From (saved place or searched / pinned point), then
    // recompose so the plan starts from it and every "min away" measures from it.
    function applyFrom(target: FromTarget) {
        if (!constraints) {
            return;
        }

        setFrom(target);
        setOpenToken(null);
        setFromQuery('');
        setFromResults([]);
        void runCompose(constraints, { archetype, locked, excluded });
    }

    // Persist the chosen transport mode globally (the server reads
    // users.transport_mode), then recompose so distances recompute in that mode.
    function persistMode(next: TransportMode) {
        setMode(next);
        post('/api/preferences/transport-mode', { mode: next })
            .then(() => {
                if (constraints) {
                    return runCompose(constraints, {
                        archetype,
                        locked,
                        excluded,
                    });
                }
            })
            .catch(() => {});
    }

    // Debounced address search inside the From picker (reuses /api/geocode).
    function searchFrom(q: string) {
        setFromQuery(q);

        if (fromSearchTimer.current) {
            clearTimeout(fromSearchTimer.current);
        }

        if (q.trim().length < 3) {
            setFromResults([]);

            return;
        }

        fromSearchTimer.current = setTimeout(() => {
            fetch(`/api/geocode?q=${encodeURIComponent(q.trim())}`, {
                credentials: 'same-origin',
            })
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
                .then(
                    (
                        data: {
                            name: string;
                            street?: string;
                            city?: string;
                            lat: number;
                            lng: number;
                        }[],
                    ) =>
                        setFromResults(
                            data.map((r) => ({
                                name: r.name,
                                address: [r.street, r.city]
                                    .filter(Boolean)
                                    .join(', '),
                                lat: r.lat,
                                lng: r.lng,
                            })),
                        ),
                )
                .catch(() => setFromResults([]));
        }, 300);
    }

    // "Pick on map" → open the map overlay; a tap on it sets a point origin.
    function pickOnMap() {
        setOpenToken(null);
        setMapPickOpen(true);
    }

    // A map tap: reverse-geocode for a readable label, then apply it as the From
    // point and close the overlay.
    function handleMapPick(lat: number, lng: number) {
        fetch(`/api/reverse-geocode?lat=${lat}&lng=${lng}`, {
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) =>
                applyFrom({
                    kind: 'point',
                    lat,
                    lng,
                    label: d?.address || 'Pinned location',
                }),
            )
            .catch(() =>
                applyFrom({
                    kind: 'point',
                    lat,
                    lng,
                    label: 'Pinned location',
                }),
            );
        setMapPickOpen(false);
    }

    const planMode = intent === 'plan_day';
    const weekday = constraints
        ? new Date(constraints.window_start).toLocaleDateString('en-GB', {
              weekday: 'long',
              timeZone: 'Europe/Berlin',
          })
        : null;

    // Title + subtitle adapt to intent: only a plan is "composed", and the
    // empty state must not read "Your Your day".
    const title =
        planMode && weekday
            ? `Your ${weekday}`
            : intent === 'bureaucracy_q'
              ? 'Paperwork'
              : intent === 'find'
                ? 'Search'
                : intent === 'take_me_there'
                  ? 'Directions'
                  : 'Day Composer';

    const subtitle =
        planMode && prompt
            ? `composed from “${prompt}”`
            : !planMode && prompt
              ? `you asked: “${prompt}”`
              : 'tell the composer what you want';

    const showInterim = !parsing && intent !== 'plan_day' && !plan;

    // ── Editable-sentence wiring (v4) — the kept "when" + "doing" tokens ──
    const whenKey = constraints ? timePresetKey(constraints) : 'afternoon';
    const whenLabel =
        TIME_OPTS.find((o) => o.value === whenKey)?.label ?? 'Afternoon';
    const doingValue = constraints?.categories[0] ?? '';
    const doingLabel = doingValue
        ? (facets.categories.find((c) => c.value === doingValue)?.label ??
          doingValue)
        : 'anything';
    const doingTokenOpts = [
        { value: '', label: 'Anything' },
        ...facets.categories,
    ];

    // ── Places-style area picker + From control wiring ──
    const places = savedPlaces ?? [];
    // A single named area reads as a picked Veedel; the picker drills into it.
    const veedel =
        constraints?.areas?.length === 1 ? constraints.areas[0]! : null;
    const areaLabel =
        bezirk === 'near'
            ? 'Near you'
            : (veedel ?? (bezirk === 'all' ? 'all Cologne' : bezirk));
    const originLabel = locating
        ? 'Locating…'
        : (origin?.label ?? 'Your location');
    const railOptions = bezirke ?? [];
    const chipOptions =
        bezirk !== 'all' && bezirk !== 'near'
            ? (veedelsByBezirk?.[bezirk] ?? [])
            : [];
    // Which From row reads as active: an explicit pick wins; otherwise a real
    // GPS-derived origin highlights "My location".
    const selectedKey =
        fromOverride?.kind === 'place'
            ? `place:${fromOverride.id}`
            : fromOverride?.kind === 'point'
              ? 'point'
              : origin && ['live', 'confirmed', 'ping'].includes(origin.source)
                ? 'live'
                : null;

    const chipOptStyle = (sel: boolean, cyan: boolean) =>
        sel
            ? cyan
                ? 'border-cyan bg-cyan-soft font-semibold text-cyan-h'
                : 'border-primary bg-primary-soft font-semibold text-primary'
            : 'border-border bg-card font-medium text-text-2 hover:border-primary';

    /**
     * A tappable word in the sentence whose popover is a rich panel (the
     * Places-style area picker / From control) rather than a chip list. Same
     * inline-word trigger + under-the-word positioning as renderToken.
     */
    function renderPanelToken(
        key: string,
        label: ReactNode,
        cyan: boolean,
        width: number,
        panel: ReactNode,
    ) {
        const open = openToken === key;
        const underline = cyan
            ? 'border-cyan'
            : open
              ? 'border-primary'
              : 'border-text-3';

        return (
            <span className="relative inline-block">
                <button
                    onClick={(e) => {
                        if (open) {
                            setOpenToken(null);
                        } else {
                            setTokenPopStyle(
                                tokenPopoverStyle(e.currentTarget, width),
                            );
                            setOpenToken(key);
                        }
                    }}
                    className={`inline border-b-2 px-px font-semibold ${open ? 'border-solid' : 'border-dotted'} ${underline} ${cyan ? 'text-cyan-h' : open ? 'text-primary' : 'text-foreground'}`}
                >
                    {label}
                </button>
                {open && (
                    <>
                        <div
                            className="fixed inset-0 z-[290]"
                            onClick={() => setOpenToken(null)}
                        />
                        <div
                            style={tokenPopStyle ?? undefined}
                            className={`z-[300] overflow-y-auto rounded-[16px] border bg-card p-[15px] text-left shadow-[0_14px_40px_rgba(20,16,8,0.18)] ${cyan ? 'border-cyan-bd' : 'border-border'}`}
                        >
                            {panel}
                        </div>
                    </>
                )}
            </span>
        );
    }

    /** One tappable word in the sentence + its inline option popover. */
    function renderToken(
        key: string,
        label: string,
        cyan: boolean,
        title: string,
        options: { value: string; label: string }[],
        current: string,
        onPick: (value: string) => void,
    ) {
        const open = openToken === key;
        const underline = cyan
            ? 'border-cyan'
            : open
              ? 'border-primary'
              : 'border-text-3';

        return (
            <span className="relative inline-block">
                <button
                    onClick={(e) => {
                        if (open) {
                            setOpenToken(null);
                        } else {
                            setTokenPopStyle(
                                tokenPopoverStyle(e.currentTarget),
                            );
                            setOpenToken(key);
                        }
                    }}
                    className={`inline border-b-2 px-px font-semibold ${open ? 'border-solid' : 'border-dotted'} ${underline} ${cyan ? 'text-cyan-h' : open ? 'text-primary' : 'text-foreground'}`}
                >
                    {label}
                </button>
                {open && (
                    <>
                        <div
                            className="fixed inset-0 z-[290]"
                            onClick={() => setOpenToken(null)}
                        />
                        <div
                            style={tokenPopStyle ?? undefined}
                            className={`z-[300] overflow-y-auto rounded-[16px] border bg-card p-[15px] text-left shadow-[0_14px_40px_rgba(20,16,8,0.18)] ${cyan ? 'border-cyan-bd' : 'border-border'}`}
                        >
                            <div
                                className={`mb-[11px] font-mono text-[10px] tracking-[0.1em] uppercase ${cyan ? 'text-cyan-h' : 'text-text-3'}`}
                            >
                                {title}
                            </div>
                            <div className="flex flex-wrap gap-[7px]">
                                {options.map((o) => (
                                    <button
                                        key={o.value}
                                        onClick={() => {
                                            onPick(o.value);
                                            setOpenToken(null);
                                        }}
                                        className={`rounded-full border px-3.5 py-2 text-[13.5px] transition-colors ${chipOptStyle(o.value === current, cyan)}`}
                                    >
                                        {o.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </>
                )}
            </span>
        );
    }

    // Extra-filter chips that are currently set (Who with / Budget / Vibe).
    const EXTRA_DEFS = [
        {
            field: 'companions' as const,
            Icon: IconUsers,
            label: 'Who with',
            opts: COMPANION_OPTS,
        },
        {
            field: 'budget' as const,
            Icon: IconCurrencyEuro,
            label: 'Budget',
            opts: BUDGET_OPTS,
        },
        {
            field: 'vibe' as const,
            Icon: IconAdjustmentsHorizontal,
            label: 'Vibe',
            opts: VIBE_OPTS,
        },
    ];
    const activeExtras = constraints
        ? EXTRA_DEFS.filter((d) => constraints[d.field])
        : [];
    const addableExtras = constraints
        ? EXTRA_DEFS.filter((d) => !constraints[d.field])
        : [];
    const openExtra = EXTRA_DEFS.find((d) => d.field === openToken) ?? null;

    return (
        <AppLayout>
            <Head title="Day Composer" />
            <div className="mx-auto w-full max-w-[680px] px-4 pt-6 pb-24 md:px-6">
                <h1 className="font-display text-[27px] font-medium tracking-[-0.02em]">
                    {title}
                </h1>
                <p className="mt-[3px] mb-5 text-[14px] text-text-2">
                    {subtitle}
                </p>

                {parsing && (
                    <div className="mb-4 flex gap-2">
                        {[1, 2, 3].map((i) => (
                            <div
                                key={i}
                                className="h-8 w-28 animate-pulse rounded-full bg-secondary"
                            />
                        ))}
                    </div>
                )}

                {showInterim && (
                    <InterimRoute intent={intent} query={prompt ?? ''} />
                )}

                {constraints && (
                    <>
                        <div className="mt-5 font-mono text-[10px] tracking-[0.1em] text-text-3 uppercase">
                            I understood — tap any word
                        </div>
                        <div className="mt-[11px] text-[19px] leading-[1.7] tracking-[-0.01em] text-text-2">
                            {renderToken(
                                'when',
                                whenLabel,
                                false,
                                'When?',
                                TIME_OPTS,
                                whenKey,
                                (v) =>
                                    updateConstraint(
                                        timeWindowPatch(v, constraints),
                                    ),
                            )}{' '}
                            around{' '}
                            {renderPanelToken(
                                'area',
                                areaLabel,
                                false,
                                360,
                                <AreaPickerPanel
                                    bezirk={bezirk}
                                    veedel={veedel}
                                    railOptions={railOptions}
                                    chipOptions={chipOptions}
                                    onAllCologne={() => {
                                        setBezirk('all');
                                        updateConstraint({ areas: [] });
                                        setOpenToken(null);
                                    }}
                                    onNearMe={() => {
                                        setBezirk('near');
                                        updateConstraint({ areas: [] });
                                        pickMyLocation();
                                    }}
                                    onPickBezirk={(name) => {
                                        setBezirk(name);
                                        updateConstraint({
                                            areas:
                                                veedelsByBezirk?.[name] ?? [],
                                        });
                                    }}
                                    onPickVeedel={(v) => {
                                        updateConstraint({
                                            areas: v
                                                ? [v]
                                                : bezirk !== 'all' &&
                                                    bezirk !== 'near'
                                                  ? (veedelsByBezirk?.[
                                                        bezirk
                                                    ] ?? [])
                                                  : [],
                                        });
                                        setOpenToken(null);
                                    }}
                                />,
                            )}
                            , for{' '}
                            {renderToken(
                                'doing',
                                doingLabel,
                                false,
                                'Doing what?',
                                doingTokenOpts,
                                doingValue,
                                (v) => {
                                    const cats = v ? [v] : [];
                                    setAmount(defaultAmount(cats));
                                    updateConstraint({ categories: cats });
                                },
                            )}{' '}
                            — from{' '}
                            {renderPanelToken(
                                'origin',
                                originLabel,
                                true,
                                300,
                                <FromPanel
                                    mode={mode}
                                    locating={locating}
                                    savedPlaces={places}
                                    query={fromQuery}
                                    results={fromResults}
                                    selectedKey={selectedKey}
                                    onSearch={searchFrom}
                                    onApply={applyFrom}
                                    onMyLocation={pickMyLocation}
                                    onPickOnMap={pickOnMap}
                                    onMode={persistMode}
                                />,
                            )}
                            .
                        </div>

                        {/* Extra-filter chips + add */}
                        <div className="mt-3.5 flex flex-wrap items-center gap-2">
                            {activeExtras.map((d) => {
                                const value = constraints[d.field];
                                const optLabel =
                                    d.opts.find((o) => o.value === value)
                                        ?.label ?? value;
                                const ExtraIcon = d.Icon;

                                return (
                                    <span
                                        key={d.field}
                                        className="inline-flex items-center gap-[7px] rounded-full border border-border bg-card py-[5px] pr-[7px] pl-3 text-[13px] font-semibold text-foreground shadow-card"
                                    >
                                        <button
                                            onClick={() => {
                                                setOpenToken(d.field);
                                                setAddOpen(false);
                                            }}
                                            className="flex cursor-pointer items-center gap-1.5"
                                        >
                                            <ExtraIcon
                                                size={14}
                                                stroke={ICON_STROKE}
                                                className="text-text-3"
                                            />
                                            {optLabel}
                                        </button>
                                        <button
                                            onClick={() =>
                                                updateConstraint({
                                                    [d.field]: null,
                                                } as Partial<Constraints>)
                                            }
                                            aria-label={`Remove ${d.label}`}
                                            className="flex size-[19px] items-center justify-center rounded-full bg-surface-2 text-text-3 transition-colors hover:text-foreground"
                                        >
                                            <IconX size={11} stroke={2.6} />
                                        </button>
                                    </span>
                                );
                            })}
                            {addableExtras.length > 0 && (
                                <button
                                    onClick={() => {
                                        setAddOpen((o) => !o);
                                        setOpenToken(null);
                                    }}
                                    className="inline-flex items-center gap-1.5 rounded-full border border-dashed border-border px-3.5 py-1.5 text-[13px] font-semibold text-text-3 transition-colors hover:border-primary hover:text-primary"
                                >
                                    <IconPlus size={13} stroke={2.4} />
                                    filter
                                </button>
                            )}
                        </div>

                        {/* Extra-chip edit panel */}
                        {openExtra && (
                            <div className="mt-3.5 rounded-[14px] border border-border bg-card p-4 shadow-[0_12px_34px_rgba(20,16,8,0.14)]">
                                <div className="mb-3 flex items-center justify-between">
                                    <span className="font-mono text-[11px] tracking-[0.08em] text-text-2 uppercase">
                                        {openExtra.label}
                                    </span>
                                    <button
                                        onClick={() => setOpenToken(null)}
                                        className="flex size-6 items-center justify-center rounded-full bg-surface-2 text-text-3"
                                    >
                                        <IconX size={13} stroke={2.4} />
                                    </button>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {openExtra.opts
                                        .filter((o) => o.value)
                                        .map((o) => (
                                            <button
                                                key={o.value}
                                                onClick={() => {
                                                    updateConstraint({
                                                        [openExtra.field]:
                                                            o.value,
                                                    } as Partial<Constraints>);
                                                    setOpenToken(null);
                                                }}
                                                className={`rounded-full border px-3.5 py-2 text-[13.5px] transition-colors ${chipOptStyle(constraints[openExtra.field] === o.value, false)}`}
                                            >
                                                {o.label}
                                            </button>
                                        ))}
                                </div>
                            </div>
                        )}

                        {/* Add-filter panel */}
                        {addOpen && addableExtras.length > 0 && (
                            <div className="mt-3.5 rounded-[14px] border border-border bg-card p-4 shadow-[0_12px_34px_rgba(20,16,8,0.14)]">
                                <div className="mb-3 flex items-center justify-between">
                                    <span className="font-mono text-[11px] tracking-[0.08em] text-text-2 uppercase">
                                        Add a filter
                                    </span>
                                    <button
                                        onClick={() => setAddOpen(false)}
                                        className="flex size-6 items-center justify-center rounded-full bg-surface-2 text-text-3"
                                    >
                                        <IconX size={13} stroke={2.4} />
                                    </button>
                                </div>
                                <div className="grid grid-cols-2 gap-2">
                                    {addableExtras.map((d) => {
                                        const ExtraIcon = d.Icon;

                                        return (
                                            <button
                                                key={d.field}
                                                onClick={() => {
                                                    updateConstraint({
                                                        [d.field]:
                                                            d.opts.find(
                                                                (o) => o.value,
                                                            )?.value ?? null,
                                                    } as Partial<Constraints>);
                                                    setAddOpen(false);
                                                }}
                                                className="flex items-center gap-2.5 rounded-[11px] border border-border bg-card px-3 py-[11px] text-left text-[13.5px] font-medium text-text-2 transition-colors hover:border-primary"
                                            >
                                                <ExtraIcon
                                                    size={16}
                                                    stroke={ICON_STROKE}
                                                    className="text-text-3"
                                                />
                                                {d.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                        {/* Amount toggle */}
                        <div className="mt-6 font-mono text-[10px] tracking-[0.1em] text-text-3 uppercase">
                            How much day?
                        </div>
                        <div className="mt-2.5 flex max-w-[380px] gap-[3px] rounded-[12px] border border-border bg-surface-2 p-1">
                            {(
                                [
                                    ['just', 'Just this'],
                                    ['few', 'A few options'],
                                    ['full', 'Full day'],
                                ] as const
                            ).map(([val, lbl]) => (
                                <button
                                    key={val}
                                    onClick={() => setAmount(val)}
                                    className={`flex-1 rounded-[8px] px-1.5 py-[9px] text-[12.5px] transition-colors ${amount === val ? 'bg-card font-semibold text-primary shadow-sm' : 'font-medium text-text-2'}`}
                                >
                                    {lbl}
                                </button>
                            ))}
                        </div>
                    </>
                )}

                {/* Cyan weather/river note — the plan's single "locating" line */}
                {plan && weather && (
                    <div className="mt-4 flex items-center gap-2.5 rounded-[11px] bg-cyan-soft px-[13px] py-2.5">
                        {weather.rain ? (
                            <IconCloudRain
                                size={16}
                                stroke={1.9}
                                className="shrink-0 text-cyan-h"
                            />
                        ) : (
                            <IconRipple
                                size={16}
                                stroke={1.9}
                                className="shrink-0 text-cyan-h"
                            />
                        )}
                        <span className="text-[13px] font-semibold text-cyan-h">
                            {weather.text}
                        </span>
                    </div>
                )}

                {plan && <NoticeChips notices={notices} />}

                {error && (
                    <div className="mb-4 rounded-[9px] bg-danger-soft px-3.5 py-2.5 text-[13px] text-danger">
                        {error}
                    </div>
                )}

                {composing && (
                    <div className="flex flex-col gap-3">
                        {[1, 2, 3].map((i) => (
                            <div
                                key={i}
                                className="h-[104px] animate-pulse rounded-[14px] bg-secondary"
                            />
                        ))}
                    </div>
                )}

                {/* Result — Just this / A few options / Full day */}
                {plan && !composing && (
                    <div className="mt-[18px]">
                        {plan.slots.length === 0 ? (
                            <div className="rounded-[14px] border border-border bg-card p-6 text-center text-sm text-text-2">
                                Nothing’s open across that window — widen the
                                time or try another day.
                            </div>
                        ) : amount === 'just' ? (
                            (() => {
                                const slot = plan.slots[0];

                                return (
                                    <div className="overflow-hidden rounded-[16px] border border-border bg-card shadow-card">
                                        <div
                                            className={`relative flex h-[120px] items-center justify-center ${categoryClass(slot.category)}`}
                                            style={{
                                                background:
                                                    'var(--cat-tint, var(--surface-2))',
                                            }}
                                        >
                                            <span className="text-[62px] leading-none">
                                                {slotEmoji(slot)}
                                            </span>
                                            <span
                                                className="absolute top-[13px] left-[14px] rounded-full bg-card px-[11px] py-[5px] font-mono text-[10px] font-semibold tracking-[0.06em] uppercase shadow-card"
                                                style={{
                                                    color: 'var(--cat-mark, var(--text-2))',
                                                }}
                                            >
                                                {slot.category}
                                            </span>
                                            <span className="absolute top-[13px] right-[14px] rounded-full bg-card px-[11px] py-[5px] font-mono text-[12px] font-semibold text-text-2 shadow-card">
                                                {slot.duration_label}
                                            </span>
                                        </div>
                                        <div className="p-[18px]">
                                            <h3 className="font-display text-[23px] leading-[1.05] font-semibold tracking-[-0.015em]">
                                                {slot.name}
                                            </h3>
                                            {slot.why && (
                                                <p className="mt-[7px] text-[14px] leading-[1.5] text-text-2">
                                                    {slot.why}
                                                </p>
                                            )}
                                            <div className="mt-3 flex items-center gap-3 font-mono text-[12px] text-cyan-h">
                                                {slot.travel_min_from_previous >
                                                    0 && (
                                                    <span>
                                                        {
                                                            slot.travel_min_from_previous
                                                        }{' '}
                                                        min away
                                                    </span>
                                                )}
                                                {costLabel(slot.cost_tier) && (
                                                    <span className="text-text-3">
                                                        {costLabel(
                                                            slot.cost_tier,
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                            <div className="mt-[17px] flex gap-2.5">
                                                <button
                                                    onClick={shuffle}
                                                    className="inline-flex items-center justify-center gap-[7px] rounded-[11px] border border-border bg-card px-[15px] py-[11px] text-[13.5px] font-semibold text-text-2 transition-colors hover:border-primary"
                                                >
                                                    <IconArrowsShuffle
                                                        size={15}
                                                        stroke={ICON_STROKE}
                                                    />
                                                    Another
                                                </button>
                                                <button
                                                    onClick={() =>
                                                        takeMeThere(slot)
                                                    }
                                                    className="flex flex-1 items-center justify-center gap-2 rounded-[11px] bg-primary py-[11px] text-[14px] font-semibold text-white transition-colors hover:bg-accent-hover"
                                                >
                                                    → Take me there
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })()
                        ) : amount === 'few' ? (
                            <>
                                <div className="mb-[11px] font-mono text-[10px] tracking-[0.08em] text-text-3 uppercase">
                                    {options.length} picks near {areaLabel} ·
                                    tap to explore
                                </div>
                                {options.map((slot) => {
                                    const tappable =
                                        !slot.is_appointment &&
                                        slot.id.startsWith('spot:');

                                    return (
                                        <button
                                            key={slot.id}
                                            onClick={() =>
                                                tappable
                                                    ? openSlotDetail(slot)
                                                    : takeMeThere(slot)
                                            }
                                            className={`mb-2.5 flex w-full items-center gap-3.5 rounded-[14px] border border-border bg-card p-[15px] text-left shadow-card transition-colors hover:border-primary ${categoryClass(slot.category)}`}
                                        >
                                            <span
                                                className="flex size-[46px] flex-none items-center justify-center rounded-[12px] text-[23px]"
                                                style={{
                                                    background:
                                                        'var(--cat-tint, var(--surface-2))',
                                                }}
                                            >
                                                {slotEmoji(slot)}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="font-display text-[17px] leading-[1.1] font-semibold">
                                                    {slot.name}
                                                </div>
                                                {slot.why && (
                                                    <div className="mt-[3px] text-[13px] leading-[1.4] text-text-2">
                                                        {slot.why}
                                                    </div>
                                                )}
                                                <div className="mt-1.5 font-mono text-[11.5px] text-cyan-h">
                                                    {[
                                                        slot.veedel,
                                                        slot.duration_label,
                                                        costLabel(
                                                            slot.cost_tier,
                                                        ),
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </div>
                                            </div>
                                        </button>
                                    );
                                })}
                            </>
                        ) : (
                            <div>
                                {plan.slots.map((slot, i) => {
                                    const isLocked = locked.includes(slot.id);
                                    const tappable =
                                        !slot.is_appointment &&
                                        slot.id.startsWith('spot:');

                                    return (
                                        <div
                                            key={slot.id}
                                            className="flex gap-3.5"
                                        >
                                            <div className="flex w-[54px] flex-none flex-col items-center pt-0.5">
                                                <span className="font-mono text-[11px] font-semibold text-cyan-h">
                                                    {slot.start_time}
                                                </span>
                                                <span className="mt-2 size-[11px] rounded-full border-[2.5px] border-cyan bg-card" />
                                                {i < plan.slots.length - 1 && (
                                                    <span
                                                        className="mt-1 w-0.5 flex-1 bg-cyan-soft"
                                                        style={{
                                                            minHeight: 30,
                                                        }}
                                                    />
                                                )}
                                            </div>
                                            <div
                                                className={`mb-3.5 min-w-0 flex-1 rounded-[14px] border bg-card p-[15px] shadow-card ${isLocked ? 'border-primary' : 'border-border'} ${categoryClass(slot.category)}`}
                                            >
                                                <div className="mb-2 flex items-center gap-2">
                                                    <span
                                                        className="rounded-full px-[9px] py-[3px] font-mono text-[10px] font-semibold tracking-[0.06em] uppercase"
                                                        style={{
                                                            background:
                                                                'var(--cat-tint, var(--surface-2))',
                                                            color: 'var(--cat-mark, var(--text-2))',
                                                        }}
                                                    >
                                                        {slot.band} ·{' '}
                                                        {slot.category}
                                                    </span>
                                                    {isLocked && (
                                                        <span className="inline-flex items-center gap-1 font-mono text-[10px] font-semibold text-primary">
                                                            <IconPin
                                                                size={11}
                                                                stroke={2.1}
                                                            />
                                                            locked
                                                        </span>
                                                    )}
                                                    <span className="ml-auto font-mono text-[11px] text-text-3">
                                                        {slot.duration_label}
                                                    </span>
                                                </div>
                                                <div
                                                    role={
                                                        tappable
                                                            ? 'button'
                                                            : undefined
                                                    }
                                                    tabIndex={
                                                        tappable ? 0 : undefined
                                                    }
                                                    onClick={
                                                        tappable
                                                            ? () =>
                                                                  openSlotDetail(
                                                                      slot,
                                                                  )
                                                            : undefined
                                                    }
                                                    onKeyDown={
                                                        tappable
                                                            ? (e) => {
                                                                  if (
                                                                      e.key ===
                                                                          'Enter' ||
                                                                      e.key ===
                                                                          ' '
                                                                  ) {
                                                                      e.preventDefault();
                                                                      openSlotDetail(
                                                                          slot,
                                                                      );
                                                                  }
                                                              }
                                                            : undefined
                                                    }
                                                    className={`flex items-start gap-3 ${tappable ? 'cursor-pointer' : ''}`}
                                                >
                                                    <span
                                                        className="flex size-[42px] flex-none items-center justify-center rounded-[11px] text-[21px]"
                                                        style={{
                                                            background:
                                                                'var(--cat-tint, var(--surface-2))',
                                                        }}
                                                    >
                                                        {slotEmoji(slot)}
                                                    </span>
                                                    <div className="min-w-0 flex-1">
                                                        <div className="font-display text-[17px] leading-[1.1] font-semibold">
                                                            {slot.name}
                                                        </div>
                                                        {slot.why && (
                                                            <div className="mt-[3px] text-[12.5px] leading-[1.4] text-text-2">
                                                                {slot.why}
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="mt-3 flex gap-[7px]">
                                                    <button
                                                        onClick={() =>
                                                            toggleLock(slot.id)
                                                        }
                                                        className={`inline-flex items-center gap-1.5 rounded-[9px] border px-3 py-2 text-[12.5px] font-semibold transition-colors ${isLocked ? 'border-primary bg-primary-soft text-primary' : 'border-border bg-card text-text-2 hover:border-primary'}`}
                                                    >
                                                        <IconPin
                                                            size={13}
                                                            stroke={ICON_STROKE}
                                                        />
                                                        {isLocked
                                                            ? 'Locked'
                                                            : 'Lock'}
                                                    </button>
                                                    {slot.swappable && (
                                                        <button
                                                            onClick={() =>
                                                                swap(i)
                                                            }
                                                            disabled={
                                                                swappingSlot ===
                                                                i
                                                            }
                                                            className="inline-flex items-center gap-1.5 rounded-[9px] border border-border bg-card px-3 py-2 text-[12.5px] font-semibold text-text-2 transition-colors hover:border-primary disabled:opacity-50"
                                                        >
                                                            <IconArrowsShuffle
                                                                size={13}
                                                                stroke={
                                                                    ICON_STROKE
                                                                }
                                                            />
                                                            Swap
                                                        </button>
                                                    )}
                                                    <button
                                                        onClick={() =>
                                                            takeMeThere(slot)
                                                        }
                                                        className="ml-auto inline-flex items-center gap-1.5 rounded-[9px] bg-cyan-soft px-3 py-2 text-[12.5px] font-semibold text-cyan-h"
                                                    >
                                                        → Go
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}

                        {/* Whole-plan actions */}
                        {plan.slots.length > 0 && (
                            <>
                                <div className="mt-[18px] flex items-center gap-2.5">
                                    <button
                                        onClick={shuffle}
                                        disabled={composing}
                                        className="inline-flex items-center gap-2 rounded-[12px] border border-border bg-card px-4 py-3 text-[13.5px] font-semibold text-text-2 transition-colors hover:border-primary disabled:opacity-50"
                                    >
                                        <IconArrowsShuffle
                                            size={15}
                                            stroke={ICON_STROKE}
                                        />
                                        Shuffle
                                    </button>
                                    <button
                                        onClick={saveToToday}
                                        disabled={saving}
                                        className="flex-1 rounded-[12px] border border-transparent bg-foreground py-[13px] text-[15px] font-semibold text-background transition-opacity hover:opacity-90 disabled:opacity-60 dark:border-border dark:bg-secondary dark:text-foreground"
                                    >
                                        {saving ? 'Saving…' : 'Save to Today'}
                                    </button>
                                </div>
                                <p className="mt-3 flex items-center justify-center gap-1.5 text-center text-[12px] text-text-3">
                                    <IconPin size={12} stroke={ICON_STROKE} />
                                    Locked picks stay when you shuffle.
                                </p>
                            </>
                        )}
                    </div>
                )}

                {!constraints && !parsing && !showInterim && (
                    <div className="rounded-[14px] border border-border bg-card p-8 text-center">
                        <IconSparkles
                            size={32}
                            stroke={ICON_STROKE}
                            className="mx-auto mb-2 text-muted-foreground"
                        />
                        <p className="mb-4 text-sm text-muted-foreground">
                            Tell me about your day and I'll compose it — or
                            start from one of these:
                        </p>
                        <div className="flex flex-wrap justify-center gap-2">
                            {[
                                'Free afternoon nearby',
                                'Something to do today',
                                'Plan my weekend',
                            ].map((starter) => (
                                <button
                                    key={starter}
                                    onClick={() =>
                                        router.visit(
                                            `/composer?prompt=${encodeURIComponent(starter)}`,
                                        )
                                    }
                                    className="rounded-full border border-border px-3.5 py-1.5 text-[13px] font-medium text-foreground transition-colors hover:border-primary hover:text-primary"
                                >
                                    {starter}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {detail && (
                <PlaceDetailModal
                    place={detail}
                    isMobile={isMobile}
                    meta={detailMeta(detail)}
                    feedback={{
                        state: stateFor(detail.id) ?? detail.feedback_state,
                        rating: ratingFor(detail.id) ?? detail.feedback_rating,
                        onAction: (action, rating) =>
                            setFeedback(detail.id, action, rating),
                    }}
                    onClose={() => setDetail(null)}
                    onNavigate={(target) => {
                        setDetail(null);
                        // Start the route from the plan's own origin — the same
                        // point the card's "N min away" was measured against —
                        // just like the plan-slot take-me-there does, so the two
                        // never contradict each other.
                        setDestination({
                            ...target,
                            fromLat: origin?.lat ?? null,
                            fromLng: origin?.lng ?? null,
                            fromName: origin?.label ?? null,
                        });
                    }}
                />
            )}

            {destination && (
                <TakeMeThereSheet
                    destination={destination}
                    onClose={() => setDestination(null)}
                />
            )}

            {/* Pick-on-map overlay — a tap sets the From point. */}
            {mapPickOpen && (
                <div
                    className="fixed inset-0 z-[400] flex items-center justify-center bg-black/45 p-4"
                    onClick={() => setMapPickOpen(false)}
                >
                    <div
                        className="relative h-[80vh] w-full max-w-[680px] overflow-hidden rounded-[16px] border border-border bg-card"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <PlacesMap
                            places={[]}
                            emojiFor={() => ''}
                            metaFor={() => ''}
                            onOpen={() => {}}
                            onTakeMeThere={() => {}}
                            pickMode
                            onMapPick={(lat, lng) =>
                                void handleMapPick(lat, lng)
                            }
                            onCancelPick={() => setMapPickOpen(false)}
                        />
                    </div>
                </div>
            )}

            <FeedbackToast message={toast} />
        </AppLayout>
    );
}
