import { useEffect, useRef, useState } from 'react';
import ProcessingNoticeController from '@/actions/App/Http/Controllers/ProcessingNoticeController';

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

/** The line under the box, in its helper text: who reads what you send, and their notice. */
export function AiNoticeLine({ notice }: { notice: AiNotice }) {
    return (
        <p className="composer-ai-notice">
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
        </p>
    );
}
