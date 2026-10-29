import { useState } from 'react';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { Sheet } from '@/components/ui/sheet';
import { showToast } from '@/components/ui/toast';
import { writeJson } from '@/lib/reliable-write';
import './ai-order-paused.css';

/*
 * Today put in order by AI, paused because its notice or the AI service
 * changed since the person's yes (App\Personalisation\TodayRankPause; the
 * consent canvas's boards 9–11): what changed, what waits, what's kept, and
 * one tap to agree to the notice as it reads now. Today keeps the order
 * everyone sees until then.
 */

export type AiOrderPaused = {
    /** The notice as it reads now. */
    notice: string[];
    /** Its lines new since their yes; null when the yes predates keeping its words. */
    changes: string[] | null;
    /** The AI service's model or its instructions changed. */
    model_changed: boolean;
    notice_version: string;
    provider_version: string;
} | null;

/** Their yes to the notice they were shown; Today is ordered again in the background. */
export async function agreeToAiOrder(
    paused: NonNullable<AiOrderPaused>,
): Promise<void> {
    await writeJson('/today-rank', 'PUT', {
        notice_version: paused.notice_version,
        provider_version: paused.provider_version,
    });
    showToast('Agreed. Today is in your order again in a minute.');
}

function Section({
    title,
    icon,
    tone,
    lines,
}: {
    title: string;
    icon: IconName;
    tone: 'changed' | 'waits' | 'kept';
    lines: string[];
}) {
    return (
        <section className={`ai-order-section is-${tone}`}>
            <h3>{title}</h3>
            <ul>
                {lines.map((line) => (
                    <li key={line}>
                        <Icon name={icon} variant="line" />
                        <span>{line}</span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** What changed, as a dialog from 701px and a bottom sheet on phones. */
export function AiOrderChange({
    paused,
    open,
    onClose,
    onAgreed,
    notNow,
}: {
    paused: NonNullable<AiOrderPaused>;
    open: boolean;
    onClose: () => void;
    onAgreed: () => void;
    /** Not now: on Today it's put off for a week; elsewhere it just closes. */
    notNow: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const changed = [
        ...(paused.changes ?? []),
        ...(paused.model_changed
            ? ['The AI service’s model or its instructions changed.']
            : []),
    ];

    async function agree() {
        if (busy) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            await agreeToAiOrder(paused);
            onAgreed();
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'That didn’t go through. Try again.',
            );
        } finally {
            setBusy(false);
        }
    }

    return (
        <Sheet
            open={open}
            onClose={onClose}
            title="Agree to the change?"
            description="We changed how Claude Haiku, the AI that puts Today in order, works with Expadu."
            className="ai-order-change"
            footer={
                <>
                    <p className="ai-order-fine">
                        Your yes is saved with the date and this notice.
                    </p>
                    {error ? (
                        <p className="ai-order-error" role="alert">
                            {error}
                        </p>
                    ) : null}
                    <button
                        type="button"
                        className="ai-order-quiet"
                        onClick={notNow}
                    >
                        Not now
                    </button>
                    <button
                        type="button"
                        className="ai-order-primary"
                        aria-busy={busy || undefined}
                        onClick={() => void agree()}
                    >
                        Agree
                    </button>
                </>
            }
        >
            {/* Without the words of their earlier yes, the notice as it reads now. */}
            {changed.length > 0 ? (
                <Section
                    title="What changed"
                    icon="edit"
                    tone="changed"
                    lines={changed}
                />
            ) : (
                <Section
                    title="How it works now"
                    icon="info"
                    tone="changed"
                    lines={paused.notice}
                />
            )}
            <Section
                title="Paused until you agree"
                icon="clock"
                tone="waits"
                lines={['Today put in order by AI, and its “why” lines']}
            />
            <Section
                title="Kept either way"
                icon="check"
                tone="kept"
                lines={['What Expadu learned, and your settings']}
            />
            {changed.length > 0 ? (
                <details className="ai-order-notice">
                    <summary>Read the whole notice</summary>
                    {paused.notice.map((line) => (
                        <p key={line}>{line}</p>
                    ))}
                </details>
            ) : null}
        </Sheet>
    );
}

/**
 * Today's line over its ideas: they're in the usual order until the person
 * reviews the change. Not now puts it off Today for a week (Settings still
 * says so).
 */
export function AiOrderPausedLine({
    paused,
}: {
    paused: NonNullable<AiOrderPaused>;
}) {
    const [open, setOpen] = useState(false);
    const [done, setDone] = useState(false);

    if (done) {
        return null;
    }

    return (
        <>
            <p className="ai-order-line" role="status">
                <Icon name="clock" variant="line" />
                <span>In the usual order until you agree to a change.</span>
                <button type="button" onClick={() => setOpen(true)}>
                    Review
                </button>
            </p>
            <AiOrderChange
                paused={paused}
                open={open}
                onClose={() => setOpen(false)}
                onAgreed={() => setDone(true)}
                notNow={() => {
                    setOpen(false);
                    setDone(true);
                    void writeJson('/today-rank/later', 'POST', {});
                }}
            />
        </>
    );
}
