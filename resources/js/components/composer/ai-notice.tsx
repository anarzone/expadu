import { useEffect, useRef, useState } from 'react';
import ProcessingNoticeController from '@/actions/App/Http/Controllers/ProcessingNoticeController';
import { writeJson } from '@/lib/reliable-write';

/**
 * Composer answers a message with an AI service only with the person's say-so (owner,
 * 21 Oct 2026: sending is the consent, with the line under the box always in view).
 * The processor, its privacy notice and the versions a permit names come from the
 * server (GET privacy/processing/composer_assist); nothing about the provider is
 * written here. When the service is off, or the notice can't be read, nothing is
 * shown and no permit is sent: the request is read without AI.
 */
export type AiNotice = {
    processor_name: string;
    processor_privacy_url: string | null;
    notice_version: string;
    provider_version: string;
};

type Disclosure = Partial<AiNotice> & { available?: boolean };

/**
 * The notice, read once per page load (or once the tray first opens, `wanted`);
 * null while it's loading, off or unreadable.
 */
export function useAiNotice(wanted = true): AiNotice | null {
    const [notice, setNotice] = useState<AiNotice | null>(null);
    const read = useRef(false);

    useEffect(() => {
        if (!wanted || read.current) {
            return;
        }

        read.current = true;
        let live = true;

        fetch(ProcessingNoticeController.url('composer_assist'), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then((response) =>
                response.ok ? (response.json() as Promise<Disclosure>) : null,
            )
            .then((disclosure) => {
                if (
                    live &&
                    disclosure?.available &&
                    disclosure.processor_name &&
                    disclosure.notice_version &&
                    disclosure.provider_version
                ) {
                    setNotice({
                        processor_name: disclosure.processor_name,
                        processor_privacy_url:
                            disclosure.processor_privacy_url ?? null,
                        notice_version: disclosure.notice_version,
                        provider_version: disclosure.provider_version,
                    });
                }
            })
            .catch(() => undefined);

        return () => {
            live = false;
        };
    }, [wanted]);

    return notice;
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

type Purpose = 'composer_assist' | 'composer_paperwork';

const WITHDRAW_EVENT = 'composer:withdraw';

/**
 * The line under the box, in its helper text: who reads what you send, their
 * notice, and Withdraw (stopping it, and removing what was kept). Sending is
 * the consent again next time (owner, 21 Oct 2026). Opened for Paperwork's own
 * permission (Composer's hand-off), it shows that one's Withdraw too, ringed.
 */
export function AiNoticeLine({ notice }: { notice: AiNotice }) {
    const line = useRef<HTMLParagraphElement>(null);
    const [said, setSaid] = useState<Partial<Record<Purpose, string>>>({});
    const [saving, setSaving] = useState<Purpose | null>(null);
    const [failed, setFailed] = useState(false);
    const [asked, setAsked] = useState<Purpose | null>(null);

    // Composer's hand-off to Withdraw: this line's button for that purpose, ringed.
    useEffect(() => {
        const open = (event: Event) =>
            setAsked(
                (event as CustomEvent<Purpose>).detail ?? 'composer_assist',
            );
        window.addEventListener(WITHDRAW_EVENT, open);

        return () => window.removeEventListener(WITHDRAW_EVENT, open);
    }, []);
    useEffect(() => {
        if (!asked) {
            return;
        }

        const button = line.current?.querySelector<HTMLElement>(
            `[data-withdraw="${asked}"]`,
        );
        button?.scrollIntoView({ block: 'nearest' });
        button?.focus({ preventScroll: true });
        button?.classList.add('is-ringed');
        const timer = window.setTimeout(
            () => button?.classList.remove('is-ringed'),
            4000,
        );

        return () => window.clearTimeout(timer);
    }, [asked]);

    const withdraw = async (purpose: Purpose) => {
        setSaving(purpose);
        setFailed(false);

        try {
            const result = await writeJson<{ message?: string }>(
                ProcessingNoticeController.withdraw.url(purpose),
                'DELETE',
            );
            setSaid((all) => ({
                ...all,
                [purpose]:
                    result?.message ??
                    'Future processing is stopped and saved responses are removed.',
                // Withdrawing Composer's AI withdraws Paperwork's permission too.
                ...(purpose === 'composer_assist'
                    ? { composer_paperwork: '' }
                    : {}),
            }));
        } catch {
            setFailed(true);
        } finally {
            setSaving(null);
        }
    };

    if (said.composer_assist !== undefined) {
        return (
            <p className="composer-ai-notice" role="status">
                {said.composer_assist}
            </p>
        );
    }

    return (
        <p ref={line} className="composer-ai-notice">
            {`Composer sends what you type, with your nearest stop and neighbourhood, to ${notice.processor_name}, an AI service, to answer you`}
            {notice.processor_privacy_url ? (
                <>
                    {' · '}
                    <a
                        href={notice.processor_privacy_url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Privacy notice
                    </a>
                </>
            ) : null}
            {' · '}
            <button
                type="button"
                className="composer-ai-withdraw"
                data-withdraw="composer_assist"
                disabled={saving !== null}
                onClick={() => void withdraw('composer_assist')}
            >
                Withdraw
            </button>
            {asked === 'composer_paperwork' &&
                (said.composer_paperwork !== undefined ? (
                    <span role="status"> · {said.composer_paperwork}</span>
                ) : (
                    <>
                        {' · '}
                        <button
                            type="button"
                            className="composer-ai-withdraw"
                            data-withdraw="composer_paperwork"
                            disabled={saving !== null}
                            onClick={() => void withdraw('composer_paperwork')}
                        >
                            Withdraw the Paperwork permission
                        </button>
                    </>
                ))}
            {failed && (
                <span role="alert"> That didn’t go through. Try again.</span>
            )}
        </p>
    );
}

/** Composer's hand-off to Withdraw: the notice under the box opens with that purpose's, ringed. */
export function ringWithdraw(purpose: Purpose = 'composer_assist') {
    window.dispatchEvent(new CustomEvent(WITHDRAW_EVENT, { detail: purpose }));
}
