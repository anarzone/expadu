export function NeighborhoodsRightPanel() {
    return (
        <>
            {/* Your area */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                <div className="border-b border-[#E2DFD6] px-[15px] py-3">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>Your area</span>
                </div>
                <div className="px-[15px] py-3.5">
                    <div className="mb-2.5 flex items-center gap-2.5">
                        <span style={{ fontSize: 24 }}>🎨</span>
                        <div>
                            <div style={{ fontSize: 14, fontWeight: 600 }}>Ehrenfeld</div>
                            <div style={{ fontSize: 11, color: '#6B6860' }}>Your current neighborhood</div>
                        </div>
                    </div>
                    <div className="flex flex-col gap-[5px]" style={{ fontSize: 12, color: '#6B6860' }}>
                        <div>🇬🇧 High English-friendliness</div>
                        <div>👥 Large expat community</div>
                        <div>🚇 Line 3, 4 direct to centre</div>
                        <div>💶 Mid-range rents (~€14/m²)</div>
                    </div>
                </div>
            </div>

            {/* Popular with expats */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                <div className="border-b border-[#E2DFD6] px-[15px] py-3">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>Popular with expats</span>
                </div>
                <PopularRow emoji="🎨" name="Ehrenfeld" sub="Creative · Diverse · Mid-range" rating="4.7" />
                <PopularRow emoji="🌿" name="Belgisches Viertel" sub="Trendy · Cafés · Pricier" rating="4.6" />
                <PopularRow emoji="🏡" name="Nippes" sub="Residential · Family · Affordable" rating="4.4" last />
            </div>

            {/* Average rents */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                <div className="border-b border-[#E2DFD6] px-[15px] py-3">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>Average rents · Cologne</span>
                </div>
                <div className="flex flex-col gap-2 px-[15px] py-3.5">
                    <RentRow area="Innenstadt" rent="€17/m²" />
                    <RentRow area="Belgisches Viertel" rent="€16/m²" />
                    <RentRow area="Ehrenfeld" rent="€14/m²" />
                    <RentRow area="Nippes" rent="€13/m²" />
                    <RentRow area="Deutz" rent="€13/m²" />
                    <RentRow area="Mülheim" rent="€11/m²" />
                </div>
            </div>
        </>
    );
}

function PopularRow({ emoji, name, sub, rating, last }: { emoji: string; name: string; sub: string; rating: string; last?: boolean }) {
    return (
        <div
            className={`flex cursor-pointer items-center gap-2.5 px-[15px] py-[11px] transition-colors hover:bg-[#EFEDE7] ${last ? '' : 'border-b border-[#E2DFD6]'}`}
        >
            <span className="shrink-0" style={{ fontSize: 16 }}>
                {emoji}
            </span>
            <div className="min-w-0 flex-1">
                <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 1 }}>{name}</div>
                <div style={{ fontSize: 11, color: '#6B6860' }}>{sub}</div>
            </div>
            <span
                className="shrink-0 rounded-[20px] px-1.5 py-0.5"
                style={{
                    fontSize: 9,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.04em',
                    background: '#D4F0E6',
                    color: '#0A7C52',
                }}
            >
                ★ {rating}
            </span>
        </div>
    );
}

function RentRow({ area, rent }: { area: string; rent: string }) {
    return (
        <div className="flex justify-between" style={{ fontSize: 12 }}>
            <span>{area}</span>
            <span style={{ fontFamily: "'Geist Mono', monospace", fontWeight: 600 }}>{rent}</span>
        </div>
    );
}
