import { useCallback, useEffect, useRef, useState } from 'react';

type GeoResult = {
    name: string;
    street: string | null;
    city: string | null;
    lat: number;
    lng: number;
};

export function GeocodeInput({
    icon,
    placeholder,
    value,
    onChange,
    onSelect,
    onFocus: onFocusProp,
    locatable = false,
}: {
    icon: string;
    placeholder: string;
    value: string;
    onChange: (val: string) => void;
    onSelect: (result: {
        lat: number;
        lng: number;
        name: string;
        label: string;
    }) => void;
    onFocus?: () => void;
    locatable?: boolean;
}) {
    const [results, setResults] = useState<GeoResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [dropdownOpen, setDropdownOpen] = useState(false);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const [locating, setLocating] = useState(false);

    const handleLocate = useCallback(() => {
        if (!navigator.geolocation) return;
        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            async (pos) => {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                try {
                    const res = await fetch(
                        `https://photon.komoot.io/reverse?lat=${lat}&lon=${lng}`,
                    );
                    const data = await res.json();
                    const props = data?.features?.[0]?.properties;
                    const street = props?.street ?? props?.name ?? '';
                    const house = props?.housenumber ?? '';
                    const district = props?.district ?? props?.locality ?? '';
                    const parts = [street, house].filter(Boolean).join(' ');
                    const label =
                        [parts, district].filter(Boolean).join(', ') ||
                        'Current location';

                    onChange(label);
                    onSelect({ lat, lng, name: label, label });
                } catch {
                    onChange('Current location');
                    onSelect({
                        lat,
                        lng,
                        name: 'Current location',
                        label: 'Current location',
                    });
                } finally {
                    setLocating(false);
                }
            },
            () => setLocating(false),
            { enableHighAccuracy: true, timeout: 5000 },
        );
    }, [onChange, onSelect]);

    const search = useCallback((query: string) => {
        if (timerRef.current) {
            clearTimeout(timerRef.current);
        }

        if (query.trim().length < 2) {
            setResults([]);
            setDropdownOpen(false);

            return;
        }

        setLoading(true);
        timerRef.current = setTimeout(async () => {
            try {
                const res = await fetch(
                    `/api/geocode?q=${encodeURIComponent(query.trim())}`,
                );

                if (res.ok) {
                    const data: GeoResult[] = await res.json();
                    setResults(data);
                    setDropdownOpen(data.length > 0);
                }
            } catch {
                setResults([]);
            } finally {
                setLoading(false);
            }
        }, 300);
    }, []);

    useEffect(() => {
        return () => {
            if (timerRef.current) {
                clearTimeout(timerRef.current);
            }
        };
    }, []);

    return (
        <div className="relative flex-1">
            <div
                className="flex cursor-text items-center gap-[10px] transition-all focus-within:shadow-[0_0_0_3px_#EBF0FD]"
                style={{
                    background: '#FFFFFF',
                    border: '1px solid #E2DFD6',
                    borderRadius: 9,
                    padding: '11px 14px',
                }}
            >
                <span
                    style={{
                        fontSize: 15,
                        color: '#AAA89F',
                        flexShrink: 0,
                        cursor: locatable ? 'pointer' : 'default',
                    }}
                    onClick={locatable ? handleLocate : undefined}
                    title={locatable ? 'Use current location' : undefined}
                >
                    {locating ? '⏳' : icon}
                </span>
                <input
                    className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F]"
                    style={{ fontFamily: "'Geist', sans-serif", fontSize: 14 }}
                    placeholder={placeholder}
                    value={value}
                    onChange={(e) => {
                        onChange(e.target.value);
                        search(e.target.value);
                    }}
                    onFocus={() => {
                        onFocusProp?.();

                        if (results.length > 0) {
                            setDropdownOpen(true);
                        }
                    }}
                    onBlur={() => {
                        setTimeout(() => setDropdownOpen(false), 200);
                    }}
                    autoComplete="off"
                />
                {loading && (
                    <div
                        style={{
                            width: 14,
                            height: 14,
                            border: '2px solid #E2DFD6',
                            borderTopColor: '#1A4CD4',
                            borderRadius: '50%',
                            animation: 'spin .7s linear infinite',
                            flexShrink: 0,
                        }}
                    />
                )}
            </div>

            {/* Dropdown */}
            {dropdownOpen && results.length > 0 && (
                <div
                    style={{
                        position: 'absolute',
                        top: '100%',
                        left: 0,
                        right: 0,
                        marginTop: 4,
                        background: 'white',
                        border: '1px solid #E2DFD6',
                        borderRadius: 9,
                        boxShadow: '0 8px 24px rgba(0,0,0,0.08)',
                        zIndex: 60,
                        overflow: 'hidden',
                    }}
                >
                    {results.map((r, idx) => (
                        <div
                            key={idx}
                            onMouseDown={(e) => {
                                e.preventDefault();
                                const label = [r.name, r.street, r.city]
                                    .filter(Boolean)
                                    .join(', ');
                                onChange(label);
                                onSelect({
                                    lat: r.lat,
                                    lng: r.lng,
                                    name: r.name,
                                    label,
                                });
                                setDropdownOpen(false);
                                setResults([]);
                            }}
                            className="cursor-pointer transition-colors hover:bg-[#EFEDE7]"
                            style={{
                                padding: '10px 14px',
                                borderBottom:
                                    idx < results.length - 1
                                        ? '1px solid #F0EDE7'
                                        : 'none',
                            }}
                        >
                            <div
                                style={{
                                    fontSize: 14,
                                    fontWeight: 500,
                                    color: '#18170F',
                                }}
                            >
                                {r.name}
                            </div>
                            <div
                                style={{
                                    fontSize: 12,
                                    color: '#6B6860',
                                    marginTop: 1,
                                }}
                            >
                                {[r.street, r.city].filter(Boolean).join(', ')}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
