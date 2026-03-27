import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { ProfileRightPanel } from '@/components/profile/profile-right-panel';
import AppLayout from '@/layouts/app-layout';
import type { Auth } from '@/types';

// ============================================================
// Types & Data
// ============================================================

type Activity = { ico: string; bg: string; title: string; sub: string; time: string };

const ACTIVITY: Activity[] = [
    { ico: '🎉', bg: '#D4F0E6', title: 'You joined International Expat Mixer', sub: 'Sat 22 Mar · 32 attending', time: '2h ago' },
    { ico: '🇬🇧', bg: '#EBF0FD', title: 'Sarah K. accepted your language request', sub: 'Connection confirmed — message to arrange session', time: '4h ago' },
    { ico: '🏛️', bg: '#FDE8E6', title: 'Bürgeramt appointment booked', sub: 'Tomorrow 09:15 · Bürgeramt Deutz', time: 'Yesterday' },
    { ico: '📅', bg: '#FDF0D4', title: 'Registered for Beginners German Practice', sub: 'Mon 24 Mar · StadtBibliothek', time: '2 days ago' },
    { ico: '✅', bg: '#D4F0E6', title: 'Completed: Register at Bürgeramt', sub: 'Anmeldung done — Meldebescheinigung received', time: '3 days ago' },
];

const DEFAULT_INTERESTS = ['Tech', 'Travel', 'Coffee', 'Language exchange', 'Running'];

type Place = { id: number; emoji: string; name: string; addr: string };

type UserPlaceData = {
    id: number;
    emoji: string | null;
    name: string;
    address: string | null;
    lat: number | null;
    lng: number | null;
};

const FALLBACK_PLACES: Place[] = [
    { id: -1, emoji: '🏠', name: 'Home', addr: 'Ehrenfeld' },
    { id: -2, emoji: '💼', name: 'Work', addr: 'Mediapark, 22 min' },
    { id: -3, emoji: '📚', name: 'Course', addr: 'Uni Köln, 35 min' },
    { id: -4, emoji: '⭐', name: 'Cafe Schmitz', addr: 'Ehrenfeld, 0.3 km' },
];

const PLACE_ICONS = [
    '🏠', '🏡', '🏢', '🏬', '🏫', '🏥', '🏦', '🏛️', '🏗️',
    '🚇', '🚂', '✈️', '🚲', '🛵', '🚗', '⛽',
    '☕', '🍕', '🍜', '🍺', '🥗', '🛒', '🏪',
    '🏋️', '🌳', '🎭', '🎵', '🏊', '⚽', '📚',
    '💼', '💻', '🏗️', '🔧', '🎨', '🩺', '⚖️',
    '⭐', '❤️', '📍', '🔑', '🎯', '🌟', '👨‍👩‍👧',
];

const PROFILE_TABS = [
    { id: 'overview', label: 'Overview' },
    { id: 'events', label: 'My Events' },
    { id: 'partners', label: 'My Partners' },
    { id: 'meetups', label: 'My Meetups' },
    { id: 'settings', label: 'Settings' },
];

// ============================================================
// Page Component
// ============================================================

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

export default function Profile() {
    const { auth, userPlaces } = usePage<{
        auth: Auth;
        userPlaces?: UserPlaceData[];
    }>().props;
    const user = auth.user;

    const [activeTab, setActiveTab] = useState('overview');

    // Profile fields — seeded from backend user
    const [profileName, setProfileName] = useState(user.name || 'Anar');
    const [profileEmail, setProfileEmail] = useState(user.email || 'anar@example.com');
    const [profileCity, setProfileCity] = useState((user.city as string) || 'Cologne, Germany');
    const [profileSituation, setProfileSituation] = useState(
        SITUATION_LABELS[(user.situation as string) ?? ''] || 'Non-EU employee',
    );
    const [profileGerman, setProfileGerman] = useState(
        GERMAN_LABELS[(user.german_level as string) ?? ''] || 'A2 — Elementary',
    );
    const [profileOrigin, setProfileOrigin] = useState('Azerbaijan');

    // Inline edit state
    const [editingField, setEditingField] = useState<string | null>(null);
    const [editValue, setEditValue] = useState('');

    // Interests
    const [interests, setInterests] = useState(DEFAULT_INTERESTS);
    const [showInterestInput, setShowInterestInput] = useState(false);
    const [newInterest, setNewInterest] = useState('');

    // Places — from backend or fallback
    const backendPlaces: Place[] = (userPlaces ?? []).map((p) => ({
        id: p.id,
        emoji: p.emoji || '📍',
        name: p.name,
        addr: p.address || '',
    }));
    const [places, setPlaces] = useState<Place[]>(backendPlaces.length > 0 ? backendPlaces : FALLBACK_PLACES);
    const [showPlaceForm, setShowPlaceForm] = useState(false);
    const [placeName, setPlaceName] = useState('');
    const [placeAddr, setPlaceAddr] = useState('');
    const [placeLat, setPlaceLat] = useState<number | null>(null);
    const [placeLng, setPlaceLng] = useState<number | null>(null);
    const [locating, setLocating] = useState(false);
    const [placeEmoji, setPlaceEmoji] = useState('🏠');
    const [editingPlaceId, setEditingPlaceId] = useState<number | null>(null);

    // Notification toggles
    const [toggles, setToggles] = useState<Record<string, boolean>>({
        transitDisruptions: true,
        buergermtSlots: true,
        eventReminders: true,
        langPartnerMsg: true,
        langPartnerMatch: true,
        checklistReminders: true,
        weeklyDigest: false,
        showAttendance: true,
        receiveInvites: true,
        discoverable: true,
        dropInMode: false,
        autoAccept: false,
        showCrowd: true,
        checkInVisibility: true,
        shareLocation: true,
        personalised: true,
        shareAnonymised: true,
        darkMode: false,
    });

    // Partner state
    const [annaAccepted, setAnnaAccepted] = useState(false);
    const [annaDeclined, setAnnaDeclined] = useState(false);

    // Delete account confirmation
    const [deleteConfirming, setDeleteConfirming] = useState(false);

    // Toast
    const [toastMsg, setToastMsg] = useState<string | null>(null);
    const showToast = useCallback((msg: string) => {
        setToastMsg(msg);
        setTimeout(() => setToastMsg(null), 2500);
    }, []);

    // Toggle handler
    const toggle = (key: string) => setToggles((prev) => ({ ...prev, [key]: !prev[key] }));

    // Inline edit handlers
    function startEdit(field: string, currentValue: string) {
        setEditingField(field);
        setEditValue(currentValue);
    }

    function saveEdit(field: string) {
        const val = editValue.trim();
        if (!val) return;

        // Update local state immediately
        switch (field) {
            case 'name': setProfileName(val); break;
            case 'email': setProfileEmail(val); break;
            case 'city': setProfileCity(val); break;
            case 'situation': setProfileSituation(val); break;
            case 'german': setProfileGerman(val); break;
            case 'origin': setProfileOrigin(val); break;
        }
        setEditingField(null);

        // Build patch data for backend-supported fields
        const patchData: Record<string, string> = {};
        switch (field) {
            case 'name': patchData.name = val; break;
            case 'email': patchData.email = val; break;
            case 'city': patchData.city = val; break;
            case 'situation': patchData.situation = SITUATION_VALUES[val] || val; break;
            case 'german': patchData.german_level = GERMAN_VALUES[val] || val; break;
        }

        if (Object.keys(patchData).length > 0) {
            router.patch('/settings/profile', patchData, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => showToast('✓ ' + field.charAt(0).toUpperCase() + field.slice(1) + ' updated'),
                onError: (errors) => {
                    const firstError = Object.values(errors)[0];
                    showToast('Error: ' + (typeof firstError === 'string' ? firstError : 'Could not save'));
                },
            });
        } else {
            showToast('✓ ' + field.charAt(0).toUpperCase() + field.slice(1) + ' updated');
        }
    }

    function cancelEdit() {
        setEditingField(null);
    }

    // Places
    function openPlaceForm(place?: Place) {
        if (place) {
            setPlaceName(place.name);
            setPlaceAddr(place.addr);
            setPlaceEmoji(place.emoji);
            setEditingPlaceId(place.id);
        } else {
            setPlaceName('');
            setPlaceAddr('');
            setPlaceEmoji('🏠');
            setEditingPlaceId(null);
            setPlaceLat(null);
            setPlaceLng(null);
        }
        setShowPlaceForm(true);
    }

    function savePlace() {
        if (!placeName.trim()) { showToast('Please enter a place name'); return; }
        if (editingPlaceId) {
            // Optimistic local update for edit (no backend endpoint for update yet)
            setPlaces((prev) => prev.map((p) => p.id === editingPlaceId ? { ...p, emoji: placeEmoji, name: placeName.trim(), addr: placeAddr.trim() } : p));
            showToast('✓ ' + placeName.trim() + ' updated');
        } else {
            // Create via backend
            router.post('/user-places', {
                emoji: placeEmoji,
                name: placeName.trim(),
                address: placeAddr.trim() || null,
                lat: placeLat,
                lng: placeLng,
            }, {
                preserveScroll: true,
                preserveState: false,
                onSuccess: () => showToast('✓ ' + placeName.trim() + ' added to your places'),
                onError: (errors) => {
                    const firstError = Object.values(errors)[0];
                    showToast('Error: ' + (typeof firstError === 'string' ? firstError : 'Could not save'));
                },
            });
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
                    const res = await fetch(`https://photon.komoot.io/reverse?lat=${lat}&lon=${lng}&limit=1&lang=en`);
                    if (res.ok) {
                        const data = await res.json();
                        const feature = data.features?.[0];
                        if (feature) {
                            const p = feature.properties;
                            const addr = [p.street, p.housenumber].filter(Boolean).join(' ');
                            const area = [addr, p.district || p.suburb || p.locality, p.city].filter(Boolean).join(', ');
                            setPlaceAddr(area || `${lat.toFixed(4)}, ${lng.toFixed(4)}`);
                        } else {
                            setPlaceAddr(`${lat.toFixed(4)}, ${lng.toFixed(4)}`);
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
                showToast(err.code === 1 ? 'Location permission denied' : 'Could not get your location');
            },
            { enableHighAccuracy: true, timeout: 10000 },
        );
    }

    // Search address via Photon and get coordinates
    const [addrSuggestions, setAddrSuggestions] = useState<{ name: string; address: string; lat: number; lng: number }[]>([]);
    const addrTimerRef = useCallback(() => {
        let timer: ReturnType<typeof setTimeout> | null = null;
        return {
            search(query: string) {
                if (timer) clearTimeout(timer);
                if (query.trim().length < 3) { setAddrSuggestions([]); return; }
                timer = setTimeout(async () => {
                    try {
                        const res = await fetch(`/api/geocode?q=${encodeURIComponent(query.trim())}`);
                        if (res.ok) {
                            const data = await res.json();
                            setAddrSuggestions(data.map((r: any) => ({
                                name: r.name,
                                address: [r.street, r.city].filter(Boolean).join(', '),
                                lat: r.lat,
                                lng: r.lng,
                            })));
                        }
                    } catch { setAddrSuggestions([]); }
                }, 300);
            },
            clear() { if (timer) clearTimeout(timer); setAddrSuggestions([]); },
        };
    }, [])();

    function deletePlace(id: number) {
        // Negative IDs are fallback/mock data — just remove locally
        if (id < 0) {
            setPlaces((prev) => prev.filter((p) => p.id !== id));
            showToast('Place removed');
            return;
        }

        // Delete via backend
        router.delete(`/user-places/${id}`, {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => showToast('Place removed'),
            onError: () => showToast('Error: Could not remove place'),
        });
    }

    const switchTab = useCallback((tab: string) => setActiveTab(tab), []);

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Profile', href: '/profile' }]}
            rightPanel={<ProfileRightPanel onSwitchTab={switchTab} />}
        >
            <Head title="Profile" />
            <div className="mx-auto w-full max-w-[680px]">
                {/* ── Profile Hero ── */}
                <div
                    className="relative overflow-hidden"
                    style={{
                        background: 'linear-gradient(145deg, #1A3A8F 0%, #1A4CD4 100%)',
                        color: 'white',
                        padding: '24px 20px 0',
                        display: 'flex',
                        flexDirection: 'column',
                    }}
                >
                    {/* Decorative circle */}
                    <div
                        className="pointer-events-none absolute"
                        style={{ top: -80, right: -60, width: 260, height: 260, background: 'rgba(255,255,255,.05)', borderRadius: '50%' }}
                    />
                    {/* Top row */}
                    <div className="relative z-[1] mb-5 flex w-full items-center gap-3.5">
                        <div
                            className="flex shrink-0 items-center justify-center rounded-full"
                            style={{
                                width: 60, height: 60,
                                background: 'rgba(255,255,255,.2)',
                                border: '2px solid rgba(255,255,255,.3)',
                                fontSize: 24, fontWeight: 700,
                            }}
                        >
                            {profileName.charAt(0).toUpperCase()}
                        </div>
                        <div className="min-w-0 flex-1">
                            <div style={{ fontFamily: "'Fraunces', serif", fontSize: 22, fontWeight: 500, lineHeight: 1.1, marginBottom: 4 }}>
                                {profileName}
                            </div>
                            <div style={{ fontSize: 11, opacity: 0.75, lineHeight: 1.5 }}>
                                {profileCity} · From {profileOrigin} · Since Jan 2023
                            </div>
                        </div>
                        <button
                            onClick={() => switchTab('settings')}
                            className="shrink-0 cursor-pointer whitespace-nowrap rounded-full border-none transition-all hover:bg-[rgba(255,255,255,0.15)]"
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
                    <div className="relative z-[1] flex w-full" style={{ borderTop: '1px solid rgba(255,255,255,.15)' }}>
                        {[
                            { num: '14', lbl: 'Events' },
                            { num: '3', lbl: 'Partners' },
                            { num: '8', lbl: 'Meetups' },
                            { num: 'A2', lbl: 'German' },
                        ].map((stat, i, arr) => (
                            <div
                                key={stat.lbl}
                                className="flex-1 text-center"
                                style={{
                                    padding: '14px 4px',
                                    borderRight: i < arr.length - 1 ? '1px solid rgba(255,255,255,.1)' : 'none',
                                }}
                            >
                                <div style={{ fontFamily: "'Geist Mono', monospace", fontSize: 20, fontWeight: 600, lineHeight: 1 }}>
                                    {stat.num}
                                </div>
                                <div style={{ fontSize: 9, opacity: 0.65, marginTop: 3, textTransform: 'uppercase', letterSpacing: '0.07em' }}>
                                    {stat.lbl}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* ── Profile Tabs ── */}
                <div
                    className="sticky top-0 z-40 flex overflow-x-auto border-b border-[#E2DFD6] bg-white"
                    style={{ scrollbarWidth: 'none' }}
                >
                    {PROFILE_TABS.map((t) => (
                        <button
                            key={t.id}
                            onClick={() => setActiveTab(t.id)}
                            className="shrink-0 cursor-pointer whitespace-nowrap bg-transparent px-4 py-3 transition-all hover:bg-[#EFEDE7]"
                            style={{
                                fontSize: 12,
                                fontWeight: 600,
                                color: activeTab === t.id ? '#1A4CD4' : '#6B6860',
                                borderBottom: `2px solid ${activeTab === t.id ? '#1A4CD4' : 'transparent'}`,
                                border: 'none',
                                borderBottomWidth: 2,
                                borderBottomStyle: 'solid',
                                borderBottomColor: activeTab === t.id ? '#1A4CD4' : 'transparent',
                                fontFamily: "'Geist', sans-serif",
                            }}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                {/* ════════════════════════════════════════════════════════════
                   TAB: OVERVIEW
                   ════════════════════════════════════════════════════════════ */}
                {activeTab === 'overview' && (
                    <>
                        {/* Recent activity */}
                        <FeedSection>
                            <SectionHeader title="Recent activity" />
                            {ACTIVITY.map((a, i) => (
                                <div
                                    key={i}
                                    className="flex items-start gap-3 border-b border-[#E2DFD6] py-3 last:border-b-0"
                                >
                                    <div
                                        className="flex shrink-0 items-center justify-center rounded-[9px]"
                                        style={{ width: 38, height: 38, background: a.bg, fontSize: 17 }}
                                    >
                                        {a.ico}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 2 }}>{a.title}</div>
                                        <div style={{ fontSize: 12, color: '#6B6860' }}>{a.sub}</div>
                                    </div>
                                    <div
                                        className="mt-0.5 shrink-0"
                                        style={{ fontSize: 11, color: '#AAA89F', fontFamily: "'Geist Mono', monospace" }}
                                    >
                                        {a.time}
                                    </div>
                                </div>
                            ))}
                        </FeedSection>

                        {/* Settlement progress */}
                        <FeedSection>
                            <SectionHeader title="Settlement progress" action="View checklist" onAction={() => showToast('Opening Bureaucracy checklist...')} />
                            <div className="rounded-[14px] bg-[#EFEDE7] p-4">
                                <div className="mb-2 flex justify-between">
                                    <span style={{ fontSize: 13, fontWeight: 600 }}>3 of 11 tasks complete</span>
                                    <span style={{ fontSize: 13, fontWeight: 700, color: '#1A4CD4' }}>27%</span>
                                </div>
                                <div className="mb-3 overflow-hidden rounded-[20px]" style={{ height: 6, background: '#E2DFD6' }}>
                                    <div style={{ width: '27%', height: 6, background: '#1A4CD4', borderRadius: 20, transition: 'width .6s' }} />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <div className="flex items-center gap-2" style={{ fontSize: 13, color: '#C4271A', fontWeight: 600 }}>
                                        <span className="shrink-0 rounded-full" style={{ width: 7, height: 7, background: '#C4271A' }} />
                                        Open a German bank account — urgent
                                    </div>
                                    <div className="flex items-center gap-2" style={{ fontSize: 13, color: '#C4271A', fontWeight: 600 }}>
                                        <span className="shrink-0 rounded-full" style={{ width: 7, height: 7, background: '#C4271A' }} />
                                        Register with Ausländerbehörde — urgent
                                    </div>
                                </div>
                            </div>
                        </FeedSection>

                        {/* My interests */}
                        <FeedSection>
                            <SectionHeader
                                title="My interests"
                                action="Edit"
                                onAction={() => setShowInterestInput(!showInterestInput)}
                            />
                            <div className="flex flex-wrap gap-1.5">
                                {interests.map((interest) => (
                                    <span
                                        key={interest}
                                        className="rounded-full"
                                        style={{
                                            fontSize: 12,
                                            padding: '4px 11px',
                                            background: '#EBF0FD',
                                            color: '#1A4CD4',
                                            border: '1px solid rgba(26,76,212,.2)',
                                            fontWeight: 500,
                                        }}
                                    >
                                        {interest}
                                    </span>
                                ))}
                            </div>
                            {showInterestInput && (
                                <div className="mt-2.5 flex gap-2">
                                    <input
                                        value={newInterest}
                                        onChange={(e) => setNewInterest(e.target.value)}
                                        placeholder="Add an interest..."
                                        className="flex-1 rounded-lg border-[1.5px] border-[#1A4CD4] bg-white px-3 py-2 text-[13px] outline-none"
                                        style={{ fontFamily: "'Geist', sans-serif" }}
                                        autoFocus
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' && newInterest.trim()) {
                                                setInterests((prev) => [...prev, newInterest.trim()]);
                                                showToast('✓ Interest added: ' + newInterest.trim());
                                                setNewInterest('');
                                                setShowInterestInput(false);
                                            }
                                        }}
                                    />
                                    <button
                                        onClick={() => {
                                            if (newInterest.trim()) {
                                                setInterests((prev) => [...prev, newInterest.trim()]);
                                                showToast('✓ Interest added: ' + newInterest.trim());
                                                setNewInterest('');
                                                setShowInterestInput(false);
                                            }
                                        }}
                                        className="cursor-pointer rounded-lg border-none bg-[#1A4CD4] px-3.5 py-2 text-[13px] font-semibold text-white"
                                        style={{ fontFamily: "'Geist', sans-serif" }}
                                    >
                                        Add
                                    </button>
                                </div>
                            )}
                        </FeedSection>
                    </>
                )}

                {/* ════════════════════════════════════════════════════════════
                   TAB: MY EVENTS
                   ════════════════════════════════════════════════════════════ */}
                {activeTab === 'events' && (
                    <>
                        <FeedSection>
                            <SectionHeader title="Going" badge="2 upcoming" />
                            <CompactCard barColor="#0A7C52" title="International Expat Mixer" sub="Sat 22 Mar · 18:00 · Startplatz, Mediapark" badge="✓ Going" badgeBg="#D4F0E6" badgeColor="#0A7C52" />
                            <CompactCard barColor="#0891B2" title="Beginners German Practice" sub="Mon 24 Mar · 10:00 · StadtBibliothek" badge="✓ Going" badgeBg="#D4F0E6" badgeColor="#0A7C52" />
                        </FeedSection>
                        <FeedSection>
                            <SectionHeader title="Saved events" badge="1 saved" />
                            <CompactCard barColor="#1A4CD4" title="Language Evening at Café Schmitz" sub="Every week · 19:00 · Ehrenfeld" badge="♥ Saved" badgeBg="#EFEDE7" badgeColor="#6B6860" />
                        </FeedSection>
                        <FeedSection>
                            <SectionHeader title="Past events" />
                            <CompactCard barColor="#E2DFD6" title="Cologne Brewery Tour" titleColor="#6B6860" sub="Sun 9 Mar · Brauhaus Sion" badge="Attended" badgeBg="#EFEDE7" badgeColor="#AAA89F" />
                        </FeedSection>
                    </>
                )}

                {/* ════════════════════════════════════════════════════════════
                   TAB: MY PARTNERS
                   ════════════════════════════════════════════════════════════ */}
                {activeTab === 'partners' && (
                    <>
                        <FeedSection>
                            <SectionHeader title="Connected" />
                            <div className="mb-2 flex cursor-pointer items-center gap-3 rounded-[14px] border border-[#E2DFD6] bg-white p-3.5 transition-all hover:border-[rgba(26,76,212,0.2)] hover:bg-[#EFEDE7]">
                                <div
                                    className="flex shrink-0 items-center justify-center rounded-full text-white"
                                    style={{ width: 38, height: 38, background: '#1A4CD4', fontSize: 15, fontWeight: 700 }}
                                >
                                    SK
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 2 }}>Sarah K. 🇬🇧</div>
                                    <div style={{ fontSize: 12, color: '#6B6860' }}>English ↔ German · Last session: 3 days ago</div>
                                </div>
                                <span
                                    className="shrink-0 rounded-[20px]"
                                    style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', background: '#D4F0E6', color: '#0A7C52' }}
                                >
                                    Active
                                </span>
                            </div>
                        </FeedSection>
                        <FeedSection>
                            <SectionHeader title="Pending requests" />
                            {/* Mehmet */}
                            <div className="mb-2 flex cursor-pointer items-center gap-3 rounded-[14px] border border-[#E2DFD6] bg-white p-3.5 transition-all hover:border-[rgba(26,76,212,0.2)] hover:bg-[#EFEDE7]">
                                <div
                                    className="flex shrink-0 items-center justify-center rounded-full text-white"
                                    style={{ width: 38, height: 38, background: '#C4271A', fontSize: 15, fontWeight: 700 }}
                                >
                                    MA
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 2 }}>Mehmet A. 🇹🇷</div>
                                    <div style={{ fontSize: 12, color: '#6B6860' }}>Turkish ↔ German · Request sent 2 days ago</div>
                                </div>
                                <span
                                    className="shrink-0 rounded-[20px]"
                                    style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', background: '#FDF0D4', color: '#C47D0E' }}
                                >
                                    Pending
                                </span>
                            </div>
                            {/* Anna */}
                            <div
                                className="mb-2 flex items-center gap-3 rounded-[14px] border border-[#E2DFD6] bg-white p-3.5 transition-all"
                                style={{ opacity: annaDeclined ? 0.4 : 1, pointerEvents: annaDeclined ? 'none' : 'auto' }}
                            >
                                <div
                                    className="flex shrink-0 items-center justify-center rounded-full text-white"
                                    style={{ width: 38, height: 38, background: '#0891B2', fontSize: 15, fontWeight: 700 }}
                                >
                                    AB
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 2 }}>Anna B. 🇩🇪</div>
                                    <div style={{ fontSize: 12, color: '#6B6860' }}>German ↔ English · New match — wants to connect</div>
                                </div>
                                <div className="flex shrink-0 gap-1.5">
                                    <button
                                        onClick={() => {
                                            setAnnaAccepted(true);
                                            showToast('🎉 Connected with Anna B. — message her to arrange a session');
                                        }}
                                        className="cursor-pointer rounded-[9px] border-none px-[11px] py-[5px] text-white"
                                        style={{
                                            background: annaAccepted ? '#D4F0E6' : '#1A4CD4',
                                            color: annaAccepted ? '#0A7C52' : 'white',
                                            fontFamily: "'Geist', sans-serif",
                                            fontSize: 11,
                                            fontWeight: 700,
                                        }}
                                    >
                                        {annaAccepted ? '✓ Connected' : 'Accept'}
                                    </button>
                                    {!annaAccepted && (
                                        <button
                                            onClick={() => {
                                                setAnnaDeclined(true);
                                                showToast('Request from Anna declined');
                                            }}
                                            className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[11px] py-[5px]"
                                            style={{ fontFamily: "'Geist', sans-serif", fontSize: 11, fontWeight: 600, color: '#6B6860' }}
                                        >
                                            Decline
                                        </button>
                                    )}
                                </div>
                            </div>
                        </FeedSection>
                        <FeedSection>
                            <SectionHeader title="Practice stats" />
                            <div className="grid grid-cols-3 gap-2.5">
                                {[
                                    { num: '12', lbl: 'Sessions', color: '#1A4CD4' },
                                    { num: '18h', lbl: 'Practice', color: '#1A4CD4' },
                                    { num: 'A2', lbl: 'German', color: '#0A7C52' },
                                ].map((s) => (
                                    <div key={s.lbl} className="rounded-[9px] bg-[#EFEDE7] p-3.5 text-center">
                                        <div style={{ fontFamily: "'Geist Mono', monospace", fontSize: 22, fontWeight: 500, color: s.color }}>
                                            {s.num}
                                        </div>
                                        <div style={{ fontSize: 11, color: '#AAA89F', textTransform: 'uppercase', letterSpacing: '0.06em', marginTop: 3 }}>
                                            {s.lbl}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </FeedSection>
                    </>
                )}

                {/* ════════════════════════════════════════════════════════════
                   TAB: MY MEETUPS
                   ════════════════════════════════════════════════════════════ */}
                {activeTab === 'meetups' && (
                    <>
                        <FeedSection>
                            <SectionHeader title="Upcoming meetups" />
                            <CompactCard barColor="#0891B2" title="Beginners German Practice" sub="Mon 24 Mar · 10:00 · StadtBibliothek · 6 attending" badge="✓ Going" badgeBg="#D4F0E6" badgeColor="#0A7C52" />
                        </FeedSection>
                        <FeedSection>
                            <SectionHeader title="Past meetups" />
                            <CompactCard barColor="#E2DFD6" title="Language Evening at Café Schmitz" titleColor="#6B6860" sub="Wed 12 Mar · 14 attended" badge="Attended" badgeBg="#EFEDE7" badgeColor="#AAA89F" />
                            <CompactCard barColor="#E2DFD6" title="German–Turkish Stammtisch" titleColor="#6B6860" sub="Tue 5 Mar · 8 attended" badge="Attended" badgeBg="#EFEDE7" badgeColor="#AAA89F" style={{ marginTop: 8 }} />
                        </FeedSection>
                    </>
                )}

                {/* ════════════════════════════════════════════════════════════
                   TAB: SETTINGS
                   ════════════════════════════════════════════════════════════ */}
                {activeTab === 'settings' && (
                    <>
                        {/* ACCOUNT */}
                        <FeedSection>
                            <SectionHeader title="Account" />
                            <InlineEditRow field="name" label="Name" value={profileName} editing={editingField} editValue={editValue} onStartEdit={startEdit} onEditValue={setEditValue} onSave={saveEdit} onCancel={cancelEdit} />
                            <InlineEditRow field="email" label="Email" value={profileEmail} editing={editingField} editValue={editValue} onStartEdit={startEdit} onEditValue={setEditValue} onSave={saveEdit} onCancel={cancelEdit} inputType="email" />
                            <InlineEditRow field="city" label="City" value={profileCity} editing={editingField} editValue={editValue} onStartEdit={startEdit} onEditValue={setEditValue} onSave={saveEdit} onCancel={cancelEdit} />
                            <InlineEditRow
                                field="situation" label="Situation" value={profileSituation}
                                editing={editingField} editValue={editValue}
                                onStartEdit={startEdit} onEditValue={setEditValue}
                                onSave={saveEdit} onCancel={cancelEdit}
                                selectOptions={['Non-EU employee', 'EU employee', 'Student', 'Freelancer', 'Family reunification']}
                                subText="Affects your checklist and visa guidance"
                            />
                            <InlineEditRow
                                field="german" label="German level" value={profileGerman}
                                editing={editingField} editValue={editValue}
                                onStartEdit={startEdit} onEditValue={setEditValue}
                                onSave={saveEdit} onCancel={cancelEdit}
                                selectOptions={['None yet', 'A1 — Beginner', 'A2 — Elementary', 'B1 — Intermediate', 'B2 — Upper-intermediate', 'C1 — Advanced', 'C2 — Fluent']}
                            />
                            <InlineEditRow field="origin" label="Origin country" value={profileOrigin} editing={editingField} editValue={editValue} onStartEdit={startEdit} onEditValue={setEditValue} onSave={saveEdit} onCancel={cancelEdit} isLast />
                        </FeedSection>

                        {/* YOUR PLACES */}
                        <FeedSection>
                            <SectionHeader title="Your Places" />
                            {places.map((p) => (
                                <div key={p.id} className="flex items-center justify-between border-b border-[#E2DFD6] py-3.5 last:border-b-0" style={{ gap: 12 }}>
                                    <span style={{ fontSize: 22, flexShrink: 0 }}>{p.emoji}</span>
                                    <div className="min-w-0 flex-1">
                                        <div style={{ fontSize: 14, fontWeight: 500 }}>{p.name}</div>
                                        <div style={{ fontSize: 12, color: '#AAA89F', marginTop: 2 }}>{p.addr}</div>
                                    </div>
                                    <button
                                        onClick={() => openPlaceForm(p)}
                                        className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-white px-2.5 py-1 transition-all hover:bg-[#EFEDE7]"
                                        style={{ fontFamily: "'Geist', sans-serif", fontSize: 11, fontWeight: 600, color: '#6B6860', marginRight: 4 }}
                                    >
                                        Edit
                                    </button>
                                    <button
                                        onClick={() => deletePlace(p.id)}
                                        className="cursor-pointer rounded-[9px] border border-[#FDE8E6] bg-[#FDE8E6] px-2.5 py-1 transition-all hover:bg-[#F9CCC8]"
                                        style={{ fontFamily: "'Geist', sans-serif", fontSize: 11, fontWeight: 600, color: '#C4271A' }}
                                    >
                                        ✕
                                    </button>
                                </div>
                            ))}
                            {showPlaceForm && (
                                <div className="mt-2.5 rounded-[14px] border border-[#E2DFD6] bg-[#EFEDE7] p-3.5">
                                    <div style={{ fontSize: 12, fontWeight: 700, color: '#6B6860', marginBottom: 10 }}>
                                        {editingPlaceId ? 'Edit place' : 'Add a place'}
                                    </div>
                                    <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.07em', color: '#AAA89F', marginBottom: 7 }}>
                                        Choose an icon
                                    </div>
                                    <div className="mb-3 flex flex-wrap gap-1.5">
                                        {PLACE_ICONS.map((ico) => (
                                            <div
                                                key={ico}
                                                onClick={() => setPlaceEmoji(ico)}
                                                className="flex cursor-pointer items-center justify-center rounded-[9px] transition-all"
                                                style={{
                                                    width: 40, height: 40, fontSize: 20,
                                                    background: placeEmoji === ico ? '#EBF0FD' : 'white',
                                                    border: `2px solid ${placeEmoji === ico ? '#1A4CD4' : 'transparent'}`,
                                                }}
                                            >
                                                {ico}
                                            </div>
                                        ))}
                                    </div>
                                    <div className="mb-2 flex gap-2">
                                        <div
                                            className="flex shrink-0 items-center justify-center rounded-[9px]"
                                            style={{ width: 44, height: 44, background: '#EBF0FD', border: '2px solid #1A4CD4', fontSize: 22 }}
                                        >
                                            {placeEmoji}
                                        </div>
                                        <input
                                            value={placeName}
                                            onChange={(e) => setPlaceName(e.target.value)}
                                            placeholder="Name (e.g. Home)"
                                            className="flex-1 rounded-[9px] border border-[#E2DFD6] bg-white px-3 py-[9px] text-sm outline-none"
                                            style={{ fontFamily: "'Geist', sans-serif" }}
                                        />
                                    </div>
                                    <div className="relative mb-2.5">
                                        <div className="flex items-center gap-1.5">
                                            <div className="relative flex-1">
                                                <input
                                                    value={placeAddr}
                                                    onChange={(e) => {
                                                        setPlaceAddr(e.target.value);
                                                        addrTimerRef.search(e.target.value);
                                                    }}
                                                    onBlur={() => setTimeout(() => addrTimerRef.clear(), 200)}
                                                    placeholder="Address or area (e.g. Ehrenfeld)"
                                                    className="w-full rounded-[9px] border border-[#E2DFD6] bg-white px-3 py-[9px] text-sm outline-none"
                                                    style={{ fontFamily: "'Geist', sans-serif" }}
                                                />
                                                {/* Address autocomplete dropdown */}
                                                {addrSuggestions.length > 0 && (
                                                    <div className="absolute top-full left-0 right-0 z-50 mt-1 overflow-hidden rounded-[9px] border border-[#E2DFD6] bg-white shadow-[0_8px_24px_rgba(0,0,0,0.08)]">
                                                        {addrSuggestions.map((s, i) => (
                                                            <div
                                                                key={i}
                                                                onMouseDown={(e) => {
                                                                    e.preventDefault();
                                                                    setPlaceAddr([s.name, s.address].filter(Boolean).join(', '));
                                                                    setPlaceLat(s.lat);
                                                                    setPlaceLng(s.lng);
                                                                    addrTimerRef.clear();
                                                                }}
                                                                className="cursor-pointer px-3 py-2.5 transition-colors hover:bg-[#EFEDE7]"
                                                                style={{ borderBottom: i < addrSuggestions.length - 1 ? '1px solid #F0EDE7' : 'none' }}
                                                            >
                                                                <div className="text-sm font-medium text-[#18170F]">{s.name}</div>
                                                                <div className="text-xs text-[#6B6860]">{s.address}</div>
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                            {/* Use my location button */}
                                            <button
                                                type="button"
                                                onClick={useMyLocation}
                                                disabled={locating}
                                                className="flex shrink-0 cursor-pointer items-center justify-center rounded-[9px] border border-[#E2DFD6] bg-white transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] disabled:opacity-50"
                                                style={{ width: 40, height: 40 }}
                                                title="Use my current location"
                                            >
                                                {locating ? (
                                                    <div style={{ width: 14, height: 14, border: '2px solid #E2DFD6', borderTopColor: '#1A4CD4', borderRadius: '50%', animation: 'spin .7s linear infinite' }} />
                                                ) : (
                                                    <span style={{ fontSize: 18 }}>📍</span>
                                                )}
                                            </button>
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <button
                                            onClick={savePlace}
                                            className="flex-1 cursor-pointer rounded-[9px] border-none bg-[#1A4CD4] py-[9px] text-[13px] font-semibold text-white"
                                            style={{ fontFamily: "'Geist', sans-serif" }}
                                        >
                                            {editingPlaceId ? 'Update place' : 'Save place'}
                                        </button>
                                        <button
                                            onClick={() => setShowPlaceForm(false)}
                                            className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-white px-3.5 py-[9px] text-[13px]"
                                            style={{ fontFamily: "'Geist', sans-serif", color: '#6B6860' }}
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            )}
                            {!showPlaceForm && (
                                <button
                                    onClick={() => openPlaceForm()}
                                    className="mt-2.5 w-full cursor-pointer rounded-[9px] border-[1.5px] border-dashed border-[#E2DFD6] bg-transparent py-2.5 text-[13px] font-medium text-[#1A4CD4] transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD]"
                                    style={{ fontFamily: "'Geist', sans-serif" }}
                                >
                                    + Add a place
                                </button>
                            )}
                        </FeedSection>

                        {/* NOTIFICATIONS */}
                        <FeedSection>
                            <SectionHeader title="Notifications" />
                            <SettingToggle label="🚇 Transit disruptions" sub="Delays, closures on your routes" on={toggles.transitDisruptions} onToggle={() => toggle('transitDisruptions')} />
                            <SettingToggle label="🏛️ Bürgeramt slot alerts" sub="Notified when new slots open" on={toggles.buergermtSlots} onToggle={() => toggle('buergermtSlots')} />
                            <SettingToggle label="📅 Event reminders" sub="1 hour before events you've joined" on={toggles.eventReminders} onToggle={() => toggle('eventReminders')} />
                            <SettingToggle label="🗣️ Language partner messages" sub="New messages and session requests" on={toggles.langPartnerMsg} onToggle={() => toggle('langPartnerMsg')} />
                            <SettingToggle label="💬 New language partner matches" sub="When someone matches your profile" on={toggles.langPartnerMatch} onToggle={() => toggle('langPartnerMatch')} />
                            <SettingToggle label="📋 Checklist reminders" sub="Weekly nudge on pending tasks" on={toggles.checklistReminders} onToggle={() => toggle('checklistReminders')} />
                            <SettingToggle label="📰 Weekly city digest" sub="Events, spots, and expat tips" on={toggles.weeklyDigest} onToggle={() => toggle('weeklyDigest')} isLast />
                        </FeedSection>

                        {/* EVENTS SETTINGS */}
                        <FeedSection>
                            <SectionHeader title="Events" />
                            <SettingToggle label="Show my attendance publicly" sub="Others can see events you've joined" on={toggles.showAttendance} onToggle={() => toggle('showAttendance')} />
                            <SettingToggle label="Receive event invites" sub="From organisers and language partners" on={toggles.receiveInvites} onToggle={() => toggle('receiveInvites')} />
                            <SettingValueRow label="Default reminder time" value="1 hour before" />
                            <SettingNavRow label="Preferred event types" sub="Language, networking, cultural" isLast />
                        </FeedSection>

                        {/* LANGUAGE EXCHANGE SETTINGS */}
                        <FeedSection>
                            <SectionHeader title="Language Exchange" />
                            <SettingToggle label="Discoverable as a partner" sub="Your profile appears in partner search" on={toggles.discoverable} onToggle={() => toggle('discoverable')} />
                            <SettingToggle label="Drop-in mode visible" sub="Show when you're available for ad-hoc sessions" on={toggles.dropInMode} onToggle={() => toggle('dropInMode')} />
                            <SettingToggle label="Accept partner requests automatically" sub="Skip manual approval for new requests" on={toggles.autoAccept} onToggle={() => toggle('autoAccept')} />
                            <SettingValueRow label="Session format preference" value="In-person" />
                            <SettingValueRow label="Max active partners" sub="Limit simultaneous language partners" value="5" isLast />
                        </FeedSection>

                        {/* EXPLORE & TRANSIT */}
                        <FeedSection>
                            <SectionHeader title="Explore & Transit" />
                            <SettingValueRow label="Default transport mode" value="🚲 Bike" />
                            <SettingToggle label="Show crowd levels" sub="Real-time occupancy at work spots" on={toggles.showCrowd} onToggle={() => toggle('showCrowd')} />
                            <SettingToggle label="Check-in visibility" sub="Share when you're at a work spot" on={toggles.checkInVisibility} onToggle={() => toggle('checkInVisibility')} />
                            <SettingValueRow label="Home commute stop" value="Venloer Str." isLast />
                        </FeedSection>

                        {/* PRIVACY */}
                        <FeedSection>
                            <SectionHeader title="Privacy" />
                            <SettingValueRow label="Profile visibility" sub="Who can see your full profile" value="Partners only" />
                            <SettingToggle label="Share location for transit" sub="Improves departure times accuracy" on={toggles.shareLocation} onToggle={() => toggle('shareLocation')} />
                            <SettingToggle label="Personalised content" sub="Use activity to improve recommendations" on={toggles.personalised} onToggle={() => toggle('personalised')} />
                            <SettingToggle label="Share anonymised data" sub="Help improve Expadu for all expats" on={toggles.shareAnonymised} onToggle={() => toggle('shareAnonymised')} isLast />
                        </FeedSection>

                        {/* APPEARANCE */}
                        <FeedSection>
                            <SectionHeader title="Appearance" />
                            <SettingValueRow label="Language" sub="App interface language" value="English" />
                            <SettingValueRow label="Date format" value="DD/MM/YYYY" />
                            <SettingValueRow label="Distance units" value="Kilometres" />
                            <SettingToggle label="Dark mode" on={toggles.darkMode} onToggle={() => toggle('darkMode')} isLast />
                        </FeedSection>

                        {/* SUPPORT */}
                        <FeedSection>
                            <SectionHeader title="Support" />
                            <SettingNavRow label="Help Centre" sub="FAQs, guides, contact support" onClick={() => showToast('Opening Help Centre...')} />
                            <SettingNavRow label="Send feedback" sub="Report a bug or suggest a feature" onClick={() => showToast('Opening feedback form...')} />
                            <SettingNavRow label="Privacy policy" onClick={() => showToast('Opening privacy policy...')} />
                            <SettingNavRow label="Terms of service" onClick={() => showToast('Opening terms...')} />
                            <div className="flex items-center justify-between py-3.5">
                                <div style={{ fontSize: 14, fontWeight: 500, color: '#AAA89F' }}>Version</div>
                                <span style={{ fontSize: 13, color: '#AAA89F' }}>1.0.0 (prototype)</span>
                            </div>
                        </FeedSection>

                        {/* DANGER ZONE */}
                        <FeedSection>
                            <SectionHeader title="Danger zone" />
                            <SettingNavRow label="Restart welcome setup" sub="Re-run the onboarding flow" onClick={() => showToast('Welcome flow reset — restarting onboarding')} />
                            <SettingNavRow label="Export my data" sub="Download everything Expadu holds about you" onClick={() => showToast('📦 Preparing your data export — you\'ll receive an email shortly')} />
                            <div
                                className="flex cursor-pointer items-center justify-between border-b-0 py-3.5 transition-colors hover:bg-[#EFEDE7]"
                                onClick={() => {
                                    if (deleteConfirming) {
                                        setDeleteConfirming(false);
                                        showToast('Account deletion requested — check your email to confirm');
                                    } else {
                                        setDeleteConfirming(true);
                                        setTimeout(() => setDeleteConfirming(false), 3000);
                                    }
                                }}
                            >
                                <div>
                                    <div style={{ fontSize: 14, fontWeight: 500, color: '#C4271A' }}>
                                        {deleteConfirming ? 'Tap again to confirm' : 'Delete account'}
                                    </div>
                                    <div style={{ fontSize: 12, color: '#AAA89F', marginTop: 2 }}>Permanently remove your account and data</div>
                                </div>
                                <span style={{ fontSize: 16, color: '#C4271A' }}>›</span>
                            </div>
                        </FeedSection>
                    </>
                )}
            </div>

            {/* Toast */}
            {toastMsg && (
                <div
                    className="pointer-events-none fixed bottom-20 left-1/2 z-[9000] -translate-x-1/2 whitespace-nowrap rounded-[14px] px-[18px] py-[11px] text-[13px] font-medium text-white shadow-[0_8px_24px_rgba(0,0,0,0.15)]"
                    style={{ background: '#18170F', maxWidth: '90vw', textAlign: 'center' }}
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

function FeedSection({ children }: { children: React.ReactNode }) {
    return (
        <div className="border-b border-[#E2DFD6] px-6 py-5 last:border-b-0">
            {children}
        </div>
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
                <span style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}>
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
            className="mb-2 flex cursor-pointer items-center gap-3 rounded-[14px] border border-[#E2DFD6] bg-white p-3.5 transition-all last:mb-0 hover:border-[rgba(26,76,212,0.2)] hover:bg-[#EFEDE7]"
            style={style}
        >
            <div className="shrink-0 rounded-sm" style={{ width: 4, height: 40, borderRadius: 2, background: barColor }} />
            <div className="min-w-0 flex-1">
                <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 2, color: titleColor || 'inherit' }}>{title}</div>
                <div style={{ fontSize: 12, color: '#6B6860' }}>{sub}</div>
            </div>
            <span
                className="shrink-0 rounded-[20px]"
                style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', background: badgeBg, color: badgeColor }}
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
            className="flex items-center justify-between py-3.5 transition-colors"
            style={{ borderBottom: isLast ? 'none' : '1px solid #E2DFD6' }}
        >
            <div>
                <div style={{ fontSize: 14, fontWeight: 500 }}>{label}</div>
                {sub && <div style={{ fontSize: 12, color: '#AAA89F', marginTop: 2 }}>{sub}</div>}
            </div>
            <div
                onClick={onToggle}
                className="relative shrink-0 cursor-pointer rounded-[20px] transition-colors"
                style={{ width: 40, height: 22, background: on ? '#0A7C52' : '#E2DFD6' }}
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

function SettingValueRow({
    label,
    sub,
    value,
    isLast,
}: {
    label: string;
    sub?: string;
    value: string;
    isLast?: boolean;
}) {
    return (
        <div
            className="flex items-center justify-between py-3.5"
            style={{ borderBottom: isLast ? 'none' : '1px solid #E2DFD6' }}
        >
            <div>
                <div style={{ fontSize: 14, fontWeight: 500 }}>{label}</div>
                {sub && <div style={{ fontSize: 12, color: '#AAA89F', marginTop: 2 }}>{sub}</div>}
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <span style={{ fontSize: 13, color: '#6B6860' }}>{value}</span>
                <span style={{ fontSize: 16, color: '#AAA89F' }}>›</span>
            </div>
        </div>
    );
}

function SettingNavRow({
    label,
    sub,
    onClick,
    isLast,
}: {
    label: string;
    sub?: string;
    onClick?: () => void;
    isLast?: boolean;
}) {
    return (
        <div
            className="flex cursor-pointer items-center justify-between py-3.5 transition-colors hover:bg-[#EFEDE7]"
            style={{ borderBottom: isLast ? 'none' : '1px solid #E2DFD6' }}
            onClick={onClick}
        >
            <div>
                <div style={{ fontSize: 14, fontWeight: 500 }}>{label}</div>
                {sub && <div style={{ fontSize: 12, color: '#AAA89F', marginTop: 2 }}>{sub}</div>}
            </div>
            <span style={{ fontSize: 16, color: '#AAA89F' }}>›</span>
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
            <div className="py-3.5" style={{ borderBottom: isLast ? 'none' : '1px solid #E2DFD6' }}>
                <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', color: '#AAA89F', marginBottom: 8 }}>
                    {label}
                </div>
                {selectOptions ? (
                    <select
                        value={editValue}
                        onChange={(e) => onEditValue(e.target.value)}
                        className="w-full rounded-lg border-[1.5px] border-[#E2DFD6] bg-white px-3.5 py-[11px] text-[15px] text-[#18170F] outline-none focus:border-[#1A4CD4]"
                        style={{ fontFamily: "'Geist', sans-serif", boxSizing: 'border-box', transition: 'border-color .2s' }}
                    >
                        {selectOptions.map((opt) => (
                            <option key={opt} value={opt}>{opt}</option>
                        ))}
                    </select>
                ) : (
                    <input
                        type={inputType || 'text'}
                        value={editValue}
                        onChange={(e) => onEditValue(e.target.value)}
                        className="w-full rounded-lg border-[1.5px] border-[#E2DFD6] bg-white px-3.5 py-[11px] text-[15px] text-[#18170F] outline-none focus:border-[#1A4CD4]"
                        style={{ fontFamily: "'Geist', sans-serif", boxSizing: 'border-box', transition: 'border-color .2s' }}
                        autoFocus
                        onKeyDown={(e) => { if (e.key === 'Enter') onSave(field); if (e.key === 'Escape') onCancel(); }}
                    />
                )}
                {subText && <div style={{ fontSize: 12, color: '#AAA89F', marginTop: 6 }}>{subText}</div>}
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
                        className="cursor-pointer rounded-lg border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-[9px] text-[13px] transition-all hover:bg-[#E2DFD6]"
                        style={{ fontFamily: "'Geist', sans-serif", color: '#6B6860' }}
                    >
                        Cancel
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div
            className="flex cursor-pointer items-center justify-between py-3.5"
            style={{ borderBottom: isLast ? 'none' : '1px solid #E2DFD6' }}
            onClick={() => onStartEdit(field, value)}
        >
            <div className="min-w-0 flex-1">
                <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', color: '#AAA89F', marginBottom: 3 }}>
                    {label}
                </div>
                <div style={{ fontSize: 15, fontWeight: 500, color: '#18170F' }}>{value}</div>
            </div>
            <span
                className="ml-3 shrink-0 transition-colors hover:text-[#1A4CD4]"
                style={{ fontSize: 13, fontWeight: 600, color: '#AAA89F' }}
            >
                Edit
            </span>
        </div>
    );
}
