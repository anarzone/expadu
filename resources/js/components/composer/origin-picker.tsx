import { IconCurrentLocation, IconMapPin } from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import GeocodeController from '@/actions/App/Http/Controllers/Api/GeocodeController';
import type {
    GeoResult,
    SavedPlace,
} from '@/components/place-shared/from-types';
import { ComposerLoading } from './loading';
import { api } from './types';

export type PlanOrigin = {
    label: string;
    lat?: number;
    lng?: number;
    from_place?: number;
    from_area?: string;
};
export function OriginPicker({
    saved,
    onSelect,
}: {
    saved: SavedPlace[];
    onSelect: (origin: PlanOrigin | null) => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<GeoResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => {
        if (query.trim().length < 3) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            setLoading(true);
            api<GeoResult[]>(
                GeocodeController.url({ query: { q: query } }),
                undefined,
                controller.signal,
            )
                .then(setResults)
                .catch((err) => {
                    if (!controller.signal.aborted) {
                        setError(err.message);
                    }
                })
                .finally(() => {
                    if (!controller.signal.aborted) {
                        setLoading(false);
                    }
                });
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    return (
        <div>
            <p className="composer-panel-note">
                Choose where new plans start. Your search area can still be
                different—include it in your message. Existing plans stay
                unchanged.
            </p>
            <label className="composer-search">
                <IconMapPin size={18} />
                <input
                    aria-label="Search starting point"
                    maxLength={100}
                    placeholder="Search an address or place…"
                    value={query}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        setResults([]);
                        setLoading(false);
                        setError('');
                    }}
                />
            </label>
            {loading && <ComposerLoading label="Finding places…" />}
            {error && (
                <p className="composer-error" role="alert">
                    {error}
                </p>
            )}
            {query.length >= 3 ? (
                results.map((item, i) => (
                    <button
                        key={i}
                        className="composer-collection-item"
                        onClick={() =>
                            onSelect({
                                label: item.name,
                                lat: item.lat,
                                lng: item.lng,
                            })
                        }
                    >
                        <IconMapPin size={18} />
                        <span>
                            {item.name}
                            <small>{item.address}</small>
                        </span>
                    </button>
                ))
            ) : (
                <>
                    <button
                        className="composer-collection-item"
                        onClick={() => {
                            setLoading(true);

                            if (!navigator.geolocation) {
                                setError(
                                    'Location is not available. Search for an address instead.',
                                );
                                setLoading(false);

                                return;
                            }

                            navigator.geolocation.getCurrentPosition(
                                (position) => {
                                    setLoading(false);
                                    onSelect({
                                        label: 'Your location',
                                        lat: position.coords.latitude,
                                        lng: position.coords.longitude,
                                    });
                                },
                                () => {
                                    setLoading(false);
                                    setError(
                                        'Could not get your location. Search for an address instead.',
                                    );
                                },
                                { timeout: 10000 },
                            );
                        }}
                    >
                        <IconCurrentLocation size={18} />
                        Use my current location
                    </button>
                    {saved.map((place) => (
                        <button
                            key={place.id}
                            className="composer-collection-item"
                            onClick={() =>
                                onSelect({
                                    label: place.name,
                                    from_place: place.id,
                                })
                            }
                        >
                            <IconMapPin size={18} />
                            <span>
                                {place.name}
                                <small>{place.address}</small>
                            </span>
                        </button>
                    ))}
                    <button
                        className="composer-text-button"
                        onClick={() => onSelect(null)}
                    >
                        Use the app’s current location setting
                    </button>
                </>
            )}
        </div>
    );
}
