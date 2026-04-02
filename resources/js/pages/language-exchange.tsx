import { Head } from '@inertiajs/react';
import { useMemo, useState, type MouseEvent } from 'react';
import { useTabState } from '@/hooks/use-tab-state';
import { LanguageHero } from '@/components/language/language-hero';
import { LanguageRightPanel } from '@/components/language/language-right-panel';
import { PartnerCard } from '@/components/language/partner-card';
import { PartnerDetailContent } from '@/components/language/partner-detail-content';
import { useTracker } from '@/hooks/use-tracker';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import AppLayout from '@/layouts/app-layout';

// ============================================================
// Types
// ============================================================

export type PartnerData = {
    id: string;
    name: string;
    flag: string;
    origin: string;
    bg: string;
    initials: string;
    native: string;
    learning: string;
    level: string;
    levelColor: string;
    levelBg: string;
    online: boolean;
    nearby: boolean;
    distance: string;
    availability: string;
    interests: string[];
    about: string;
    pair: string;
    isNew: boolean;
    saved?: boolean;
    requested?: boolean;
    meetingSpots: string[];
};

export type MeetupData = {
    id: number;
    title: string;
    date: string;
    month: string;
    time: string;
    duration: string;
    location: string;
    distance: string;
    host: string;
    hostFlag: string;
    languages: string[];
    langColors: string[];
    attending: number;
    max: number;
    attendees: string[];
    color: string;
    joined: boolean;
    desc: string;
};

export type DropinSpot = {
    name: string;
    emoji: string;
    area: string;
    dist: string;
    people: number;
};

type ActiveDropin = {
    name: string;
    flag: string;
    lang: string;
    spot: string;
    ago: string;
    bg: string;
};

type ProfileLang = {
    flag: string;
    name: string;
    role: 'native' | 'learner';
    level: string;
    levelBg: string;
    levelColor: string;
};

type AvailableLang = {
    flag: string;
    name: string;
};

// ============================================================
// Seed data — matches prototype exactly
// ============================================================

const SEED_PARTNERS: PartnerData[] = [
    {
        id: 'sarah',
        name: 'Sarah K.',
        flag: '🇬🇧',
        origin: 'London, UK',
        bg: '#1A4CD4',
        initials: 'SK',
        native: 'English 🇬🇧',
        learning: 'German 🇩🇪',
        level: 'B1',
        levelColor: '#C47D0E',
        levelBg: '#FDF0D4',
        online: true,
        nearby: true,
        distance: '0.4 km · Ehrenfeld',
        availability: 'Weekday evenings, weekends',
        interests: ['Tech', 'Travel', 'Coffee', 'Books'],
        about: 'Software engineer, moved to Cologne 6 months ago. Love meeting new people and exploring the city.',
        pair: 'de-en',
        isNew: true,
        meetingSpots: ['Cafe Schmitz', 'StadtBibliothek', 'Hallmackenreuther'],
    },
    {
        id: 'mehmet',
        name: 'Mehmet A.',
        flag: '🇹🇷',
        origin: 'Istanbul, Turkey',
        bg: '#C4271A',
        initials: 'MA',
        native: 'Turkish 🇹🇷',
        learning: 'German 🇩🇪',
        level: 'A2',
        levelColor: '#C4271A',
        levelBg: '#FDE8E6',
        online: false,
        nearby: false,
        distance: '1.2 km · Nippes',
        availability: 'Weekends only',
        interests: ['Football', 'Cooking', 'Music', 'Cinema'],
        about: 'Mechanical engineer at Ford Cologne. Learning German to feel more at home. Happy to teach Turkish!',
        pair: 'de-tr',
        isNew: true,
        meetingSpots: ['Cafe Schmitz', 'Neumarkt area'],
    },
    {
        id: 'claire',
        name: 'Claire D.',
        flag: '🇫🇷',
        origin: 'Lyon, France',
        bg: '#0A7C52',
        initials: 'CD',
        native: 'French 🇫🇷',
        learning: 'German 🇩🇪',
        level: 'B2',
        levelColor: '#1A4CD4',
        levelBg: '#EBF0FD',
        online: false,
        nearby: true,
        distance: '0.8 km · Belgisches Viertel',
        availability: 'Tue & Thu evenings',
        interests: ['Art', 'Architecture', 'Food', 'Running'],
        about: 'Architect working in Cologne for 2 years. Fluent French speaker, happy to do French↔German or French↔English.',
        pair: 'de-fr',
        isNew: false,
        meetingSpots: ['Hallmackenreuther', 'StadtBibliothek'],
    },
    {
        id: 'carlos',
        name: 'Carlos M.',
        flag: '🇪🇸',
        origin: 'Madrid, Spain',
        bg: '#7C3AED',
        initials: 'CM',
        native: 'Spanish 🇪🇸',
        learning: 'German 🇩🇪',
        level: 'A1',
        levelColor: '#C4271A',
        levelBg: '#FDE8E6',
        online: true,
        nearby: false,
        distance: '2.1 km · Deutz',
        availability: 'Flexible',
        interests: ['Music', 'Cycling', 'Photography', 'Hiking'],
        about: 'Just arrived in Cologne! Very beginner German. Native Spanish, good English. Excited to explore.',
        pair: 'de-es',
        isNew: false,
        meetingSpots: ['Cafe Schmitz'],
    },
    {
        id: 'anna',
        name: 'Anna B.',
        flag: '🇩🇪',
        origin: 'Munich, Germany',
        bg: '#0891B2',
        initials: 'AB',
        native: 'German 🇩🇪',
        learning: 'English 🇬🇧',
        level: 'B2',
        levelColor: '#1A4CD4',
        levelBg: '#EBF0FD',
        online: true,
        nearby: true,
        distance: '0.3 km · Ehrenfeld',
        availability: 'Mon / Wed evenings',
        interests: ['Literature', 'Theatre', 'Yoga', 'Cooking'],
        about: 'Cologne local, teacher. Happy to help with German in exchange for English conversation practice.',
        pair: 'de-en',
        isNew: false,
        meetingSpots: ['Cafe Schmitz', 'Hallmackenreuther', 'StadtBibliothek'],
    },
    {
        id: 'yuki',
        name: 'Yuki T.',
        flag: '🇯🇵',
        origin: 'Tokyo, Japan',
        bg: '#C4271A',
        initials: 'YT',
        native: 'Japanese 🇯🇵',
        learning: 'German 🇩🇪',
        level: 'A2',
        levelColor: '#C4271A',
        levelBg: '#FDE8E6',
        online: false,
        nearby: false,
        distance: '3.2 km · Innenstadt',
        availability: 'Weekends',
        interests: ['Anime', 'Technology', 'Photography', 'Sushi'],
        about: 'Exchange student at Uni Koln. Looking for German practice and to make friends.',
        pair: 'de-en',
        isNew: false,
        meetingSpots: ['StadtBibliothek'],
    },
];

const SEED_MEETUPS: MeetupData[] = [
    {
        id: 1,
        title: 'Language Evening at Cafe Schmitz',
        date: '22',
        month: 'Mar',
        time: '19:00',
        duration: '2 hours',
        location: 'Cafe Schmitz, Ehrenfeld',
        distance: '0.4 km',
        host: 'Anna B.',
        hostFlag: '🇩🇪',
        languages: ['🇩🇪 German', '🇬🇧 English', '🇫🇷 French'],
        langColors: ['#1A4CD4', '#0A7C52', '#C4271A'],
        attending: 14,
        max: 20,
        attendees: ['🇬🇧', '🇹🇷', '🇫🇷', '🇪🇸'],
        color: '#1A4CD4',
        joined: false,
        desc: 'Relaxed weekly language exchange. Split into small groups by language pair. Beginners very welcome.',
    },
    {
        id: 2,
        title: 'German-Turkish Language Stammtisch',
        date: '25',
        month: 'Mar',
        time: '18:30',
        duration: '1.5 hours',
        location: 'Hallmackenreuther, Belgisches Viertel',
        distance: '0.9 km',
        host: 'Mehmet A.',
        hostFlag: '🇹🇷',
        languages: ['🇩🇪 German', '🇹🇷 Turkish'],
        langColors: ['#1A4CD4', '#C4271A'],
        attending: 8,
        max: 12,
        attendees: ['🇩🇪', '🇩🇪', '🇹🇷', '🇹🇷'],
        color: '#C4271A',
        joined: false,
        desc: 'Focused German-Turkish exchange. Bring curiosity! Snacks provided.',
    },
    {
        id: 3,
        title: 'Beginners German Practice',
        date: '29',
        month: 'Mar',
        time: '10:00',
        duration: '1 hour',
        location: 'StadtBibliothek, Centrum',
        distance: '1.2 km',
        host: 'Sarah K.',
        hostFlag: '🇬🇧',
        languages: ['🇩🇪 German', '🇬🇧 English', '🇪🇸 Spanish'],
        langColors: ['#1A4CD4', '#0A7C52', '#7C3AED'],
        attending: 6,
        max: 10,
        attendees: ['🇬🇧', '🇫🇷', '🇪🇸'],
        color: '#0A7C52',
        joined: true,
        desc: 'Morning session specifically for A1-B1 German learners. Supportive, low-pressure environment.',
    },
    {
        id: 4,
        title: 'International Language Mixer',
        date: '5',
        month: 'Apr',
        time: '19:30',
        duration: '2.5 hours',
        location: 'Startplatz, Mediapark',
        distance: '0.7 km',
        host: 'Anker Community',
        hostFlag: '⚓',
        languages: ['🇩🇪', '🇬🇧', '🇫🇷', '🇹🇷', '🇪🇸', '+ more'],
        langColors: [
            '#1A4CD4',
            '#0A7C52',
            '#C4271A',
            '#C4271A',
            '#7C3AED',
            '#AAA89F',
        ],
        attending: 32,
        max: 50,
        attendees: ['🇬🇧', '🇹🇷', '🇫🇷', '🇯🇵'],
        color: '#7C3AED',
        joined: false,
        desc: 'Monthly big mixer. All languages, all levels. Networking, games, drinks. Great for new arrivals.',
    },
];

const DROPIN_SPOTS: DropinSpot[] = [
    {
        name: 'Cafe Schmitz',
        emoji: '☕',
        area: 'Ehrenfeld',
        dist: '0.3 km',
        people: 3,
    },
    {
        name: 'StadtBibliothek',
        emoji: '📚',
        area: 'Centrum',
        dist: '1.2 km',
        people: 1,
    },
    {
        name: 'Hallmackenreuther',
        emoji: '🌿',
        area: 'Belgisches Viertel',
        dist: '0.9 km',
        people: 2,
    },
    {
        name: 'Startplatz',
        emoji: '🏢',
        area: 'Mediapark',
        dist: '0.7 km',
        people: 0,
    },
];

const ACTIVE_DROPINS: ActiveDropin[] = [
    {
        name: 'Sarah K.',
        flag: '🇬🇧',
        lang: 'English↔German',
        spot: 'Cafe Schmitz',
        ago: '5 min ago',
        bg: '#1A4CD4',
    },
    {
        name: 'Anna B.',
        flag: '🇩🇪',
        lang: 'German↔English',
        spot: 'Cafe Schmitz',
        ago: '12 min ago',
        bg: '#0891B2',
    },
    {
        name: 'Carlos M.',
        flag: '🇪🇸',
        lang: 'Spanish↔German',
        spot: 'Hallmackenreuther',
        ago: '18 min ago',
        bg: '#7C3AED',
    },
    {
        name: 'Yuki T.',
        flag: '🇯🇵',
        lang: 'Japanese↔German',
        spot: 'StadtBibliothek',
        ago: '24 min ago',
        bg: '#C4271A',
    },
    {
        name: 'Mehmet A.',
        flag: '🇹🇷',
        lang: 'Turkish↔German',
        spot: 'Cafe Schmitz',
        ago: '31 min ago',
        bg: '#C4271A',
    },
    {
        name: 'Claire D.',
        flag: '🇫🇷',
        lang: 'French↔German',
        spot: 'Hallmackenreuther',
        ago: '45 min ago',
        bg: '#0A7C52',
    },
];

const INTERESTS_LIST = [
    'Tech',
    'Travel',
    'Coffee',
    'Music',
    'Books',
    'Cooking',
    'Sport',
    'Art',
    'Cinema',
    'Hiking',
];
const AVAIL_DAYS_LABELS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
const INITIAL_ACTIVE_DAYS = [true, true, false, true, false, true, false];

const INITIAL_PROFILE_LANGS: ProfileLang[] = [
    {
        flag: '🇦🇿',
        name: 'Azerbaijani',
        role: 'native',
        level: 'Native',
        levelBg: '#D4F0E6',
        levelColor: '#0A7C52',
    },
    {
        flag: '🇬🇧',
        name: 'English',
        role: 'native',
        level: 'Native',
        levelBg: '#D4F0E6',
        levelColor: '#0A7C52',
    },
    {
        flag: '🇩🇪',
        name: 'German',
        role: 'learner',
        level: 'A2',
        levelBg: '#EBF0FD',
        levelColor: '#1A4CD4',
    },
];

const AVAILABLE_LANGS: AvailableLang[] = [
    { flag: '🇫🇷', name: 'French' },
    { flag: '🇪🇸', name: 'Spanish' },
    { flag: '🇮🇹', name: 'Italian' },
    { flag: '🇵🇱', name: 'Polish' },
    { flag: '🇷🇺', name: 'Russian' },
    { flag: '🇯🇵', name: 'Japanese' },
    { flag: '🇨🇳', name: 'Chinese' },
    { flag: '🇰🇷', name: 'Korean' },
    { flag: '🇧🇷', name: 'Portuguese' },
    { flag: '🇸🇦', name: 'Arabic' },
    { flag: '🇳🇱', name: 'Dutch' },
    { flag: '🇸🇪', name: 'Swedish' },
];

const FILTER_PILLS = [
    { id: 'all', label: '✨ All' },
    { id: 'de-en', label: '🇩🇪↔🇬🇧 German↔English' },
    { id: 'de-tr', label: '🇩🇪↔🇹🇷 German↔Turkish' },
    { id: 'de-fr', label: '🇩🇪↔🇫🇷 German↔French' },
    { id: 'de-es', label: '🇩🇪↔🇪🇸 German↔Spanish' },
    { id: 'online', label: '🟢 Online now' },
    { id: 'nearby', label: '📍 Nearby' },
];

const TABS = [
    { id: 'partners', label: 'Find Partners' },
    { id: 'meetups', label: 'Meetups' },
    { id: 'dropin', label: 'Drop-in' },
    { id: 'profile', label: 'My Languages' },
];

// ============================================================
// Page component
// ============================================================

export default function LanguageExchange() {
    const { track } = useTracker();

    // Data state
    const [partners, setPartners] = useState<PartnerData[]>(SEED_PARTNERS);
    const [meetups, setMeetups] = useState<MeetupData[]>(SEED_MEETUPS);

    // UI state
    const [activeTab, setActiveTab] = useTabState('partners');
    const [activeFilter, setActiveFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [selectedPartner, setSelectedPartner] = useState<PartnerData | null>(
        null,
    );
    const [sheetView, setSheetView] = useState<
        'detail' | 'request' | 'suggest' | 'meetup-detail'
    >('detail');
    const [selectedMeetup, setSelectedMeetup] = useState<MeetupData | null>(
        null,
    );
    const [suggestSpotName, setSuggestSpotName] = useState('');

    // Drop-in state
    const [dropinOn, setDropinOn] = useState(false);
    const [selectedDropinSpot, setSelectedDropinSpot] = useState<string | null>(
        null,
    );

    // Profile state
    const [editMode, setEditMode] = useState(false);
    const [profileLangs, setProfileLangs] = useState<ProfileLang[]>(
        INITIAL_PROFILE_LANGS,
    );
    const [activeDays, setActiveDays] = useState(INITIAL_ACTIVE_DAYS);
    const [selectedInterests] = useState(new Set(['Tech', 'Travel', 'Coffee']));

    // Add language form state
    const [showAddLangForm, setShowAddLangForm] = useState(false);
    const [newLangFlag, setNewLangFlag] = useState<string | null>(null);
    const [newLangName, setNewLangName] = useState<string | null>(null);
    const [newLangRole, setNewLangRole] = useState<'native' | 'learner' | null>(
        null,
    );
    const [newLangLevel, setNewLangLevel] = useState<string | null>(null);

    // Suggest meeting form state
    const [pickedDays, setPickedDays] = useState(new Set<string>());
    const [pickedTimes, setPickedTimes] = useState(new Set<string>());

    // Toast state
    const [toast, setToast] = useState<string | null>(null);

    function showToast(msg: string) {
        setToast(msg);
        setTimeout(() => setToast(null), 2500);
    }

    // ── Partner filtering ──
    const filteredPartners = useMemo(() => {
        return partners.filter((p) => {
            const matchFilter =
                activeFilter === 'all'
                    ? true
                    : activeFilter === 'online'
                      ? p.online
                      : activeFilter === 'nearby'
                        ? p.nearby
                        : p.pair === activeFilter;
            const q = search.toLowerCase().trim();
            const matchSearch =
                !q ||
                p.name.toLowerCase().includes(q) ||
                p.native.toLowerCase().includes(q) ||
                p.learning.toLowerCase().includes(q) ||
                p.interests.some((i) => i.toLowerCase().includes(q));
            return matchFilter && matchSearch;
        });
    }, [partners, activeFilter, search]);

    // ── Partner handlers ──
    function openPartner(id: string) {
        const p = partners.find((x) => x.id === id);
        if (p) {
            setSelectedPartner(p);
            setSheetView('detail');
        }
    }

    function toggleSavePartner(id: string, e?: MouseEvent) {
        if (e) e.stopPropagation();
        setPartners((prev) =>
            prev.map((p) => (p.id === id ? { ...p, saved: !p.saved } : p)),
        );
        const p = partners.find((x) => x.id === id);
        if (p) {
            showToast(
                p.saved
                    ? '♡ Removed from favourites'
                    : `♥ ${p.name} saved to your favourites`,
            );
        }
    }

    // ── Meetup handlers ──
    function toggleJoinMeetup(id: number, e?: MouseEvent) {
        if (e) e.stopPropagation();
        setMeetups((prev) =>
            prev.map((m) => {
                if (m.id !== id) return m;
                const newJoined = !m.joined;
                return {
                    ...m,
                    joined: newJoined,
                    attending: newJoined ? m.attending + 1 : m.attending - 1,
                };
            }),
        );
        const m = meetups.find((x) => x.id === id);
        if (m) {
            showToast(
                m.joined
                    ? `Removed from ${m.title}`
                    : `🎉 You're going to ${m.title}`,
            );
        }
    }

    function openMeetup(id: number) {
        const m = meetups.find((x) => x.id === id);
        if (m) {
            setSelectedMeetup(m);
            setSheetView('meetup-detail');
            setSelectedPartner(null);
        }
    }

    // ── Drop-in handlers ──
    function toggleDropinMode() {
        const newState = !dropinOn;
        setDropinOn(newState);
        if (!newState) setSelectedDropinSpot(null);
    }

    function selectDropinSpot(name: string) {
        setSelectedDropinSpot(name);
        showToast(`📍 Showing as available at ${name}`);
    }

    // ── Profile handlers ──
    function toggleEditMode() {
        setEditMode(!editMode);
        if (editMode) setShowAddLangForm(false);
    }

    function saveProfile() {
        setEditMode(false);
        setShowAddLangForm(false);
        showToast('✓ Profile saved');
    }

    function removeLang(index: number) {
        if (profileLangs[index].name === 'Azerbaijani') return;
        setProfileLangs((prev) => prev.filter((_, i) => i !== index));
    }

    function toggleAvailDay(index: number) {
        if (!editMode) return;
        setActiveDays((prev) => prev.map((v, i) => (i === index ? !v : v)));
    }

    function resetAddLangForm() {
        setNewLangFlag(null);
        setNewLangName(null);
        setNewLangRole(null);
        setNewLangLevel(null);
    }

    function saveNewLanguage() {
        if (!newLangName) {
            showToast('Please select a language');
            return;
        }
        if (!newLangRole) {
            showToast('Are you a native speaker or learning it?');
            return;
        }
        if (newLangRole === 'learner' && !newLangLevel) {
            showToast('Please select your level');
            return;
        }

        const levelBg = newLangRole === 'native' ? '#D4F0E6' : '#EBF0FD';
        const levelColor = newLangRole === 'native' ? '#0A7C52' : '#1A4CD4';
        const level = newLangRole === 'native' ? 'Native' : newLangLevel!;

        setProfileLangs((prev) => [
            ...prev,
            {
                flag: newLangFlag!,
                name: newLangName!,
                role: newLangRole!,
                level,
                levelBg,
                levelColor,
            },
        ]);
        showToast(`${newLangFlag} ${newLangName} added`);
        setShowAddLangForm(false);
        resetAddLangForm();
    }

    // ── Sheet helpers ──
    function closeSheet() {
        setSelectedPartner(null);
        setSelectedMeetup(null);
    }

    function handleSendRequest() {
        setSheetView('request');
    }

    function handleSuggestMeeting(spotName: string) {
        setSuggestSpotName(spotName);
        setPickedDays(new Set());
        setPickedTimes(new Set());
        setSheetView('suggest');
    }

    function confirmRequest() {
        if (!selectedPartner) return;
        track('partner_connect', { partner_id: selectedPartner.id });
        setPartners((prev) =>
            prev.map((p) =>
                p.id === selectedPartner.id
                    ? { ...p, isNew: false, requested: true }
                    : p,
            ),
        );
        closeSheet();
        showToast(
            `✉️ Request sent to ${selectedPartner.name} — they'll be notified`,
        );
    }

    function confirmMeeting() {
        if (pickedDays.size === 0 && pickedTimes.size === 0) {
            showToast('Please pick at least one day or time preference');
            return;
        }
        closeSheet();
        showToast(
            `📅 Meeting suggested to ${selectedPartner?.name} at ${suggestSpotName}`,
        );
    }

    // Keep sheet data in sync
    const sheetPartner = selectedPartner
        ? (partners.find((p) => p.id === selectedPartner.id) ?? selectedPartner)
        : null;
    const sheetMeetup = selectedMeetup
        ? (meetups.find((m) => m.id === selectedMeetup.id) ?? selectedMeetup)
        : null;
    const isSheetOpen =
        sheetPartner !== null ||
        (sheetView === 'meetup-detail' && sheetMeetup !== null);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Language Exchange', href: '/language-exchange' },
            ]}
            rightPanel={<LanguageRightPanel onOpenPartner={openPartner} />}
        >
            <Head title="Language Exchange" />
            <div className="w-full">
                {/* ── Sticky header: title + pill tabs ── */}
                <div
                    className="sticky top-0 z-50 flex items-center justify-between border-b border-[#E2DFD6] px-3 pt-4 pb-3.5"
                    style={{
                        background: 'rgba(246,245,241,.92)',
                        backdropFilter: 'blur(16px)',
                    }}
                >
                    <span
                        className="hidden shrink-0 md:block"
                        style={{
                            fontFamily: "'Fraunces', serif",
                            fontSize: 18,
                            fontWeight: 500,
                            letterSpacing: '-0.01em',
                        }}
                    >
                        Language Exchange
                    </span>
                    <div
                        className="flex shrink-0 gap-1 rounded-full p-[3px]"
                        style={{ background: '#EFEDE7' }}
                    >
                        {TABS.map((t) => (
                            <button
                                key={t.id}
                                onClick={() => setActiveTab(t.id)}
                                className="shrink-0 cursor-pointer rounded-full border-none px-[14px] py-[6px] whitespace-nowrap transition-all"
                                style={{
                                    fontSize: 12,
                                    fontWeight: 600,
                                    color:
                                        activeTab === t.id
                                            ? '#18170F'
                                            : '#6B6860',
                                    background:
                                        activeTab === t.id
                                            ? 'white'
                                            : 'transparent',
                                    boxShadow:
                                        activeTab === t.id
                                            ? '0 1px 4px rgba(0,0,0,0.08)'
                                            : 'none',
                                }}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* ════ FIND PARTNERS TAB ════ */}
                {activeTab === 'partners' && (
                    <>
                        <LanguageHero />

                        {/* Search + filters */}
                        <div className="border-b border-[#E2DFD6] px-6 pt-3.5 pb-3.5">
                            {/* Search bar */}
                            <div className="flex items-center gap-[9px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-[#1A4CD4] focus-within:bg-white focus-within:shadow-[0_0_0_3px_#EBF0FD]">
                                <span
                                    style={{ fontSize: 15, color: '#AAA89F' }}
                                >
                                    🔍
                                </span>
                                <input
                                    type="text"
                                    placeholder="Search by language, name, interests…"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F]"
                                    style={{
                                        fontFamily: "'Geist', sans-serif",
                                        fontSize: 14,
                                    }}
                                />
                                {search && (
                                    <button
                                        onClick={() => setSearch('')}
                                        className="cursor-pointer border-none bg-transparent text-[13px] text-[#AAA89F]"
                                    >
                                        ✕
                                    </button>
                                )}
                            </div>

                            {/* Filter pills */}
                            <div
                                className="mt-2.5 flex gap-1.5 overflow-x-auto pb-0.5"
                                style={{ scrollbarWidth: 'none' }}
                            >
                                {FILTER_PILLS.map((f) => (
                                    <button
                                        key={f.id}
                                        onClick={() => setActiveFilter(f.id)}
                                        className="flex shrink-0 cursor-pointer items-center gap-[5px] rounded-full border px-[11px] py-[5px] transition-all"
                                        style={{
                                            fontSize: 12,
                                            fontWeight: 500,
                                            fontFamily: "'Geist', sans-serif",
                                            whiteSpace: 'nowrap',
                                            background:
                                                activeFilter === f.id
                                                    ? '#1A4CD4'
                                                    : 'white',
                                            color:
                                                activeFilter === f.id
                                                    ? 'white'
                                                    : '#6B6860',
                                            borderColor:
                                                activeFilter === f.id
                                                    ? '#1A4CD4'
                                                    : '#E2DFD6',
                                        }}
                                    >
                                        {f.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Partner list */}
                        <div className="px-6 py-5">
                            {filteredPartners.length === 0 ? (
                                <div
                                    className="py-12 text-center"
                                    style={{ color: '#AAA89F' }}
                                >
                                    <div
                                        style={{
                                            fontSize: 40,
                                            marginBottom: 12,
                                        }}
                                    >
                                        🔍
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 16,
                                            fontWeight: 600,
                                            color: '#6B6860',
                                            marginBottom: 6,
                                        }}
                                    >
                                        No partners found
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 13,
                                            lineHeight: 1.6,
                                        }}
                                    >
                                        Try a different filter or search term.
                                        New members join every week.
                                    </div>
                                </div>
                            ) : (
                                filteredPartners.map((p, i) => (
                                    <PartnerCard
                                        key={p.id}
                                        partner={p}
                                        index={i}
                                        onSave={(e) =>
                                            toggleSavePartner(p.id, e)
                                        }
                                        onClick={() => openPartner(p.id)}
                                    />
                                ))
                            )}
                        </div>
                    </>
                )}

                {/* ════ MEETUPS TAB ════ */}
                {activeTab === 'meetups' && (
                    <div className="px-6 py-5">
                        <div className="mb-3 flex items-center justify-between">
                            <span
                                style={{
                                    fontSize: 11,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.08em',
                                    color: '#AAA89F',
                                }}
                            >
                                Upcoming Meetups
                            </span>
                            <span
                                className="cursor-pointer"
                                style={{
                                    fontSize: 13,
                                    fontWeight: 600,
                                    color: '#1A4CD4',
                                }}
                                onClick={() =>
                                    showToast(
                                        'Create meetup — coming in full build',
                                    )
                                }
                            >
                                + Create
                            </span>
                        </div>

                        {meetups.map((m) => (
                            <div
                                key={m.id}
                                onClick={() => openMeetup(m.id)}
                                className="mb-3 cursor-pointer overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white transition-all hover:border-[rgba(26,76,212,0.25)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.07)]"
                            >
                                {/* Color banner */}
                                <div
                                    className="h-1.5"
                                    style={{ background: m.color }}
                                />
                                <div className="p-4">
                                    {/* Top: date + info */}
                                    <div className="mb-2.5 flex items-start gap-3">
                                        {/* Date block */}
                                        <div
                                            className="shrink-0 rounded-[9px] bg-[#EFEDE7] px-2.5 py-[7px] text-center"
                                            style={{ minWidth: 48 }}
                                        >
                                            <div
                                                style={{
                                                    fontFamily:
                                                        "'Geist Mono', monospace",
                                                    fontSize: 20,
                                                    fontWeight: 500,
                                                    lineHeight: 1,
                                                }}
                                            >
                                                {m.date}
                                            </div>
                                            <div
                                                style={{
                                                    fontSize: 9,
                                                    textTransform: 'uppercase',
                                                    letterSpacing: '0.07em',
                                                    color: '#AAA89F',
                                                    fontWeight: 700,
                                                    marginTop: 2,
                                                }}
                                            >
                                                {m.month}
                                            </div>
                                        </div>
                                        {/* Info */}
                                        <div className="flex-1">
                                            <div
                                                style={{
                                                    fontSize: 15,
                                                    fontWeight: 600,
                                                    marginBottom: 3,
                                                }}
                                            >
                                                {m.title}
                                            </div>
                                            <div
                                                className="mb-1.5 flex flex-wrap items-center gap-[5px]"
                                                style={{
                                                    fontSize: 12,
                                                    color: '#6B6860',
                                                }}
                                            >
                                                <span>📍 {m.location}</span>
                                                <span className="inline-block size-[3px] rounded-full bg-[#AAA89F]" />
                                                <span>
                                                    🕐 {m.time} · {m.duration}
                                                </span>
                                                <span className="inline-block size-[3px] rounded-full bg-[#AAA89F]" />
                                                <span>{m.distance}</span>
                                            </div>
                                            {/* Language tags */}
                                            <div className="flex flex-wrap gap-[5px]">
                                                {m.languages.map((lang, i) => (
                                                    <span
                                                        key={i}
                                                        className="rounded-[20px] px-2 py-0.5"
                                                        style={{
                                                            fontSize: 11,
                                                            fontWeight: 600,
                                                            background: `${m.langColors[i]}22`,
                                                            color: m.langColors[
                                                                i
                                                            ],
                                                        }}
                                                    >
                                                        {lang}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                    {/* Bottom: attendees + join */}
                                    <div className="flex items-center justify-between">
                                        <div
                                            className="flex items-center gap-[5px]"
                                            style={{
                                                fontSize: 12,
                                                color: '#6B6860',
                                            }}
                                        >
                                            <div className="flex">
                                                {m.attendees.map((a, i) => (
                                                    <div
                                                        key={i}
                                                        className="flex size-5 items-center justify-center rounded-full border-2 border-white bg-[#EFEDE7]"
                                                        style={{
                                                            fontSize: 10,
                                                            marginLeft:
                                                                i === 0
                                                                    ? 0
                                                                    : -6,
                                                        }}
                                                    >
                                                        {a}
                                                    </div>
                                                ))}
                                            </div>
                                            <span className="ml-2">
                                                {m.attending}/{m.max} attending
                                            </span>
                                        </div>
                                        <button
                                            onClick={(e) =>
                                                toggleJoinMeetup(m.id, e)
                                            }
                                            className="cursor-pointer rounded-[9px] border-none px-4 py-[7px] transition-all"
                                            style={{
                                                fontFamily:
                                                    "'Geist', sans-serif",
                                                fontSize: 12,
                                                fontWeight: 600,
                                                background: m.joined
                                                    ? '#D4F0E6'
                                                    : '#1A4CD4',
                                                color: m.joined
                                                    ? '#0A7C52'
                                                    : 'white',
                                            }}
                                        >
                                            {m.joined ? '✓ Going' : 'Join'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {/* ════ DROP-IN TAB ════ */}
                {activeTab === 'dropin' && (
                    <div className="px-6 py-5">
                        {/* Drop-in hero card */}
                        <div className="mb-4 rounded-[20px] border border-[#E2DFD6] bg-[#EFEDE7] p-5 px-[22px]">
                            {/* Top: icon + text */}
                            <div className="mb-3.5 flex items-center gap-3">
                                <span style={{ fontSize: 28 }}>📍</span>
                                <div>
                                    <div
                                        style={{
                                            fontFamily: "'Fraunces', serif",
                                            fontSize: 18,
                                            fontWeight: 500,
                                        }}
                                    >
                                        Drop-in Mode
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 13,
                                            color: '#6B6860',
                                            lineHeight: 1.5,
                                            marginTop: 2,
                                        }}
                                    >
                                        Let other members know you're at a cafe
                                        and open to a chat. They can see you're
                                        there and join you.
                                    </div>
                                </div>
                            </div>

                            {/* Toggle row */}
                            <div className="mb-3 flex items-center justify-between rounded-[9px] bg-white px-3.5 py-3">
                                <div>
                                    <div
                                        style={{
                                            fontSize: 14,
                                            fontWeight: 600,
                                        }}
                                    >
                                        I'm available now
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 12,
                                            color: '#6B6860',
                                        }}
                                    >
                                        {dropinOn
                                            ? 'Select where you are below'
                                            : 'Turn on to show your location to other members'}
                                    </div>
                                </div>
                                <div
                                    onClick={toggleDropinMode}
                                    className="relative shrink-0 cursor-pointer rounded-[20px] transition-colors"
                                    style={{
                                        width: 44,
                                        height: 24,
                                        background: dropinOn
                                            ? '#0A7C52'
                                            : '#E2DFD6',
                                    }}
                                >
                                    <div
                                        className="absolute top-[3px] left-[3px] size-[18px] rounded-full bg-white shadow-[0_1px_4px_rgba(0,0,0,0.2)] transition-transform"
                                        style={{
                                            transform: dropinOn
                                                ? 'translateX(20px)'
                                                : 'translateX(0)',
                                        }}
                                    />
                                </div>
                            </div>

                            {/* Spot picker — visible when toggle is on */}
                            {dropinOn && (
                                <div>
                                    <div
                                        style={{
                                            fontSize: 11,
                                            fontWeight: 700,
                                            textTransform: 'uppercase',
                                            letterSpacing: '0.08em',
                                            color: '#AAA89F',
                                            marginBottom: 8,
                                        }}
                                    >
                                        Where are you?
                                    </div>
                                    {DROPIN_SPOTS.map((s) => (
                                        <div
                                            key={s.name}
                                            onClick={() =>
                                                selectDropinSpot(s.name)
                                            }
                                            className="mb-2 flex cursor-pointer items-center gap-3 rounded-[9px] border bg-white p-3.5 transition-all hover:translate-x-0.5 hover:border-[rgba(26,76,212,0.2)]"
                                            style={{
                                                borderColor:
                                                    selectedDropinSpot ===
                                                    s.name
                                                        ? '#1A4CD4'
                                                        : '#E2DFD6',
                                                background:
                                                    selectedDropinSpot ===
                                                    s.name
                                                        ? '#EBF0FD'
                                                        : 'white',
                                            }}
                                        >
                                            <span
                                                className="shrink-0"
                                                style={{ fontSize: 20 }}
                                            >
                                                {s.emoji}
                                            </span>
                                            <div className="flex-1">
                                                <div
                                                    style={{
                                                        fontSize: 13,
                                                        fontWeight: 600,
                                                        marginBottom: 2,
                                                    }}
                                                >
                                                    {s.name}
                                                </div>
                                                <div
                                                    style={{
                                                        fontSize: 12,
                                                        color: '#6B6860',
                                                    }}
                                                >
                                                    {s.area} · {s.dist}
                                                </div>
                                            </div>
                                            {s.people > 0 ? (
                                                <div
                                                    className="flex shrink-0 items-center gap-[5px] rounded-[20px] px-[9px] py-[3px]"
                                                    style={{
                                                        fontSize: 12,
                                                        fontWeight: 600,
                                                        color: '#0A7C52',
                                                        background: '#D4F0E6',
                                                    }}
                                                >
                                                    <div
                                                        className="size-[5px] rounded-full bg-[#0A7C52]"
                                                        style={{
                                                            animation:
                                                                'pulse 2s infinite',
                                                        }}
                                                    />
                                                    {s.people} here
                                                </div>
                                            ) : (
                                                <span
                                                    style={{
                                                        fontSize: 11,
                                                        color: '#AAA89F',
                                                    }}
                                                >
                                                    0 here
                                                </span>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Active drop-ins */}
                        <div className="mb-3 flex items-center justify-between">
                            <span
                                style={{
                                    fontSize: 11,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.08em',
                                    color: '#AAA89F',
                                }}
                            >
                                People here right now
                            </span>
                            <span
                                style={{
                                    fontSize: 12,
                                    fontWeight: 600,
                                    color: '#0A7C52',
                                }}
                            >
                                6 active
                            </span>
                        </div>

                        {ACTIVE_DROPINS.map((a) => {
                            const partnerId =
                                partners.find((p) => p.name === a.name)?.id ||
                                'sarah';
                            return (
                                <div
                                    key={a.name}
                                    onClick={() => openPartner(partnerId)}
                                    className="mb-2 flex cursor-pointer items-center gap-3 rounded-[9px] border border-[#E2DFD6] bg-white p-3.5 transition-all hover:translate-x-0.5 hover:border-[rgba(26,76,212,0.2)]"
                                >
                                    <div
                                        className="flex size-9 shrink-0 items-center justify-center rounded-full"
                                        style={{
                                            background: a.bg,
                                            color: 'white',
                                            fontSize: 14,
                                            fontWeight: 700,
                                        }}
                                    >
                                        {a.flag}
                                    </div>
                                    <div className="flex-1">
                                        <div
                                            style={{
                                                fontSize: 13,
                                                fontWeight: 600,
                                                marginBottom: 2,
                                            }}
                                        >
                                            {a.name}{' '}
                                            <span
                                                style={{
                                                    fontSize: 11,
                                                    color: '#AAA89F',
                                                    fontWeight: 400,
                                                }}
                                            >
                                                &middot; {a.lang}
                                            </span>
                                        </div>
                                        <div
                                            style={{
                                                fontSize: 12,
                                                color: '#6B6860',
                                            }}
                                        >
                                            📍 {a.spot} · {a.ago}
                                        </div>
                                    </div>
                                    <button
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            openPartner(partnerId);
                                        }}
                                        className="shrink-0 cursor-pointer rounded-[9px] border-none px-[11px] py-[5px]"
                                        style={{
                                            fontSize: 11,
                                            fontWeight: 600,
                                            background: '#1A4CD4',
                                            color: 'white',
                                        }}
                                    >
                                        Say hi →
                                    </button>
                                </div>
                            );
                        })}
                    </div>
                )}

                {/* ════ MY LANGUAGES TAB ════ */}
                {activeTab === 'profile' && (
                    <div className="px-6 py-5">
                        {/* Stats row */}
                        <div className="mb-4 flex overflow-hidden rounded-[9px] border border-[#E2DFD6]">
                            {[
                                { num: '12', lbl: 'Sessions' },
                                { num: '3', lbl: 'Partners' },
                                { num: '18h', lbl: 'Practice' },
                                {
                                    num:
                                        profileLangs.find(
                                            (l) => l.name === 'German',
                                        )?.level || 'A2',
                                    lbl: 'German',
                                },
                            ].map((s, i, arr) => (
                                <div
                                    key={s.lbl}
                                    className="flex-1 py-3 text-center"
                                    style={{
                                        borderRight:
                                            i < arr.length - 1
                                                ? '1px solid #E2DFD6'
                                                : 'none',
                                    }}
                                >
                                    <div
                                        style={{
                                            fontFamily:
                                                "'Geist Mono', monospace",
                                            fontSize: 20,
                                            fontWeight: 500,
                                            color: '#1A4CD4',
                                        }}
                                    >
                                        {s.num}
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 10,
                                            color: '#AAA89F',
                                            fontWeight: 600,
                                            textTransform: 'uppercase',
                                            letterSpacing: '0.05em',
                                            marginTop: 2,
                                        }}
                                    >
                                        {s.lbl}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {/* Profile card */}
                        <div className="mb-4 rounded-[20px] border border-[#E2DFD6] bg-white p-5">
                            {/* Avatar row */}
                            <div className="mb-4 flex items-center gap-3.5">
                                <div
                                    className="flex size-[60px] shrink-0 items-center justify-center rounded-full"
                                    style={{
                                        background:
                                            'linear-gradient(135deg, #1A4CD4, #6366F1)',
                                        color: 'white',
                                        fontSize: 24,
                                        fontWeight: 600,
                                    }}
                                >
                                    A
                                </div>
                                <div>
                                    <div
                                        style={{
                                            fontFamily: "'Fraunces', serif",
                                            fontSize: 20,
                                            fontWeight: 500,
                                        }}
                                    >
                                        Anar
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 13,
                                            color: '#6B6860',
                                            marginTop: 2,
                                        }}
                                    >
                                        Cologne · From Azerbaijan · Here since
                                        2023
                                    </div>
                                </div>
                                {!editMode && (
                                    <button
                                        onClick={toggleEditMode}
                                        className="ml-auto shrink-0 cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-3.5 py-[7px] transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] hover:text-[#1A4CD4]"
                                        style={{
                                            fontFamily: "'Geist', sans-serif",
                                            fontSize: 13,
                                            fontWeight: 600,
                                        }}
                                    >
                                        Edit
                                    </button>
                                )}
                            </div>

                            {/* Edit mode banner */}
                            {editMode && (
                                <div className="mb-3.5 flex items-center gap-2 rounded-[9px] bg-[#EBF0FD] px-[13px] py-[9px]">
                                    <span style={{ fontSize: 14 }}>✏️</span>
                                    <span
                                        className="flex-1"
                                        style={{
                                            fontSize: 13,
                                            fontWeight: 600,
                                            color: '#1A4CD4',
                                        }}
                                    >
                                        Editing your profile
                                    </span>
                                    <button
                                        onClick={saveProfile}
                                        className="cursor-pointer rounded-[9px] border-none px-[13px] py-1.5"
                                        style={{
                                            fontFamily: "'Geist', sans-serif",
                                            fontSize: 12,
                                            fontWeight: 700,
                                            background: '#1A4CD4',
                                            color: 'white',
                                        }}
                                    >
                                        Save
                                    </button>
                                    <button
                                        onClick={toggleEditMode}
                                        className="cursor-pointer rounded-[9px] border px-2.5 py-1.5"
                                        style={{
                                            fontFamily: "'Geist', sans-serif",
                                            fontSize: 12,
                                            fontWeight: 600,
                                            background: 'transparent',
                                            borderColor: 'rgba(26,76,212,.2)',
                                            color: '#1A4CD4',
                                        }}
                                    >
                                        Cancel
                                    </button>
                                </div>
                            )}

                            {/* Languages */}
                            <div className="mb-3.5">
                                <div
                                    style={{
                                        fontSize: 11,
                                        fontWeight: 700,
                                        textTransform: 'uppercase',
                                        letterSpacing: '0.08em',
                                        color: '#AAA89F',
                                        marginBottom: 8,
                                    }}
                                >
                                    My languages
                                </div>
                                {profileLangs.map((l, i) => (
                                    <div
                                        key={`${l.name}-${i}`}
                                        className="border-b border-[#E2DFD6] py-3"
                                    >
                                        <div className="flex items-start gap-2.5">
                                            <span
                                                style={{
                                                    fontSize: 20,
                                                    marginTop: 2,
                                                }}
                                            >
                                                {l.flag}
                                            </span>
                                            <div className="flex-1">
                                                <div
                                                    style={{
                                                        fontSize: 14,
                                                        fontWeight: 600,
                                                    }}
                                                >
                                                    {l.name}
                                                </div>
                                                <div
                                                    style={{
                                                        fontSize: 12,
                                                        color: '#6B6860',
                                                    }}
                                                >
                                                    {l.role === 'native'
                                                        ? 'Native speaker'
                                                        : `Learning (${l.level})`}
                                                </div>
                                            </div>
                                            <span
                                                className="mt-0.5 shrink-0 rounded-[20px] px-2 py-0.5"
                                                style={{
                                                    fontSize: 10,
                                                    fontWeight: 700,
                                                    background: l.levelBg,
                                                    color: l.levelColor,
                                                }}
                                            >
                                                {l.level}
                                            </span>
                                            {editMode &&
                                                l.name !== 'Azerbaijani' && (
                                                    <span
                                                        onClick={() =>
                                                            removeLang(i)
                                                        }
                                                        className="mt-0.5 shrink-0 cursor-pointer rounded-[5px] px-[7px] py-[3px] text-[#AAA89F] transition-all hover:bg-[#FDE8E6] hover:text-[#C4271A]"
                                                        style={{ fontSize: 14 }}
                                                    >
                                                        ✕
                                                    </span>
                                                )}
                                        </div>
                                    </div>
                                ))}

                                {/* Add language button — edit mode only */}
                                {editMode && !showAddLangForm && (
                                    <button
                                        onClick={() => {
                                            setShowAddLangForm(true);
                                            resetAddLangForm();
                                        }}
                                        className="mt-2.5 w-full cursor-pointer rounded-[9px] border border-dashed border-[#1A4CD4] bg-[#EBF0FD] py-2 transition-all"
                                        style={{
                                            fontFamily: "'Geist', sans-serif",
                                            fontSize: 13,
                                            fontWeight: 500,
                                            color: '#1A4CD4',
                                        }}
                                    >
                                        + Add another language
                                    </button>
                                )}

                                {/* Add language form */}
                                {editMode && showAddLangForm && (
                                    <div className="mt-3 rounded-[14px] border border-[#E2DFD6] bg-[#EFEDE7] p-3.5">
                                        <div
                                            style={{
                                                fontSize: 12,
                                                fontWeight: 700,
                                                color: '#6B6860',
                                                marginBottom: 10,
                                            }}
                                        >
                                            Add a language
                                        </div>

                                        {/* Language picker */}
                                        <div className="mb-2.5">
                                            <div
                                                style={{
                                                    fontSize: 11,
                                                    fontWeight: 600,
                                                    color: '#AAA89F',
                                                    textTransform: 'uppercase',
                                                    letterSpacing: '0.06em',
                                                    marginBottom: 6,
                                                }}
                                            >
                                                Language
                                            </div>
                                            <div className="flex flex-wrap gap-[5px]">
                                                {AVAILABLE_LANGS.map((al) => (
                                                    <div
                                                        key={al.name}
                                                        onClick={() => {
                                                            setNewLangFlag(
                                                                al.flag,
                                                            );
                                                            setNewLangName(
                                                                al.name,
                                                            );
                                                        }}
                                                        className="cursor-pointer rounded-full border-[1.5px] px-[11px] py-[5px] transition-all"
                                                        style={{
                                                            fontSize: 12,
                                                            fontWeight: 500,
                                                            background:
                                                                newLangName ===
                                                                al.name
                                                                    ? '#1A4CD4'
                                                                    : 'white',
                                                            borderColor:
                                                                newLangName ===
                                                                al.name
                                                                    ? '#1A4CD4'
                                                                    : '#E2DFD6',
                                                            color:
                                                                newLangName ===
                                                                al.name
                                                                    ? 'white'
                                                                    : '#6B6860',
                                                        }}
                                                    >
                                                        {al.flag} {al.name}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>

                                        {/* Role picker */}
                                        <div className="mb-2.5">
                                            <div
                                                style={{
                                                    fontSize: 11,
                                                    fontWeight: 600,
                                                    color: '#AAA89F',
                                                    textTransform: 'uppercase',
                                                    letterSpacing: '0.06em',
                                                    marginBottom: 6,
                                                }}
                                            >
                                                I am a…
                                            </div>
                                            <div className="flex gap-1.5">
                                                {(
                                                    [
                                                        'native',
                                                        'learner',
                                                    ] as const
                                                ).map((role) => (
                                                    <div
                                                        key={role}
                                                        onClick={() => {
                                                            setNewLangRole(
                                                                role,
                                                            );
                                                            if (
                                                                role ===
                                                                'native'
                                                            )
                                                                setNewLangLevel(
                                                                    'Native',
                                                                );
                                                        }}
                                                        className="flex-1 cursor-pointer rounded-[9px] border-[1.5px] py-2 text-center transition-all"
                                                        style={{
                                                            fontSize: 13,
                                                            fontWeight: 500,
                                                            background:
                                                                newLangRole ===
                                                                role
                                                                    ? '#1A4CD4'
                                                                    : 'white',
                                                            borderColor:
                                                                newLangRole ===
                                                                role
                                                                    ? '#1A4CD4'
                                                                    : '#E2DFD6',
                                                            color:
                                                                newLangRole ===
                                                                role
                                                                    ? 'white'
                                                                    : '#6B6860',
                                                        }}
                                                    >
                                                        {role === 'native'
                                                            ? 'Native speaker'
                                                            : 'Learning it'}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>

                                        {/* Level picker — learner only */}
                                        {newLangRole === 'learner' && (
                                            <div className="mb-2.5">
                                                <div
                                                    style={{
                                                        fontSize: 11,
                                                        fontWeight: 600,
                                                        color: '#AAA89F',
                                                        textTransform:
                                                            'uppercase',
                                                        letterSpacing: '0.06em',
                                                        marginBottom: 6,
                                                    }}
                                                >
                                                    My level
                                                </div>
                                                <div className="flex gap-[5px]">
                                                    {[
                                                        'A1',
                                                        'A2',
                                                        'B1',
                                                        'B2',
                                                        'C1',
                                                        'C2',
                                                    ].map((lvl) => (
                                                        <div
                                                            key={lvl}
                                                            onClick={() =>
                                                                setNewLangLevel(
                                                                    lvl,
                                                                )
                                                            }
                                                            className="flex-1 cursor-pointer rounded-[9px] border-[1.5px] py-1.5 text-center transition-all"
                                                            style={{
                                                                fontSize: 12,
                                                                fontWeight: 600,
                                                                background:
                                                                    newLangLevel ===
                                                                    lvl
                                                                        ? '#1A4CD4'
                                                                        : 'white',
                                                                borderColor:
                                                                    newLangLevel ===
                                                                    lvl
                                                                        ? '#1A4CD4'
                                                                        : '#E2DFD6',
                                                                color:
                                                                    newLangLevel ===
                                                                    lvl
                                                                        ? 'white'
                                                                        : '#6B6860',
                                                            }}
                                                        >
                                                            {lvl}
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {/* Save / Cancel */}
                                        <div className="mt-1 flex gap-2">
                                            <button
                                                onClick={saveNewLanguage}
                                                className="flex-1 cursor-pointer rounded-[9px] border-none py-[9px]"
                                                style={{
                                                    fontFamily:
                                                        "'Geist', sans-serif",
                                                    fontSize: 13,
                                                    fontWeight: 600,
                                                    background: '#1A4CD4',
                                                    color: 'white',
                                                }}
                                            >
                                                Save language
                                            </button>
                                            <button
                                                onClick={() =>
                                                    setShowAddLangForm(false)
                                                }
                                                className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-white px-3.5 py-[9px]"
                                                style={{
                                                    fontFamily:
                                                        "'Geist', sans-serif",
                                                    fontSize: 13,
                                                    fontWeight: 500,
                                                    color: '#6B6860',
                                                }}
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Availability */}
                            <div>
                                <div
                                    style={{
                                        fontSize: 11,
                                        fontWeight: 700,
                                        textTransform: 'uppercase',
                                        letterSpacing: '0.08em',
                                        color: '#AAA89F',
                                        marginBottom: 8,
                                    }}
                                >
                                    Available days
                                </div>
                                <div className="grid grid-cols-7 gap-1">
                                    {AVAIL_DAYS_LABELS.map((d, i) => (
                                        <div
                                            key={`${d}-${i}`}
                                            onClick={() => toggleAvailDay(i)}
                                            className="flex aspect-square items-center justify-center rounded-[6px] transition-all"
                                            style={{
                                                fontSize: 10,
                                                fontWeight: 700,
                                                cursor: editMode
                                                    ? 'pointer'
                                                    : 'default',
                                                background: activeDays[i]
                                                    ? '#EBF0FD'
                                                    : '#EFEDE7',
                                                color: activeDays[i]
                                                    ? '#1A4CD4'
                                                    : '#AAA89F',
                                            }}
                                        >
                                            {d}
                                        </div>
                                    ))}
                                </div>
                                {editMode && (
                                    <div
                                        style={{
                                            fontSize: 11,
                                            color: '#AAA89F',
                                            marginTop: 6,
                                        }}
                                    >
                                        Tap days to toggle availability
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Interests */}
                        <div className="mb-2.5 flex items-baseline justify-between">
                            <div
                                style={{
                                    fontSize: 11,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.08em',
                                    color: '#AAA89F',
                                }}
                            >
                                My interests
                            </div>
                            <span
                                className="cursor-pointer"
                                style={{
                                    fontSize: 12,
                                    color: '#1A4CD4',
                                    fontWeight: 600,
                                }}
                            >
                                Edit in Profile →
                            </span>
                        </div>
                        <div className="mb-1 flex flex-wrap gap-1.5">
                            {INTERESTS_LIST.filter((i) =>
                                selectedInterests.has(i),
                            ).map((i) => (
                                <span
                                    key={i}
                                    className="rounded-full border border-[#E2DFD6] bg-[#EFEDE7] px-[11px] py-1"
                                    style={{
                                        fontSize: 12,
                                        fontWeight: 500,
                                        color: '#6B6860',
                                    }}
                                >
                                    {i}
                                </span>
                            ))}
                        </div>
                        <div
                            style={{
                                fontSize: 11,
                                color: '#AAA89F',
                                marginTop: 8,
                            }}
                        >
                            Used to match you with language partners. Edit in
                            your Profile.
                        </div>
                    </div>
                )}
            </div>

            {/* ── Bottom sheet ── */}
            <BottomSheet open={isSheetOpen} onClose={closeSheet}>
                {/* Partner detail */}
                {sheetView === 'detail' && sheetPartner && (
                    <PartnerDetailContent
                        partner={sheetPartner}
                        dropinSpots={DROPIN_SPOTS}
                        onSave={(e) => toggleSavePartner(sheetPartner.id, e)}
                        onSendRequest={handleSendRequest}
                        onSuggestMeeting={handleSuggestMeeting}
                    />
                )}

                {/* Send request view */}
                {sheetView === 'request' && sheetPartner && (
                    <SendRequestView
                        partner={sheetPartner}
                        onBack={() => setSheetView('detail')}
                        onConfirm={confirmRequest}
                    />
                )}

                {/* Suggest meeting view */}
                {sheetView === 'suggest' && sheetPartner && (
                    <SuggestMeetingView
                        partner={sheetPartner}
                        spotName={suggestSpotName}
                        dropinSpots={DROPIN_SPOTS}
                        pickedDays={pickedDays}
                        pickedTimes={pickedTimes}
                        onPickDay={(day) => {
                            setPickedDays((prev) => {
                                const next = new Set(prev);
                                if (next.has(day)) next.delete(day);
                                else next.add(day);
                                return next;
                            });
                        }}
                        onPickTime={(time) => {
                            setPickedTimes((prev) => {
                                const next = new Set(prev);
                                if (next.has(time)) next.delete(time);
                                else next.add(time);
                                return next;
                            });
                        }}
                        onBack={() => setSheetView('detail')}
                        onConfirm={confirmMeeting}
                    />
                )}

                {/* Meetup detail view */}
                {sheetView === 'meetup-detail' && sheetMeetup && (
                    <MeetupDetailView
                        meetup={sheetMeetup}
                        onJoin={() => {
                            toggleJoinMeetup(sheetMeetup.id);
                            closeSheet();
                        }}
                    />
                )}
            </BottomSheet>

            {/* Toast */}
            {toast && (
                <div
                    className="pointer-events-none fixed bottom-20 left-1/2 z-[9000] -translate-x-1/2 rounded-[14px] px-[18px] py-[11px] text-center shadow-[0_8px_24px_rgba(0,0,0,0.15)]"
                    style={{
                        background: '#18170F',
                        color: 'white',
                        fontSize: 13,
                        fontWeight: 500,
                        maxWidth: '90vw',
                        whiteSpace: 'nowrap',
                    }}
                >
                    {toast}
                </div>
            )}

            {/* Keyframe for fadeUp animation */}
            <style>{`
                @keyframes fadeUp {
                    from { opacity: 0; transform: translateY(12px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                @keyframes pulse {
                    0%, 100% { opacity: 1; transform: scale(1); }
                    50% { opacity: .4; transform: scale(.7); }
                }
            `}</style>
        </AppLayout>
    );
}

// ============================================================
// Send Request sub-view (inside bottom sheet)
// ============================================================

function SendRequestView({
    partner,
    onBack,
    onConfirm,
}: {
    partner: PartnerData;
    onBack: () => void;
    onConfirm: () => void;
}) {
    const [msg, setMsg] = useState(
        `Hi ${partner.name}! I'm Anar, originally from Azerbaijan, living in Cologne. I'm learning German (currently A2) and would love to practice with a native German speaker. In return I can help you with Azerbaijani or English. I'm usually free on weekday evenings. Would love to meet for a coffee at Cafe Schmitz sometime!`,
    );

    return (
        <>
            {/* Back + title */}
            <div className="mb-5 flex items-center gap-2.5">
                <button
                    onClick={onBack}
                    className="flex cursor-pointer items-center gap-[5px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-3 py-[7px]"
                    style={{ fontSize: 13, fontWeight: 600 }}
                >
                    ← Back
                </button>
                <div
                    style={{
                        fontFamily: "'Fraunces', serif",
                        fontSize: 18,
                        fontWeight: 500,
                    }}
                >
                    Send a request
                </div>
            </div>

            {/* Partner row */}
            <div className="mb-5 flex items-center gap-3 rounded-[14px] bg-[#EFEDE7] px-4 py-3.5">
                <div
                    className="flex size-[42px] shrink-0 items-center justify-center rounded-full"
                    style={{
                        background: partner.bg,
                        color: 'white',
                        fontSize: 16,
                        fontWeight: 700,
                    }}
                >
                    {partner.initials}
                </div>
                <div>
                    <div style={{ fontSize: 15, fontWeight: 600 }}>
                        {partner.flag} {partner.name}
                    </div>
                    <div style={{ fontSize: 12, color: '#6B6860' }}>
                        {partner.native} ⇄ {partner.learning} · {partner.level}{' '}
                        level
                    </div>
                </div>
                <div
                    className="ml-auto shrink-0 rounded-[20px] border px-[9px] py-[3px]"
                    style={{
                        fontSize: 11,
                        fontWeight: 600,
                        color: partner.online ? '#0A7C52' : '#AAA89F',
                        background: partner.online ? '#D4F0E6' : 'white',
                        borderColor: partner.online ? 'transparent' : '#E2DFD6',
                    }}
                >
                    {partner.online ? '🟢 Online' : 'Offline'}
                </div>
            </div>

            {/* Message */}
            <div
                style={{
                    fontSize: 11,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.08em',
                    color: '#AAA89F',
                    marginBottom: 8,
                }}
            >
                Your introduction
            </div>
            <textarea
                value={msg}
                onChange={(e) => setMsg(e.target.value)}
                rows={5}
                className="mb-1.5 w-full rounded-[9px] border border-[#E2DFD6] p-[13px] transition-all outline-none focus:border-[#1A4CD4] focus:shadow-[0_0_0_3px_#EBF0FD]"
                style={{
                    fontFamily: "'Geist', sans-serif",
                    fontSize: 14,
                    color: '#18170F',
                    resize: 'none',
                    lineHeight: 1.6,
                }}
            />
            <div style={{ fontSize: 11, color: '#AAA89F', marginBottom: 20 }}>
                Feel free to personalise this message before sending.
            </div>

            {/* What happens next */}
            <div className="mb-5 rounded-[9px] bg-[#EFEDE7] px-3.5 py-3">
                <div
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                        color: '#AAA89F',
                        marginBottom: 8,
                    }}
                >
                    What happens next
                </div>
                <div className="flex flex-col gap-[7px]">
                    <div
                        className="flex gap-2"
                        style={{ fontSize: 12, color: '#6B6860' }}
                    >
                        <span>1.</span>
                        <span>
                            {partner.name} receives your request and
                            introduction message
                        </span>
                    </div>
                    <div
                        className="flex gap-2"
                        style={{ fontSize: 12, color: '#6B6860' }}
                    >
                        <span>2.</span>
                        <span>
                            If they accept, you can message each other to
                            arrange a session
                        </span>
                    </div>
                    <div
                        className="flex gap-2"
                        style={{ fontSize: 12, color: '#6B6860' }}
                    >
                        <span>3.</span>
                        <span>
                            Anker suggests nearby cafes based on both your
                            locations
                        </span>
                    </div>
                </div>
            </div>

            <button
                onClick={() => {
                    if (!msg.trim()) return;
                    onConfirm();
                }}
                className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-[9px] border-none py-[13px] transition-all hover:bg-[#1540B8]"
                style={{
                    fontFamily: "'Geist', sans-serif",
                    fontSize: 15,
                    fontWeight: 600,
                    background: '#1A4CD4',
                    color: 'white',
                }}
            >
                ✉️ Send request to {partner.name}
            </button>
        </>
    );
}

// ============================================================
// Suggest Meeting sub-view (inside bottom sheet)
// ============================================================

function SuggestMeetingView({
    partner,
    spotName,
    dropinSpots,
    pickedDays,
    pickedTimes,
    onPickDay,
    onPickTime,
    onBack,
    onConfirm,
}: {
    partner: PartnerData;
    spotName: string;
    dropinSpots: DropinSpot[];
    pickedDays: Set<string>;
    pickedTimes: Set<string>;
    onPickDay: (day: string) => void;
    onPickTime: (time: string) => void;
    onBack: () => void;
    onConfirm: () => void;
}) {
    const spot = dropinSpots.find((s) => s.name === spotName) || {
        emoji: '☕',
        area: 'Cologne',
        dist: 'nearby',
        people: 0,
    };
    const days = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
    ];
    const timeSlots = [
        'Morning (9–12)',
        'Afternoon (12–17)',
        'Evening (17–21)',
    ];

    return (
        <>
            {/* Back + title */}
            <div className="mb-5 flex items-center gap-2.5">
                <button
                    onClick={onBack}
                    className="flex cursor-pointer items-center gap-[5px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-3 py-[7px]"
                    style={{ fontSize: 13, fontWeight: 600 }}
                >
                    ← Back
                </button>
                <div
                    style={{
                        fontFamily: "'Fraunces', serif",
                        fontSize: 18,
                        fontWeight: 500,
                    }}
                >
                    Suggest a meeting
                </div>
            </div>

            {/* Spot confirmation */}
            <div
                className="mb-5 flex items-center gap-3 rounded-[14px] border px-4 py-3.5"
                style={{
                    background: '#EBF0FD',
                    borderColor: 'rgba(26,76,212,.15)',
                }}
            >
                <span style={{ fontSize: 28 }}>{spot.emoji}</span>
                <div>
                    <div style={{ fontSize: 15, fontWeight: 600 }}>
                        {spotName}
                    </div>
                    <div style={{ fontSize: 12, color: '#6B6860' }}>
                        {spot.area} · {spot.dist}
                        {spot.people > 0 && (
                            <span style={{ color: '#0A7C52', fontWeight: 600 }}>
                                {' '}
                                · {spot.people} language learners here now
                            </span>
                        )}
                    </div>
                </div>
            </div>

            {/* To */}
            <div className="mb-4 flex items-center gap-2.5 rounded-[9px] bg-[#EFEDE7] px-3.5 py-3">
                <div
                    className="flex size-[34px] shrink-0 items-center justify-center rounded-full"
                    style={{
                        background: partner.bg,
                        color: 'white',
                        fontSize: 13,
                        fontWeight: 700,
                    }}
                >
                    {partner.initials}
                </div>
                <div>
                    <div style={{ fontSize: 13, fontWeight: 600 }}>
                        {partner.flag} {partner.name}
                    </div>
                    <div style={{ fontSize: 11, color: '#6B6860' }}>
                        {partner.native} ⇄ {partner.learning}
                    </div>
                </div>
            </div>

            {/* Day picker */}
            <div
                style={{
                    fontSize: 11,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.08em',
                    color: '#AAA89F',
                    marginBottom: 8,
                }}
            >
                Preferred days
            </div>
            <div className="mb-4 flex flex-wrap gap-[5px]">
                {days.map((d) => (
                    <div
                        key={d}
                        onClick={() => onPickDay(d)}
                        className="cursor-pointer rounded-full border-[1.5px] px-3 py-1.5 transition-all"
                        style={{
                            fontSize: 12,
                            fontWeight: 500,
                            background: pickedDays.has(d) ? '#1A4CD4' : 'white',
                            borderColor: pickedDays.has(d)
                                ? '#1A4CD4'
                                : '#E2DFD6',
                            color: pickedDays.has(d) ? 'white' : '#6B6860',
                        }}
                    >
                        {d}
                    </div>
                ))}
            </div>

            {/* Time picker */}
            <div
                style={{
                    fontSize: 11,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.08em',
                    color: '#AAA89F',
                    marginBottom: 8,
                }}
            >
                Preferred time slots
            </div>
            <div className="mb-5 flex flex-wrap gap-[5px]">
                {timeSlots.map((t) => (
                    <div
                        key={t}
                        onClick={() => onPickTime(t)}
                        className="cursor-pointer rounded-full border-[1.5px] px-3 py-1.5 transition-all"
                        style={{
                            fontSize: 12,
                            fontWeight: 500,
                            background: pickedTimes.has(t)
                                ? '#1A4CD4'
                                : 'white',
                            borderColor: pickedTimes.has(t)
                                ? '#1A4CD4'
                                : '#E2DFD6',
                            color: pickedTimes.has(t) ? 'white' : '#6B6860',
                        }}
                    >
                        {t}
                    </div>
                ))}
            </div>

            {/* Note */}
            <div
                style={{
                    fontSize: 11,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.08em',
                    color: '#AAA89F',
                    marginBottom: 8,
                }}
            >
                Add a note (optional)
            </div>
            <textarea
                rows={3}
                placeholder="e.g. I'm free most evenings, happy to try your favourite spot!"
                className="mb-4 w-full rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] p-[11px] outline-none"
                style={{
                    fontFamily: "'Geist', sans-serif",
                    fontSize: 13,
                    color: '#18170F',
                    resize: 'none',
                    lineHeight: 1.5,
                }}
            />

            <button
                onClick={onConfirm}
                className="w-full cursor-pointer rounded-[9px] border-none py-[13px] transition-all hover:bg-[#1540B8]"
                style={{
                    fontFamily: "'Geist', sans-serif",
                    fontSize: 15,
                    fontWeight: 600,
                    background: '#1A4CD4',
                    color: 'white',
                }}
            >
                📅 Send meeting suggestion
            </button>
        </>
    );
}

// ============================================================
// Meetup Detail sub-view (inside bottom sheet)
// ============================================================

function MeetupDetailView({
    meetup,
    onJoin,
}: {
    meetup: MeetupData;
    onJoin: () => void;
}) {
    return (
        <>
            {/* Color bar */}
            <div
                className="-mx-5 -mt-1 mb-4 h-1.5 rounded-t"
                style={{ background: meetup.color }}
            />

            <div
                style={{
                    fontFamily: "'Fraunces', serif",
                    fontSize: 22,
                    fontWeight: 500,
                    marginBottom: 6,
                }}
            >
                {meetup.title}
            </div>
            <div style={{ fontSize: 13, color: '#6B6860', marginBottom: 16 }}>
                Hosted by {meetup.hostFlag} {meetup.host}
            </div>

            {/* Details */}
            <div className="mb-4 flex flex-col gap-2">
                <div
                    className="flex items-center gap-2.5"
                    style={{ fontSize: 13 }}
                >
                    <span style={{ fontSize: 16 }}>📅</span>
                    <span>
                        {meetup.date} {meetup.month} · {meetup.time} ·{' '}
                        {meetup.duration}
                    </span>
                </div>
                <div
                    className="flex items-center gap-2.5"
                    style={{ fontSize: 13 }}
                >
                    <span style={{ fontSize: 16 }}>📍</span>
                    <span>
                        {meetup.location} · {meetup.distance}
                    </span>
                </div>
                <div
                    className="flex items-center gap-2.5"
                    style={{ fontSize: 13 }}
                >
                    <span style={{ fontSize: 16 }}>👥</span>
                    <span>
                        {meetup.attending} attending ·{' '}
                        {meetup.max - meetup.attending} spots left
                    </span>
                </div>
            </div>

            {/* Languages */}
            <div
                style={{
                    fontSize: 11,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.08em',
                    color: '#AAA89F',
                    marginBottom: 8,
                }}
            >
                Languages
            </div>
            <div className="mb-4 flex flex-wrap gap-1.5">
                {meetup.languages.map((l, i) => (
                    <span
                        key={i}
                        className="rounded-[20px] px-3 py-1"
                        style={{
                            fontSize: 13,
                            fontWeight: 600,
                            background: `${meetup.langColors[i]}22`,
                            color: meetup.langColors[i],
                        }}
                    >
                        {l}
                    </span>
                ))}
            </div>

            {/* About */}
            <div
                style={{
                    fontSize: 11,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.08em',
                    color: '#AAA89F',
                    marginBottom: 8,
                }}
            >
                About this meetup
            </div>
            <div
                style={{
                    fontSize: 14,
                    color: '#6B6860',
                    lineHeight: 1.6,
                    marginBottom: 20,
                }}
            >
                {meetup.desc}
            </div>

            {/* Actions */}
            <div className="flex gap-[9px]">
                <button
                    onClick={onJoin}
                    className="flex flex-[2] cursor-pointer items-center justify-center gap-[7px] rounded-[9px] border-none py-3 transition-all"
                    style={{
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 14,
                        fontWeight: 600,
                        background: meetup.joined ? '#D4F0E6' : '#1A4CD4',
                        color: meetup.joined ? '#0A7C52' : 'white',
                    }}
                >
                    {meetup.joined ? "✓ You're going" : '🎉 Join meetup'}
                </button>
                <button
                    onClick={() =>
                        window.open(
                            `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(meetup.location)}`,
                            '_blank',
                            'noopener',
                        )
                    }
                    className="flex flex-1 cursor-pointer items-center justify-center gap-[7px] rounded-[9px] border border-[#E2DFD6] py-3 transition-all hover:bg-[#E2DFD6]"
                    style={{
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 14,
                        fontWeight: 600,
                        background: '#EFEDE7',
                        color: '#18170F',
                    }}
                >
                    Directions ↗
                </button>
            </div>
        </>
    );
}
