export type ServiceData = {
    id: number;
    cat: string;
    name: string;
    type: string;
    emoji: string;
    avatarBg: string;
    rating: number;
    reviews: number;
    verified: boolean;
    languages: string[];
    address: string;
    distance: string;
    phone: string | null;
    acceptingNew: boolean;
    insurance: string[];
    desc: string;
    hours: string;
    website: string;
    tips: { text: string; author: string }[];
};

export function ServiceCard({
    service,
    onClick,
    index = 0,
}: {
    service: ServiceData;
    onClick: () => void;
    index?: number;
}) {
    const stars = '★'.repeat(Math.round(service.rating)) + '☆'.repeat(5 - Math.round(service.rating));

    return (
        <div
            onClick={onClick}
            className="animate-fade-up mb-2.5 cursor-pointer rounded-[14px] border border-[#E2DFD6] bg-white p-4 transition-all hover:border-[rgba(26,76,212,0.25)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.07)]"
            style={{ animationDelay: `${index * 0.04}s` }}
        >
            {/* Top row: avatar, info, rating */}
            <div className="mb-2.5 flex items-start gap-[13px]">
                {/* Avatar */}
                <div
                    className="flex shrink-0 items-center justify-center rounded-[9px]"
                    style={{ width: 46, height: 46, background: service.avatarBg, fontSize: 22 }}
                >
                    {service.emoji}
                </div>

                {/* Info */}
                <div className="min-w-0 flex-1">
                    <div style={{ fontSize: 15, fontWeight: 600, marginBottom: 2 }}>{service.name}</div>
                    <div style={{ fontSize: 12, color: '#6B6860', marginBottom: 5 }}>{service.type}</div>
                    <div className="flex flex-wrap gap-[5px]">
                        {service.verified && (
                            <span
                                className="inline-flex items-center gap-[3px] rounded-[20px]"
                                style={{
                                    fontSize: 10,
                                    fontWeight: 700,
                                    background: '#D4F0E6',
                                    color: '#0A7C52',
                                    padding: '2px 7px',
                                }}
                            >
                                ✓ Verified by expats
                            </span>
                        )}
                        {service.languages.map((lang) => (
                            <span
                                key={lang}
                                className="rounded-[20px]"
                                style={{
                                    fontSize: 10,
                                    fontWeight: 700,
                                    padding: '2px 7px',
                                    background: '#EFEDE7',
                                    color: '#6B6860',
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.03em',
                                }}
                            >
                                {lang}
                            </span>
                        ))}
                        {service.acceptingNew ? (
                            <span
                                className="rounded-[20px]"
                                style={{
                                    fontSize: 10,
                                    fontWeight: 700,
                                    padding: '2px 7px',
                                    background: '#D4F0E6',
                                    color: '#0A7C52',
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.03em',
                                }}
                            >
                                Accepting patients
                            </span>
                        ) : (
                            <span
                                className="rounded-[20px]"
                                style={{
                                    fontSize: 10,
                                    fontWeight: 700,
                                    padding: '2px 7px',
                                    background: '#FDE8E6',
                                    color: '#C4271A',
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.03em',
                                }}
                            >
                                Waitlist only
                            </span>
                        )}
                    </div>
                </div>

                {/* Rating — only show if we have data */}
                {service.rating > 0 && (
                    <div className="flex shrink-0 flex-col items-end gap-[3px]">
                        <div style={{ fontSize: 12, color: '#C47D0E' }}>{stars}</div>
                        <div style={{ fontSize: 13, fontWeight: 700 }}>{service.rating}</div>
                        <div style={{ fontSize: 10, color: '#AAA89F' }}>{service.reviews} reviews</div>
                    </div>
                )}
            </div>

            {/* Bottom row: meta + actions */}
            <div className="flex items-center justify-between border-t border-[#E2DFD6] pt-2.5">
                <div className="flex flex-wrap gap-2.5" style={{ fontSize: 12, color: '#6B6860' }}>
                    {service.address && <span>📍 {service.address}</span>}
                    {service.distance && <span>· {service.distance}</span>}
                </div>
                <div className="flex gap-[7px]">
                    {service.phone && (
                        <button
                            onClick={(e) => {
                                e.stopPropagation();
                                window.location.href = 'tel:' + service.phone;
                            }}
                            className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-1.5 transition-all hover:bg-[#E2DFD6]"
                            style={{
                                fontSize: 12,
                                fontWeight: 600,
                                fontFamily: "'Geist', sans-serif",
                                color: '#6B6860',
                            }}
                        >
                            📞 Call
                        </button>
                    )}
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            onClick();
                        }}
                        className="cursor-pointer rounded-[9px] border-none bg-[#1A4CD4] px-[13px] py-1.5 text-white transition-all hover:bg-[#1540B8]"
                        style={{
                            fontSize: 12,
                            fontWeight: 600,
                            fontFamily: "'Geist', sans-serif",
                        }}
                    >
                        Details →
                    </button>
                </div>
            </div>
        </div>
    );
}
