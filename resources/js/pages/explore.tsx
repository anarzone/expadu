import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ExploreFilterBar } from '@/components/explore/filter-bar';
import { SpotCard } from '@/components/explore/spot-card';
import { SpotDetailSheet } from '@/components/explore/spot-detail-sheet';
import AppLayout from '@/layouts/app-layout';

type SpotData = {
    id: number;
    name: string;
    category: string;
    address: string | null;
    wifi_speed: string | null;
    noise_level: string | null;
    time_limit_mins: number | null;
    rating: number | null;
    active_checkins_count: number;
};

export default function Explore() {
    const { spots, filters } = usePage<{
        spots: { data: SpotData[] };
        filters: { category?: string | null };
    }>().props;

    const [category, setCategory] = useState(filters.category || '');
    const [selectedSpot, setSelectedSpot] = useState<SpotData | null>(null);
    const [search, setSearch] = useState('');
    const [listOpen, setListOpen] = useState(false);

    function filterByCategory(cat: string) {
        setCategory(cat);
        router.get('/explore', cat ? { category: cat } : {}, { preserveState: true, preserveScroll: true });
    }

    const filteredSpots = spots.data.filter((s) => {
        if (!search) return true;
        const q = search.toLowerCase();
        return s.name.toLowerCase().includes(q) || s.address?.toLowerCase().includes(q);
    });

    function selectSpot(spot: SpotData) {
        setSelectedSpot(spot);
    }

    return (
        <AppLayout fullWidth>
            <Head title="Explore" />

            <div className="relative flex h-svh overflow-hidden md:h-[calc(100vh-52px)]">

                {/* ═══ LEFT LIST PANEL ═══
                    Desktop (lg+): 420px, always visible, border-right
                    Tablet (md–lg): absolute overlay, slides from left
                    Mobile (<md): hidden — uses bottom sheet instead */}
                <div className={`
                    flex w-[420px] shrink-0 flex-col overflow-hidden border-r border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]
                    max-md:hidden
                    max-lg:absolute max-lg:inset-y-0 max-lg:left-0 max-lg:z-[80] max-lg:shadow-[4px_0_32px_rgba(0,0,0,0.1)] max-lg:transition-transform max-lg:duration-300
                    ${listOpen ? 'max-lg:translate-x-0' : 'max-lg:-translate-x-full'}
                `}>
                    {/* Header: title, search, filters */}
                    <div className="shrink-0 border-b border-[#E2DFD6] px-5 pt-[18px] pb-3.5 dark:border-[#3A3930]">
                        <div className="mb-3 flex items-center justify-between">
                            <span className="font-display text-xl font-medium tracking-tight">Explore Cologne</span>
                            <span className="font-mono text-xs text-[#AAA89F]">{filteredSpots.length} spots</span>
                        </div>
                        <SearchBar search={search} setSearch={setSearch} />
                        <ExploreFilterBar active={category} onChange={filterByCategory} />
                    </div>

                    {/* Scrollable spot list */}
                    <div className="flex-1 overflow-y-auto px-4 py-3" style={{ scrollbarWidth: 'thin' }}>
                        {filteredSpots.length === 0 ? (
                            <div className="py-12 text-center text-sm text-[#AAA89F]">No spots found.</div>
                        ) : (
                            filteredSpots.map((s) => (
                                <SpotCard
                                    key={s.id}
                                    spot={s}
                                    selected={selectedSpot?.id === s.id}
                                    onSelect={() => selectSpot(s)}
                                />
                            ))
                        )}
                    </div>
                </div>

                {/* ═══ MAP PANEL ═══ flex:1, always visible */}
                <div className="relative flex-1 overflow-hidden bg-[#E8E4DC]">

                    {/* Tablet: ☰ toggle button — md visible, lg hidden */}
                    <button
                        onClick={() => setListOpen(!listOpen)}
                        className="absolute top-5 left-5 z-[60] hidden items-center gap-2 rounded-[9px] border border-[#E2DFD6] bg-white px-3.5 py-2.5 text-[13px] font-semibold shadow-md transition-all hover:bg-[#EFEDE7] md:flex lg:hidden"
                    >
                        <span>☰</span>
                        <span>{listOpen ? 'Hide list' : 'Show list'}</span>
                    </button>

                    {/* Mobile: floating search + filters over map */}
                    <div className="absolute top-4 left-1/2 z-50 w-[calc(100%-32px)] max-w-[520px] -translate-x-1/2 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white/[0.97] shadow-[0_8px_32px_rgba(0,0,0,0.12)] backdrop-blur-xl md:hidden">
                        <div className="flex items-center gap-2.5 border-b border-[#E2DFD6] px-3.5 py-[11px]">
                            <span className="text-[15px] text-[#AAA89F]">🔍</span>
                            <input
                                type="text"
                                placeholder="Search work spots…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="flex-1 border-none bg-transparent text-sm outline-none placeholder:text-[#AAA89F]"
                            />
                            {search && <button onClick={() => setSearch('')} className="text-[13px] text-[#AAA89F]">✕</button>}
                        </div>
                        <div className="px-3.5 py-2">
                            <ExploreFilterBar active={category} onChange={filterByCategory} />
                        </div>
                    </div>

                    {/* Map placeholder */}
                    <div className="flex h-full flex-col items-center justify-center text-center">
                        <span className="mb-3 text-5xl">🗺️</span>
                        <div className="text-base font-semibold text-[#6B6860]">Map View</div>
                        <div className="mt-1.5 text-sm text-[#AAA89F]">MapLibre integration coming soon</div>
                    </div>

                    {/* Map controls */}
                    <div className="absolute right-4 bottom-4 flex flex-col gap-1 max-md:bottom-[48%]">
                        {['+', '−', '📍', '🗂'].map((icon) => (
                            <div key={icon} className="flex size-9 cursor-pointer items-center justify-center rounded-lg border border-[#E2DFD6] bg-white text-sm shadow-sm hover:bg-[#EFEDE7]">
                                {icon}
                            </div>
                        ))}
                    </div>

                    {/* ═══ MOBILE: Draggable bottom sheet list ═══ */}
                    <MobileListSheet spots={filteredSpots} selectedId={selectedSpot?.id ?? null} onSelect={selectSpot} />
                </div>

                {/* Tablet: overlay behind list to close it */}
                {listOpen && (
                    <div className="absolute inset-0 z-[70] hidden bg-black/20 md:block lg:hidden" onClick={() => setListOpen(false)} />
                )}
            </div>

            {/* Spot detail bottom sheet — all breakpoints */}
            <SpotDetailSheet spot={selectedSpot} onClose={() => setSelectedSpot(null)} />
        </AppLayout>
    );
}

/* ═══ SEARCH BAR ═══ matching prototype: #EFEDE7 bg, #E2DFD6 border, focus: #1A4CD4 border + white bg + #EBF0FD ring */
function SearchBar({ search, setSearch }: { search: string; setSearch: (v: string) => void }) {
    return (
        <div className="mb-3 flex cursor-text items-center gap-2.5 rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-[#1A4CD4] focus-within:bg-white focus-within:shadow-[0_0_0_3px_#EBF0FD] dark:border-[#3A3930] dark:bg-[#2A2920]">
            <span className="text-[15px] text-[#AAA89F]">🔍</span>
            <input
                type="text"
                placeholder="Search cafés, coworking..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="flex-1 border-none bg-transparent font-sans text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F] dark:text-[#F6F5F1]"
            />
            {search && <button onClick={() => setSearch('')} className="text-[13px] text-[#AAA89F]">✕</button>}
        </div>
    );
}

/* ═══ MOBILE LIST SHEET ═══
   Ported from prototype's makeDraggable():
   - Snap points: 18%, 44%, 80% of container height
   - Spring animation: cubic-bezier(.32,1,.4,1)
   - Min 80px, max 92% of container
   - Non-passive touch/mouse on document level
   - cursor/userSelect toggling during drag */
function MobileListSheet({ spots, selectedId, onSelect }: { spots: SpotData[]; selectedId: number | null; onSelect: (s: SpotData) => void }) {
    const sheetRef = useRef<HTMLDivElement>(null);
    const handleRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const handle = handleRef.current;
        const sheet = sheetRef.current;
        if (!handle || !sheet) return;

        const SNAP_POINTS = [0.18, 0.44, 0.80];
        const MIN_H = 80;
        let dragging = false;
        let startY = 0;
        let startH = 0;

        function getContainerH() {
            return sheet.parentElement?.offsetHeight || window.innerHeight;
        }

        function getH() {
            return sheet.offsetHeight;
        }

        function setH(h: number) {
            const containerH = getContainerH();
            const maxH = containerH * 0.92;
            h = Math.max(MIN_H, Math.min(maxH, h));
            sheet.style.transition = 'none';
            sheet.style.height = h + 'px';
            return h;
        }

        function snapTo(h: number) {
            const containerH = getContainerH();
            const snapPx = SNAP_POINTS.map(p => containerH * p);
            const nearest = snapPx.reduce((prev, curr) =>
                Math.abs(curr - h) < Math.abs(prev - h) ? curr : prev
            );
            sheet.style.transition = 'height .3s cubic-bezier(.32,1,.4,1)';
            sheet.style.height = nearest + 'px';
        }

        function onStart(e: MouseEvent | TouchEvent) {
            dragging = true;
            startY = 'touches' in e ? e.touches[0].clientY : e.clientY;
            startH = getH();
            handle.style.cursor = 'grabbing';
            document.body.style.userSelect = 'none';
            e.preventDefault();
        }

        function onMove(e: MouseEvent | TouchEvent) {
            if (!dragging) return;
            const y = 'touches' in e ? e.touches[0].clientY : e.clientY;
            const delta = startY - y; // drag up = positive = taller
            setH(startH + delta);
            e.preventDefault();
        }

        function onEnd() {
            if (!dragging) return;
            dragging = false;
            handle.style.cursor = 'grab';
            document.body.style.userSelect = '';
            snapTo(getH());
        }

        handle.addEventListener('mousedown', onStart);
        handle.addEventListener('touchstart', onStart, { passive: false });
        document.addEventListener('mousemove', onMove);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('mouseup', onEnd);
        document.addEventListener('touchend', onEnd);

        return () => {
            handle.removeEventListener('mousedown', onStart);
            handle.removeEventListener('touchstart', onStart);
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('mouseup', onEnd);
            document.removeEventListener('touchend', onEnd);
        };
    }, []);

    const categoryEmoji: Record<string, string> = { cafe: '☕', coworking: '🏢', library: '📚', park: '🌳' };

    return (
        <div
            ref={sheetRef}
            className="absolute right-0 bottom-0 left-0 z-[80] flex flex-col rounded-t-[20px] bg-white shadow-[0_-8px_40px_rgba(0,0,0,0.12)] md:hidden dark:bg-[#1E1D15]"
            style={{ height: '44%', maxHeight: 'calc(100% - 60px)' }}
        >
            {/* Drag handle — all events attached imperatively */}
            <div
                ref={handleRef}
                className="flex shrink-0 cursor-grab justify-center py-3 select-none"
                style={{ touchAction: 'none' }}
            >
                <div className="h-1 w-9 rounded-full bg-[#E2DFD6] transition-all hover:w-12 hover:bg-[#AAA89F]" />
            </div>

            {/* Header */}
            <div className="flex shrink-0 items-center justify-between px-4 pb-2.5">
                <span className="text-sm font-semibold">Work Spots</span>
                <span className="font-mono text-[11px] text-[#AAA89F]">{spots.length} nearby</span>
            </div>

            {/* Scrollable compact cards */}
            <div className="flex-1 overflow-y-auto px-3 pb-28">
                {spots.map((s) => (
                    <div
                        key={s.id}
                        onClick={() => onSelect(s)}
                        className={`mb-[9px] flex cursor-pointer items-center gap-3 rounded-[14px] border bg-white p-[13px] transition-all dark:bg-[#1E1D15] ${
                            selectedId === s.id ? 'border-[#1A4CD4]' : 'border-[#E2DFD6] hover:border-[#1A4CD4] dark:border-[#3A3930]'
                        }`}
                    >
                        <span className="shrink-0 text-2xl">{categoryEmoji[s.category] || '📍'}</span>
                        <div className="min-w-0 flex-1">
                            <div className="mb-0.5 text-sm font-semibold">{s.name}</div>
                            <div className="text-xs text-[#6B6860]">{s.address?.split(',')[1]?.trim() || s.address}</div>
                            <div className="mt-1 flex gap-1">
                                {s.wifi_speed && <span className="rounded-full bg-[#EFEDE7] px-1.5 py-[2px] text-[10px] font-medium text-[#6B6860]">📶 WiFi</span>}
                                {s.noise_level === 'quiet' && <span className="rounded-full bg-[#EFEDE7] px-1.5 py-[2px] text-[10px] font-medium text-[#6B6860]">🤫 Quiet</span>}
                            </div>
                        </div>
                        <span className="shrink-0 font-mono text-xs text-[#AAA89F]">0.3 km</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
