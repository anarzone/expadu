import { Head, router, usePage } from '@inertiajs/react';
import {
    IconCalendar,
    IconCalendarEvent,
    IconClock,
    IconMapPin,
    IconUser,
    IconX,
} from '@tabler/icons-react';
import type { IconProps } from '@tabler/icons-react';
import { useCallback, useState } from 'react';
import type { ComponentType } from 'react';
import { showToast } from '@/components/ui/toast';
import { ICON_STROKE } from '@/constants/icons';
import AppLayout from '@/layouts/app-layout';
import { writeJson } from '@/lib/reliable-write';
import type { Auth } from '@/types';

// ============================================================
// Types & Data
// ============================================================

type Place = {
    id: number;
    emoji: string;
    name: string;
    addr: string;
    lat?: number | null;
    lng?: number | null;
    arrive_by?: string | null;
    category?: string;
    day_mode?: string;
    active_days?: string[];
};

type UserPlaceData = {
    id: number;
    emoji: string | null;
    name: string;
    address: string | null;
    lat: number | null;
    lng: number | null;
    arrive_by?: string | null;
    category?: string;
    day_mode?: string;
    active_days?: string[];
};

const PLACE_ICONS = [
    '🏠',
    '🏡',
    '🏢',
    '🏬',
    '🏫',
    '🏥',
    '🏦',
    '🏛️',
    '🏗️',
    '🚇',
    '🚂',
    '✈️',
    '🚲',
    '🛵',
    '🚗',
    '⛽',
    '☕',
    '🍕',
    '🍜',
    '🍺',
    '🥗',
    '🛒',
    '🏪',
    '🏋️',
    '🌳',
    '🎭',
    '🎵',
    '🏊',
    '⚽',
    '📚',
    '💼',
    '💻',
    '🔧',
    '🎨',
    '🩺',
    '⚖️',
    '⭐',
    '❤️',
    '📍',
    '🔑',
    '🎯',
    '🌟',
    '👨‍👩‍👧',
];

// Maps for enum display values
const SITUATION_LABELS: Record<string, string> = {
    non_eu_employee: 'Non-EU employee',
    eu_employee: 'EU employee',
    student: 'Student',
    freelancer: 'Freelancer',
    family_reunification: 'Family reunification',
    digital_nomad: 'Digital nomad',
    other: 'Other',
};

const SITUATION_VALUES: Record<string, string> = {
    'Non-EU employee': 'non_eu_employee',
    'EU employee': 'eu_employee',
    Student: 'student',
    Freelancer: 'freelancer',
    'Family reunification': 'family_reunification',
    'Digital nomad': 'digital_nomad',
    Other: 'other',
};

const GERMAN_LABELS: Record<string, string> = {
    none: 'None yet',
    a1: 'A1 — Beginner',
    a2: 'A2 — Elementary',
    b1: 'B1 — Intermediate',
    b2: 'B2 — Upper-intermediate',
    c1: 'C1 — Advanced',
    c2: 'C2 — Fluent',
};

const GERMAN_VALUES: Record<string, string> = {
    'None yet': 'none',
    'A1 — Beginner': 'a1',
    'A2 — Elementary': 'a2',
    'B1 — Intermediate': 'b1',
    'B2 — Upper-intermediate': 'b2',
    'C1 — Advanced': 'c1',
    'C2 — Fluent': 'c2',
};

type EventSummary = {
    id: number;
    title: string;
    emoji: string | null;
    date: string;
    time: string;
    venue: string | null;
};

type ProfileStats = {
    events_joined: number;
    tasks_completed: number;
    tasks_total: number;
    days_in_germany: number | null;
};

// ============================================================
// Page Component
// ============================================================

/** The save's own note when a typed address wasn't found on the map. */
const notFoundNote = (page: { props: unknown }) =>
    (page.props as { flash?: { status?: string | null } }).flash?.status ??
    null;

export default function Profile() {
    const { auth, userPlaces, goingEvents, pastEvents, stats } = usePage<{
        auth: Auth;
        userPlaces?: UserPlaceData[];
        goingEvents?: EventSummary[];
        pastEvents?: EventSummary[];
        stats?: ProfileStats;
    }>().props;
    const user = auth.user;

    // Profile drill-in: which sub-page is open (null = the menu itself).
    const [profilePage, setProfilePage] = useState<string | null>(null);

    // Profile fields — seeded from backend user
    const [profileName, setProfileName] = useState(user.name || '');
    const [profileEmail, setProfileEmail] = useState(user.email || '');
    const [profileCity, setProfileCity] = useState(
        (user.city as string | undefined) ?? '',
    );
    const [profileSituation, setProfileSituation] = useState(
        SITUATION_LABELS[(user.situation as string) ?? ''] ?? '',
    );
    const [profileGerman, setProfileGerman] = useState(
        GERMAN_LABELS[(user.german_level as string) ?? ''] ?? '',
    );
    // Drives the journey-aware fare advice ("covered" vs a single ticket).
    // Read-only here: it's included in every buildProfilePayload PATCH.
    const [hasDticket] = useState<boolean>(
        (user.has_deutschlandticket as boolean | undefined) ?? false,
    );

    // Inline edit state
    const [editingField, setEditingField] = useState<string | null>(null);
    const [editValue, setEditValue] = useState('');
    const [profileSaving, setProfileSaving] = useState(false);
    const [profileError, setProfileError] = useState<string | null>(null);

    // Places — always derived from backend props (re-syncs after Inertia navigations)
    const places: Place[] = (userPlaces ?? []).map((p: UserPlaceData) => ({
        id: p.id,
        emoji: p.emoji || '📍',
        name: p.name,
        addr: p.address || '',
        lat: p.lat ?? null,
        lng: p.lng ?? null,
        arrive_by: p.arrive_by ?? null,
        category: p.category ?? 'custom',
        day_mode: p.day_mode ?? 'all',
        active_days: p.active_days ?? [],
    }));

    const [showPlaceForm, setShowPlaceForm] = useState(false);
    const [placeSaving, setPlaceSaving] = useState(false);
    const [placeError, setPlaceError] = useState<string | null>(null);
    const [placeName, setPlaceName] = useState('');
    const [placeAddr, setPlaceAddr] = useState('');
    const [placeLat, setPlaceLat] = useState<number | null>(null);
    const [placeLng, setPlaceLng] = useState<number | null>(null);
    const [placeArriveBy, setPlaceArriveBy] = useState('');
    const [placeDayMode, setPlaceDayMode] = useState<string>('all');
    const [placeActiveDays, setPlaceActiveDays] = useState<string[]>([]);
    const [locating, setLocating] = useState(false);
    const [placeEmoji, setPlaceEmoji] = useState('🏠');
    const [showIconPicker, setShowIconPicker] = useState(false);
    const [showArriveBy, setShowArriveBy] = useState(false);
    const [showDays, setShowDays] = useState(false);
    const [editingPlaceId, setEditingPlaceId] = useState<number | null>(null);

    // Toast

    // Build the full PATCH payload the backend always requires (name + email),
    // optionally overriding one field with a fresh value.
    function buildProfilePayload(
        field: string,
        value: string,
    ): Record<string, string | boolean | null> {
        const currentName = field === 'name' ? value : profileName;
        const currentEmail = field === 'email' ? value : profileEmail;
        const currentCity = field === 'city' ? value : profileCity;
        const currentSituation =
            field === 'situation'
                ? SITUATION_VALUES[value] || value
                : SITUATION_VALUES[profileSituation] || profileSituation;
        const currentGerman =
            field === 'german'
                ? GERMAN_VALUES[value] || value
                : GERMAN_VALUES[profileGerman] || profileGerman;

        return {
            name: currentName,
            email: currentEmail,
            city: currentCity || null,
            situation: currentSituation || null,
            german_level: currentGerman || null,
            has_deutschlandticket: hasDticket,
        };
    }

    // Inline edit handlers
    function startEdit(field: string, currentValue: string) {
        if (profileSaving) {
            return;
        }

        setProfileError(null);
        setEditingField(field);
        setEditValue(currentValue);
    }

    async function saveEdit(field: string) {
        const val = editValue.trim();

        if (!val || profileSaving) {
            return;
        }

        setProfileSaving(true);
        setProfileError(null);

        try {
            await writeJson(
                '/settings/profile',
                'PATCH',
                buildProfilePayload(field, val),
            );

            switch (field) {
                case 'name':
                    setProfileName(val);
                    break;
                case 'email':
                    setProfileEmail(val);
                    break;
                case 'city':
                    setProfileCity(val);
                    break;
                case 'situation':
                    setProfileSituation(val);
                    break;
                case 'german':
                    setProfileGerman(val);
                    break;
            }

            setEditingField(null);
            showToast(
                '✓ ' +
                    field.charAt(0).toUpperCase() +
                    field.slice(1) +
                    ' updated',
            );
            router.reload({ only: ['auth'] });
        } catch (error) {
            setProfileError(
                error instanceof Error
                    ? error.message
                    : 'We couldn’t save that. Please try again.',
            );
        } finally {
            setProfileSaving(false);
        }
    }

    function cancelEdit() {
        if (profileSaving) {
            return;
        }

        setEditingField(null);
        setProfileError(null);
    }

    // Places
    function openPlaceForm(place?: Place) {
        setPlaceError(null);

        if (place) {
            setPlaceName(place.name);
            setPlaceAddr(place.addr);
            setPlaceEmoji(place.emoji);
            setEditingPlaceId(place.id);
            setPlaceLat(place.lat ?? null);
            setPlaceLng(place.lng ?? null);
            setPlaceArriveBy(place.arrive_by?.slice(0, 5) ?? '');
            setPlaceDayMode(place.day_mode ?? 'all');
            setPlaceActiveDays(place.active_days ?? []);
        } else {
            setPlaceName('');
            setPlaceAddr('');
            setPlaceEmoji('🏠');
            setEditingPlaceId(null);
            setPlaceLat(null);
            setPlaceLng(null);
            setPlaceArriveBy('');
            setPlaceDayMode('all');
            setPlaceActiveDays([]);
        }

        setShowPlaceForm(true);
        setShowIconPicker(false);
        setShowArriveBy(!!place?.arrive_by);
        setShowDays(!!place?.active_days?.length);
    }

    function savePlace() {
        if (placeSaving) {
            return;
        }

        if (!placeName.trim()) {
            setPlaceError('Please enter a place name.');

            return;
        }

        setPlaceSaving(true);
        setPlaceError(null);

        if (editingPlaceId) {
            // Update via backend
            router.put(
                `/user-places/${editingPlaceId}`,
                {
                    emoji: placeEmoji,
                    name: placeName.trim(),
                    address: placeAddr.trim() || null,
                    lat: placeLat,
                    lng: placeLng,
                    arrive_by: placeArriveBy || null,
                    day_mode: placeDayMode,
                    active_days:
                        placeDayMode === 'custom' ? placeActiveDays : null,
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: (page) => {
                        setShowPlaceForm(false);
                        showToast(
                            notFoundNote(page) ??
                                '✓ ' + placeName.trim() + ' updated',
                        );
                    },
                    onFinish: () => setPlaceSaving(false),
                    onError: (errors) => {
                        const firstError = Object.values(errors)[0];
                        setPlaceError(
                            typeof firstError === 'string'
                                ? firstError
                                : 'Could not update',
                        );
                    },
                },
            );
        } else {
            // Create via backend
            router.post(
                '/user-places',
                {
                    emoji: placeEmoji,
                    name: placeName.trim(),
                    address: placeAddr.trim() || null,
                    lat: placeLat,
                    lng: placeLng,
                    arrive_by: placeArriveBy || null,
                    day_mode: placeDayMode,
                    active_days:
                        placeDayMode === 'custom' ? placeActiveDays : null,
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: (page) => {
                        setShowPlaceForm(false);
                        showToast(
                            notFoundNote(page) ??
                                '✓ ' +
                                    placeName.trim() +
                                    ' added to your places',
                        );
                    },
                    onFinish: () => setPlaceSaving(false),
                    onError: (errors) => {
                        const firstError = Object.values(errors)[0];
                        setPlaceError(
                            typeof firstError === 'string'
                                ? firstError
                                : 'Could not save',
                        );
                    },
                },
            );
        }
    }

    // Use browser geolocation → reverse geocode with Photon
    function useMyLocation() {
        if (!navigator.geolocation) {
            showToast('Geolocation not supported by your browser');

            return;
        }

        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            async (pos) => {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                setPlaceLat(lat);
                setPlaceLng(lng);

                // Reverse geocode via Photon
                try {
                    const res = await fetch(
                        `https://photon.komoot.io/reverse?lat=${lat}&lon=${lng}&limit=1&lang=en`,
                    );

                    if (res.ok) {
                        const data = await res.json();
                        const feature = data.features?.[0];

                        if (feature) {
                            const p = feature.properties;
                            const addr = [p.street, p.housenumber]
                                .filter(Boolean)
                                .join(' ');
                            const area = [
                                addr,
                                p.district || p.suburb || p.locality,
                                p.city,
                            ]
                                .filter(Boolean)
                                .join(', ');
                            setPlaceAddr(
                                area || `${lat.toFixed(4)}, ${lng.toFixed(4)}`,
                            );
                        } else {
                            setPlaceAddr(
                                `${lat.toFixed(4)}, ${lng.toFixed(4)}`,
                            );
                        }
                    }
                } catch {
                    setPlaceAddr(`${lat.toFixed(4)}, ${lng.toFixed(4)}`);
                }

                setLocating(false);
                showToast('📍 Location detected');
            },
            (err) => {
                setLocating(false);
                showToast(
                    err.code === 1
                        ? 'Location permission denied'
                        : 'Could not get your location',
                );
            },
            { enableHighAccuracy: true, timeout: 10000 },
        );
    }

    // Search address via /api/geocode/search and get coordinates
    const [addrSuggestions, setAddrSuggestions] = useState<
        { name: string; address: string; lat: number; lng: number }[]
    >([]);
    const [addrHighlight, setAddrHighlight] = useState(-1);
    const addrTimerRef = useCallback(() => {
        let timer: ReturnType<typeof setTimeout> | null = null;

        return {
            search(query: string) {
                if (timer) {
                    clearTimeout(timer);
                }

                if (query.trim().length < 3) {
                    setAddrSuggestions([]);

                    return;
                }

                timer = setTimeout(async () => {
                    try {
                        // What was typed goes in the body, never a URL or an access log.
                        const res = await fetch('/api/geocode/search', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-CSRF-TOKEN':
                                    document
                                        .querySelector(
                                            'meta[name="csrf-token"]',
                                        )
                                        ?.getAttribute('content') ?? '',
                            },
                            body: JSON.stringify({
                                q: query.trim().slice(0, 100),
                            }),
                        });

                        if (res.ok) {
                            const data = await res.json();
                            setAddrSuggestions(
                                data.map(
                                    (r: {
                                        name: string;
                                        street?: string;
                                        city?: string;
                                        lat: number;
                                        lng: number;
                                    }) => ({
                                        name: r.name,
                                        address: [r.street, r.city]
                                            .filter(Boolean)
                                            .join(', '),
                                        lat: r.lat,
                                        lng: r.lng,
                                    }),
                                ),
                            );
                        }
                    } catch {
                        setAddrSuggestions([]);
                    }
                }, 300);
            },
            clear() {
                if (timer) {
                    clearTimeout(timer);
                }

                setAddrSuggestions([]);
            },
        };
    }, [])();

    function deletePlace(id: number) {
        router.delete(`/user-places/${id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => showToast('Place removed'),
            onError: () => showToast('Error: Could not remove place'),
        });
    }

    // Situation lives on Profile; account (name / email / password) lives in
    // Settings. The header carries situation + how long you've been in Cologne.
    const daysHere =
        stats?.days_in_germany != null
            ? `${stats.days_in_germany} ${stats.days_in_germany === 1 ? 'day' : 'days'} in Cologne`
            : null;
    const profileSubtitle =
        [profileSituation, daysHere].filter(Boolean).join(' · ') ||
        'Complete your profile';

    return (
        <AppLayout>
            <Head title="Profile" />
            <div className="mx-auto w-full max-w-[680px]">
                {/* ── Profile header (simple — no hero) ── */}
                <div className="flex items-center gap-3.5 px-1 pt-3 pb-5">
                    <div className="flex size-[52px] shrink-0 items-center justify-center rounded-full bg-action font-display text-[22px] font-semibold text-white">
                        {(profileName.charAt(0) || '?').toUpperCase()}
                    </div>
                    <div className="min-w-0 flex-1">
                        <h1 className="font-display text-[21px] leading-tight font-semibold">
                            {profileName || 'Your profile'}
                        </h1>
                        <div className="mt-1 text-[13px] text-text-muted">
                            {profileSubtitle}
                        </div>
                    </div>
                </div>

                {/* ── Profile drill-in menu ── */}
                <div className="py-2">
                    {profileError && (
                        <p
                            role="alert"
                            className="px-5 py-3 text-sm text-conflict-text"
                        >
                            {profileError}
                        </p>
                    )}
                    {profileSaving && (
                        <p
                            role="status"
                            className="px-5 py-2 text-sm text-text-muted"
                        >
                            Saving…
                        </p>
                    )}
                    {/* PERSONAL DETAILS — drill-in */}
                    <SettingsSection
                        id="details"
                        icon={IconUser}
                        title="Personal details"
                        activePage={profilePage}
                        onNavigate={setProfilePage}
                        onBack={() => setProfilePage(null)}
                        backLabel="← Back to Profile"
                    >
                        <InlineEditRow
                            field="situation"
                            label="Situation"
                            value={profileSituation}
                            editing={editingField}
                            editValue={editValue}
                            onStartEdit={startEdit}
                            onEditValue={setEditValue}
                            onSave={saveEdit}
                            onCancel={cancelEdit}
                            saving={profileSaving}
                            selectOptions={[
                                'Non-EU employee',
                                'EU employee',
                                'Student',
                                'Freelancer',
                                'Family reunification',
                                'Digital nomad',
                                'Other',
                            ]}
                            subText="Affects your checklist and visa guidance"
                        />
                        <InlineEditRow
                            field="german"
                            label="German level"
                            value={profileGerman}
                            editing={editingField}
                            editValue={editValue}
                            onStartEdit={startEdit}
                            onEditValue={setEditValue}
                            onSave={saveEdit}
                            onCancel={cancelEdit}
                            saving={profileSaving}
                            selectOptions={[
                                'None yet',
                                'A1 — Beginner',
                                'A2 — Elementary',
                                'B1 — Intermediate',
                                'B2 — Upper-intermediate',
                                'C1 — Advanced',
                                'C2 — Fluent',
                            ]}
                            isLast
                        />
                    </SettingsSection>

                    {/* YOUR PLACES — drill-in */}
                    <SettingsSection
                        id="places"
                        icon={IconMapPin}
                        title="Your places"
                        activePage={profilePage}
                        onNavigate={setProfilePage}
                        onBack={() => setProfilePage(null)}
                        backLabel="← Back to Profile"
                    >
                        {places.map((p) => (
                            <div
                                key={p.id}
                                className="flex items-center justify-between border-b border-border py-3.5 last:border-b-0"
                                style={{ gap: 12 }}
                            >
                                <span
                                    style={{
                                        fontSize: 22,
                                        flexShrink: 0,
                                    }}
                                >
                                    {p.emoji}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div
                                        style={{
                                            fontSize: 14,
                                            fontWeight: 500,
                                        }}
                                    >
                                        {p.name}
                                    </div>
                                    <div
                                        className="text-text-soft"
                                        style={{
                                            fontSize: 12,
                                            marginTop: 2,
                                        }}
                                    >
                                        {p.addr}
                                    </div>
                                    {p.addr &&
                                    (p.lat === null || p.lng === null) ? (
                                        <div
                                            className="text-attention-text"
                                            style={{
                                                fontSize: 12,
                                                marginTop: 2,
                                            }}
                                        >
                                            Not on the map yet. Edit it and pick
                                            a suggestion so trips can start
                                            here.
                                        </div>
                                    ) : null}
                                </div>
                                <button
                                    onClick={() => openPlaceForm(p)}
                                    className="cursor-pointer rounded-[9px] border border-border bg-bg-surface px-2.5 py-1 text-text-soft transition-all hover:bg-bg-hover"
                                    style={{
                                        fontFamily: "'Geist', sans-serif",
                                        fontSize: 11,
                                        fontWeight: 600,
                                        marginRight: 4,
                                    }}
                                >
                                    Edit
                                </button>
                                {p.category !== 'home' &&
                                    p.category !== 'work' && (
                                        <button
                                            onClick={() => deletePlace(p.id)}
                                            aria-label={`Delete ${p.name}`}
                                            className="cursor-pointer rounded-[9px] border border-conflict-bg bg-conflict-bg px-2.5 py-1 transition-all hover:bg-conflict"
                                            style={{
                                                fontFamily:
                                                    "'Geist', sans-serif",
                                                fontSize: 11,
                                                fontWeight: 600,
                                                color: 'var(--color-conflict-text)',
                                            }}
                                        >
                                            <IconX
                                                size={13}
                                                stroke={ICON_STROKE}
                                            />
                                        </button>
                                    )}
                            </div>
                        ))}
                        {showPlaceForm && (
                            <div className="mt-2.5 rounded-[14px] border border-border bg-bg-quiet p-3.5">
                                <div
                                    className="text-text-soft"
                                    style={{
                                        fontSize: 12,
                                        fontWeight: 700,
                                        marginBottom: 10,
                                    }}
                                >
                                    {editingPlaceId
                                        ? 'Edit place'
                                        : 'Add a place'}
                                </div>
                                {/* Icon picker — collapsed by default */}
                                {showIconPicker ? (
                                    <>
                                        <div className="mb-1 flex items-center justify-between">
                                            <span className="text-[11px] font-bold tracking-[0.07em] text-text-soft uppercase">
                                                Choose an icon
                                            </span>
                                            <button
                                                onClick={() =>
                                                    setShowIconPicker(false)
                                                }
                                                className="text-[11px] font-semibold text-select-text"
                                            >
                                                Done
                                            </button>
                                        </div>
                                        <div className="mb-3 flex flex-wrap gap-1.5">
                                            {PLACE_ICONS.map((ico) => (
                                                <div
                                                    key={ico}
                                                    onClick={() => {
                                                        setPlaceEmoji(ico);
                                                        setShowIconPicker(
                                                            false,
                                                        );
                                                    }}
                                                    className={`flex cursor-pointer items-center justify-center rounded-[9px] transition-all ${placeEmoji === ico ? 'border-2 border-select-text bg-select-bg' : 'border-2 border-transparent bg-bg-surface'}`}
                                                    style={{
                                                        width: 40,
                                                        height: 40,
                                                        fontSize: 20,
                                                    }}
                                                >
                                                    {ico}
                                                </div>
                                            ))}
                                        </div>
                                    </>
                                ) : null}
                                <div className="mb-2 flex gap-2">
                                    <div
                                        onClick={() =>
                                            setShowIconPicker(!showIconPicker)
                                        }
                                        className="flex shrink-0 cursor-pointer items-center justify-center rounded-[9px] transition-all hover:opacity-80"
                                        style={{
                                            width: 44,
                                            height: 44,
                                            background:
                                                'var(--color-select-bg)',
                                            border: '2px solid var(--color-select-text)',
                                            fontSize: 22,
                                        }}
                                    >
                                        {placeEmoji}
                                    </div>
                                    <input
                                        value={placeName}
                                        onChange={(e) =>
                                            setPlaceName(e.target.value)
                                        }
                                        placeholder="Name (e.g. Home)"
                                        className="flex-1 rounded-[9px] border border-border bg-bg-surface px-3 py-[9px] text-sm outline-none"
                                        style={{
                                            fontFamily: "'Geist', sans-serif",
                                        }}
                                    />
                                </div>
                                <div className="relative mb-2.5">
                                    <div className="flex items-center gap-1.5">
                                        <div className="relative flex-1">
                                            <input
                                                value={placeAddr}
                                                onChange={(e) => {
                                                    setPlaceAddr(
                                                        e.target.value,
                                                    );
                                                    // A point picked before belongs to the old words.
                                                    setPlaceLat(null);
                                                    setPlaceLng(null);
                                                    setAddrHighlight(-1);
                                                    addrTimerRef.search(
                                                        e.target.value,
                                                    );
                                                }}
                                                onKeyDown={(e) => {
                                                    if (
                                                        addrSuggestions.length ===
                                                        0
                                                    ) {
                                                        return;
                                                    }

                                                    if (e.key === 'ArrowDown') {
                                                        e.preventDefault();
                                                        setAddrHighlight(
                                                            (prev) =>
                                                                Math.min(
                                                                    prev + 1,
                                                                    addrSuggestions.length -
                                                                        1,
                                                                ),
                                                        );
                                                    } else if (
                                                        e.key === 'ArrowUp'
                                                    ) {
                                                        e.preventDefault();
                                                        setAddrHighlight(
                                                            (prev) =>
                                                                Math.max(
                                                                    prev - 1,
                                                                    0,
                                                                ),
                                                        );
                                                    } else if (
                                                        e.key === 'Enter' &&
                                                        addrHighlight >= 0
                                                    ) {
                                                        e.preventDefault();
                                                        const s =
                                                            addrSuggestions[
                                                                addrHighlight
                                                            ];
                                                        setPlaceAddr(
                                                            s.address || s.name,
                                                        );
                                                        setPlaceLat(s.lat);
                                                        setPlaceLng(s.lng);
                                                        addrTimerRef.clear();
                                                        setAddrHighlight(-1);
                                                    } else if (
                                                        e.key === 'Escape'
                                                    ) {
                                                        addrTimerRef.clear();
                                                        setAddrHighlight(-1);
                                                    }
                                                }}
                                                onBlur={() =>
                                                    setTimeout(() => {
                                                        addrTimerRef.clear();
                                                        setAddrHighlight(-1);
                                                    }, 200)
                                                }
                                                placeholder="Address or area (e.g. Ehrenfeld)"
                                                className="w-full rounded-[9px] border border-border bg-bg-surface px-3 py-[9px] text-sm outline-none"
                                                style={{
                                                    fontFamily:
                                                        "'Geist', sans-serif",
                                                }}
                                            />
                                            {/* Address autocomplete dropdown */}
                                            {addrSuggestions.length > 0 && (
                                                <div className="absolute top-full right-0 left-0 z-50 mt-1 overflow-hidden rounded-[9px] border border-border bg-bg-surface shadow-[0_8px_24px_rgba(0,0,0,0.08)]">
                                                    {addrSuggestions.map(
                                                        (s, i) => (
                                                            <div
                                                                key={i}
                                                                onMouseDown={(
                                                                    e,
                                                                ) => {
                                                                    e.preventDefault();
                                                                    setPlaceAddr(
                                                                        s.address ||
                                                                            s.name,
                                                                    );
                                                                    setPlaceLat(
                                                                        s.lat,
                                                                    );
                                                                    setPlaceLng(
                                                                        s.lng,
                                                                    );
                                                                    addrTimerRef.clear();
                                                                }}
                                                                onMouseEnter={() =>
                                                                    setAddrHighlight(
                                                                        i,
                                                                    )
                                                                }
                                                                className={`cursor-pointer px-3 py-2.5 transition-colors ${addrHighlight === i ? 'bg-select-bg' : 'hover:bg-bg-hover'}`}
                                                                style={{
                                                                    borderBottom:
                                                                        i <
                                                                        addrSuggestions.length -
                                                                            1
                                                                            ? '1px solid var(--color-border)'
                                                                            : 'none',
                                                                }}
                                                            >
                                                                <div className="text-sm font-medium text-text">
                                                                    {s.name}
                                                                </div>
                                                                <div className="text-xs text-text-soft">
                                                                    {s.address}
                                                                </div>
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                        {/* Use my location button */}
                                        <button
                                            type="button"
                                            onClick={useMyLocation}
                                            disabled={locating}
                                            className="flex shrink-0 cursor-pointer items-center justify-center rounded-[9px] border border-border bg-bg-surface transition-all hover:border-select-text hover:bg-select-bg disabled:opacity-50"
                                            style={{
                                                width: 40,
                                                height: 40,
                                            }}
                                            title="Use my current location"
                                        >
                                            {locating ? (
                                                <div
                                                    style={{
                                                        width: 14,
                                                        height: 14,
                                                        border: '2px solid var(--color-border)',
                                                        borderTopColor:
                                                            'var(--color-select-text)',
                                                        borderRadius: '50%',
                                                        animation:
                                                            'spin .7s linear infinite',
                                                    }}
                                                />
                                            ) : (
                                                <IconMapPin
                                                    size={18}
                                                    stroke={ICON_STROKE}
                                                />
                                            )}
                                        </button>
                                    </div>
                                    {placeAddr.trim() &&
                                    (placeLat === null || placeLng === null) ? (
                                        <p className="mt-1 text-xs text-text-soft">
                                            Pick a suggestion to pin it on the
                                            map. Otherwise we’ll look the
                                            address up when you save.
                                        </p>
                                    ) : null}
                                </div>
                                {/* Optional: Arrive by + Days — toggle buttons */}
                                <div className="mb-2.5 flex gap-2">
                                    <button
                                        onClick={() =>
                                            setShowArriveBy(!showArriveBy)
                                        }
                                        className={`flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[12px] font-medium transition-all ${showArriveBy ? 'bg-select-bg text-select-text' : 'bg-bg-quiet text-text-soft'}`}
                                    >
                                        <IconClock size={14} stroke={1.7} />{' '}
                                        Arrive by
                                    </button>
                                    <button
                                        onClick={() => setShowDays(!showDays)}
                                        className={`flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[12px] font-medium transition-all ${showDays ? 'bg-select-bg text-select-text' : 'bg-bg-quiet text-text-soft'}`}
                                    >
                                        <IconCalendar size={14} stroke={1.7} />{' '}
                                        Days
                                    </button>
                                </div>

                                {/* Arrive by time */}
                                {showArriveBy && (
                                    <div className="mb-2.5 flex items-center gap-2">
                                        <span
                                            className="text-text-soft"
                                            style={{
                                                fontSize: 12,
                                                whiteSpace: 'nowrap',
                                            }}
                                        >
                                            Arrive by
                                        </span>
                                        <input
                                            type="time"
                                            value={placeArriveBy}
                                            onChange={(e) =>
                                                setPlaceArriveBy(e.target.value)
                                            }
                                            className="flex-1 rounded-[9px] border border-border bg-bg-surface px-3 py-[9px] text-sm outline-none"
                                            style={{
                                                fontFamily:
                                                    "'Geist Mono', monospace",
                                                color: placeArriveBy
                                                    ? 'var(--color-select-text)'
                                                    : 'var(--color-text-soft)',
                                            }}
                                            placeholder="--:--"
                                        />
                                        <span
                                            className="text-text-soft"
                                            style={{ fontSize: 11 }}
                                        >
                                            For smart commute
                                        </span>
                                    </div>
                                )}
                                {/* Active days */}
                                {showDays && (
                                    <div className="mb-2.5">
                                        <div className="mb-1.5 flex gap-1.5">
                                            {[
                                                {
                                                    id: 'all',
                                                    label: 'Every day',
                                                },
                                                {
                                                    id: 'weekdays',
                                                    label: 'Weekdays',
                                                },
                                                {
                                                    id: 'weekends',
                                                    label: 'Weekends',
                                                },
                                                {
                                                    id: 'custom',
                                                    label: 'Custom',
                                                },
                                            ].map((m) => (
                                                <button
                                                    key={m.id}
                                                    type="button"
                                                    onClick={() =>
                                                        setPlaceDayMode(m.id)
                                                    }
                                                    className={`cursor-pointer rounded-full border px-2.5 py-[4px] transition-all ${placeDayMode === m.id ? 'border-select-text bg-action text-white' : 'border-border bg-bg-surface text-text-soft'}`}
                                                    style={{
                                                        fontSize: 11,
                                                        fontWeight: 600,
                                                    }}
                                                >
                                                    {m.label}
                                                </button>
                                            ))}
                                        </div>
                                        {placeDayMode === 'custom' && (
                                            <div className="flex gap-1">
                                                {[
                                                    'mon',
                                                    'tue',
                                                    'wed',
                                                    'thu',
                                                    'fri',
                                                    'sat',
                                                    'sun',
                                                ].map((d) => (
                                                    <button
                                                        key={d}
                                                        type="button"
                                                        onClick={() =>
                                                            setPlaceActiveDays(
                                                                (prev) =>
                                                                    prev.includes(
                                                                        d,
                                                                    )
                                                                        ? prev.filter(
                                                                              (
                                                                                  x,
                                                                              ) =>
                                                                                  x !==
                                                                                  d,
                                                                          )
                                                                        : [
                                                                              ...prev,
                                                                              d,
                                                                          ],
                                                            )
                                                        }
                                                        className={`flex cursor-pointer items-center justify-center transition-all ${placeActiveDays.includes(d) ? 'border-2 border-select-text bg-select-bg text-select-text' : 'border-2 border-transparent bg-bg-quiet text-text-soft'}`}
                                                        style={{
                                                            width: 32,
                                                            height: 32,
                                                            borderRadius: '50%',
                                                            fontSize: 11,
                                                            fontWeight: 700,
                                                        }}
                                                    >
                                                        {d
                                                            .charAt(0)
                                                            .toUpperCase() +
                                                            d.slice(1, 2)}
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                )}
                                {placeError && (
                                    <p
                                        role="alert"
                                        className="mb-3 text-sm text-conflict-text"
                                    >
                                        {placeError}
                                    </p>
                                )}
                                <div className="flex gap-2">
                                    <button
                                        onClick={savePlace}
                                        disabled={placeSaving}
                                        className="flex-1 cursor-pointer rounded-[9px] border-none bg-action py-[9px] text-[13px] font-semibold text-white"
                                        style={{
                                            fontFamily: "'Geist', sans-serif",
                                        }}
                                    >
                                        {placeSaving
                                            ? 'Saving…'
                                            : editingPlaceId
                                              ? 'Update place'
                                              : 'Save place'}
                                    </button>
                                    <button
                                        onClick={() => setShowPlaceForm(false)}
                                        disabled={placeSaving}
                                        className="cursor-pointer rounded-[9px] border border-border bg-bg-surface px-3.5 py-[9px] text-[13px] text-text-soft"
                                        style={{
                                            fontFamily: "'Geist', sans-serif",
                                        }}
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        )}
                        {!showPlaceForm && (
                            <button
                                onClick={() => openPlaceForm()}
                                className="mt-2.5 w-full cursor-pointer rounded-[9px] border-[1.5px] border-dashed border-border bg-transparent py-2.5 text-[13px] font-medium text-select-text transition-all hover:border-select-text hover:bg-select-bg"
                                style={{
                                    fontFamily: "'Geist', sans-serif",
                                }}
                            >
                                + Add a place
                            </button>
                        )}
                    </SettingsSection>

                    {/* MY EVENTS — drill-in */}
                    <SettingsSection
                        id="events"
                        icon={IconCalendarEvent}
                        title="My events"
                        activePage={profilePage}
                        onNavigate={setProfilePage}
                        onBack={() => setProfilePage(null)}
                        backLabel="← Back to Profile"
                    >
                        <div className="mb-3 flex items-center justify-between">
                            <span
                                style={{
                                    fontSize: 16,
                                    fontWeight: 600,
                                }}
                            >
                                Going
                            </span>
                            <span
                                className="text-text-soft"
                                style={{
                                    fontSize: 11,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.08em',
                                }}
                            >
                                {`${(goingEvents ?? []).length} upcoming`}
                            </span>
                        </div>
                        {(goingEvents ?? []).length > 0 ? (
                            (goingEvents ?? []).map((ev) => (
                                <CompactCard
                                    key={ev.id}
                                    barColor="var(--color-success)"
                                    title={ev.title}
                                    sub={`${ev.date} · ${ev.time}${ev.venue ? ` · ${ev.venue}` : ''}`}
                                    badge="✓ Going"
                                    badgeBg="var(--color-success-soft)"
                                    badgeColor="var(--color-success)"
                                />
                            ))
                        ) : (
                            <div className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-text-soft">
                                No upcoming events. Browse the{' '}
                                <a
                                    href="/events"
                                    className="text-select-text underline"
                                >
                                    Events
                                </a>{' '}
                                page to find something.
                            </div>
                        )}
                        {(pastEvents ?? []).length > 0 && (
                            <div className="mt-4">
                                <SectionHeader title="Past events" />
                                {(pastEvents ?? []).map((ev) => (
                                    <CompactCard
                                        key={ev.id}
                                        barColor="var(--color-border)"
                                        title={ev.title}
                                        titleColor="var(--color-text-soft)"
                                        sub={`${ev.date}${ev.venue ? ` · ${ev.venue}` : ''}`}
                                        badge="Attended"
                                        badgeBg="var(--color-bg-quiet)"
                                        badgeColor="var(--color-text-soft)"
                                    />
                                ))}
                            </div>
                        )}
                    </SettingsSection>
                </div>
            </div>

            {/* Toast */}
        </AppLayout>
    );
}

// ============================================================
// Sub-components
// ============================================================

function SettingsSection({
    id,
    icon: Icon,
    title,
    activePage,
    onNavigate,
    onBack,
    children,
    danger,
    backLabel = '← Back to Account',
}: {
    id: string;
    icon: ComponentType<IconProps>;
    title: string;
    activePage: string | null;
    onNavigate: (id: string) => void;
    onBack: () => void;
    children: React.ReactNode;
    danger?: boolean;
    backLabel?: string;
}) {
    // When no page is active, show the menu item
    if (!activePage) {
        return (
            <button
                onClick={() => onNavigate(id)}
                className={`flex w-full cursor-pointer items-center gap-3 border-b border-border px-5 py-3.5 text-left transition-colors hover:bg-bg-quiet ${danger ? 'text-conflict-text' : ''}`}
            >
                <span className="flex shrink-0 items-center">
                    <Icon size={18} stroke={ICON_STROKE} />
                </span>
                <span className="flex-1 text-[14px] font-medium">{title}</span>
                <svg
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className="shrink-0 text-text-soft"
                >
                    <polyline points="9 18 15 12 9 6" />
                </svg>
            </button>
        );
    }

    // When this page is active, show back button + content
    if (activePage === id) {
        return (
            <div>
                <button
                    onClick={onBack}
                    className="flex w-full items-center gap-2 border-b border-border px-5 py-3 text-[13px] font-semibold text-text-soft transition-colors hover:text-select-text"
                >
                    {backLabel}
                </button>
                <div className="border-b border-border px-5 py-4">
                    <div className="mb-3 flex items-center gap-2">
                        <Icon size={18} stroke={ICON_STROKE} />
                        <span className="text-base font-semibold">{title}</span>
                    </div>
                    {children}
                </div>
            </div>
        );
    }

    // Another page is active — hide this item
    return null;
}

function SectionHeader({
    title,
    action,
    onAction,
    badge,
}: {
    title: string;
    action?: string;
    onAction?: () => void;
    badge?: string;
}) {
    return (
        <div className="mb-3 flex items-baseline justify-between">
            <span style={{ fontSize: 16, fontWeight: 600 }}>{title}</span>
            {action && (
                <span
                    onClick={onAction}
                    className="cursor-pointer hover:underline"
                    style={{
                        fontSize: 13,
                        color: 'var(--color-select-text)',
                        fontWeight: 500,
                    }}
                >
                    {action}
                </span>
            )}
            {badge && (
                <span
                    className="text-text-soft"
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                    }}
                >
                    {badge}
                </span>
            )}
        </div>
    );
}

function CompactCard({
    barColor,
    title,
    titleColor,
    sub,
    badge,
    badgeBg,
    badgeColor,
    style,
}: {
    barColor: string;
    title: string;
    titleColor?: string;
    sub: string;
    badge: string;
    badgeBg: string;
    badgeColor: string;
    style?: React.CSSProperties;
}) {
    return (
        <div
            className="mb-2 flex cursor-pointer items-center gap-3 rounded-[14px] border border-border bg-bg-surface p-3.5 transition-all last:mb-0 hover:border-[rgba(26,76,212,0.2)] hover:bg-bg-hover"
            style={style}
        >
            <div
                className="shrink-0 rounded-sm"
                style={{
                    width: 4,
                    height: 40,
                    borderRadius: 2,
                    background: barColor,
                }}
            />
            <div className="min-w-0 flex-1">
                <div
                    style={{
                        fontSize: 13,
                        fontWeight: 600,
                        marginBottom: 2,
                        color: titleColor || 'inherit',
                    }}
                >
                    {title}
                </div>
                <div className="text-text-soft" style={{ fontSize: 12 }}>
                    {sub}
                </div>
            </div>
            <span
                className="shrink-0 rounded-[20px]"
                style={{
                    fontSize: 10,
                    fontWeight: 700,
                    padding: '2px 8px',
                    background: badgeBg,
                    color: badgeColor,
                }}
            >
                {badge}
            </span>
        </div>
    );
}

function InlineEditRow({
    field,
    label,
    value,
    editing,
    editValue,
    onStartEdit,
    onEditValue,
    onSave,
    onCancel,
    saving,
    inputType,
    selectOptions,
    subText,
    isLast,
}: {
    field: string;
    label: string;
    value: string;
    editing: string | null;
    editValue: string;
    onStartEdit: (field: string, value: string) => void;
    onEditValue: (val: string) => void;
    onSave: (field: string) => void;
    onCancel: () => void;
    saving: boolean;
    inputType?: string;
    selectOptions?: string[];
    subText?: string;
    isLast?: boolean;
}) {
    const isEditing = editing === field;

    if (isEditing) {
        return (
            <div className={`py-3.5 ${isLast ? '' : 'border-b border-border'}`}>
                <div
                    className="text-text-soft"
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.06em',
                        marginBottom: 8,
                    }}
                >
                    {label}
                </div>
                {selectOptions ? (
                    <select
                        aria-label={label}
                        disabled={saving}
                        value={editValue}
                        onChange={(e) => onEditValue(e.target.value)}
                        className="w-full rounded-lg border-[1.5px] border-border bg-bg-surface px-3.5 py-[11px] text-[15px] text-text outline-none focus:border-focus"
                        style={{
                            fontFamily: "'Geist', sans-serif",
                            boxSizing: 'border-box',
                            transition: 'border-color .2s',
                        }}
                    >
                        {selectOptions.map((opt) => (
                            <option key={opt} value={opt}>
                                {opt}
                            </option>
                        ))}
                    </select>
                ) : (
                    <input
                        aria-label={label}
                        disabled={saving}
                        type={inputType || 'text'}
                        value={editValue}
                        onChange={(e) => onEditValue(e.target.value)}
                        className="w-full rounded-lg border-[1.5px] border-border bg-bg-surface px-3.5 py-[11px] text-[15px] text-text outline-none focus:border-focus"
                        style={{
                            fontFamily: "'Geist', sans-serif",
                            boxSizing: 'border-box',
                            transition: 'border-color .2s',
                        }}
                        autoFocus
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                onSave(field);
                            }

                            if (e.key === 'Escape') {
                                onCancel();
                            }
                        }}
                    />
                )}
                {subText && (
                    <div
                        className="text-text-soft"
                        style={{ fontSize: 12, marginTop: 6 }}
                    >
                        {subText}
                    </div>
                )}
                <div className="mt-2.5 flex gap-2">
                    <button
                        disabled={saving}
                        onClick={() => onSave(field)}
                        className="cursor-pointer rounded-lg border-none bg-action px-5 py-[9px] text-[13px] font-semibold text-white transition-all hover:bg-action-hover"
                        style={{ fontFamily: "'Geist', sans-serif" }}
                    >
                        {saving ? 'Saving…' : 'Save'}
                    </button>
                    <button
                        disabled={saving}
                        onClick={onCancel}
                        className="cursor-pointer rounded-lg border border-border bg-bg-quiet px-4 py-[9px] text-[13px] text-text-soft transition-all hover:bg-bg-hover"
                        style={{ fontFamily: "'Geist', sans-serif" }}
                    >
                        Cancel
                    </button>
                </div>
            </div>
        );
    }

    return (
        <button
            type="button"
            aria-label={`Edit ${label}`}
            disabled={saving}
            className={`flex w-full cursor-pointer items-center justify-between py-3.5 text-left ${isLast ? '' : 'border-b border-border'}`}
            onClick={() => onStartEdit(field, value)}
        >
            <span className="min-w-0 flex-1">
                <span
                    className="block text-text-soft"
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.06em',
                        marginBottom: 3,
                    }}
                >
                    {label}
                </span>
                <span
                    className="block text-text"
                    style={{ fontSize: 15, fontWeight: 500 }}
                >
                    {value || '—'}
                </span>
            </span>
            <span
                className="ml-3 shrink-0 text-text-soft transition-colors hover:text-select-text"
                style={{ fontSize: 13, fontWeight: 600 }}
            >
                Edit
            </span>
        </button>
    );
}
