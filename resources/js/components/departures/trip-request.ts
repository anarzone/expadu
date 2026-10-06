export class TripRequestError extends Error {}

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
