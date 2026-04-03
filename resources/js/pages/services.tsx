import { Head, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    ServiceCard
    
} from '@/components/services/service-card';
import type {ServiceData} from '@/components/services/service-card';
import { ServicesRightPanel } from '@/components/services/services-right-panel';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import AppLayout from '@/layouts/app-layout';

// ============================================================
// Types + categories
// ============================================================

type DbService = {
    id: number;
    name: string;
    cat: string;
    emoji: string;
    type: string;
    desc: string | null;
    address: string | null;
    distance: string | null;
    phone: string | null;
    website: string | null;
    languages: string[];
    insurance: string[];
    hours: { raw: string } | null;
    verified: boolean;
    acceptingNew: boolean;
    rating: number | null;
    reviews: number;
};

const AVATAR_COLORS: Record<string, string> = {
    doctor: '#EBF0FD',
    dental: '#FDF0D4',
    mental: '#EDE9FE',
    bank: '#D4F0E6',
    tax: '#FDF0D4',
    legal: '#EDE9FE',
    insure: '#D4F0E6',
    pharmacy: '#FDE8E6',
};

function dbToServiceData(s: DbService): ServiceData {
    return {
        id: s.id,
        cat: s.cat,
        name: s.name,
        type: `${s.type}${s.address ? ' · ' + s.address.split(',')[0] : ''}`,
        emoji: s.emoji || '📍',
        avatarBg: AVATAR_COLORS[s.cat] ?? '#EFEDE7',
        rating: s.rating ?? 0,
        reviews: s.reviews,
        verified: s.verified,
        languages: s.languages ?? [],
        address: s.address ?? '',
        distance: s.distance ?? '',
        phone: s.phone,
        acceptingNew: s.acceptingNew,
        insurance: s.insurance ?? [],
        desc: s.desc ?? '',
        hours: s.hours?.raw ?? '',
        website: s.website ?? '',
        tips: [],
    };
}

const CATEGORIES = [
    { id: 'all', ico: '✨', label: 'All' },
    { id: 'doctor', ico: '🩺', label: 'Doctors' },
    { id: 'dental', ico: '🦷', label: 'Dentists' },
    { id: 'pharmacy', ico: '💊', label: 'Pharmacies' },
    { id: 'bank', ico: '🏦', label: 'Banks' },
    { id: 'tax', ico: '📋', label: 'Tax advisors' },
    { id: 'legal', ico: '⚖️', label: 'Lawyers' },
];

// No more hardcoded SERVICES — data comes from ServicesController via dbServices prop

// ============================================================
// Page Component
// ============================================================

export default function Services() {
    const { dbServices, categoryCounts } = usePage<{
        dbServices?: DbService[];
        categoryCounts?: Record<string, number>;
    }>().props;

    const [activeCat, setActiveCat] = useState('all');
    const [search, setSearch] = useState('');
    const [selectedService, setSelectedService] = useState<ServiceData | null>(
        null,
    );

    // Map backend data to ServiceData shape
    const services = useMemo(
        () => (dbServices ?? []).map(dbToServiceData),
        [dbServices],
    );

    // Filter services
    const filtered = useMemo(() => {
        const q = search.toLowerCase().trim();

        return services.filter((s) => {
            const matchCat = activeCat === 'all' || s.cat === activeCat;
            const matchQ =
                !q ||
                s.name.toLowerCase().includes(q) ||
                s.type.toLowerCase().includes(q) ||
                s.desc.toLowerCase().includes(q) ||
                s.languages.some((l) => l.toLowerCase().includes(q));

            return matchCat && matchQ;
        });
    }, [services, activeCat, search]);

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
                    style={{
                        background: 'rgba(246,245,241,.92)',
                        backdropFilter: 'blur(16px)',
                    }}
                >
                    <span
                        style={{
                            fontFamily: "'Fraunces', serif",
                            fontSize: 20,
                            fontWeight: 500,
                            letterSpacing: '-0.01em',
                        }}
                    >
                        Services
                    </span>
                </div>

                {/* All content in one px-6 wrapper for consistent width */}
                <div className="px-6">
                    {/* Search bar */}
                    <div className="mt-3.5 mb-3 flex cursor-text items-center gap-[9px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-[#1A4CD4] focus-within:bg-white focus-within:shadow-[0_0_0_3px_#EBF0FD]">
                        <span style={{ fontSize: 15, color: '#AAA89F' }}>
                            🔍
                        </span>
                        <input
                            type="text"
                            placeholder="Search services, doctors, banks…"
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
                                    color:
                                        activeCat === c.id
                                            ? '#1A4CD4'
                                            : '#6B6860',
                                }}
                            >
                                <span style={{ fontSize: 20 }}>{c.ico}</span>
                                <span style={{ fontSize: 11, fontWeight: 600 }}>
                                    {c.label}
                                    {categoryCounts &&
                                    c.id !== 'all' &&
                                    categoryCounts[c.id]
                                        ? ` (${categoryCounts[c.id]})`
                                        : ''}
                                    {c.id === 'all' && services.length > 0
                                        ? ` (${services.length})`
                                        : ''}
                                </span>
                            </button>
                        ))}
                    </div>

                    {/* Services feed */}
                    <div className="py-0">
                        {filtered.length === 0 ? (
                            <div
                                className="py-12 text-center"
                                style={{ color: '#AAA89F' }}
                            >
                                <div style={{ fontSize: 36, marginBottom: 12 }}>
                                    🔍
                                </div>
                                <div
                                    style={{
                                        fontSize: 15,
                                        fontWeight: 600,
                                        color: '#6B6860',
                                    }}
                                >
                                    No services found
                                </div>
                                <div style={{ fontSize: 13, marginTop: 4 }}>
                                    Try a different search or category
                                </div>
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
            <BottomSheet
                open={selectedService !== null}
                onClose={() => setSelectedService(null)}
            >
                {selectedService && (
                    <ServiceDetailContent service={selectedService} />
                )}
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
                    style={{
                        width: 52,
                        height: 52,
                        background: s.avatarBg,
                        fontSize: 26,
                    }}
                >
                    {s.emoji}
                </div>
                <div>
                    <div
                        style={{
                            fontFamily: "'Fraunces', serif",
                            fontSize: 20,
                            fontWeight: 500,
                        }}
                    >
                        {s.name}
                    </div>
                    <div
                        style={{ fontSize: 13, color: '#6B6860', marginTop: 2 }}
                    >
                        {s.type}
                    </div>
                </div>
            </div>

            {/* Badges row */}
            <div className="mb-3.5 flex flex-wrap items-center gap-2.5">
                {s.verified && (
                    <span
                        className="inline-flex items-center gap-[3px] rounded-[20px]"
                        style={{
                            fontSize: 11,
                            fontWeight: 700,
                            background: '#D4F0E6',
                            color: '#0A7C52',
                            padding: '3px 9px',
                        }}
                    >
                        ✓ Verified by expats
                    </span>
                )}
                {s.rating > 0 && (
                    <>
                        <span
                            style={{
                                fontSize: 13,
                                fontWeight: 700,
                                color: '#C47D0E',
                            }}
                        >
                            ★ {s.rating}
                        </span>
                        <span style={{ fontSize: 12, color: '#AAA89F' }}>
                            {s.reviews} reviews
                        </span>
                    </>
                )}
                {s.acceptingNew && (
                    <span
                        className="rounded-[20px]"
                        style={{
                            fontSize: 10,
                            fontWeight: 700,
                            background: '#D4F0E6',
                            color: '#0A7C52',
                            padding: '2px 8px',
                        }}
                    >
                        Accepting patients
                    </span>
                )}
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
                {s.desc}
            </div>

            {/* Details rows */}
            <div className="mb-4 flex flex-col gap-2">
                {s.address && (
                    <div className="flex gap-2.5" style={{ fontSize: 13 }}>
                        <span>📍</span>
                        <span>
                            {s.address}
                            {s.distance ? ` · ${s.distance}` : ''}
                        </span>
                    </div>
                )}
                {s.hours && (
                    <div className="flex gap-2.5" style={{ fontSize: 13 }}>
                        <span>🕐</span>
                        <span>{s.hours}</span>
                    </div>
                )}
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
                                        marginTop: 3,
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
                {s.website && s.website !== '#' && (
                    <button
                        onClick={() =>
                            window.open(s.website, '_blank', 'noopener')
                        }
                        className="flex-1 cursor-pointer rounded-[9px] border-none bg-[#1A4CD4] py-3 text-white transition-all hover:bg-[#1540B8]"
                        style={{
                            fontFamily: "'Geist', sans-serif",
                            fontSize: 13,
                            fontWeight: 600,
                        }}
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
                    style={{
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 13,
                        fontWeight: 600,
                    }}
                >
                    Directions ↗
                </button>
                {s.phone && (
                    <button
                        onClick={() =>
                            (window.location.href = 'tel:' + s.phone)
                        }
                        className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 transition-all hover:bg-[#E2DFD6]"
                        style={{
                            fontFamily: "'Geist', sans-serif",
                            fontSize: 13,
                            fontWeight: 600,
                        }}
                    >
                        📞 Call
                    </button>
                )}
            </div>
        </div>
    );
}
