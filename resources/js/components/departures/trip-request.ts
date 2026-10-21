export class TripRequestError extends Error {}

/** Another trip is running and the request didn't say to replace it (409). */
export type TripConflict = {
    destination: string | null;
    arrive_time: string | null;
};

export class TripConflictError extends TripRequestError {
    // A plain field, not a parameter property: Node runs this file with
    // type stripping only (tests/Frontend), which can't rewrite one.
    readonly trip: TripConflict;

    constructor(trip: TripConflict) {
        super('You’re already on a trip. Replace it?');
        this.trip = trip;
    }
}

/** A persisted trip is only confirmed after the shared app state refreshes. */
export async function persistTrip(
    url: string,
    body: Record<string, unknown> | undefined,
    csrf: string,
    refresh: () => Promise<boolean>,
): Promise<void> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (response.status === 409) {
        const data = (await response.json().catch(() => null)) as {
            trip?: TripConflict;
        } | null;

        throw new TripConflictError(
            data?.trip ?? { destination: null, arrive_time: null },
        );
    }

    if (!response.ok) {
        throw new TripRequestError(
            'Your trip change couldn’t be saved. Try again.',
        );
    }

    if (!(await refresh())) {
        throw new TripRequestError(
            'The trip change was saved, but its status couldn’t refresh. Reopen Travel to check it.',
        );
    }
}
