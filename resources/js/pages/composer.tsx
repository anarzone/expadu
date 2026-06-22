import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    IconAdjustmentsHorizontal,
    IconArrowsShuffle,
    IconBus,
    IconChecklist,
    IconChevronDown,
    IconClock,
    IconCoinEuro,
    IconCompass,
    IconLeaf,
    IconMapPin,
    IconPin,
    IconRefresh,
    IconSearch,
    IconSparkles,
    IconStar,
    IconSun,
    IconTag,
    IconTarget,
    IconUsers,
    IconX,
} from '@tabler/icons-react';
import type { IconProps } from '@tabler/icons-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ComponentType } from 'react';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import {
    categoryEmoji,
    categoryIcon,
} from '@/components/places/category-illustration';
import type { PlacesOrigin } from '@/components/places/from-control';
import { PlaceDetailModal } from '@/components/places/place-detail-modal';
import { FeedbackToast } from '@/components/places/place-feedback-menu';
import type { Place } from '@/components/places/types';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ICON_STROKE } from '@/constants/icons';
import { useFeedback } from '@/hooks/use-feedback';
import { useIsMobile } from '@/hooks/use-mobile';
import { useTracker } from '@/hooks/use-tracker';
import AppLayout from '@/layouts/app-layout';

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

const ARCHETYPES: {
    key: string;
    label: string;
    Icon: ComponentType<IconProps>;
}[] = [
    { key: 'one_main', label: 'One main + supports', Icon: IconTarget },
    { key: 'explore_veedel', label: 'Explore a Veedel', Icon: IconCompass },
    { key: 'chill', label: 'Chill nearby', Icon: IconLeaf },
    { key: 'make_a_day', label: 'Make a day of it', Icon: IconSun },
];

const BANDS = ['Morning', 'Midday', 'Afternoon', 'Evening'];

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

function toggleIn(list: string[], value: string): string[] {
    return list.includes(value)
        ? list.filter((v) => v !== value)
        : [...list, value];
}

/** A time preset → a window on the plan's existing day. */
function timeWindowPatch(preset: string, c: Constraints): Partial<Constraints> {
    const day = new Date(c.window_start);
    const at = (h: number) => {
        const d = new Date(day);
        d.setHours(h, 0, 0, 0);

        return d.toISOString();
    };
    const [s, e] = TIME_RANGES[preset] ?? TIME_RANGES.afternoon;

    return { window_start: at(s), window_end: at(e) };
}

/** Which preset the current window reads as — for the chip + highlight. */
function timePresetKey(c: Constraints): string {
    const h = new Date(c.window_start).getHours();
    const end = new Date(c.window_end).getHours();

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

const CHIP =
    'flex shrink-0 cursor-pointer items-center gap-1 rounded-full border px-3 py-1.5 text-[13px] font-medium outline-none transition-colors';

function FilterTrigger({
    label,
    active,
    Icon,
}: {
    label: string;
    active: boolean;
    Icon: ComponentType<IconProps>;
}) {
    return (
        <DropdownMenuTrigger
            className={`${CHIP} ${active ? 'border-primary bg-accent-soft text-primary' : 'border-border bg-card text-foreground hover:border-primary'}`}
        >
            <Icon size={14} stroke={ICON_STROKE} className="opacity-70" />
            {label}
            <IconChevronDown
                size={13}
                stroke={ICON_STROKE}
                className="opacity-60"
            />
        </DropdownMenuTrigger>
    );
}

function SingleFilter({
    label,
    active,
    value,
    options,
    onChange,
    Icon,
}: {
    label: string;
    active: boolean;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
    Icon: ComponentType<IconProps>;
}) {
    return (
        <DropdownMenu>
            <FilterTrigger label={label} active={active} Icon={Icon} />
            <DropdownMenuContent align="start" className="w-48">
                <DropdownMenuRadioGroup value={value} onValueChange={onChange}>
                    {options.map((o) => (
                        <DropdownMenuRadioItem key={o.value} value={o.value}>
                            {o.label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function MultiFilter({
    label,
    active,
    selected,
    options,
    onToggle,
    Icon,
}: {
    label: string;
    active: boolean;
    selected: string[];
    options: { value: string; label: string }[];
    onToggle: (value: string) => void;
    Icon: ComponentType<IconProps>;
}) {
    return (
        <DropdownMenu>
            <FilterTrigger label={label} active={active} Icon={Icon} />
            <DropdownMenuContent
                align="start"
                className="max-h-72 w-52 overflow-y-auto"
            >
                {options.length === 0 ? (
                    <div className="px-2 py-1.5 text-[13px] text-muted-foreground">
                        Nothing to choose yet
                    </div>
                ) : (
                    options.map((o) => (
                        <DropdownMenuCheckboxItem
                            key={o.value}
                            checked={selected.includes(o.value)}
                            onCheckedChange={() => onToggle(o.value)}
                            onSelect={(e) => e.preventDefault()}
                        >
                            {o.label}
                        </DropdownMenuCheckboxItem>
                    ))
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Short summary for a multi-select chip: "Culture +2", or a default. */
function multiLabel(
    selected: string[],
    options: { value: string; label: string }[],
    empty: string,
): string {
    if (selected.length === 0) {
        return empty;
    }

    const first =
        options.find((o) => o.value === selected[0])?.label ?? selected[0];
    const extra = selected.length - 1;

    return `${first}${extra > 0 ? ` +${extra}` : ''}`;
}

/**
 * Editable, content-adaptive filter chips — the prototype's "I understood,
 * tap to correct" bar. Each chip is a dropdown; changing any recomposes.
 * Area + category options are facets (only what has candidates), so the
 * dropdowns offer real choices, not all 25 Veedels.
 */
function EditableFilters({
    constraints,
    facets,
    homeVeedel,
    origin,
    locating,
    onLocate,
    onChange,
    onStartOver,
}: {
    constraints: Constraints;
    facets: Facets;
    homeVeedel: string | null;
    origin: PlacesOrigin | null;
    locating: boolean;
    onLocate: () => void;
    onChange: (patch: Partial<Constraints>) => void;
    onStartOver: () => void;
}) {
    const areaOptions = facets.areas.map((a) => ({ value: a, label: a }));
    const companion = constraints.companions ?? '';
    const budget = constraints.budget ?? '';
    const vibe = constraints.vibe ?? '';
    const edited =
        constraints.areas.length > 0 ||
        constraints.categories.length > 0 ||
        companion !== '' ||
        budget !== '' ||
        vibe !== '';

    // Where the plan starts from — the same shared origin the Places list uses.
    const fromKnown = origin !== null && origin.source !== 'none';
    const fromLabel = locating
        ? 'Locating…'
        : origin === null || origin.source === 'none'
          ? 'Set start'
          : origin.source === 'area'
            ? `From ${origin.label ?? 'area'}`
            : (origin.label ?? 'Your location');

    return (
        <div className="mb-5">
            <div className="mb-2 font-mono text-[10px] tracking-[0.1em] text-muted-foreground/70 uppercase">
                I understood — tap any to change
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={onLocate}
                    aria-label="Set where the plan starts from"
                    className={`${CHIP} ${fromKnown ? 'border-primary bg-accent-soft text-primary' : 'border-border bg-card text-foreground hover:border-primary'}`}
                >
                    <IconMapPin
                        size={14}
                        stroke={ICON_STROKE}
                        className="opacity-70"
                    />
                    {fromLabel}
                </button>
                <SingleFilter
                    label={
                        TIME_OPTS.find(
                            (o) => o.value === timePresetKey(constraints),
                        )?.label ?? 'When'
                    }
                    Icon={IconClock}
                    active
                    value={timePresetKey(constraints)}
                    options={TIME_OPTS}
                    onChange={(v) => onChange(timeWindowPatch(v, constraints))}
                />
                <MultiFilter
                    label={
                        constraints.areas.length === 0 && homeVeedel
                            ? `Around ${homeVeedel}`
                            : multiLabel(
                                  constraints.areas,
                                  areaOptions,
                                  'Any area',
                              )
                    }
                    Icon={IconMapPin}
                    active={constraints.areas.length > 0}
                    selected={constraints.areas}
                    options={areaOptions}
                    onToggle={(v) =>
                        onChange({ areas: toggleIn(constraints.areas, v) })
                    }
                />
                <MultiFilter
                    label={multiLabel(
                        constraints.categories,
                        facets.categories,
                        'Anything',
                    )}
                    Icon={IconTag}
                    active={constraints.categories.length > 0}
                    selected={constraints.categories}
                    options={facets.categories}
                    onToggle={(v) =>
                        onChange({
                            categories: toggleIn(constraints.categories, v),
                        })
                    }
                />
                <SingleFilter
                    label={
                        COMPANION_OPTS.find((o) => o.value === companion)
                            ?.label ?? 'Anyone'
                    }
                    Icon={IconUsers}
                    active={companion !== ''}
                    value={companion}
                    options={COMPANION_OPTS}
                    onChange={(v) => onChange({ companions: v || null })}
                />
                <SingleFilter
                    label={
                        BUDGET_OPTS.find((o) => o.value === budget)?.label ??
                        'Any budget'
                    }
                    Icon={IconCoinEuro}
                    active={budget !== ''}
                    value={budget}
                    options={BUDGET_OPTS}
                    onChange={(v) => onChange({ budget: v || null })}
                />
                <SingleFilter
                    label={
                        VIBE_OPTS.find((o) => o.value === vibe)?.label ??
                        'Any vibe'
                    }
                    Icon={IconAdjustmentsHorizontal}
                    active={vibe !== ''}
                    value={vibe}
                    options={VIBE_OPTS}
                    onChange={(v) => onChange({ vibe: v || null })}
                />
                {edited && (
                    <button
                        onClick={onStartOver}
                        className="shrink-0 cursor-pointer px-2 text-[12px] text-muted-foreground underline underline-offset-2 hover:text-foreground"
                    >
                        start over
                    </button>
                )}
            </div>
        </div>
    );
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

export default function Composer() {
    const { prompt, homeVeedel, pins } = usePage<{
        prompt?: string;
        homeVeedel?: string | null;
        pins?: string[];
    }>().props;
    const { track } = useTracker();

    const [constraints, setConstraints] = useState<Constraints | null>(null);
    const [facets, setFacets] = useState<Facets>({
        categories: [],
        areas: [],
    });
    const [plan, setPlan] = useState<Plan | null>(null);
    const [notices, setNotices] = useState<Notice[]>([]);
    const [intent, setIntent] = useState<Intent>('plan_day');
    const [parsing, setParsing] = useState(false);
    const [composing, setComposing] = useState(false);
    const [swappingSlot, setSwappingSlot] = useState<number | null>(null);
    const [destination, setDestination] = useState<Destination | null>(null);
    const [detail, setDetail] = useState<Place | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [archetype, setArchetype] = useState<string | null>(null);
    const [locked, setLocked] = useState<string[]>([]);
    const [excluded, setExcluded] = useState<string[]>([]);
    const [origin, setOrigin] = useState<PlacesOrigin | null>(null);
    const [locating, setLocating] = useState(false);
    const { stateFor, ratingFor, setFeedback, toast } = useFeedback();
    const isMobile = useIsMobile();
    // Guards against parsing the same prompt twice (React strict-mode
    // double-invoke would otherwise fire two parse + compose round-trips).
    const parsedPrompt = useRef<string | null>(null);

    const runCompose = useCallback(
        async (
            next: Constraints,
            opts: {
                archetype?: string | null;
                locked?: string[];
                excluded?: string[];
            } = {},
        ) => {
            setComposing(true);
            setError(null);

            try {
                const json = await post<{
                    plan: Plan;
                    notices: Notice[];
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
                });
                setPlan(json.plan);
                setNotices(json.notices ?? []);
                setOrigin(json.origin ?? null);

                if (json.facets) {
                    setFacets(json.facets);
                }
            } catch {
                setError(
                    'Could not compose your day. Try adjusting the chips.',
                );
            } finally {
                setComposing(false);
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

    function startOver() {
        if (!constraints) {
            return;
        }

        const next: Constraints = {
            ...constraints,
            areas: [],
            categories: [],
            companions: null,
            budget: null,
            vibe: null,
        };
        setConstraints(next);
        setArchetype(null);
        void runCompose(next, { archetype: null, locked, excluded });
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

    function pickArchetype(next: string) {
        if (!constraints) {
            return;
        }

        setArchetype(next);
        void runCompose(constraints, { archetype: next, locked, excluded });
    }

    function toggleLock(id: string) {
        setLocked((cur) =>
            cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id],
        );
    }

    function removePick(id: string) {
        if (!constraints) {
            return;
        }

        const nextExcluded = [...excluded, id];
        setExcluded(nextExcluded);
        void runCompose(constraints, {
            archetype,
            locked,
            excluded: nextExcluded,
        });
    }

    function shuffle() {
        if (!constraints || !plan) {
            return;
        }

        // Re-roll the unlocked picks for this recompose only (keeping locked).
        const unlocked = plan.slots
            .filter((s) => s.swappable && !locked.includes(s.id))
            .map((s) => s.id);
        void runCompose(constraints, {
            archetype,
            locked,
            excluded: [...excluded, ...unlocked],
        });
    }

    function recompose() {
        if (!constraints) {
            return;
        }

        void runCompose(constraints, { archetype, locked, excluded });
    }

    // The From chip: get a fresh GPS fix, persist it as the shared "I'm here"
    // anchor, then recompose so the plan starts from where you actually are.
    function locateFrom() {
        if (!('geolocation' in navigator) || !constraints) {
            return;
        }

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

    const planMode = intent === 'plan_day';
    const weekday = constraints
        ? new Date(constraints.window_start).toLocaleDateString('en-GB', {
              weekday: 'long',
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

    return (
        <AppLayout>
            <Head title="Day Composer" />
            <div className="mx-auto w-full max-w-[600px] px-4 pt-6 pb-24 md:px-6">
                <h1 className="mb-1 font-display text-[26px] font-medium tracking-tight">
                    {title}
                </h1>
                <p className="mb-5 text-sm text-muted-foreground">{subtitle}</p>

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
                    <EditableFilters
                        constraints={constraints}
                        facets={facets}
                        homeVeedel={homeVeedel ?? null}
                        origin={origin}
                        locating={locating}
                        onLocate={locateFrom}
                        onChange={updateConstraint}
                        onStartOver={startOver}
                    />
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

                {/* Plan — archetype switcher + soft-band lanes */}
                {plan && !composing && (
                    <div>
                        <div className="mb-1.5 font-mono text-[10px] tracking-[0.1em] text-muted-foreground/70 uppercase">
                            Shape of the day
                        </div>
                        <div className="mb-5 flex flex-wrap gap-2">
                            {ARCHETYPES.map((a) => {
                                const ArchIcon = a.Icon;

                                return (
                                    <button
                                        key={a.key}
                                        onClick={() => pickArchetype(a.key)}
                                        className={`flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1.5 text-[12.5px] font-semibold transition-colors ${
                                            archetype === a.key
                                                ? 'border-foreground bg-foreground text-background'
                                                : 'border-border bg-card text-muted-foreground hover:border-primary hover:text-primary'
                                        }`}
                                    >
                                        <ArchIcon
                                            size={14}
                                            stroke={ICON_STROKE}
                                        />
                                        {a.label}
                                    </button>
                                );
                            })}
                        </div>

                        {plan.slots.length === 0 && (
                            <div className="rounded-[14px] border border-border bg-card p-6 text-center text-sm text-muted-foreground">
                                Nothing’s open across that window — try a wider
                                time range or another day.
                            </div>
                        )}

                        {BANDS.map((band) => {
                            const bandSlots = plan.slots.filter(
                                (s) => s.band === band,
                            );

                            if (bandSlots.length === 0) {
                                return null;
                            }

                            return (
                                <section key={band} className="mb-1">
                                    <h2 className="mt-4 mb-2 font-display text-[16px] font-medium tracking-tight">
                                        {band}
                                    </h2>
                                    <div className="flex flex-col gap-3">
                                        {bandSlots.map((slot) => {
                                            const i = plan.slots.indexOf(slot);
                                            const isLocked = locked.includes(
                                                slot.id,
                                            );
                                            const SlotCatIcon = categoryIcon(
                                                slot.category,
                                            );
                                            // Only real places open the detail
                                            // modal; appointments/events don't.
                                            const tappable =
                                                !slot.is_appointment &&
                                                slot.id.startsWith('spot:');

                                            return (
                                                <div
                                                    key={slot.id}
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
                                                    className={`rounded-[14px] border p-4 ${
                                                        tappable
                                                            ? 'cursor-pointer transition-colors hover:border-primary'
                                                            : ''
                                                    } ${
                                                        slot.is_appointment
                                                            ? 'border-primary bg-accent-soft'
                                                            : slot.is_landmark
                                                              ? 'border-warn-soft bg-warn-soft'
                                                              : 'border-border bg-card'
                                                    }`}
                                                >
                                                    {slot.is_appointment && (
                                                        <div className="mb-1 font-mono text-[10px] font-semibold tracking-wide text-primary uppercase">
                                                            Your appointment ·
                                                            not movable
                                                        </div>
                                                    )}
                                                    <div className="mb-1 flex items-center justify-between gap-2">
                                                        <span
                                                            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-mono text-[10px] tracking-wide uppercase ${
                                                                slot.is_appointment
                                                                    ? 'bg-primary text-white'
                                                                    : slot.is_landmark
                                                                      ? 'bg-warn-soft text-warn'
                                                                      : 'bg-secondary text-muted-foreground'
                                                            }`}
                                                        >
                                                            {slot.is_appointment ? (
                                                                `${slot.start_time} · fixed`
                                                            ) : slot.is_landmark ? (
                                                                <>
                                                                    <IconStar
                                                                        size={
                                                                            11
                                                                        }
                                                                        stroke={
                                                                            ICON_STROKE
                                                                        }
                                                                    />
                                                                    main
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <SlotCatIcon
                                                                        size={
                                                                            11
                                                                        }
                                                                        stroke={
                                                                            ICON_STROKE
                                                                        }
                                                                    />
                                                                    {slot.category.replace(
                                                                        '_',
                                                                        ' ',
                                                                    )}
                                                                </>
                                                            )}
                                                        </span>
                                                        {!slot.is_appointment && (
                                                            <span className="font-mono text-[12px] text-muted-foreground">
                                                                {
                                                                    slot.duration_label
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div
                                                        className={`font-semibold ${slot.is_landmark ? 'text-[17px]' : 'text-[15px]'}`}
                                                    >
                                                        {slot.name}
                                                    </div>
                                                    {(slot.is_appointment
                                                        ? slot.subtitle
                                                        : slot.why) && (
                                                        <div className="mt-0.5 text-[13px] text-muted-foreground">
                                                            {slot.is_appointment
                                                                ? slot.subtitle
                                                                : slot.why}
                                                        </div>
                                                    )}
                                                    {slot.is_appointment &&
                                                        slot.leave_by && (
                                                            <span className="mt-2 inline-flex items-center gap-1 rounded-full bg-accent-soft px-2.5 py-1 font-mono text-[11px] font-semibold text-primary">
                                                                <IconClock
                                                                    size={12}
                                                                    stroke={
                                                                        ICON_STROKE
                                                                    }
                                                                />
                                                                Leave by{' '}
                                                                {slot.leave_by}
                                                            </span>
                                                        )}
                                                    <div className="mt-3 flex flex-wrap gap-2">
                                                        {!slot.is_appointment && (
                                                            <>
                                                                <button
                                                                    onClick={(
                                                                        e,
                                                                    ) => {
                                                                        e.stopPropagation();
                                                                        toggleLock(
                                                                            slot.id,
                                                                        );
                                                                    }}
                                                                    className={`inline-flex cursor-pointer items-center gap-1.5 rounded-[9px] border px-3 py-2 text-[12.5px] font-semibold transition-colors ${
                                                                        isLocked
                                                                            ? 'border-primary bg-accent-soft text-primary'
                                                                            : 'border-border bg-card text-muted-foreground hover:border-primary hover:text-primary'
                                                                    }`}
                                                                >
                                                                    <IconPin
                                                                        size={
                                                                            14
                                                                        }
                                                                        stroke={
                                                                            ICON_STROKE
                                                                        }
                                                                    />
                                                                    {isLocked
                                                                        ? 'Locked'
                                                                        : 'Lock'}
                                                                </button>
                                                                {slot.swappable && (
                                                                    <button
                                                                        onClick={(
                                                                            e,
                                                                        ) => {
                                                                            e.stopPropagation();
                                                                            swap(
                                                                                i,
                                                                            );
                                                                        }}
                                                                        disabled={
                                                                            swappingSlot !==
                                                                            null
                                                                        }
                                                                        className="inline-flex cursor-pointer items-center gap-1.5 rounded-[9px] border border-border bg-card px-3 py-2 text-[12.5px] font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary disabled:opacity-50"
                                                                    >
                                                                        {swappingSlot ===
                                                                        i ? (
                                                                            'Swapping…'
                                                                        ) : (
                                                                            <>
                                                                                <IconRefresh
                                                                                    size={
                                                                                        14
                                                                                    }
                                                                                    stroke={
                                                                                        ICON_STROKE
                                                                                    }
                                                                                />
                                                                                Swap
                                                                            </>
                                                                        )}
                                                                    </button>
                                                                )}
                                                                <button
                                                                    onClick={(
                                                                        e,
                                                                    ) => {
                                                                        e.stopPropagation();
                                                                        removePick(
                                                                            slot.id,
                                                                        );
                                                                    }}
                                                                    className="inline-flex cursor-pointer items-center gap-1.5 rounded-[9px] border border-border bg-card px-3 py-2 text-[12.5px] font-semibold text-muted-foreground transition-colors hover:border-danger hover:text-danger"
                                                                >
                                                                    <IconX
                                                                        size={
                                                                            14
                                                                        }
                                                                        stroke={
                                                                            ICON_STROKE
                                                                        }
                                                                    />
                                                                    Remove
                                                                </button>
                                                            </>
                                                        )}
                                                        <button
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                takeMeThere(
                                                                    slot,
                                                                );
                                                            }}
                                                            className="cursor-pointer rounded-[9px] bg-accent-soft px-3 py-2 text-[12.5px] font-semibold text-primary transition-colors hover:bg-primary hover:text-white"
                                                        >
                                                            → Take me there
                                                        </button>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </section>
                            );
                        })}

                        {plan.slots.length > 0 && (
                            <>
                                <div className="mt-5 flex flex-wrap gap-2">
                                    <button
                                        onClick={shuffle}
                                        disabled={composing}
                                        className="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-dashed border-border px-4 py-2 text-[12.5px] font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary disabled:opacity-50"
                                    >
                                        <IconArrowsShuffle
                                            size={14}
                                            stroke={ICON_STROKE}
                                        />
                                        Shuffle
                                    </button>
                                    <button
                                        onClick={recompose}
                                        disabled={composing}
                                        className="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-dashed border-border px-4 py-2 text-[12.5px] font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary disabled:opacity-50"
                                    >
                                        <IconRefresh
                                            size={14}
                                            stroke={ICON_STROKE}
                                        />
                                        Recompose
                                    </button>
                                </div>
                                <button
                                    onClick={() => router.visit('/dashboard')}
                                    className="mt-3 w-full cursor-pointer rounded-[9px] bg-primary py-3 text-[14px] font-semibold text-white transition-colors hover:bg-accent-hover"
                                >
                                    Save to Today
                                </button>
                                <p className="mt-3 flex items-center justify-center gap-1 text-center text-xs text-muted-foreground/70">
                                    <IconPin size={12} stroke={ICON_STROKE} />
                                    Locked picks stay when you shuffle or switch
                                    the shape.
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
                        <p className="text-sm text-muted-foreground">
                            Tell me about your day on the Today screen and I'll
                            compose it.
                        </p>
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
                        setDestination(target);
                    }}
                />
            )}

            {destination && (
                <TakeMeThereSheet
                    destination={destination}
                    onClose={() => setDestination(null)}
                />
            )}

            <FeedbackToast message={toast} />
        </AppLayout>
    );
}
