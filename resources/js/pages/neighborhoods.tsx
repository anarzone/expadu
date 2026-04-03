import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { NeighborhoodCard } from '@/components/neighborhoods/neighborhood-card';
import { NeighborhoodsRightPanel } from '@/components/neighborhoods/neighborhoods-right-panel';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import { useTabState } from '@/hooks/use-tab-state';
import AppLayout from '@/layouts/app-layout';

// ============================================================
// Types
// ============================================================

export type TagStyle = { bg: string; c: string };

export type NeighborhoodData = {
    id: string;
    name: string;
    emoji: string;
    current?: boolean;
    tagline: string;
    tags: string[];
    tagStyles: TagStyle[];
    scores: {
        expat: number;
        english: number;
        transport: number;
        nightlife: number;
        quiet: number;
        affordable: number;
        green: number;
        family: number;
    };
    rent: string;
    expats: string;
    transit: string;
    rating: number;
    saved: boolean;
    filters: string[];
    desc: string;
    pros: string[];
    cons: string[];
    bestFor: string[];
    tips: { text: string; author: string }[];
};

type QuizOption = {
    ico: string;
    text: string;
    sub: string;
    val: Record<string, number>;
};

type QuizQuestion = {
    q: string;
    opts: QuizOption[];
};

// ============================================================
// Hardcoded prototype data
// ============================================================

const SEED_NEIGHBORHOODS: NeighborhoodData[] = [
    {
        id: 'ehrenfeld',
        name: 'Ehrenfeld',
        emoji: '🎨',
        current: true,
        tagline:
            'Creative, diverse, and full of life. The expat heartland of Cologne.',
        tags: ['Creative', 'Diverse', 'Expat hub', 'Mid-range'],
        tagStyles: [
            { bg: '#EDE9FE', c: '#7C3AED' },
            { bg: '#EBF0FD', c: '#1A4CD4' },
            { bg: '#D4F0E6', c: '#0A7C52' },
            { bg: '#EFEDE7', c: '#6B6860' },
        ],
        scores: {
            expat: 92,
            english: 85,
            transport: 88,
            nightlife: 80,
            quiet: 40,
            affordable: 62,
            green: 50,
            family: 55,
        },
        rent: '€14/m²',
        expats: '~35%',
        transit: 'Line 3, 4, 13',
        rating: 4.7,
        saved: false,
        filters: ['expat', 'lively'],
        desc: "Ehrenfeld is Cologne's most expat-friendly district. Former industrial area transformed into a creative hub with independent cafés, galleries, and a thriving international community. Home to the legendary Neptunbad pool and some of the city's best street art.",
        pros: [
            'Huge expat community — easy to make friends',
            'Excellent café culture and coworking spots',
            'Good transit connections to city centre',
            'Lower rents than Innenstadt or Belgisches Viertel',
        ],
        cons: [
            'Can be noisy on weekends — active nightlife',
            'Less green space than Nippes or Sülz',
            'Gentrifying — rents rising year by year',
        ],
        bestFor: [
            'Young professionals',
            'First-time Cologne expats',
            'Language exchange seekers',
            'Remote workers',
        ],
        tips: [
            {
                text: '"The Saturday market on Venloer Str. is unmissable — best bread in Cologne."',
                author: 'Sarah K.',
            },
            {
                text: '"Neptunbad swimming pool is a hidden gem — locals only know about it."',
                author: 'Anna B.',
            },
        ],
    },
    {
        id: 'belgisches',
        name: 'Belgisches Viertel',
        emoji: '🌿',
        tagline:
            "Cologne's most stylish quarter. Boutiques, cafés, and beautiful Gründerzeit buildings.",
        tags: ['Trendy', 'Cafés', 'Upmarket', 'Central'],
        tagStyles: [
            { bg: '#FDF0D4', c: '#C47D0E' },
            { bg: '#EFEDE7', c: '#6B6860' },
            { bg: '#EDE9FE', c: '#7C3AED' },
            { bg: '#EBF0FD', c: '#1A4CD4' },
        ],
        scores: {
            expat: 78,
            english: 80,
            transport: 85,
            nightlife: 75,
            quiet: 55,
            affordable: 30,
            green: 60,
            family: 60,
        },
        rent: '€16/m²',
        expats: '~25%',
        transit: 'Line 1, 7, 9',
        rating: 4.6,
        saved: false,
        filters: ['expat', 'lively', 'central'],
        desc: 'The most aesthetically striking neighborhood in Cologne — wide tree-lined streets, Art Nouveau architecture, and independent boutiques. A little pricier, but the quality of life is exceptional. Great base if you work in the city centre.',
        pros: [
            'Beautiful architecture and streets',
            'Excellent restaurant and café scene',
            'Very walkable to city centre',
            'Strong English-speaking community',
        ],
        cons: [
            'Most expensive rents in the guide',
            'Very little parking',
            'Can feel a bit bubble-like',
        ],
        bestFor: [
            'Professionals with higher budgets',
            'Culture lovers',
            'Foodies',
            'Short-term rentals',
        ],
        tips: [
            {
                text: '"Hallmackenreuther on Brüsseler Platz is the best terrace in Cologne."',
                author: 'Claire D.',
            },
        ],
    },
    {
        id: 'nippes',
        name: 'Nippes',
        emoji: '🏡',
        tagline:
            'Residential, leafy, and genuinely local. A real Cologne neighborhood without the tourist gloss.',
        tags: ['Residential', 'Family', 'Affordable', 'Green'],
        tagStyles: [
            { bg: '#D4F0E6', c: '#0A7C52' },
            { bg: '#EBF0FD', c: '#1A4CD4' },
            { bg: '#EFEDE7', c: '#6B6860' },
            { bg: '#D4F0E6', c: '#0A7C52' },
        ],
        scores: {
            expat: 55,
            english: 60,
            transport: 80,
            nightlife: 45,
            quiet: 75,
            affordable: 80,
            green: 82,
            family: 88,
        },
        rent: '€13/m²',
        expats: '~15%',
        transit: 'Line 12, 15, 16',
        rating: 4.4,
        saved: false,
        filters: ['affordable', 'quiet'],
        desc: 'Nippes is where Cologne locals actually live. Less polished than Belgisches Viertel but more authentic — local butchers, Turkish bakeries, and community gardens. Great for families and anyone who wants to feel genuinely embedded in German city life.',
        pros: [
            'Most affordable rents of central areas',
            'Very family-friendly with parks and schools',
            'Authentic local character — few tourists',
            'Good S-Bahn and tram connections',
        ],
        cons: [
            'Fewer English speakers than Ehrenfeld',
            'Less expat community to connect with',
            'Quieter nightlife — better for families',
        ],
        bestFor: [
            'Families with children',
            'Budget-conscious expats',
            'Those wanting authentic German experience',
            'Long-term settlers',
        ],
        tips: [
            {
                text: '"The Nippes market on Saturday morning is the most local experience in the city."',
                author: 'Mehmet A.',
            },
        ],
    },
    {
        id: 'deutz',
        name: 'Deutz',
        emoji: '🌉',
        tagline:
            'Across the Rhine with stunning Dom views. Up-and-coming and more affordable.',
        tags: ['Up-and-coming', 'Views', 'Affordable', 'Convenient'],
        tagStyles: [
            { bg: '#EBF0FD', c: '#1A4CD4' },
            { bg: '#FDF0D4', c: '#C47D0E' },
            { bg: '#EFEDE7', c: '#6B6860' },
            { bg: '#D4F0E6', c: '#0A7C52' },
        ],
        scores: {
            expat: 45,
            english: 55,
            transport: 88,
            nightlife: 40,
            quiet: 65,
            affordable: 78,
            green: 55,
            family: 60,
        },
        rent: '€13/m²',
        expats: '~12%',
        transit: 'S-Bahn, Line 1 direct',
        rating: 4.1,
        saved: false,
        filters: ['affordable', 'central'],
        desc: "Deutz sits directly across the Rhine from the Dom and offers some of Cologne's best views at significantly lower rents. The Koelnmesse brings international visitors, and the area is actively being developed. A smart bet for expats who want central access without Innenstadt prices.",
        pros: [
            'Stunning Rhine and Dom views',
            'Direct S-Bahn to airport and city',
            'Lower rents than west-bank areas',
            'Improving restaurant and café scene',
        ],
        cons: [
            'Still developing — fewer amenities',
            'Smaller expat community',
            'Less vibrant street life currently',
        ],
        bestFor: [
            'Budget-conscious professionals',
            'Airport commuters',
            'Those who work in Koelnmesse area',
            'Anyone who values views',
        ],
        tips: [
            {
                text: '"Watch the sunset from the Deutzer Bridge — best free view in Cologne."',
                author: 'Carlos M.',
            },
        ],
    },
    {
        id: 'suelz',
        name: 'Sülz',
        emoji: '🌳',
        tagline:
            'Green, student-friendly, and calm. Ideal for university expats and families.',
        tags: ['Student', 'Green', 'Calm', 'University'],
        tagStyles: [
            { bg: '#D4F0E6', c: '#0A7C52' },
            { bg: '#EFEDE7', c: '#6B6860' },
            { bg: '#EBF0FD', c: '#1A4CD4' },
            { bg: '#EDE9FE', c: '#7C3AED' },
        ],
        scores: {
            expat: 60,
            english: 72,
            transport: 70,
            nightlife: 50,
            quiet: 80,
            affordable: 72,
            green: 90,
            family: 85,
        },
        rent: '€13/m²',
        expats: '~20%',
        transit: 'Line 9, 18',
        rating: 4.3,
        saved: false,
        filters: ['quiet', 'expat'],
        desc: "Sülz is one of Cologne's greenest and most pleasant residential areas, stretching towards the university and Stadtwald forest. Popular with students, academics, and young families. The international university community means a good level of English and an open, multicultural atmosphere.",
        pros: [
            'Very green — direct access to Stadtwald',
            'Strong international student community',
            'Quiet and safe — great for families',
            'Proximity to University of Cologne',
        ],
        cons: [
            'Further from nightlife and social scene',
            'Less vibrant than Ehrenfeld or Belgisches Viertel',
            'Fewer coworking options',
        ],
        bestFor: [
            'University students and academics',
            'Families with young children',
            'Nature lovers',
            'Those who work from home',
        ],
        tips: [
            {
                text: '"The Stadtwald is Cologne\'s best-kept secret — 4km² of forest 10 min from centre."',
                author: 'Yuki T.',
            },
        ],
    },
    {
        id: 'innenstadt',
        name: 'Innenstadt',
        emoji: '🏛️',
        tagline:
            'Right in the heart of it all. Highest rents, maximum convenience.',
        tags: ['Central', 'Tourist', 'Expensive', 'Convenient'],
        tagStyles: [
            { bg: '#EBF0FD', c: '#1A4CD4' },
            { bg: '#FDF0D4', c: '#C47D0E' },
            { bg: '#FDE8E6', c: '#C4271A' },
            { bg: '#D4F0E6', c: '#0A7C52' },
        ],
        scores: {
            expat: 50,
            english: 88,
            transport: 95,
            nightlife: 85,
            quiet: 25,
            affordable: 15,
            green: 30,
            family: 35,
        },
        rent: '€17/m²',
        expats: '~18%',
        transit: 'All lines',
        rating: 4.0,
        saved: false,
        filters: ['central', 'lively'],
        desc: 'Living in the Innenstadt means maximum convenience and the Dom on your doorstep — but also maximum cost and noise. Best for short stays or executives on corporate housing budgets. Most long-term expats end up preferring the character of surrounding districts.',
        pros: [
            'Unbeatable central location',
            'All transit lines',
            'Walking distance to everything',
            'Best English-language services',
        ],
        cons: [
            'Most expensive rents in Cologne',
            'Very touristy — can feel impersonal',
            'Noisy, especially on weekends and Karneval',
            'Small apartments for the price',
        ],
        bestFor: [
            'Short-term residents',
            'Corporate relocations',
            'Those who travel frequently',
            'Absolute city-centre lifestyle',
        ],
        tips: [
            {
                text: '"Stay for 3 months, then move to Ehrenfeld. Everyone does this."',
                author: 'Anna B.',
            },
        ],
    },
];

const QUIZ_QUESTIONS: QuizQuestion[] = [
    {
        q: 'What matters most to you day-to-day?',
        opts: [
            {
                ico: '☕',
                text: 'Great cafés and social scene',
                sub: 'Meeting people, working remotely',
                val: { lively: 2, expat: 1 },
            },
            {
                ico: '🌳',
                text: 'Green space and quiet streets',
                sub: 'Parks, running, calm evenings',
                val: { quiet: 2, affordable: 1 },
            },
            {
                ico: '🚇',
                text: 'Fast commute to work',
                sub: 'Near transit, central location',
                val: { central: 2 },
            },
            {
                ico: '💶',
                text: 'Keeping costs down',
                sub: 'Affordable rent is the priority',
                val: { affordable: 2 },
            },
        ],
    },
    {
        q: 'What kind of expat experience are you looking for?',
        opts: [
            {
                ico: '🌍',
                text: 'Big expat community',
                sub: 'English everywhere, easy to connect',
                val: { expat: 2 },
            },
            {
                ico: '🇩🇪',
                text: 'Authentic German life',
                sub: 'Few tourists, real local feel',
                val: { quiet: 1, affordable: 1 },
            },
            {
                ico: '⚖️',
                text: 'A balance of both',
                sub: 'Some expats, some locals',
                val: { expat: 1, lively: 1 },
            },
            {
                ico: '🏡',
                text: 'Family-oriented area',
                sub: 'Safe, schools, parks',
                val: { quiet: 2, affordable: 1 },
            },
        ],
    },
    {
        q: 'How important is nightlife and going out?',
        opts: [
            {
                ico: '🎵',
                text: 'Very — I want to be out most evenings',
                sub: 'Bars, concerts, late nights',
                val: { lively: 2, central: 1 },
            },
            {
                ico: '🍷',
                text: 'Occasionally — weekends mostly',
                sub: 'Good restaurants, some bars',
                val: { lively: 1, expat: 1 },
            },
            {
                ico: '📚',
                text: 'Not much — I prefer quiet evenings',
                sub: 'Restaurants, cinema, early nights',
                val: { quiet: 2 },
            },
            {
                ico: '👶',
                text: 'Not at all — I have kids',
                sub: 'Playgrounds over pubs',
                val: { quiet: 2, affordable: 1 },
            },
        ],
    },
    {
        q: "What's your rent budget per month?",
        opts: [
            {
                ico: '💸',
                text: 'Under €700 cold',
                sub: 'Very budget-conscious',
                val: { affordable: 3 },
            },
            {
                ico: '💶',
                text: '€700–€1,100',
                sub: 'Reasonable budget',
                val: { affordable: 2, quiet: 1 },
            },
            {
                ico: '💰',
                text: '€1,100–€1,600',
                sub: 'Mid-range',
                val: { lively: 1, expat: 1 },
            },
            {
                ico: '🏆',
                text: 'No strong preference',
                sub: 'Location beats price',
                val: { central: 1, lively: 1 },
            },
        ],
    },
];

const FILTER_WEIGHTS: Record<string, Record<string, number>> = {
    ehrenfeld: { expat: 3, lively: 2, affordable: 1 },
    belgisches: { expat: 2, lively: 2, central: 2 },
    nippes: { affordable: 3, quiet: 2 },
    deutz: { affordable: 2, central: 2 },
    suelz: { quiet: 3, expat: 1 },
    innenstadt: { central: 3, lively: 1 },
};

const FILTERS = [
    { id: 'all', label: 'All' },
    { id: 'expat', label: 'Expat-friendly' },
    { id: 'affordable', label: 'Affordable' },
    { id: 'lively', label: 'Lively' },
    { id: 'quiet', label: 'Quiet' },
    { id: 'central', label: 'Central' },
];

const TABS = [
    { id: 'explore', label: 'Explore' },
    { id: 'compare', label: 'Compare' },
    { id: 'quiz', label: 'Find mine' },
];

// ============================================================
// Page Component
// ============================================================

export default function Neighborhoods() {
    const [neighborhoods, setNeighborhoods] =
        useState<NeighborhoodData[]>(SEED_NEIGHBORHOODS);
    const [activeTab, setActiveTab] = useTabState('explore');
    const [activeFilter, setActiveFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [sheetContent, setSheetContent] = useState<'detail' | 'picker'>(
        'detail',
    );
    const [selectedNeighborhood, setSelectedNeighborhood] =
        useState<NeighborhoodData | null>(null);

    // Compare state
    const [compareA, setCompareA] = useState<string | null>(null);
    const [compareB, setCompareB] = useState<string | null>(null);
    const [pickerSlot, setPickerSlot] = useState<'a' | 'b'>('a');

    // Quiz state
    const [quizStep, setQuizStep] = useState(-1); // -1 = intro, 0-3 = questions, 4 = result
    const [quizAnswers, setQuizAnswers] = useState<(number | undefined)[]>([]);
    const [quizScores, setQuizScores] = useState<Record<string, number>>({});

    // ── Filtering ──
    const filtered = useMemo(() => {
        return neighborhoods.filter((n) => {
            const matchFilter =
                activeFilter === 'all' || n.filters.includes(activeFilter);
            const q = search.toLowerCase().trim();
            const matchSearch =
                !q ||
                n.name.toLowerCase().includes(q) ||
                n.tagline.toLowerCase().includes(q) ||
                n.tags.some((t) => t.toLowerCase().includes(q));

            return matchFilter && matchSearch;
        });
    }, [neighborhoods, activeFilter, search]);

    // ── Handlers ──
    function toggleSave(id: string) {
        setNeighborhoods((prev) =>
            prev.map((n) => (n.id === id ? { ...n, saved: !n.saved } : n)),
        );
    }

    function openDetail(id: string) {
        const n = neighborhoods.find((x) => x.id === id);

        if (!n) {
return;
}

        setSelectedNeighborhood(n);
        setSheetContent('detail');
        setSheetOpen(true);
    }

    function openPicker(slot: 'a' | 'b') {
        setPickerSlot(slot);
        setSheetContent('picker');
        setSheetOpen(true);
    }

    function selectForCompare(id: string) {
        if (pickerSlot === 'a') {
setCompareA(id);
} else {
setCompareB(id);
}

        setSheetOpen(false);
    }

    function clearSlot(slot: 'a' | 'b') {
        if (slot === 'a') {
setCompareA(null);
} else {
setCompareB(null);
}
    }

    // ── Quiz handlers ──
    function startQuiz() {
        setQuizStep(0);
        setQuizScores({});
        setQuizAnswers([]);
    }

    function selectQuizOption(idx: number) {
        const newAnswers = [...quizAnswers];
        newAnswers[quizStep] = idx;
        setQuizAnswers(newAnswers);
    }

    function quizNext() {
        if (quizAnswers[quizStep] === undefined) {
return;
}

        if (quizStep < QUIZ_QUESTIONS.length - 1) {
            setQuizStep(quizStep + 1);
        } else {
            // Compute scores
            const scores: Record<string, number> = {};
            quizAnswers.forEach((ansIdx, stepIdx) => {
                if (ansIdx === undefined) {
return;
}

                const vals = QUIZ_QUESTIONS[stepIdx].opts[ansIdx].val;
                Object.entries(vals).forEach(([k, v]) => {
                    scores[k] = (scores[k] || 0) + v;
                });
            });
            setQuizScores(scores);
            setQuizStep(4);
        }
    }

    function quizBack() {
        if (quizStep > 0) {
setQuizStep(quizStep - 1);
}
    }

    // ── Quiz result computation ──
    const quizResults = useMemo(() => {
        if (quizStep !== 4) {
return { top: null, runner: null };
}

        const scored = Object.entries(FILTER_WEIGHTS)
            .map(([id, weights]) => {
                let score = 0;
                Object.entries(weights).forEach(([factor, weight]) => {
                    score += (quizScores[factor] || 0) * weight;
                });

                return { id, score };
            })
            .sort((a, b) => b.score - a.score);

        return {
            top: neighborhoods.find((n) => n.id === scored[0].id) ?? null,
            runner: neighborhoods.find((n) => n.id === scored[1].id) ?? null,
        };
    }, [quizStep, quizScores, neighborhoods]);

    // Keep sheet detail in sync
    const sheetNeighborhood = selectedNeighborhood
        ? (neighborhoods.find((n) => n.id === selectedNeighborhood.id) ??
          selectedNeighborhood)
        : null;

    // Compare neighborhoods
    const compA = compareA
        ? neighborhoods.find((n) => n.id === compareA)
        : null;
    const compB = compareB
        ? neighborhoods.find((n) => n.id === compareB)
        : null;

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Neighborhoods', href: '/neighborhoods' }]}
            rightPanel={<NeighborhoodsRightPanel />}
        >
            <Head title="Neighborhoods" />
            <div className="mx-auto w-full max-w-[680px]">
                {/* ── Sticky header: title + pill tabs ── */}
                <div
                    className="sticky top-0 z-50 flex items-center justify-between border-b border-[#E2DFD6] px-6 py-3.5"
                    style={{
                        background: 'rgba(246,245,241,.94)',
                        backdropFilter: 'blur(16px)',
                    }}
                >
                    <span
                        className="shrink-0"
                        style={{
                            fontFamily: "'Fraunces', serif",
                            fontSize: 20,
                            fontWeight: 500,
                            letterSpacing: '-0.01em',
                        }}
                    >
                        Neighborhoods
                    </span>
                    <div
                        className="flex gap-1 rounded-full p-[3px]"
                        style={{ background: '#EFEDE7' }}
                    >
                        {TABS.map((t) => (
                            <button
                                key={t.id}
                                onClick={() => setActiveTab(t.id)}
                                className="cursor-pointer rounded-full border-none px-3.5 py-1.5 transition-all"
                                style={{
                                    fontSize: 12,
                                    fontWeight: 600,
                                    fontFamily: "'Geist', sans-serif",
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

                {/* ════ EXPLORE TAB ════ */}
                {activeTab === 'explore' && (
                    <>
                        {/* Search + Filters */}
                        <div className="border-b border-[#E2DFD6] px-6 pt-3.5 pb-3">
                            <div className="mb-2.5 flex cursor-text items-center gap-[9px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-[#1A4CD4] focus-within:bg-white focus-within:shadow-[0_0_0_3px_#EBF0FD]">
                                <span
                                    style={{ fontSize: 15, color: '#AAA89F' }}
                                >
                                    🔍
                                </span>
                                <input
                                    type="text"
                                    placeholder="Search neighborhoods…"
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
                            <div
                                className="flex gap-1.5 overflow-x-auto"
                                style={{ scrollbarWidth: 'none' }}
                            >
                                {FILTERS.map((f) => (
                                    <button
                                        key={f.id}
                                        onClick={() => setActiveFilter(f.id)}
                                        className="shrink-0 cursor-pointer rounded-full border px-3 py-[5px] transition-all"
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

                        {/* Neighborhood cards */}
                        <div className="px-6 py-4">
                            {filtered.length === 0 ? (
                                <div
                                    className="py-10 text-center"
                                    style={{ color: '#AAA89F' }}
                                >
                                    <div
                                        style={{
                                            fontSize: 36,
                                            marginBottom: 12,
                                        }}
                                    >
                                        🔍
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 14,
                                            fontWeight: 600,
                                            color: '#6B6860',
                                        }}
                                    >
                                        No neighborhoods found
                                    </div>
                                </div>
                            ) : (
                                filtered.map((n, i) => (
                                    <NeighborhoodCard
                                        key={n.id}
                                        neighborhood={n}
                                        onSave={() => toggleSave(n.id)}
                                        onClick={() => openDetail(n.id)}
                                        index={i}
                                    />
                                ))
                            )}
                        </div>
                    </>
                )}

                {/* ════ COMPARE TAB ════ */}
                {activeTab === 'compare' && (
                    <div className="px-6 py-5">
                        {/* Section header */}
                        <div className="mb-4 flex items-center gap-2">
                            <div className="h-px flex-1 bg-[#E2DFD6]" />
                            <span
                                style={{
                                    fontSize: 11,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.08em',
                                    color: '#AAA89F',
                                }}
                            >
                                Compare neighborhoods
                            </span>
                            <div className="h-px flex-1 bg-[#E2DFD6]" />
                        </div>

                        {/* Picker slots */}
                        <div className="mb-5 flex gap-2.5">
                            <CompareSlot
                                neighborhood={compA}
                                onClick={() => openPicker('a')}
                                onClear={() => clearSlot('a')}
                            />
                            <div
                                className="flex items-center"
                                style={{
                                    fontSize: 20,
                                    color: '#AAA89F',
                                    fontWeight: 300,
                                }}
                            >
                                vs
                            </div>
                            <CompareSlot
                                neighborhood={compB}
                                onClick={() => openPicker('b')}
                                onClear={() => clearSlot('b')}
                            />
                        </div>

                        {/* Compare table */}
                        {compareA && compareB && compA && compB ? (
                            <CompareTable a={compA} b={compB} />
                        ) : compareA || compareB ? (
                            <div
                                className="py-6 text-center"
                                style={{ fontSize: 13, color: '#AAA89F' }}
                            >
                                Pick a second neighborhood to compare
                            </div>
                        ) : null}
                    </div>
                )}

                {/* ════ QUIZ TAB ════ */}
                {activeTab === 'quiz' && (
                    <div className="px-6 py-5">
                        {quizStep === -1 && (
                            <>
                                {/* Hero */}
                                <div
                                    className="relative mb-5 overflow-hidden rounded-[20px] p-6 text-white"
                                    style={{
                                        background:
                                            'linear-gradient(135deg, #1B3A8A, #1A4CD4)',
                                    }}
                                >
                                    <div
                                        className="pointer-events-none absolute rounded-full"
                                        style={{
                                            top: -40,
                                            right: -40,
                                            width: 160,
                                            height: 160,
                                            background: 'rgba(255,255,255,.06)',
                                        }}
                                    />
                                    <div
                                        className="relative z-[1]"
                                        style={{
                                            fontFamily: "'Fraunces', serif",
                                            fontSize: 20,
                                            fontWeight: 500,
                                            marginBottom: 6,
                                        }}
                                    >
                                        Find your neighborhood
                                    </div>
                                    <div
                                        className="relative z-[1]"
                                        style={{
                                            fontSize: 13,
                                            opacity: 0.8,
                                            marginBottom: 16,
                                            lineHeight: 1.5,
                                        }}
                                    >
                                        Answer 4 quick questions and we'll match
                                        you to the Cologne neighborhood that
                                        fits your lifestyle best.
                                    </div>
                                    <button
                                        onClick={startQuiz}
                                        className="relative z-[1] cursor-pointer rounded-[9px] border-none px-5 py-2.5 transition-all hover:-translate-y-px hover:shadow-[0_4px_12px_rgba(0,0,0,0.15)]"
                                        style={{
                                            background: 'white',
                                            color: '#1A4CD4',
                                            fontSize: 13,
                                            fontWeight: 700,
                                            fontFamily: "'Geist', sans-serif",
                                        }}
                                    >
                                        Start quiz →
                                    </button>
                                </div>
                                <div
                                    style={{
                                        fontSize: 13,
                                        color: '#6B6860',
                                        lineHeight: 1.6,
                                    }}
                                >
                                    We'll consider your priorities — budget,
                                    nightlife, family needs, expat community,
                                    and more — to find the right area for you.
                                </div>
                            </>
                        )}

                        {quizStep >= 0 && quizStep < QUIZ_QUESTIONS.length && (
                            <QuizStep
                                question={QUIZ_QUESTIONS[quizStep]}
                                step={quizStep}
                                totalSteps={QUIZ_QUESTIONS.length}
                                selectedIdx={quizAnswers[quizStep]}
                                onSelect={selectQuizOption}
                                onNext={quizNext}
                                onBack={quizBack}
                            />
                        )}

                        {quizStep === 4 &&
                            quizResults.top &&
                            quizResults.runner && (
                                <QuizResult
                                    top={quizResults.top}
                                    runner={quizResults.runner}
                                    onExplore={(id) => openDetail(id)}
                                    onRetake={startQuiz}
                                />
                            )}
                    </div>
                )}
            </div>

            {/* ── Bottom Sheet ── */}
            <BottomSheet open={sheetOpen} onClose={() => setSheetOpen(false)}>
                {sheetContent === 'detail' && sheetNeighborhood && (
                    <DetailContent neighborhood={sheetNeighborhood} />
                )}
                {sheetContent === 'picker' && (
                    <PickerContent
                        neighborhoods={neighborhoods}
                        onSelect={selectForCompare}
                    />
                )}
            </BottomSheet>
        </AppLayout>
    );
}

// ============================================================
// Compare Slot
// ============================================================

function CompareSlot({
    neighborhood,
    onClick,
    onClear,
}: {
    neighborhood: NeighborhoodData | null | undefined;
    onClick: () => void;
    onClear: () => void;
}) {
    if (neighborhood) {
        return (
            <div
                onClick={onClick}
                className="flex flex-1 cursor-pointer flex-col items-center justify-center gap-1.5 rounded-[14px] transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD]"
                style={{
                    background: 'white',
                    border: '1.5px solid #E2DFD6',
                    padding: 14,
                    textAlign: 'center',
                    minHeight: 80,
                }}
            >
                <span style={{ fontSize: 24 }}>{neighborhood.emoji}</span>
                <span style={{ fontSize: 13, fontWeight: 600 }}>
                    {neighborhood.name}
                </span>
                <span
                    onClick={(e) => {
                        e.stopPropagation();
                        onClear();
                    }}
                    className="cursor-pointer"
                    style={{
                        fontSize: 10,
                        color: '#C4271A',
                        fontWeight: 600,
                        marginTop: 2,
                    }}
                >
                    ✕ Remove
                </span>
            </div>
        );
    }

    return (
        <div
            onClick={onClick}
            className="flex flex-1 cursor-pointer flex-col items-center justify-center gap-1.5 rounded-[14px] transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD]"
            style={{
                background: '#EFEDE7',
                border: '1.5px dashed #E2DFD6',
                padding: 14,
                textAlign: 'center',
                minHeight: 80,
            }}
        >
            <span style={{ fontSize: 24 }}>＋</span>
            <span style={{ fontSize: 12, color: '#AAA89F' }}>
                Pick a neighborhood
            </span>
        </div>
    );
}

// ============================================================
// Compare Table
// ============================================================

type CompareRow = {
    label: string;
    keyA: string;
    format: (v: number | string) => string;
    higher: boolean;
};

const COMPARE_ROWS: CompareRow[] = [
    {
        label: '⭐ Rating',
        keyA: 'rating',
        format: (v) => `★ ${v}`,
        higher: true,
    },
    {
        label: '💶 Avg rent',
        keyA: 'rent',
        format: (v) => String(v),
        higher: false,
    },
    {
        label: '👥 Expats',
        keyA: 'expats',
        format: (v) => String(v),
        higher: true,
    },
    {
        label: '🌍 Expat score',
        keyA: 'scores.expat',
        format: (v) => `${v}/100`,
        higher: true,
    },
    {
        label: '🇬🇧 English',
        keyA: 'scores.english',
        format: (v) => `${v}/100`,
        higher: true,
    },
    {
        label: '🚇 Transport',
        keyA: 'scores.transport',
        format: (v) => `${v}/100`,
        higher: true,
    },
    {
        label: '🔇 Quiet',
        keyA: 'scores.quiet',
        format: (v) => `${v}/100`,
        higher: true,
    },
    {
        label: '🌿 Green',
        keyA: 'scores.green',
        format: (v) => `${v}/100`,
        higher: true,
    },
    {
        label: '💰 Affordable',
        keyA: 'scores.affordable',
        format: (v) => `${v}/100`,
        higher: true,
    },
];

function getNestedVal(obj: Record<string, unknown>, path: string): unknown {
    return path.split('.').reduce((o: unknown, k: string) => {
        if (o && typeof o === 'object') {
return (o as Record<string, unknown>)[k];
}

        return undefined;
    }, obj);
}

function CompareTable({ a, b }: { a: NeighborhoodData; b: NeighborhoodData }) {
    return (
        <div className="overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
            {/* Header */}
            <div
                className="grid border-b border-[#E2DFD6]"
                style={{
                    gridTemplateColumns: '120px 1fr 1fr',
                    background: '#EFEDE7',
                }}
            >
                <div
                    className="px-3.5 py-3"
                    style={{ fontSize: 11, color: '#AAA89F' }}
                >
                    Metric
                </div>
                <div
                    className="px-3.5 py-3"
                    style={{ fontSize: 12, fontWeight: 700 }}
                >
                    {a.emoji} {a.name}
                </div>
                <div
                    className="px-3.5 py-3"
                    style={{ fontSize: 12, fontWeight: 700 }}
                >
                    {b.emoji} {b.name}
                </div>
            </div>

            {/* Rows */}
            {COMPARE_ROWS.map((row, i) => {
                const av = getNestedVal(
                    a as unknown as Record<string, unknown>,
                    row.keyA,
                );
                const bv = getNestedVal(
                    b as unknown as Record<string, unknown>,
                    row.keyA,
                );
                const aNum = parseFloat(String(av).replace(/[^0-9.]/g, ''));
                const bNum = parseFloat(String(bv).replace(/[^0-9.]/g, ''));
                const aWins = row.higher ? aNum > bNum : aNum < bNum;
                const bWins = row.higher ? bNum > aNum : bNum < aNum;

                return (
                    <div
                        key={i}
                        className="grid transition-colors hover:bg-[#EFEDE7]"
                        style={{
                            gridTemplateColumns: '120px 1fr 1fr',
                            borderBottom:
                                i < COMPARE_ROWS.length - 1
                                    ? '1px solid #E2DFD6'
                                    : 'none',
                        }}
                    >
                        <div
                            className="flex items-center gap-1.5 px-3.5 py-[11px]"
                            style={{
                                fontSize: 12,
                                fontWeight: 600,
                                color: '#6B6860',
                            }}
                        >
                            {row.label}
                        </div>
                        <div
                            className="flex items-center gap-1.5 px-3.5 py-[11px]"
                            style={{
                                fontSize: 13,
                                fontWeight: aWins ? 700 : 500,
                                color: aWins ? '#0A7C52' : undefined,
                            }}
                        >
                            {row.format(av as number | string)}
                        </div>
                        <div
                            className="flex items-center gap-1.5 px-3.5 py-[11px]"
                            style={{
                                fontSize: 13,
                                fontWeight: bWins ? 700 : 500,
                                color: bWins ? '#0A7C52' : undefined,
                            }}
                        >
                            {row.format(bv as number | string)}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

// ============================================================
// Quiz Step
// ============================================================

function QuizStep({
    question,
    step,
    totalSteps,
    selectedIdx,
    onSelect,
    onNext,
    onBack,
}: {
    question: QuizQuestion;
    step: number;
    totalSteps: number;
    selectedIdx: number | undefined;
    onSelect: (idx: number) => void;
    onNext: () => void;
    onBack: () => void;
}) {
    return (
        <div>
            {/* Progress dots */}
            <div className="mb-5 flex gap-1">
                {Array.from({ length: totalSteps }).map((_, i) => (
                    <div
                        key={i}
                        className="flex-1 rounded-[2px]"
                        style={{
                            height: 4,
                            background:
                                i < step
                                    ? '#1A4CD4'
                                    : i === step
                                      ? '#EBF0FD'
                                      : '#E2DFD6',
                            border: i === step ? '1px solid #1A4CD4' : 'none',
                            transition: 'background 0.3s',
                        }}
                    />
                ))}
            </div>

            <div
                style={{
                    fontSize: 11,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.08em',
                    color: '#AAA89F',
                    marginBottom: 6,
                }}
            >
                Question {step + 1} of {totalSteps}
            </div>

            <div
                style={{
                    fontFamily: "'Fraunces', serif",
                    fontSize: 20,
                    fontWeight: 400,
                    marginBottom: 16,
                    lineHeight: 1.3,
                }}
            >
                {question.q}
            </div>

            {/* Options */}
            <div className="flex flex-col gap-2">
                {question.opts.map((o, i) => (
                    <div
                        key={i}
                        onClick={() => onSelect(i)}
                        className="flex cursor-pointer items-center gap-3 rounded-[14px] transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD]"
                        style={{
                            padding: '13px 16px',
                            border:
                                selectedIdx === i
                                    ? '1.5px solid #1A4CD4'
                                    : '1.5px solid #E2DFD6',
                            background: selectedIdx === i ? '#EBF0FD' : 'white',
                        }}
                    >
                        <span className="shrink-0" style={{ fontSize: 20 }}>
                            {o.ico}
                        </span>
                        <div>
                            <div style={{ fontSize: 14, fontWeight: 500 }}>
                                {o.text}
                            </div>
                            <div style={{ fontSize: 12, color: '#6B6860' }}>
                                {o.sub}
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            {/* Navigation */}
            {selectedIdx !== undefined && (
                <div className="mt-4 flex gap-2">
                    {step > 0 && (
                        <button
                            onClick={onBack}
                            className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-[11px] transition-all"
                            style={{
                                fontFamily: "'Geist', sans-serif",
                                fontSize: 13,
                                fontWeight: 600,
                            }}
                        >
                            ← Back
                        </button>
                    )}
                    <button
                        onClick={onNext}
                        className="flex-1 cursor-pointer rounded-[9px] border-none py-[11px] text-white transition-all"
                        style={{
                            background: '#1A4CD4',
                            fontFamily: "'Geist', sans-serif",
                            fontSize: 14,
                            fontWeight: 600,
                        }}
                    >
                        {step === totalSteps - 1 ? 'See my match →' : 'Next →'}
                    </button>
                </div>
            )}
        </div>
    );
}

// ============================================================
// Quiz Result
// ============================================================

function QuizResult({
    top,
    runner,
    onExplore,
    onRetake,
}: {
    top: NeighborhoodData;
    runner: NeighborhoodData;
    onExplore: (id: string) => void;
    onRetake: () => void;
}) {
    return (
        <div>
            {/* Match hero */}
            <div
                className="mb-4 rounded-[20px] p-5 text-center text-white"
                style={{
                    background: 'linear-gradient(135deg, #0A7C52, #059669)',
                }}
            >
                <div style={{ fontSize: 40, marginBottom: 8 }}>{top.emoji}</div>
                <div
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                        opacity: 0.7,
                        marginBottom: 4,
                    }}
                >
                    Your best match
                </div>
                <div
                    style={{
                        fontFamily: "'Fraunces', serif",
                        fontSize: 24,
                        fontWeight: 500,
                        marginBottom: 4,
                    }}
                >
                    {top.name}
                </div>
                <div style={{ fontSize: 13, opacity: 0.85 }}>{top.tagline}</div>
            </div>

            <div
                style={{
                    fontSize: 13,
                    color: '#6B6860',
                    lineHeight: 1.65,
                    marginBottom: 16,
                }}
            >
                Based on your priorities, <strong>{top.name}</strong> is your
                best fit in Cologne. {top.desc.split('.')[0]}.
            </div>

            {/* Also consider */}
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
                Also consider
            </div>
            <div
                onClick={() => onExplore(runner.id)}
                className="mb-5 flex cursor-pointer items-center gap-3 rounded-[9px] p-3 px-3.5"
                style={{ background: '#EFEDE7' }}
            >
                <span style={{ fontSize: 24 }}>{runner.emoji}</span>
                <div className="min-w-0 flex-1">
                    <div style={{ fontSize: 14, fontWeight: 600 }}>
                        {runner.name}
                    </div>
                    <div style={{ fontSize: 12, color: '#6B6860' }}>
                        {runner.tagline.substring(0, 55)}…
                    </div>
                </div>
                <span style={{ fontSize: 13, color: '#1A4CD4' }}>›</span>
            </div>

            {/* Action buttons */}
            <div className="flex gap-[9px]">
                <button
                    onClick={() => onExplore(top.id)}
                    className="flex-1 cursor-pointer rounded-[9px] border-none py-3 text-white transition-all"
                    style={{
                        background: '#1A4CD4',
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 13,
                        fontWeight: 600,
                    }}
                >
                    Explore {top.name} →
                </button>
                <button
                    onClick={onRetake}
                    className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 transition-all"
                    style={{
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 13,
                        fontWeight: 600,
                    }}
                >
                    Retake
                </button>
            </div>
        </div>
    );
}

// ============================================================
// Detail Sheet Content
// ============================================================

function DetailScoreBar({
    label,
    value,
    color,
}: {
    label: string;
    value: number;
    color: string;
}) {
    return (
        <div className="flex flex-col gap-1">
            <div
                style={{
                    fontSize: 10,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.06em',
                    color: '#AAA89F',
                }}
            >
                {label}
            </div>
            <div className="flex items-center gap-1.5">
                <div
                    className="flex-1 overflow-hidden rounded-[20px]"
                    style={{ height: 5, background: '#EFEDE7' }}
                >
                    <div
                        className="rounded-[20px]"
                        style={{
                            height: 5,
                            width: `${value}%`,
                            background: color,
                            transition: 'width 0.6s cubic-bezier(.32,1,.4,1)',
                        }}
                    />
                </div>
                <span
                    style={{
                        fontFamily: "'Geist Mono', monospace",
                        fontSize: 10,
                        color: '#6B6860',
                        fontWeight: 500,
                    }}
                >
                    {value}
                </span>
            </div>
        </div>
    );
}

function DetailContent({
    neighborhood: n,
}: {
    neighborhood: NeighborhoodData;
}) {
    return (
        <div>
            {/* Header */}
            <div className="mb-4 flex items-center gap-3.5">
                <span style={{ fontSize: 40 }}>{n.emoji}</span>
                <div>
                    <div
                        className="flex items-center gap-2"
                        style={{
                            fontFamily: "'Fraunces', serif",
                            fontSize: 22,
                            fontWeight: 500,
                        }}
                    >
                        {n.name}
                        {n.current && (
                            <span
                                className="rounded-[20px]"
                                style={{
                                    fontSize: 10,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.06em',
                                    background: '#EBF0FD',
                                    color: '#1A4CD4',
                                    padding: '2px 8px',
                                }}
                            >
                                Your area
                            </span>
                        )}
                    </div>
                    <div
                        style={{ fontSize: 13, color: '#6B6860', marginTop: 3 }}
                    >
                        ★ {n.rating} · {n.rent} avg rent · {n.expats} expats
                    </div>
                </div>
            </div>

            {/* Description */}
            <div
                style={{
                    fontSize: 14,
                    color: '#6B6860',
                    lineHeight: 1.65,
                    marginBottom: 16,
                }}
            >
                {n.desc}
            </div>

            {/* Score bars — 2 column */}
            <div className="mb-4 grid grid-cols-2 gap-2">
                <DetailScoreBar
                    label="Expat-friendly"
                    value={n.scores.expat}
                    color="#1A4CD4"
                />
                <DetailScoreBar
                    label="English"
                    value={n.scores.english}
                    color="#0A7C52"
                />
                <DetailScoreBar
                    label="Transport"
                    value={n.scores.transport}
                    color="#7C3AED"
                />
                <DetailScoreBar
                    label="Affordable"
                    value={n.scores.affordable}
                    color="#C47D0E"
                />
                <DetailScoreBar
                    label="Quiet"
                    value={n.scores.quiet}
                    color="#0891B2"
                />
                <DetailScoreBar
                    label="Green"
                    value={n.scores.green}
                    color="#16A34A"
                />
            </div>

            {/* Tags */}
            <div className="mb-4 flex flex-wrap gap-[5px]">
                {n.tags.map((tag, i) => (
                    <span
                        key={tag}
                        className="rounded-[20px]"
                        style={{
                            fontSize: 11,
                            padding: '3px 9px',
                            fontWeight: 600,
                            background: n.tagStyles[i].bg,
                            color: n.tagStyles[i].c,
                        }}
                    >
                        {tag}
                    </span>
                ))}
            </div>

            {/* Transit */}
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
                Transit
            </div>
            <div style={{ fontSize: 13, color: '#6B6860', marginBottom: 16 }}>
                🚇 {n.transit}
            </div>

            {/* Pros */}
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
                ✅ Pros
            </div>
            <div className="mb-3.5 flex flex-col gap-[5px]">
                {n.pros.map((p, i) => (
                    <div
                        key={i}
                        className="flex gap-2"
                        style={{ fontSize: 13, color: '#6B6860' }}
                    >
                        <span style={{ color: '#0A7C52' }}>✓</span>
                        {p}
                    </div>
                ))}
            </div>

            {/* Cons */}
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
                ⚠️ Cons
            </div>
            <div className="mb-3.5 flex flex-col gap-[5px]">
                {n.cons.map((c, i) => (
                    <div
                        key={i}
                        className="flex gap-2"
                        style={{ fontSize: 13, color: '#6B6860' }}
                    >
                        <span style={{ color: '#C47D0E' }}>•</span>
                        {c}
                    </div>
                ))}
            </div>

            {/* Best for */}
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
                Best for
            </div>
            <div className="mb-4 flex flex-wrap gap-[5px]">
                {n.bestFor.map((b) => (
                    <span
                        key={b}
                        className="rounded-[20px]"
                        style={{
                            fontSize: 12,
                            padding: '4px 10px',
                            background: '#EFEDE7',
                            border: '1px solid #E2DFD6',
                            color: '#6B6860',
                            fontWeight: 500,
                        }}
                    >
                        {b}
                    </span>
                ))}
            </div>

            {/* Community tips */}
            {n.tips.length > 0 && (
                <>
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
                        Community tips
                    </div>
                    {n.tips.map((t, i) => (
                        <div
                            key={i}
                            className="mb-2 flex items-start gap-[9px] rounded-[9px]"
                            style={{
                                background: '#EFEDE7',
                                padding: '11px 13px',
                            }}
                        >
                            <span style={{ fontSize: 14 }}>💬</span>
                            <div>
                                <div
                                    style={{
                                        fontSize: 13,
                                        color: '#6B6860',
                                        lineHeight: 1.5,
                                    }}
                                >
                                    {t.text}
                                </div>
                                <div
                                    style={{
                                        fontSize: 11,
                                        color: '#AAA89F',
                                        marginTop: 4,
                                    }}
                                >
                                    {t.author} · Community tip
                                </div>
                            </div>
                        </div>
                    ))}
                </>
            )}

            {/* Action buttons */}
            <div className="mt-2 flex gap-[9px]">
                <button
                    onClick={() =>
                        window.open(
                            `https://www.google.com/maps/search/${encodeURIComponent(n.name + ' Cologne')}`,
                            '_blank',
                            'noopener',
                        )
                    }
                    className="flex-1 cursor-pointer rounded-[9px] border-none py-3 text-white"
                    style={{
                        background: '#1A4CD4',
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 13,
                        fontWeight: 600,
                    }}
                >
                    View on map ↗
                </button>
                <button
                    onClick={() =>
                        window.open(
                            `https://www.immobilienscout24.de/suche/kauf/wohnung/nordrhein-westfalen/koeln-${n.id}`,
                            '_blank',
                            'noopener',
                        )
                    }
                    className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3"
                    style={{
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 13,
                        fontWeight: 600,
                    }}
                >
                    Find flats ↗
                </button>
            </div>
        </div>
    );
}

// ============================================================
// Picker Sheet Content
// ============================================================

function PickerContent({
    neighborhoods,
    onSelect,
}: {
    neighborhoods: NeighborhoodData[];
    onSelect: (id: string) => void;
}) {
    return (
        <div>
            <div
                style={{
                    fontFamily: "'Fraunces', serif",
                    fontSize: 20,
                    fontWeight: 500,
                    marginBottom: 16,
                }}
            >
                Pick a neighborhood
            </div>
            {neighborhoods.map((n) => (
                <div
                    key={n.id}
                    onClick={() => onSelect(n.id)}
                    className="flex cursor-pointer items-center gap-3 border-b border-[#E2DFD6] py-3 transition-opacity hover:opacity-70"
                >
                    <span style={{ fontSize: 24 }}>{n.emoji}</span>
                    <div className="min-w-0 flex-1">
                        <div style={{ fontSize: 14, fontWeight: 600 }}>
                            {n.name}
                        </div>
                        <div style={{ fontSize: 12, color: '#6B6860' }}>
                            {n.tagline.substring(0, 50)}…
                        </div>
                    </div>
                    <span style={{ fontSize: 12, color: '#AAA89F' }}>
                        ★ {n.rating}
                    </span>
                </div>
            ))}
        </div>
    );
}
