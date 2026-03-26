import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { ServiceCard, type ServiceData } from '@/components/services/service-card';
import { ServicesRightPanel } from '@/components/services/services-right-panel';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import AppLayout from '@/layouts/app-layout';

// ============================================================
// Categories
// ============================================================

const CATEGORIES = [
    { id: 'all', ico: '✨', label: 'All' },
    { id: 'doctor', ico: '🩺', label: 'Doctors' },
    { id: 'dental', ico: '🦷', label: 'Dentists' },
    { id: 'mental', ico: '🧠', label: 'Therapists' },
    { id: 'bank', ico: '🏦', label: 'Banks' },
    { id: 'tax', ico: '📋', label: 'Tax advisors' },
    { id: 'legal', ico: '⚖️', label: 'Lawyers' },
    { id: 'insure', ico: '🛡️', label: 'Insurance' },
];

// ============================================================
// Hardcoded prototype data
// ============================================================

const SERVICES: ServiceData[] = [
    {
        id: 1, cat: 'doctor', name: 'Dr. Julia Müller', type: 'General Practitioner · Ehrenfeld', emoji: '🩺', avatarBg: '#EBF0FD',
        rating: 4.9, reviews: 127, verified: true, languages: ['English', 'German', 'French'],
        address: 'Venloer Str. 218, Ehrenfeld', distance: '0.3 km', phone: '+49 221 5541200',
        acceptingNew: true, insurance: ['TK', 'AOK', 'Barmer', 'Private'],
        desc: 'Dr. Müller is highly recommended by the Cologne expat community. Fluent English, patient with paperwork questions, and takes time to explain diagnoses clearly.',
        hours: 'Mon–Fri 8:00–18:00', website: 'https://www.doctolib.de',
        tips: [{ text: '"Books up fast — call first thing Monday morning for same-week appointments."', author: 'Sarah K.' }],
    },
    {
        id: 2, cat: 'doctor', name: 'Dr. Hendrik Bauer', type: 'Internal Medicine · Belgisches Viertel', emoji: '🩺', avatarBg: '#D4F0E6',
        rating: 4.7, reviews: 89, verified: true, languages: ['English', 'German'],
        address: 'Aachener Str. 55, Belgisches Viertel', distance: '1.1 km', phone: '+49 221 4402100',
        acceptingNew: true, insurance: ['TK', 'AOK', 'Private'],
        desc: 'Specialises in chronic conditions and preventive medicine. Known for thorough check-ups and excellent bedside manner with international patients.',
        hours: 'Mon–Thu 8:30–17:00, Fri 8:30–13:00', website: 'https://www.doctolib.de', tips: [],
    },
    {
        id: 3, cat: 'dental', name: 'Zahnarztpraxis Köln Mitte', type: 'Dentist · Innenstadt', emoji: '🦷', avatarBg: '#FDF0D4',
        rating: 4.8, reviews: 203, verified: true, languages: ['English', 'German', 'Spanish'],
        address: 'Hohenstaufenring 62, Innenstadt', distance: '2.0 km', phone: '+49 221 2573900',
        acceptingNew: true, insurance: ['TK', 'AOK', 'Barmer', 'Private', 'Self-pay'],
        desc: 'Modern dental practice with English-speaking staff. Same-day emergency appointments available. Transparent pricing with written quotes before any procedure.',
        hours: 'Mon–Fri 8:00–19:00, Sat 9:00–14:00', website: 'https://www.doctolib.de', tips: [],
    },
    {
        id: 4, cat: 'mental', name: 'Expat Counselling Cologne', type: 'Psychotherapy · English-only practice', emoji: '🧠', avatarBg: '#EDE9FE',
        rating: 5.0, reviews: 44, verified: true, languages: ['English', 'German'],
        address: 'Friesenplatz 4, Innenstadt', distance: '1.4 km', phone: '+49 221 9988100',
        acceptingNew: true, insurance: ['Private', 'Self-pay'],
        desc: 'Specialises in expat-specific challenges: relocation stress, cultural adjustment, career transitions. Sessions fully in English. Video appointments available.',
        hours: 'Mon–Fri 9:00–20:00', website: '#',
        tips: [{ text: '"Life-changing for my first year in Cologne. Worth every euro."', author: 'Carlos M.' }],
    },
    {
        id: 5, cat: 'bank', name: 'N26', type: 'Online Bank · App-based', emoji: '🏦', avatarBg: '#EBF0FD',
        rating: 4.6, reviews: 4800, verified: false, languages: ['English', 'German', 'French', 'Spanish', 'Italian'],
        address: 'App-based — no branch needed', distance: 'Online', phone: null,
        acceptingNew: true, insurance: [],
        desc: 'Best bank for new expats. English-first app, no monthly fees on basic account, instant IBAN, and VideoIdent verification from your phone. Open in under 10 minutes.',
        hours: '24/7 app · Support Mon–Fri 9–18', website: 'https://n26.com', tips: [],
    },
    {
        id: 6, cat: 'bank', name: 'Deutsche Bank Ehrenfeld', type: 'Full-service Bank · Branch', emoji: '🏦', avatarBg: '#D4F0E6',
        rating: 4.2, reviews: 312, verified: true, languages: ['English', 'German'],
        address: 'Venloer Str. 100, Ehrenfeld', distance: '0.4 km', phone: '+49 800 4000400',
        acceptingNew: true, insurance: [],
        desc: 'Traditional bank with English-speaking advisors. Good for those who need in-person service, mortgages, or business accounts. English appointments available on request.',
        hours: 'Mon–Fri 9:00–18:00', website: 'https://www.deutsche-bank.de', tips: [],
    },
    {
        id: 7, cat: 'tax', name: 'ExpatTax Cologne', type: 'Tax Advisor · Expat specialists', emoji: '📋', avatarBg: '#FDF0D4',
        rating: 4.9, reviews: 178, verified: true, languages: ['English', 'German'],
        address: 'Habsburgerring 2, Innenstadt', distance: '1.8 km', phone: '+49 221 3339900',
        acceptingNew: true, insurance: [],
        desc: 'Specialist tax advisory firm for international employees and self-employed expats. Handles German tax returns, double taxation, and Freiberufler registration. All communication in English.',
        hours: 'Mon–Fri 9:00–17:30', website: '#',
        tips: [{ text: '"Got €1,800 back on my first German tax return. Worth every cent of their fee."', author: 'Yuki T.' }],
    },
    {
        id: 8, cat: 'tax', name: 'Taxfix', type: 'Tax App · DIY digital filing', emoji: '📱', avatarBg: '#EBF0FD',
        rating: 4.5, reviews: 12000, verified: false, languages: ['English', 'German'],
        address: 'App-based', distance: 'Online', phone: null,
        acceptingNew: true, insurance: [],
        desc: 'Beginner-friendly tax app with English interface. Guides you step-by-step through your German Steuererklärung. Costs ~€40 and takes 1–2 hours. Average refund: €1,095.',
        hours: '24/7', website: 'https://taxfix.de', tips: [],
    },
    {
        id: 9, cat: 'legal', name: 'RA Expat Law Cologne', type: 'Lawyer · Immigration & Employment', emoji: '⚖️', avatarBg: '#EDE9FE',
        rating: 4.8, reviews: 67, verified: true, languages: ['English', 'German', 'Turkish'],
        address: 'Breite Str. 6–26, Innenstadt', distance: '2.2 km', phone: '+49 221 9974400',
        acceptingNew: true, insurance: [],
        desc: 'Specialises in expat legal issues: residence permits, work visas, employment contract disputes, and tenancy law. First consultation (30 min) free of charge.',
        hours: 'Mon–Fri 9:00–18:00', website: '#',
        tips: [{ text: '"Helped me resolve a lease dispute in 2 weeks. Highly professional."', author: 'Mehmet A.' }],
    },
    {
        id: 10, cat: 'insure', name: 'Feather Insurance', type: 'Expat Insurance · Digital-first', emoji: '🛡️', avatarBg: '#D4F0E6',
        rating: 4.7, reviews: 2100, verified: false, languages: ['English', 'German'],
        address: 'App-based — Germany-wide', distance: 'Online', phone: null,
        acceptingNew: true, insurance: [],
        desc: 'English-first insurance for expats in Germany. Covers health, liability, household, legal, and dental. Designed specifically for non-EU nationals. Cancel anytime.',
        hours: '24/7 app · Support Mon–Fri', website: 'https://feather-insurance.com', tips: [],
    },
];

// ============================================================
// Page Component
// ============================================================

export default function Services() {
    const [activeCat, setActiveCat] = useState('all');
    const [search, setSearch] = useState('');
    const [selectedService, setSelectedService] = useState<ServiceData | null>(null);

    // Filter services
    const filtered = useMemo(() => {
        const q = search.toLowerCase().trim();
        return SERVICES.filter((s) => {
            const matchCat = activeCat === 'all' || s.cat === activeCat;
            const matchQ =
                !q ||
                s.name.toLowerCase().includes(q) ||
                s.type.toLowerCase().includes(q) ||
                s.desc.toLowerCase().includes(q) ||
                s.languages.some((l) => l.toLowerCase().includes(q));
            return matchCat && matchQ;
        });
    }, [activeCat, search]);

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Services', href: '/services' }]}
            rightPanel={<ServicesRightPanel />}
        >
            <Head title="Services" />
            <div className="w-full">
                {/* Sticky header — title only */}
                <div
                    className="sticky top-0 z-50 border-b border-[#E2DFD6] px-6 py-3.5"
                    style={{ background: 'rgba(246,245,241,.92)', backdropFilter: 'blur(16px)' }}
                >
                    <span
                        style={{ fontFamily: "'Fraunces', serif", fontSize: 20, fontWeight: 500, letterSpacing: '-0.01em' }}
                    >
                        Services
                    </span>
                </div>

                {/* All content in one px-6 wrapper for consistent width */}
                <div className="px-6">
                    {/* Search bar */}
                    <div
                        className="mt-3.5 mb-3 flex cursor-text items-center gap-[9px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-[#1A4CD4] focus-within:bg-white focus-within:shadow-[0_0_0_3px_#EBF0FD]"
                    >
                        <span style={{ fontSize: 15, color: '#AAA89F' }}>🔍</span>
                        <input
                            type="text"
                            placeholder="Search services, doctors, banks…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F]"
                            style={{ fontFamily: "'Geist', sans-serif", fontSize: 14 }}
                        />
                        {search && (
                            <button onClick={() => setSearch('')} className="cursor-pointer border-none bg-transparent text-[13px] text-[#AAA89F]">
                                ✕
                            </button>
                        )}
                    </div>

                    {/* Category tabs */}
                    <div
                        className="mb-4 flex overflow-x-auto border-b border-[#E2DFD6] bg-white dark:bg-[#1E1D15]"
                        style={{ scrollbarWidth: 'none' }}
                    >
                        {CATEGORIES.map((c) => (
                            <button
                                key={c.id}
                                onClick={() => setActiveCat(c.id)}
                                className="flex shrink-0 cursor-pointer flex-col items-center gap-1 bg-transparent px-4 py-3 transition-all hover:bg-[#EFEDE7]"
                                style={{
                                    border: 'none',
                                    borderBottom: `2px solid ${activeCat === c.id ? '#1A4CD4' : 'transparent'}`,
                                    color: activeCat === c.id ? '#1A4CD4' : '#6B6860',
                                }}
                            >
                                <span style={{ fontSize: 20 }}>{c.ico}</span>
                                <span style={{ fontSize: 11, fontWeight: 600 }}>{c.label}</span>
                            </button>
                        ))}
                    </div>

                    {/* Services feed */}
                    <div className="py-0">
                    {filtered.length === 0 ? (
                        <div className="py-12 text-center" style={{ color: '#AAA89F' }}>
                            <div style={{ fontSize: 36, marginBottom: 12 }}>🔍</div>
                            <div style={{ fontSize: 15, fontWeight: 600, color: '#6B6860' }}>No services found</div>
                            <div style={{ fontSize: 13, marginTop: 4 }}>Try a different search or category</div>
                        </div>
                    ) : (
                        filtered.map((s, i) => (
                            <ServiceCard
                                key={s.id}
                                service={s}
                                onClick={() => setSelectedService(s)}
                                index={i}
                            />
                        ))
                    )}
                    </div>
                </div>
            </div>

            {/* Detail bottom sheet */}
            <BottomSheet open={selectedService !== null} onClose={() => setSelectedService(null)}>
                {selectedService && <ServiceDetailContent service={selectedService} />}
            </BottomSheet>
        </AppLayout>
    );
}

// ============================================================
// Service Detail Content (shown inside BottomSheet)
// ============================================================

function ServiceDetailContent({ service }: { service: ServiceData }) {
    const s = service;

    return (
        <div>
            {/* Header: avatar + name */}
            <div className="mb-4 flex items-center gap-3.5">
                <div
                    className="flex shrink-0 items-center justify-center rounded-[9px]"
                    style={{ width: 52, height: 52, background: s.avatarBg, fontSize: 26 }}
                >
                    {s.emoji}
                </div>
                <div>
                    <div style={{ fontFamily: "'Fraunces', serif", fontSize: 20, fontWeight: 500 }}>{s.name}</div>
                    <div style={{ fontSize: 13, color: '#6B6860', marginTop: 2 }}>{s.type}</div>
                </div>
            </div>

            {/* Badges row */}
            <div className="mb-3.5 flex flex-wrap items-center gap-2.5">
                {s.verified && (
                    <span
                        className="inline-flex items-center gap-[3px] rounded-[20px]"
                        style={{ fontSize: 11, fontWeight: 700, background: '#D4F0E6', color: '#0A7C52', padding: '3px 9px' }}
                    >
                        ✓ Verified by expats
                    </span>
                )}
                <span style={{ fontSize: 13, fontWeight: 700, color: '#C47D0E' }}>★ {s.rating}</span>
                <span style={{ fontSize: 12, color: '#AAA89F' }}>{s.reviews} reviews</span>
                {s.acceptingNew && (
                    <span
                        className="rounded-[20px]"
                        style={{ fontSize: 10, fontWeight: 700, background: '#D4F0E6', color: '#0A7C52', padding: '2px 8px' }}
                    >
                        Accepting patients
                    </span>
                )}
            </div>

            {/* Description */}
            <div style={{ fontSize: 14, color: '#6B6860', lineHeight: 1.65, marginBottom: 16 }}>
                {s.desc}
            </div>

            {/* Details rows */}
            <div className="mb-4 flex flex-col gap-2">
                <div className="flex gap-2.5" style={{ fontSize: 13 }}>
                    <span>📍</span>
                    <span>{s.address} · {s.distance}</span>
                </div>
                <div className="flex gap-2.5" style={{ fontSize: 13 }}>
                    <span>🕐</span>
                    <span>{s.hours}</span>
                </div>
                {s.phone && (
                    <div className="flex gap-2.5" style={{ fontSize: 13 }}>
                        <span>📞</span>
                        <span>{s.phone}</span>
                    </div>
                )}
                <div className="flex gap-2.5" style={{ fontSize: 13 }}>
                    <span>🌐</span>
                    <span>{s.languages.join(', ')}</span>
                </div>
                {s.insurance.length > 0 && (
                    <div className="flex gap-2.5" style={{ fontSize: 13 }}>
                        <span>🛡️</span>
                        <span>Accepts: {s.insurance.join(', ')}</span>
                    </div>
                )}
            </div>

            {/* Community tips */}
            {s.tips.length > 0 && (
                <>
                    <div
                        className="mb-2"
                        style={{
                            fontSize: 11,
                            fontWeight: 700,
                            textTransform: 'uppercase',
                            letterSpacing: '0.08em',
                            color: '#AAA89F',
                        }}
                    >
                        Community tips
                    </div>
                    {s.tips.map((t, i) => (
                        <div
                            key={i}
                            className="mb-2 flex gap-[9px] rounded-[9px] bg-[#EFEDE7] p-[11px_13px]"
                        >
                            <span>💬</span>
                            <div>
                                <div style={{ fontSize: 13, color: '#6B6860', lineHeight: 1.5 }}>{t.text}</div>
                                <div style={{ fontSize: 11, color: '#AAA89F', marginTop: 3 }}>{t.author} · Community tip</div>
                            </div>
                        </div>
                    ))}
                </>
            )}

            {/* Action buttons */}
            <div className="mt-2 flex gap-[9px]">
                {s.website && s.website !== '#' && (
                    <button
                        onClick={() => window.open(s.website, '_blank', 'noopener')}
                        className="flex-1 cursor-pointer rounded-[9px] border-none bg-[#1A4CD4] py-3 text-white transition-all hover:bg-[#1540B8]"
                        style={{ fontFamily: "'Geist', sans-serif", fontSize: 13, fontWeight: 600 }}
                    >
                        Book appointment ↗
                    </button>
                )}
                <button
                    onClick={() =>
                        window.open(
                            `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(s.address)}`,
                            '_blank',
                            'noopener',
                        )
                    }
                    className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 transition-all hover:bg-[#E2DFD6]"
                    style={{ fontFamily: "'Geist', sans-serif", fontSize: 13, fontWeight: 600 }}
                >
                    Directions ↗
                </button>
                {s.phone && (
                    <button
                        onClick={() => (window.location.href = 'tel:' + s.phone)}
                        className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 transition-all hover:bg-[#E2DFD6]"
                        style={{ fontFamily: "'Geist', sans-serif", fontSize: 13, fontWeight: 600 }}
                    >
                        📞 Call
                    </button>
                )}
            </div>
        </div>
    );
}
