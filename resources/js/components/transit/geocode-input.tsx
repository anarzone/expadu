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
    gpsPosition,
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
    gpsPosition?: { lat: number; lng: number } | null;
}) {
    const [results, setResults] = useState<GeoResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [dropdownOpen, setDropdownOpen] = useState(false);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const [locating, setLocating] = useState(false);

    // Refs to avoid stale closures in geolocation callback
    const onChangeRef = useRef(onChange);
    const onSelectRef = useRef(onSelect);
    onChangeRef.current = onChange;
    onSelectRef.current = onSelect;

    async function resolveAddress(lat: number, lng: number) {
        try {
            const res = await fetch(
                `/api/reverse-geocode?lat=${lat}&lng=${lng}`,
            );
            const data = await res.json();

            return data?.address || `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
        } catch {
            return `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
        }
    }

    function handleLocate() {
        setLocating(true);

        // Use existing GPS position if available (instant)
        if (gpsPosition?.lat && gpsPosition?.lng) {
            resolveAddress(gpsPosition.lat, gpsPosition.lng).then((label) => {
                onChangeRef.current(label);
                onSelectRef.current({
                    lat: gpsPosition.lat,
                    lng: gpsPosition.lng,
                    name: label,
                    label,
                });
                setLocating(false);
            });

            return;
        }

        // Fallback: request fresh position (low accuracy for speed)
        if (!navigator.geolocation) {
            setLocating(false);

            return;
        }

        navigator.geolocation.getCurrentPosition(
            async (pos) => {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                const label = await resolveAddress(lat, lng);
                onChangeRef.current(label);
                onSelectRef.current({ lat, lng, name: label, label });
                setLocating(false);
            },
            () => setLocating(false),
            { enableHighAccuracy: false, timeout: 5000, maximumAge: 60000 },
        );
    }

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
            <div className="flex cursor-text items-center gap-[10px] rounded-[9px] border border-[#E2DFD6] bg-white px-3.5 py-[11px] transition-all focus-within:shadow-[0_0_0_3px_#EBF0FD] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:focus-within:shadow-[0_0_0_3px_rgba(91,141,239,0.15)]">
                <span
                    className="shrink-0 text-[15px] text-[#AAA89F] dark:text-[#6B6A60]"
                    style={{ cursor: locatable ? 'pointer' : 'default' }}
                    onClick={locatable ? handleLocate : undefined}
                    title={locatable ? 'Use current location' : undefined}
                >
                    {locating ? '⏳' : icon}
                </span>
                <input
                    className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F] dark:text-[#F6F5F1] dark:placeholder:text-[#6B6A60]"
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
                        className="shrink-0 rounded-full border-2 border-[#E2DFD6] dark:border-[#3A3930]"
                        style={{
                            width: 14,
                            height: 14,
                            borderTopColor: '#1A4CD4',
                            animation: 'spin .7s linear infinite',
                        }}
                    />
                )}
            </div>

            {/* Dropdown */}
            {dropdownOpen && results.length > 0 && (
                <div className="absolute top-full right-0 left-0 z-[60] mt-1 overflow-hidden rounded-[9px] border border-[#E2DFD6] bg-white shadow-[0_8px_24px_rgba(0,0,0,0.08)] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:shadow-[0_8px_24px_rgba(0,0,0,0.4)]">
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
                            className={`cursor-pointer px-3.5 py-2.5 transition-colors hover:bg-[#EFEDE7] dark:hover:bg-[#2A2920] ${
                                idx < results.length - 1
                                    ? 'border-b border-[#F0EDE7] dark:border-[#2A2920]'
                                    : ''
                            }`}
                        >
                            <div className="text-sm font-medium text-[#18170F] dark:text-[#F6F5F1]">
                                {r.name}
                            </div>
                            <div className="mt-px text-xs text-[#6B6860] dark:text-[#AAA89F]">
                                {[r.street, r.city].filter(Boolean).join(', ')}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
