export type Departure = {
    stop_name?: string;
    line: string;
    destination: string;
    type: string;
    color: string;
    minutes: number[];
    delay: number;
    cancelled: boolean;
    disrupted: boolean;
    direction: number | null;
    via: string[];
    services?: Array<{
        id: string;
        stop_name?: string;
        departure_at: string | null;
        scheduled_at?: string | null;
        expected_at?: string | null;
        time_basis?: string;
        cancelled?: boolean;
        delay?: number;
        boarding_point?: {
            id?: string;
            name?: string;
            platform?: string;
        } | null;
    }>;
};

export type DepartureFilters = {
    mode: 'all' | 'tram' | 'bus' | 'rail';
    line: string;
    direction: string;
    notices: boolean;
};

export function filterDepartures(
    departures: Departure[],
    filters: DepartureFilters,
): Departure[] {
    return departures.filter(
        (departure) =>
            (filters.mode === 'all' ||
                departure.type === filters.mode ||
                (filters.mode === 'tram' && departure.type === 'subway')) &&
            (filters.line === 'all' || departure.line === filters.line) &&
            (filters.direction === 'all' ||
                departure.destination === filters.direction) &&
            (!filters.notices ||
                departure.cancelled ||
                departure.disrupted ||
                departure.delay > 0 ||
                departure.services?.some(
                    (service) => service.cancelled || (service.delay ?? 0) > 0,
                )),
    );
}

export type DepartureRow = {
    departure: Departure;
    at: number | null;
    key: string;
    estimated: boolean;
    boardingPoint?: { id?: string; name?: string; platform?: string } | null;
};

/** Prefer individual provider calls; legacy countdown groups remain explicitly approximate. */
export function departureRows(
    departures: Departure[],
    receivedAt: number,
): DepartureRow[] {
    return departures
        .flatMap<DepartureRow>((departure) => {
            const identity = `${departure.stop_name ?? ''}:${departure.type}:${departure.line}:${departure.destination}:${departure.direction ?? ''}`;

            if (departure.services?.length) {
                return departure.services.map((service) => {
                    const parsed = Date.parse(service.departure_at ?? '');

                    return {
                        departure: {
                            ...departure,
                            stop_name: service.stop_name ?? departure.stop_name,
                            cancelled: service.cancelled ?? departure.cancelled,
                            delay: service.delay ?? departure.delay,
                        },
                        at: Number.isFinite(parsed) ? parsed : null,
                        key: `${identity}:${service.id}`,
                        estimated: false,
                        boardingPoint: service.boarding_point ?? null,
                    };
                });
            }

            if (departure.cancelled) {
                return [
                    {
                        departure,
                        at: null,
                        key: `${identity}:cancelled`,
                        estimated: true,
                    },
                ];
            }

            return [
                ...new Set(
                    departure.minutes.filter(
                        (minute) => Number.isFinite(minute) && minute >= 0,
                    ),
                ),
            ].map((minute) => {
                const at =
                    Math.round((receivedAt + minute * 60_000) / 60_000) *
                    60_000;

                return {
                    departure,
                    at,
                    key: `${identity}:${at}`,
                    estimated: true,
                };
            });
        })
        .sort(
            (a, b) =>
                (a.at ?? Infinity) - (b.at ?? Infinity) ||
                a.departure.line.localeCompare(b.departure.line, undefined, {
                    numeric: true,
                }),
        );
}

/** Never conceal part of a simultaneous departure group behind More. */
export function visibleDepartureRows(
    rows: DepartureRow[],
    limit: number,
): DepartureRow[] {
    const boundary = rows[limit - 1]?.at;

    return rows.filter(
        (row, index) =>
            index < limit || (boundary !== null && row.at === boundary),
    );
}
