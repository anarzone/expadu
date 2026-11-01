/**
 * The header that says a write comes from this app. The XSRF-TOKEN cookie
 * is renewed by Laravel on every response, so it's current after signing in
 * or out without a page load (an Inertia form), where the page's
 * <meta name="csrf-token"> keeps the token it was rendered with. Laravel
 * reads the cookie's (encrypted) value from X-XSRF-TOKEN; the meta, sent as
 * X-CSRF-TOKEN, only stands in when there's no cookie.
 */
export function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    if (cookie) {
        return {
            'X-XSRF-TOKEN': decodeURIComponent(
                cookie.slice('XSRF-TOKEN='.length),
            ),
        };
    }

    return {
        'X-CSRF-TOKEN':
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '',
    };
}
