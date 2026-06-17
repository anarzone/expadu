import { Head, router, usePage } from '@inertiajs/react';
import { IconCalendar, IconClock } from '@tabler/icons-react';
import { useCallback, useState } from 'react';
import { ProfileRightPanel } from '@/components/profile/profile-right-panel';
import { useTabState } from '@/hooks/use-tab-state';
import AppLayout from '@/layouts/app-layout';
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

// Keys + labels + emojis mirror the backend App\Profile\Interest enum.
const INTEREST_OPTIONS: { value: string; label: string; emoji: string }[] = [
    { value: 'parks', label: 'Parks & green', emoji: '🌳' },
    { value: 'swimming', label: 'Swimming & lakes', emoji: '🏊' },
    { value: 'sports', label: 'Sports & courts', emoji: '⚽' },
    { value: 'museums', label: 'Museums & galleries', emoji: '🎨' },
    { value: 'sights', label: 'Sights & landmarks', emoji: '🏛️' },
    { value: 'family', label: 'Family & kids', emoji: '🛝' },
    { value: 'cafes', label: 'Cafés & bakeries', emoji: '☕' },
    { value: 'nightlife', label: 'Bars & nightlife', emoji: '🍻' },
    { value: 'live_music', label: 'Live music', emoji: '🎶' },
    { value: 'coworking', label: 'Coworking', emoji: '💻' },
    { value: 'libraries', label: 'Libraries', emoji: '📚' },
    { value: 'dogs', label: 'Dog-friendly', emoji: '🐕' },
];

// Mirrors App\Profile\Interest::MIN_SELECT / MAX_SELECT.
const INTEREST_MIN = 3;
const INTEREST_MAX = 6;

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

const PROFILE_TABS: { id: string; label: string }[] = [
    { id: 'profile', label: 'Profile' },
    { id: 'account', label: 'Account' },
];

// ============================================================
// Page Component
// ============================================================

export default function Profile() {
    const {
        auth,
        userPlaces,
        goingEvents,
        pastEvents,
        stats,
        notificationPreferences,
        userSettings,
        interests: serverInterests,
    } = usePage<{
        auth: Auth;
        userPlaces?: UserPlaceData[];
        goingEvents?: EventSummary[];
        pastEvents?: EventSummary[];
        stats?: ProfileStats;
        notificationPreferences?: Record<string, boolean>;
        userSettings?: Record<string, boolean | string>;
        interests?: string[];
    }>().props;
    const user = auth.user;

    // Two-tab layout: 'profile' (hero + editable sections) and 'account'
    // (drill-in settings menu). Persisted in the URL hash via useTabState.
    const [activeTab, setActiveTab] = useTabState('profile');

    // Account drill-in: which sub-page is open (null = the menu itself).
    const [settingsPage, setSettingsPage] = useState<string | null>(null);

    // Profile drill-in: which sub-page is open (null = the menu itself).
    // Kept independent from the Account tab's settingsPage so the two menus
    // never share a stale state.
    const [profilePage, setProfilePage] = useState<string | null>(null);

    // Switch tabs and reset BOTH drill-in sub-pages, so the next visit to
    // either tab starts at its menu rather than a stale drill-in.
    const switchTab = useCallback(
        (tab: string) => {
            setActiveTab(tab);
            setSettingsPage(null);
            setProfilePage(null);
        },
        [setActiveTab],
    );

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

    // Inline edit state
    const [editingField, setEditingField] = useState<string | null>(null);
    const [editValue, setEditValue] = useState('');

    // Interests — seeded from backend, persisted to /settings/profile
    const [interests, setInterests] = useState<string[]>(serverInterests ?? []);

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

    // Notification + privacy toggles — initialized from server data
    const np = notificationPreferences ?? {};
    const us = userSettings ?? {};
    const [toggles, setToggles] = useState<Record<string, boolean>>({
        // Notification prefs (synced with backend)
        transitDisruptions: np.transit ?? true,
        buergermtSlots: np.burgeramt ?? true,
        eventReminders: np.events ?? true,
        checklistReminders: np.checklist ?? true,
        weeklyDigest: np.digest ?? false,
        // Privacy settings (synced with backend)
        shareLocation: (us.share_location as boolean) ?? true,
        personalised: (us.personalised_content as boolean) ?? true,
        shareAnonymised: (us.share_anonymised as boolean) ?? true,
    });

    // Delete account confirmation
    const [deleteConfirming, setDeleteConfirming] = useState(false);

    // Toast
    const [toastMsg, setToastMsg] = useState<string | null>(null);
    const showToast = useCallback((msg: string) => {
        setToastMsg(msg);
        setTimeout(() => setToastMsg(null), 2500);
    }, []);

    // Notification pref key mapping (frontend key → backend key)
    const notifKeyMap: Record<string, string> = {
        transitDisruptions: 'transit',
        buergermtSlots: 'burgeramt',
        eventReminders: 'events',
        checklistReminders: 'checklist',
        weeklyDigest: 'digest',
    };

    // Privacy setting key mapping (frontend key → backend key)
    const privacyKeyMap: Record<string, string> = {
        shareLocation: 'share_location',
        personalised: 'personalised_content',
        shareAnonymised: 'share_anonymised',
    };

    const csrf =
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

    const persistNotifPref = useCallback(
        (prefs: Record<string, boolean>) => {
            fetch('/notification-preferences', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ preferences: prefs }),
            }).catch(() => {});
        },
        [csrf],
    );

    const persistUserSetting = useCallback(
        (settings: Record<string, boolean | string>) => {
            fetch('/user-settings', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ settings }),
            }).catch(() => {});
        },
        [csrf],
    );

    // Toggle handler — persists to correct backend
    const toggle = (key: string) => {
        setToggles((prev) => {
            const newVal = !prev[key];

            // Notification preferences
            if (key in notifKeyMap) {
                const backendKey = notifKeyMap[key];
                const currentPrefs = { ...(notificationPreferences ?? {}) };
                currentPrefs[backendKey] = newVal;
                persistNotifPref(currentPrefs);
            }

            // Privacy settings
            if (key in privacyKeyMap) {
                const backendKey = privacyKeyMap[key];
                persistUserSetting({ [backendKey]: newVal });
            }

            return { ...prev, [key]: newVal };
        });
    };

    // Build the full PATCH payload the backend always requires (name + email),
    // optionally overriding one field with a fresh value.
    function buildProfilePayload(
        field: string,
        value: string,
        extraInterests?: string[],
    ): Record<string, string | string[] | null> {
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
            interests: extraInterests ?? interests,
        };
    }

    // Inline edit handlers
    function startEdit(field: string, currentValue: string) {
        setEditingField(field);
        setEditValue(currentValue);
    }

    function saveEdit(field: string) {
        const val = editValue.trim();

        if (!val) {
            return;
        }

        // Update local state immediately
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

        router.patch('/settings/profile', buildProfilePayload(field, val), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () =>
                showToast(
                    '✓ ' +
                        field.charAt(0).toUpperCase() +
                        field.slice(1) +
                        ' updated',
                ),
            onError: (errors) => {
                const firstError = Object.values(errors)[0];
                showToast(
                    'Error: ' +
                        (typeof firstError === 'string'
                            ? firstError
                            : 'Could not save'),
                );

                switch (field) {
                    case 'name':
                        setProfileName(user?.name ?? '');
                        break;
                    case 'email':
                        setProfileEmail(user?.email ?? '');
                        break;
                }
            },
        });
    }

    function cancelEdit() {
        setEditingField(null);
    }

    // Interests — toggle a chip and persist alongside required profile fields.
    function toggleInterest(value: string) {
        const isOn = interests.includes(value);

        // Don't let the user drop below the minimum; backend also enforces this.
        if (isOn && interests.length <= INTEREST_MIN) {
            showToast(`Keep at least ${INTEREST_MIN} interests`);

            return;
        }

        if (!isOn && interests.length >= INTEREST_MAX) {
            showToast(`Up to ${INTEREST_MAX} interests`);

            return;
        }

        const next = isOn
            ? interests.filter((i) => i !== value)
            : [...interests, value];

        setInterests(next);

        router.patch(
            '/settings/profile',
            buildProfilePayload('interests', '', next),
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => showToast('✓ Interests updated'),
                onError: () => {
                    showToast('Error: Could not save interests');
                    setInterests(interests);
                },
            },
        );
    }

    // Places
    function openPlaceForm(place?: Place) {
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
        if (!placeName.trim()) {
            showToast('Please enter a place name');

            return;
        }

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
                    onSuccess: () =>
                        showToast('✓ ' + placeName.trim() + ' updated'),
                    onError: (errors) => {
                        const firstError = Object.values(errors)[0];
                        showToast(
                            'Error: ' +
                                (typeof firstError === 'string'
                                    ? firstError
                                    : 'Could not update'),
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
                    onSuccess: () =>
                        showToast(
                            '✓ ' + placeName.trim() + ' added to your places',
                        ),
                    onError: (errors) => {
                        const firstError = Object.values(errors)[0];
                        showToast(
                            'Error: ' +
                                (typeof firstError === 'string'
                                    ? firstError
                                    : 'Could not save'),
                        );
                    },
                },
            );
        }

        setShowPlaceForm(false);
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

    // Search address via /api/geocode and get coordinates
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
                        const res = await fetch(
                            `/api/geocode?q=${encodeURIComponent(query.trim())}`,
                        );

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

    const germanBadge = (user.german_level as string)?.toUpperCase() ?? '—';

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Profile', href: '/profile' }]}
            rightPanel={<ProfileRightPanel />}
        >
            <Head title="Profile" />
            <div className="mx-auto w-full max-w-[680px]">
                {/* ── Tab bar ── */}
                <div
                    className="sticky top-0 z-40 flex border-b border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]"
                    style={{ scrollbarWidth: 'none' }}
                >
                    {PROFILE_TABS.map((t) => (
                        <button
                            key={t.id}
                            onClick={() => switchTab(t.id)}
                            className={`flex-1 cursor-pointer bg-transparent px-4 py-3 whitespace-nowrap transition-all hover:bg-[#EFEDE7] dark:hover:bg-[#2A2920] ${activeTab === t.id ? 'text-[#1A4CD4]' : 'text-[#6B6860] dark:text-[#AAA89F]'}`}
                            style={{
                                fontSize: 12,
                                fontWeight: 600,
                                border: 'none',
                                borderBottomWidth: 2,
                                borderBottomStyle: 'solid',
                                borderBottomColor:
                                    activeTab === t.id
                                        ? '#1A4CD4'
                                        : 'transparent',
                                fontFamily: "'Geist', sans-serif",
                            }}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                {/* ════════════════════════════════════════════════════════════
                   TAB: PROFILE
                   ════════════════════════════════════════════════════════════ */}
                {activeTab === 'profile' && (
                    <>
                        {/* ── Profile Hero ── */}
                        <div
                            className="relative overflow-hidden"
                            style={{
                                background:
                                    'linear-gradient(145deg, #1A3A8F 0%, #1A4CD4 100%)',
                                color: 'white',
                                padding: '24px 20px 0',
                                display: 'flex',
                                flexDirection: 'column',
                            }}
                        >
                            {/* Decorative circle */}
                            <div
                                className="pointer-events-none absolute"
                                style={{
                                    top: -80,
                                    right: -60,
                                    width: 260,
                                    height: 260,
                                    background: 'rgba(255,255,255,.05)',
                                    borderRadius: '50%',
                                }}
                            />
                            {/* Top row */}
                            <div className="relative z-[1] mb-5 flex w-full items-center gap-3.5">
                                <div
                                    className="flex shrink-0 items-center justify-center rounded-full"
                                    style={{
                                        width: 60,
                                        height: 60,
                                        background: 'rgba(255,255,255,.2)',
                                        border: '2px solid rgba(255,255,255,.3)',
                                        fontSize: 24,
                                        fontWeight: 700,
                                    }}
                                >
                                    {(
                                        profileName.charAt(0) || '?'
                                    ).toUpperCase()}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div
                                        style={{
                                            fontFamily: "'Fraunces', serif",
                                            fontSize: 22,
                                            fontWeight: 500,
                                            lineHeight: 1.1,
                                            marginBottom: 4,
                                        }}
                                    >
                                        {profileName || 'Your profile'}
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 11,
                                            opacity: 0.75,
                                            lineHeight: 1.5,
                                        }}
                                    >
                                        {[profileSituation, profileCity]
                                            .filter(Boolean)
                                            .join(' · ') ||
                                            'Complete your profile'}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setProfilePage('details')}
                                    className="shrink-0 cursor-pointer rounded-full border-none whitespace-nowrap transition-all hover:bg-[rgba(255,255,255,0.15)]"
                                    style={{
                                        padding: '7px 14px',
                                        border: '1.5px solid rgba(255,255,255,.4)',
                                        background: 'transparent',
                                        color: 'white',
                                        fontFamily: "'Geist', sans-serif",
                                        fontSize: 12,
                                        fontWeight: 600,
                                    }}
                                >
                                    Edit profile
                                </button>
                            </div>
                            {/* Stats */}
                            <div
                                className="relative z-[1] flex w-full"
                                style={{
                                    borderTop:
                                        '1px solid rgba(255,255,255,.15)',
                                }}
                            >
                                {[
                                    {
                                        num: String(stats?.events_joined ?? 0),
                                        lbl: 'Events',
                                    },
                                    {
                                        num: String(
                                            stats?.tasks_completed ?? 0,
                                        ),
                                        lbl: 'Tasks',
                                    },
                                    {
                                        num:
                                            stats?.days_in_germany != null
                                                ? String(stats.days_in_germany)
                                                : '—',
                                        lbl: 'Days',
                                    },
                                    {
                                        num: germanBadge,
                                        lbl: 'German',
                                    },
                                ].map((stat, i, arr) => (
                                    <div
                                        key={stat.lbl}
                                        className="flex-1 text-center"
                                        style={{
                                            padding: '14px 4px',
                                            borderRight:
                                                i < arr.length - 1
                                                    ? '1px solid rgba(255,255,255,.1)'
                                                    : 'none',
                                        }}
                                    >
                                        <div
                                            style={{
                                                fontFamily:
                                                    "'Geist Mono', monospace",
                                                fontSize: 20,
                                                fontWeight: 600,
                                                lineHeight: 1,
                                            }}
                                        >
                                            {stat.num}
                                        </div>
                                        <div
                                            style={{
                                                fontSize: 9,
                                                opacity: 0.65,
                                                marginTop: 3,
                                                textTransform: 'uppercase',
                                                letterSpacing: '0.07em',
                                            }}
                                        >
                                            {stat.lbl}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* ── Profile drill-in menu ── */}
                        <div className="py-2">
                            {/* PERSONAL DETAILS — drill-in */}
                            <SettingsSection
                                id="details"
                                icon="👤"
                                title="Personal details"
                                activePage={profilePage}
                                onNavigate={setProfilePage}
                                onBack={() => setProfilePage(null)}
                                backLabel="← Back to Profile"
                            >
                                <InlineEditRow
                                    field="name"
                                    label="Name"
                                    value={profileName}
                                    editing={editingField}
                                    editValue={editValue}
                                    onStartEdit={startEdit}
                                    onEditValue={setEditValue}
                                    onSave={saveEdit}
                                    onCancel={cancelEdit}
                                />
                                <InlineEditRow
                                    field="email"
                                    label="Email"
                                    value={profileEmail}
                                    editing={editingField}
                                    editValue={editValue}
                                    onStartEdit={startEdit}
                                    onEditValue={setEditValue}
                                    onSave={saveEdit}
                                    onCancel={cancelEdit}
                                    inputType="email"
                                />
                                <InlineEditRow
                                    field="city"
                                    label="City"
                                    value={profileCity}
                                    editing={editingField}
                                    editValue={editValue}
                                    onStartEdit={startEdit}
                                    onEditValue={setEditValue}
                                    onSave={saveEdit}
                                    onCancel={cancelEdit}
                                />
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
                                    selectOptions={[
                                        'Non-EU employee',
                                        'EU employee',
                                        'Student',
                                        'Freelancer',
                                        'Family reunification',
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

                                {/* Redo onboarding: re-asks every answer, the
                        plan recomputes, nothing is deleted. */}
                                <div className="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-[10px] bg-[#EFEDE7] px-3.5 py-3 dark:bg-[#2A2920]">
                                    <div className="min-w-0">
                                        <div className="text-[13px] font-semibold">
                                            Situation changed?
                                        </div>
                                        <div className="text-xs text-[#6B6860] dark:text-[#AAA89F]">
                                            Walk through the welcome questions
                                            again — your plan adapts, and
                                            nothing you've done is lost.
                                        </div>
                                    </div>
                                    <button
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    'Redo onboarding? Your answers will be re-asked and your plan recomputed. All progress is kept.',
                                                )
                                            ) {
                                                router.post(
                                                    '/onboarding/restart',
                                                );
                                            }
                                        }}
                                        className="shrink-0 cursor-pointer rounded-lg border border-[#E2DFD6] bg-white px-3 py-2 text-xs font-semibold text-[#18170F] transition-colors hover:border-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#F6F5F1] dark:hover:border-[#5B8DEF]"
                                    >
                                        🔄 Redo onboarding
                                    </button>
                                </div>
                            </SettingsSection>

                            {/* INTERESTS — drill-in */}
                            <SettingsSection
                                id="interests"
                                icon="⭐"
                                title="Interests"
                                activePage={profilePage}
                                onNavigate={setProfilePage}
                                onBack={() => setProfilePage(null)}
                                backLabel="← Back to Profile"
                            >
                                <div className="mb-3 flex items-center justify-end">
                                    <span
                                        className="text-[#AAA89F]"
                                        style={{
                                            fontSize: 11,
                                            fontWeight: 700,
                                            textTransform: 'uppercase',
                                            letterSpacing: '0.08em',
                                        }}
                                    >
                                        {`${interests.length}/${INTEREST_MAX}`}
                                    </span>
                                </div>
                                <p className="mb-3 text-xs text-[#6B6860] dark:text-[#AAA89F]">
                                    Pick {INTEREST_MIN}–{INTEREST_MAX}. We lead
                                    your home feed and day plans with these.
                                </p>
                                <div className="grid grid-cols-2 gap-2.5">
                                    {INTEREST_OPTIONS.map((interest) => {
                                        const on = interests.includes(
                                            interest.value,
                                        );
                                        const atMax =
                                            interests.length >= INTEREST_MAX;
                                        const disabled = !on && atMax;

                                        return (
                                            <button
                                                key={interest.value}
                                                type="button"
                                                aria-pressed={on}
                                                disabled={disabled}
                                                onClick={() =>
                                                    toggleInterest(
                                                        interest.value,
                                                    )
                                                }
                                                className={`flex items-center gap-2.5 rounded-[12px] border-[1.5px] px-4 py-3.5 text-left text-[14px] font-semibold transition-all ${
                                                    on
                                                        ? 'border-[#1A4CD4] bg-[#EBF0FD] text-[#1A4CD4] dark:bg-[#1A4CD4]/15'
                                                        : disabled
                                                          ? 'border-[#E2DFD6] bg-white opacity-40 dark:border-[#3A3930] dark:bg-[#1E1D15]'
                                                          : 'border-[#E2DFD6] bg-white hover:border-[#1A4CD4]/30 dark:border-[#3A3930] dark:bg-[#1E1D15]'
                                                }`}
                                            >
                                                <span className="text-[20px] leading-none">
                                                    {interest.emoji}
                                                </span>
                                                {interest.label}
                                                {on && (
                                                    <span className="ml-auto text-[#1A4CD4]">
                                                        ✓
                                                    </span>
                                                )}
                                            </button>
                                        );
                                    })}
                                </div>
                            </SettingsSection>

                            {/* YOUR PLACES — drill-in */}
                            <SettingsSection
                                id="places"
                                icon="📍"
                                title="Your places"
                                activePage={profilePage}
                                onNavigate={setProfilePage}
                                onBack={() => setProfilePage(null)}
                                backLabel="← Back to Profile"
                            >
                                {places.map((p) => (
                                    <div
                                        key={p.id}
                                        className="flex items-center justify-between border-b border-[#E2DFD6] py-3.5 last:border-b-0 dark:border-[#3A3930]"
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
                                                className="text-[#AAA89F]"
                                                style={{
                                                    fontSize: 12,
                                                    marginTop: 2,
                                                }}
                                            >
                                                {p.addr}
                                            </div>
                                        </div>
                                        <button
                                            onClick={() => openPlaceForm(p)}
                                            className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-white px-2.5 py-1 text-[#6B6860] transition-all hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#AAA89F] dark:hover:bg-[#2A2920]"
                                            style={{
                                                fontFamily:
                                                    "'Geist', sans-serif",
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
                                                    onClick={() =>
                                                        deletePlace(p.id)
                                                    }
                                                    className="cursor-pointer rounded-[9px] border border-[#FDE8E6] bg-[#FDE8E6] px-2.5 py-1 transition-all hover:bg-[#F9CCC8]"
                                                    style={{
                                                        fontFamily:
                                                            "'Geist', sans-serif",
                                                        fontSize: 11,
                                                        fontWeight: 600,
                                                        color: '#C4271A',
                                                    }}
                                                >
                                                    ✕
                                                </button>
                                            )}
                                    </div>
                                ))}
                                {showPlaceForm && (
                                    <div className="mt-2.5 rounded-[14px] border border-[#E2DFD6] bg-[#EFEDE7] p-3.5 dark:border-[#3A3930] dark:bg-[#2A2920]">
                                        <div
                                            className="text-[#6B6860] dark:text-[#AAA89F]"
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
                                                    <span className="text-[11px] font-bold tracking-[0.07em] text-[#AAA89F] uppercase">
                                                        Choose an icon
                                                    </span>
                                                    <button
                                                        onClick={() =>
                                                            setShowIconPicker(
                                                                false,
                                                            )
                                                        }
                                                        className="text-[11px] font-semibold text-[#1A4CD4]"
                                                    >
                                                        Done
                                                    </button>
                                                </div>
                                                <div className="mb-3 flex flex-wrap gap-1.5">
                                                    {PLACE_ICONS.map((ico) => (
                                                        <div
                                                            key={ico}
                                                            onClick={() => {
                                                                setPlaceEmoji(
                                                                    ico,
                                                                );
                                                                setShowIconPicker(
                                                                    false,
                                                                );
                                                            }}
                                                            className={`flex cursor-pointer items-center justify-center rounded-[9px] transition-all ${placeEmoji === ico ? 'border-2 border-[#1A4CD4] bg-[#EBF0FD]' : 'border-2 border-transparent bg-white dark:bg-[#1E1D15]'}`}
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
                                                    setShowIconPicker(
                                                        !showIconPicker,
                                                    )
                                                }
                                                className="flex shrink-0 cursor-pointer items-center justify-center rounded-[9px] transition-all hover:opacity-80"
                                                style={{
                                                    width: 44,
                                                    height: 44,
                                                    background: '#EBF0FD',
                                                    border: '2px solid #1A4CD4',
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
                                                className="flex-1 rounded-[9px] border border-[#E2DFD6] bg-white px-3 py-[9px] text-sm outline-none dark:border-[#3A3930] dark:bg-[#1E1D15]"
                                                style={{
                                                    fontFamily:
                                                        "'Geist', sans-serif",
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
                                                            setAddrHighlight(
                                                                -1,
                                                            );
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

                                                            if (
                                                                e.key ===
                                                                'ArrowDown'
                                                            ) {
                                                                e.preventDefault();
                                                                setAddrHighlight(
                                                                    (prev) =>
                                                                        Math.min(
                                                                            prev +
                                                                                1,
                                                                            addrSuggestions.length -
                                                                                1,
                                                                        ),
                                                                );
                                                            } else if (
                                                                e.key ===
                                                                'ArrowUp'
                                                            ) {
                                                                e.preventDefault();
                                                                setAddrHighlight(
                                                                    (prev) =>
                                                                        Math.max(
                                                                            prev -
                                                                                1,
                                                                            0,
                                                                        ),
                                                                );
                                                            } else if (
                                                                e.key ===
                                                                    'Enter' &&
                                                                addrHighlight >=
                                                                    0
                                                            ) {
                                                                e.preventDefault();
                                                                const s =
                                                                    addrSuggestions[
                                                                        addrHighlight
                                                                    ];
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
                                                                setAddrHighlight(
                                                                    -1,
                                                                );
                                                            } else if (
                                                                e.key ===
                                                                'Escape'
                                                            ) {
                                                                addrTimerRef.clear();
                                                                setAddrHighlight(
                                                                    -1,
                                                                );
                                                            }
                                                        }}
                                                        onBlur={() =>
                                                            setTimeout(() => {
                                                                addrTimerRef.clear();
                                                                setAddrHighlight(
                                                                    -1,
                                                                );
                                                            }, 200)
                                                        }
                                                        placeholder="Address or area (e.g. Ehrenfeld)"
                                                        className="w-full rounded-[9px] border border-[#E2DFD6] bg-white px-3 py-[9px] text-sm outline-none dark:border-[#3A3930] dark:bg-[#1E1D15]"
                                                        style={{
                                                            fontFamily:
                                                                "'Geist', sans-serif",
                                                        }}
                                                    />
                                                    {/* Address autocomplete dropdown */}
                                                    {addrSuggestions.length >
                                                        0 && (
                                                        <div className="absolute top-full right-0 left-0 z-50 mt-1 overflow-hidden rounded-[9px] border border-[#E2DFD6] bg-white shadow-[0_8px_24px_rgba(0,0,0,0.08)] dark:border-[#3A3930] dark:bg-[#1E1D15]">
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
                                                                        className={`cursor-pointer px-3 py-2.5 transition-colors ${addrHighlight === i ? 'bg-[#EBF0FD] dark:bg-[#1A4CD4]/20' : 'hover:bg-[#EFEDE7] dark:hover:bg-[#2A2920]'}`}
                                                                        style={{
                                                                            borderBottom:
                                                                                i <
                                                                                addrSuggestions.length -
                                                                                    1
                                                                                    ? '1px solid #F0EDE7'
                                                                                    : 'none',
                                                                        }}
                                                                    >
                                                                        <div className="text-sm font-medium text-[#18170F] dark:text-[#F5F4F0]">
                                                                            {
                                                                                s.name
                                                                            }
                                                                        </div>
                                                                        <div className="text-xs text-[#6B6860] dark:text-[#AAA89F]">
                                                                            {
                                                                                s.address
                                                                            }
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
                                                    className="flex shrink-0 cursor-pointer items-center justify-center rounded-[9px] border border-[#E2DFD6] bg-white transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] disabled:opacity-50 dark:border-[#3A3930] dark:bg-[#1E1D15]"
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
                                                                border: '2px solid #E2DFD6',
                                                                borderTopColor:
                                                                    '#1A4CD4',
                                                                borderRadius:
                                                                    '50%',
                                                                animation:
                                                                    'spin .7s linear infinite',
                                                            }}
                                                        />
                                                    ) : (
                                                        <span
                                                            style={{
                                                                fontSize: 18,
                                                            }}
                                                        >
                                                            📍
                                                        </span>
                                                    )}
                                                </button>
                                            </div>
                                        </div>
                                        {/* Optional: Arrive by + Days — toggle buttons */}
                                        <div className="mb-2.5 flex gap-2">
                                            <button
                                                onClick={() =>
                                                    setShowArriveBy(
                                                        !showArriveBy,
                                                    )
                                                }
                                                className={`flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[12px] font-medium transition-all ${showArriveBy ? 'bg-[#EBF0FD] text-[#1A4CD4] dark:bg-[#1A4CD4]/20 dark:text-[#6B9AFF]' : 'bg-[#EFEDE7] text-[#6B6860] dark:bg-[#2A2920] dark:text-[#AAA89F]'}`}
                                            >
                                                <IconClock
                                                    size={14}
                                                    stroke={1.7}
                                                />{' '}
                                                Arrive by
                                            </button>
                                            <button
                                                onClick={() =>
                                                    setShowDays(!showDays)
                                                }
                                                className={`flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[12px] font-medium transition-all ${showDays ? 'bg-[#EBF0FD] text-[#1A4CD4] dark:bg-[#1A4CD4]/20 dark:text-[#6B9AFF]' : 'bg-[#EFEDE7] text-[#6B6860] dark:bg-[#2A2920] dark:text-[#AAA89F]'}`}
                                            >
                                                <IconCalendar
                                                    size={14}
                                                    stroke={1.7}
                                                />{' '}
                                                Days
                                            </button>
                                        </div>

                                        {/* Arrive by time */}
                                        {showArriveBy && (
                                            <div className="mb-2.5 flex items-center gap-2">
                                                <span
                                                    className="text-[#6B6860] dark:text-[#AAA89F]"
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
                                                        setPlaceArriveBy(
                                                            e.target.value,
                                                        )
                                                    }
                                                    className="flex-1 rounded-[9px] border border-[#E2DFD6] bg-white px-3 py-[9px] text-sm outline-none dark:border-[#3A3930] dark:bg-[#1E1D15]"
                                                    style={{
                                                        fontFamily:
                                                            "'Geist Mono', monospace",
                                                        color: placeArriveBy
                                                            ? '#1A4CD4'
                                                            : '#AAA89F',
                                                    }}
                                                    placeholder="--:--"
                                                />
                                                <span
                                                    className="text-[#AAA89F]"
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
                                                                setPlaceDayMode(
                                                                    m.id,
                                                                )
                                                            }
                                                            className={`cursor-pointer rounded-full border px-2.5 py-[4px] transition-all ${placeDayMode === m.id ? 'border-[#1A4CD4] bg-[#1A4CD4] text-white' : 'border-[#E2DFD6] bg-white text-[#6B6860] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#AAA89F]'}`}
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
                                                                        (
                                                                            prev,
                                                                        ) =>
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
                                                                className={`flex cursor-pointer items-center justify-center transition-all ${placeActiveDays.includes(d) ? 'border-2 border-[#1A4CD4] bg-[#EBF0FD] text-[#1A4CD4]' : 'border-2 border-transparent bg-[#EFEDE7] text-[#AAA89F] dark:bg-[#2A2920]'}`}
                                                                style={{
                                                                    width: 32,
                                                                    height: 32,
                                                                    borderRadius:
                                                                        '50%',
                                                                    fontSize: 11,
                                                                    fontWeight: 700,
                                                                }}
                                                            >
                                                                {d
                                                                    .charAt(0)
                                                                    .toUpperCase() +
                                                                    d.slice(
                                                                        1,
                                                                        2,
                                                                    )}
                                                            </button>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                        <div className="flex gap-2">
                                            <button
                                                onClick={savePlace}
                                                className="flex-1 cursor-pointer rounded-[9px] border-none bg-[#1A4CD4] py-[9px] text-[13px] font-semibold text-white"
                                                style={{
                                                    fontFamily:
                                                        "'Geist', sans-serif",
                                                }}
                                            >
                                                {editingPlaceId
                                                    ? 'Update place'
                                                    : 'Save place'}
                                            </button>
                                            <button
                                                onClick={() =>
                                                    setShowPlaceForm(false)
                                                }
                                                className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-white px-3.5 py-[9px] text-[13px] text-[#6B6860] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#AAA89F]"
                                                style={{
                                                    fontFamily:
                                                        "'Geist', sans-serif",
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
                                        className="mt-2.5 w-full cursor-pointer rounded-[9px] border-[1.5px] border-dashed border-[#E2DFD6] bg-transparent py-2.5 text-[13px] font-medium text-[#1A4CD4] transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] dark:border-[#3A3930]"
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
                                icon="📅"
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
                                        className="text-[#AAA89F]"
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
                                            barColor="#0A7C52"
                                            title={ev.title}
                                            sub={`${ev.date} · ${ev.time}${ev.venue ? ` · ${ev.venue}` : ''}`}
                                            badge="✓ Going"
                                            badgeBg="#D4F0E6"
                                            badgeColor="#0A7C52"
                                        />
                                    ))
                                ) : (
                                    <div className="rounded-xl border border-dashed border-[#E2DFD6] p-6 text-center text-sm text-[#AAA89F] dark:border-[#3A3930]">
                                        No upcoming events. Browse the{' '}
                                        <a
                                            href="/events"
                                            className="text-[#1A4CD4] underline"
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
                                                barColor="#E2DFD6"
                                                title={ev.title}
                                                titleColor="#6B6860"
                                                sub={`${ev.date}${ev.venue ? ` · ${ev.venue}` : ''}`}
                                                badge="Attended"
                                                badgeBg="#EFEDE7"
                                                badgeColor="#AAA89F"
                                            />
                                        ))}
                                    </div>
                                )}
                            </SettingsSection>
                        </div>
                    </>
                )}

                {/* ════════════════════════════════════════════════════════════
                   TAB: ACCOUNT — drill-in settings menu
                   ════════════════════════════════════════════════════════════ */}
                {activeTab === 'account' && (
                    <div className="py-2">
                        {/* NOTIFICATIONS — drill-in */}
                        <SettingsSection
                            id="notifications"
                            icon="🔔"
                            title="Notifications"
                            activePage={settingsPage}
                            onNavigate={setSettingsPage}
                            onBack={() => setSettingsPage(null)}
                        >
                            <SettingToggle
                                label="🚇 Transit disruptions"
                                sub="Delays, closures on your routes"
                                on={toggles.transitDisruptions}
                                onToggle={() => toggle('transitDisruptions')}
                            />
                            <SettingToggle
                                label="🏛️ Bürgeramt slot alerts"
                                sub="Notified when new slots open"
                                on={toggles.buergermtSlots}
                                onToggle={() => toggle('buergermtSlots')}
                            />
                            <SettingToggle
                                label="📅 Event reminders"
                                sub="1 hour before events you've joined"
                                on={toggles.eventReminders}
                                onToggle={() => toggle('eventReminders')}
                            />
                            <SettingToggle
                                label="📋 Checklist reminders"
                                sub="Weekly nudge on pending tasks"
                                on={toggles.checklistReminders}
                                onToggle={() => toggle('checklistReminders')}
                            />
                            <SettingToggle
                                label="📰 Weekly city digest"
                                sub="Events, spots, and expat tips"
                                on={toggles.weeklyDigest}
                                onToggle={() => toggle('weeklyDigest')}
                                isLast
                            />
                        </SettingsSection>

                        {/* PRIVACY & DATA — drill-in */}
                        <SettingsSection
                            id="privacy"
                            icon="🔐"
                            title="Privacy & data"
                            activePage={settingsPage}
                            onNavigate={setSettingsPage}
                            onBack={() => setSettingsPage(null)}
                        >
                            <SettingToggle
                                label="Share location for transit"
                                sub="Improves departure times accuracy"
                                on={toggles.shareLocation}
                                onToggle={() => toggle('shareLocation')}
                            />
                            <SettingToggle
                                label="Personalised content"
                                sub="Use activity to improve recommendations"
                                on={toggles.personalised}
                                onToggle={() => toggle('personalised')}
                            />
                            <SettingToggle
                                label="Share anonymised data"
                                sub="Help improve Expadu for all expats"
                                on={toggles.shareAnonymised}
                                onToggle={() => toggle('shareAnonymised')}
                                isLast
                            />
                        </SettingsSection>

                        {/* The nav-away + feedback rows only show on the menu
                            itself (i.e. when no drill-in page is open). */}
                        {settingsPage === null && (
                            <>
                                <SettingNavRow
                                    icon="🔒"
                                    label="Security"
                                    sub="Password & 2FA"
                                    onClick={() =>
                                        router.visit('/settings/security')
                                    }
                                />
                                <SettingNavRow
                                    icon="🎨"
                                    label="Appearance"
                                    sub="Theme & dark mode"
                                    onClick={() =>
                                        router.visit('/settings/appearance')
                                    }
                                />
                                <a
                                    href="mailto:feedback@expadu.com"
                                    className="flex w-full cursor-pointer items-center gap-3 border-b border-[#E2DFD6] px-5 py-3.5 text-left transition-colors hover:bg-[#F6F5F1] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
                                >
                                    <span className="shrink-0 text-base">
                                        ✉️
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-[14px] font-medium">
                                            Send feedback
                                        </div>
                                        <div
                                            className="text-[#AAA89F]"
                                            style={{
                                                fontSize: 12,
                                                marginTop: 2,
                                            }}
                                        >
                                            Report a bug or suggest a feature
                                        </div>
                                    </div>
                                    <span
                                        className="text-[#AAA89F]"
                                        style={{ fontSize: 16 }}
                                    >
                                        ›
                                    </span>
                                </a>
                                <SettingNavRow
                                    icon="🏛️"
                                    label="Bureaucracy checklist"
                                    sub="Your settling-in tasks & appointments"
                                    onClick={() => router.visit('/bureaucracy')}
                                />
                            </>
                        )}

                        {/* DELETE ACCOUNT — danger drill-in with two-tap confirm */}
                        <SettingsSection
                            id="danger"
                            icon="⚠️"
                            title="Delete account"
                            danger
                            activePage={settingsPage}
                            onNavigate={setSettingsPage}
                            onBack={() => setSettingsPage(null)}
                        >
                            <div
                                className="flex cursor-pointer items-center justify-between py-3.5 transition-colors hover:bg-[#EFEDE7] dark:hover:bg-[#2A2920]"
                                onClick={() => {
                                    if (deleteConfirming) {
                                        router.delete('/settings/profile');
                                    } else {
                                        setDeleteConfirming(true);
                                        setTimeout(
                                            () => setDeleteConfirming(false),
                                            3000,
                                        );
                                    }
                                }}
                            >
                                <div>
                                    <div
                                        style={{
                                            fontSize: 14,
                                            fontWeight: 500,
                                            color: '#C4271A',
                                        }}
                                    >
                                        {deleteConfirming
                                            ? 'Tap again to confirm'
                                            : 'Delete account'}
                                    </div>
                                    <div
                                        className="text-[#AAA89F]"
                                        style={{ fontSize: 12, marginTop: 2 }}
                                    >
                                        Permanently remove your account and data
                                    </div>
                                </div>
                                <span
                                    style={{ fontSize: 16, color: '#C4271A' }}
                                >
                                    ›
                                </span>
                            </div>
                        </SettingsSection>

                        {/* Version — only on the menu itself */}
                        {settingsPage === null && (
                            <div className="flex items-center justify-between px-5 py-3.5">
                                <div
                                    className="text-[#AAA89F]"
                                    style={{ fontSize: 14, fontWeight: 500 }}
                                >
                                    Version
                                </div>
                                <span
                                    className="text-[#AAA89F]"
                                    style={{ fontSize: 13 }}
                                >
                                    1.0.0
                                </span>
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Toast */}
            {toastMsg && (
                <div
                    className="pointer-events-none fixed bottom-20 left-1/2 z-[9000] -translate-x-1/2 rounded-[14px] bg-[#18170F] px-[18px] py-[11px] text-[13px] font-medium whitespace-nowrap text-white shadow-[0_8px_24px_rgba(0,0,0,0.15)] dark:bg-[#F5F4F0] dark:text-[#18170F]"
                    style={{ maxWidth: '90vw', textAlign: 'center' }}
                >
                    {toastMsg}
                </div>
            )}
        </AppLayout>
    );
}

// ============================================================
// Sub-components
// ============================================================

function SettingsSection({
    id,
    icon,
    title,
    activePage,
    onNavigate,
    onBack,
    children,
    danger,
    backLabel = '← Back to Account',
}: {
    id: string;
    icon: string;
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
                className={`flex w-full cursor-pointer items-center gap-3 border-b border-[#E2DFD6] px-5 py-3.5 text-left transition-colors hover:bg-[#F6F5F1] dark:border-[#3A3930] dark:hover:bg-[#2A2920] ${danger ? 'text-[#C4271A]' : ''}`}
            >
                <span className="shrink-0 text-base">{icon}</span>
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
                    className="shrink-0 text-[#AAA89F]"
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
                    className="flex w-full items-center gap-2 border-b border-[#E2DFD6] px-5 py-3 text-[13px] font-semibold text-[#6B6860] transition-colors hover:text-[#1A4CD4] dark:border-[#3A3930] dark:text-[#AAA89F]"
                >
                    {backLabel}
                </button>
                <div className="border-b border-[#E2DFD6] px-5 py-4 dark:border-[#3A3930]">
                    <div className="mb-3 flex items-center gap-2">
                        <span className="text-base">{icon}</span>
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

function SettingNavRow({
    icon,
    label,
    sub,
    onClick,
}: {
    icon: string;
    label: string;
    sub?: string;
    onClick: () => void;
}) {
    return (
        <button
            onClick={onClick}
            className="flex w-full cursor-pointer items-center gap-3 border-b border-[#E2DFD6] px-5 py-3.5 text-left transition-colors hover:bg-[#F6F5F1] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
        >
            <span className="shrink-0 text-base">{icon}</span>
            <div className="min-w-0 flex-1">
                <div className="text-[14px] font-medium">{label}</div>
                {sub && (
                    <div
                        className="text-[#AAA89F]"
                        style={{ fontSize: 12, marginTop: 2 }}
                    >
                        {sub}
                    </div>
                )}
            </div>
            <span className="text-[#AAA89F]" style={{ fontSize: 16 }}>
                ›
            </span>
        </button>
    );
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
                    style={{ fontSize: 13, color: '#1A4CD4', fontWeight: 500 }}
                >
                    {action}
                </span>
            )}
            {badge && (
                <span
                    className="text-[#AAA89F]"
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
            className="mb-2 flex cursor-pointer items-center gap-3 rounded-[14px] border border-[#E2DFD6] bg-white p-3.5 transition-all last:mb-0 hover:border-[rgba(26,76,212,0.2)] hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:hover:bg-[#2A2920]"
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
                <div
                    className="text-[#6B6860] dark:text-[#AAA89F]"
                    style={{ fontSize: 12 }}
                >
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

function SettingToggle({
    label,
    sub,
    on,
    onToggle,
    isLast,
}: {
    label: string;
    sub?: string;
    on: boolean;
    onToggle: () => void;
    isLast?: boolean;
}) {
    return (
        <div
            className={`flex items-center justify-between py-3.5 transition-colors ${isLast ? '' : 'border-b border-[#E2DFD6] dark:border-[#3A3930]'}`}
        >
            <div>
                <div style={{ fontSize: 14, fontWeight: 500 }}>{label}</div>
                {sub && (
                    <div
                        className="text-[#AAA89F]"
                        style={{ fontSize: 12, marginTop: 2 }}
                    >
                        {sub}
                    </div>
                )}
            </div>
            <div
                onClick={onToggle}
                className="relative shrink-0 cursor-pointer rounded-[20px] transition-colors"
                style={{
                    width: 40,
                    height: 22,
                    background: on ? '#0A7C52' : '#E2DFD6',
                }}
            >
                <div
                    className="absolute rounded-full bg-white shadow-[0_1px_4px_rgba(0,0,0,0.2)]"
                    style={{
                        top: 2,
                        left: 2,
                        width: 18,
                        height: 18,
                        transform: on ? 'translateX(18px)' : 'translateX(0)',
                        transition: 'transform .25s cubic-bezier(.32,1,.4,1)',
                    }}
                />
            </div>
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
    inputType?: string;
    selectOptions?: string[];
    subText?: string;
    isLast?: boolean;
}) {
    const isEditing = editing === field;

    if (isEditing) {
        return (
            <div
                className={`py-3.5 ${isLast ? '' : 'border-b border-[#E2DFD6] dark:border-[#3A3930]'}`}
            >
                <div
                    className="text-[#AAA89F]"
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
                        value={editValue}
                        onChange={(e) => onEditValue(e.target.value)}
                        className="w-full rounded-lg border-[1.5px] border-[#E2DFD6] bg-white px-3.5 py-[11px] text-[15px] text-[#18170F] outline-none focus:border-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#F5F4F0]"
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
                        type={inputType || 'text'}
                        value={editValue}
                        onChange={(e) => onEditValue(e.target.value)}
                        className="w-full rounded-lg border-[1.5px] border-[#E2DFD6] bg-white px-3.5 py-[11px] text-[15px] text-[#18170F] outline-none focus:border-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#F5F4F0]"
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
                        className="text-[#AAA89F]"
                        style={{ fontSize: 12, marginTop: 6 }}
                    >
                        {subText}
                    </div>
                )}
                <div className="mt-2.5 flex gap-2">
                    <button
                        onClick={() => onSave(field)}
                        className="cursor-pointer rounded-lg border-none bg-[#1A4CD4] px-5 py-[9px] text-[13px] font-semibold text-white transition-all hover:bg-[#1541B8]"
                        style={{ fontFamily: "'Geist', sans-serif" }}
                    >
                        Save
                    </button>
                    <button
                        onClick={onCancel}
                        className="cursor-pointer rounded-lg border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-[9px] text-[13px] text-[#6B6860] transition-all hover:bg-[#E2DFD6] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#AAA89F] dark:hover:bg-[#3A3930]"
                        style={{ fontFamily: "'Geist', sans-serif" }}
                    >
                        Cancel
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div
            className={`flex cursor-pointer items-center justify-between py-3.5 ${isLast ? '' : 'border-b border-[#E2DFD6] dark:border-[#3A3930]'}`}
            onClick={() => onStartEdit(field, value)}
        >
            <div className="min-w-0 flex-1">
                <div
                    className="text-[#AAA89F]"
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.06em',
                        marginBottom: 3,
                    }}
                >
                    {label}
                </div>
                <div
                    className="text-[#18170F] dark:text-[#F5F4F0]"
                    style={{ fontSize: 15, fontWeight: 500 }}
                >
                    {value || '—'}
                </div>
            </div>
            <span
                className="ml-3 shrink-0 text-[#AAA89F] transition-colors hover:text-[#1A4CD4]"
                style={{ fontSize: 13, fontWeight: 600 }}
            >
                Edit
            </span>
        </div>
    );
}
