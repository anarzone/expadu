/**
 * Reading Composer's AI notice (GET privacy/processing/composer_assist), shared by
 * the line under the box and by sending: one read at a time, and a message never
 * goes without its permit while the notice could still be read (owner, 26 Oct
 * 2026: a message sent before the notice loaded was answered "sent without it").
 * Plain TypeScript, without the app's aliases, so node's tests can load it.
 */
export type AiNotice = {
    processor_name: string;
    processor_privacy_url: string | null;
    notice_version: string;
    provider_version: string;
};

/** What the notice said: on (send with its permit), off (send without), or no answer. */
export type AiNoticeRead =
    | { state: 'on'; notice: AiNotice }
    | { state: 'off' }
    | { state: 'unreadable' };

type Disclosure = Partial<AiNotice> & { available?: boolean };

/** How long a send waits for the notice before saying the AI service couldn't be reached. */
export const NOTICE_TIMEOUT = 5000;

// A read that answered (on or off) holds for the page; one that didn't is tried again.
let known: AiNoticeRead | null = null;
let reading: Promise<AiNoticeRead> | null = null;
// Whoever shows the line under the box hears when the notice is known.
const listeners = new Set<() => void>();

/** The notice once a read has found it on; null before, or when off. */
export const knownAiNotice = (): AiNotice | null =>
    known?.state === 'on' ? known.notice : null;

export function subscribeAiNotice(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

function read(url: string): Promise<AiNoticeRead> {
    return fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    })
        .then((response) =>
            response.ok ? (response.json() as Promise<Disclosure>) : null,
        )
        .then((disclosure): AiNoticeRead => {
            if (disclosure?.available === false) {
                return { state: 'off' };
            }

            return disclosure?.available &&
                disclosure.processor_name &&
                disclosure.notice_version &&
                disclosure.provider_version
                ? {
                      state: 'on',
                      notice: {
                          processor_name: disclosure.processor_name,
                          processor_privacy_url:
                              disclosure.processor_privacy_url ?? null,
                          notice_version: disclosure.notice_version,
                          provider_version: disclosure.provider_version,
                      },
                  }
                : { state: 'unreadable' };
        })
        .catch((): AiNoticeRead => ({ state: 'unreadable' }));
}

/** The notice, read once (one read in flight at a time); unreadable after `timeout`. */
export function readAiNotice(
    url: string,
    timeout = NOTICE_TIMEOUT,
): Promise<AiNoticeRead> {
    if (known) {
        return Promise.resolve(known);
    }

    reading ??= read(url).then((result) => {
        reading = null;

        if (result.state !== 'unreadable') {
            known = result;
            listeners.forEach((listener) => listener());
        }

        return result;
    });

    let timer: ReturnType<typeof setTimeout> | undefined;
    const late = new Promise<AiNoticeRead>((resolve) => {
        timer = setTimeout(() => resolve({ state: 'unreadable' }), timeout);
    });

    return Promise.race([reading, late]).finally(() => clearTimeout(timer));
}

/** Forgets what was read, for tests. */
export function forgetAiNotice(): void {
    known = null;
    reading = null;
}

/** The permit a message sends: one request's say-so, for the notice the person saw. */
export function aiPermit(notice: AiNotice | null): object {
    return notice
        ? {
              processing: {
                  consent: true,
                  request_id: crypto.randomUUID(),
                  notice_version: notice.notice_version,
                  provider_version: notice.provider_version,
              },
          }
        : {};
}
