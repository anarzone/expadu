/** Resolve only after the server has acknowledged a write, never a login page. */
export async function writeJson<T = Record<string, unknown>>(
    url: string,
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    data?: unknown,
): Promise<T> {
    const token =
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        redirect: 'error',
        signal: AbortSignal.timeout(15000),
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
        },
        body: data === undefined ? undefined : JSON.stringify(data),
    }).catch(() => {
        throw new Error(
            'We couldn’t reach the server. Check your connection and try again.',
        );
    });

    if (
        !response.ok ||
        !response.headers.get('content-type')?.includes('application/json')
    ) {
        throw new Error(
            response.status === 401 || response.status === 419
                ? 'Your session expired. Refresh the page and try again.'
                : 'We couldn’t save that. Please try again.',
        );
    }

    return response.json() as Promise<T>;
}
