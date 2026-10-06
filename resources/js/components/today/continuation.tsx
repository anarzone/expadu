import { Link } from '@inertiajs/react';
import {
    IconBookmark,
    IconMapPin,
    IconSparkles,
    IconX,
} from '@tabler/icons-react';
import { useState } from 'react';
import { dateLabel } from '@/components/composer/types';
import type {
    Message,
    Plan,
    Slot,
    Workspace,
} from '@/components/composer/types';
import type { SavedPlan } from '@/components/today/model';
import { ICON_STROKE } from '@/constants/icons';
import { composer } from '@/routes';

export function ComposerContinuation({
    workspace,
    userId,
    date,
    now,
}: {
    workspace: Workspace | null;
    userId: number;
    date: string;
    now: Date;
}) {
    const draft = workspace?.draft;
    const conversation = workspace?.conversations.find(
        (item) => item.id === workspace.active,
    );
    // Operational acknowledgements belong in the conversation, not Today.
    const latestMessage = conversation?.messages
        .filter((item) => item.role === 'user' && item.text.trim())
        .at(-1);
    const fresh =
        workspace &&
        !workspace.draft &&
        !Object.keys(workspace.plans).length &&
        !workspace.conversations.some((item) => item.messages.length);

    if (!workspace || (!draft && !latestMessage && !fresh)) {
        return null;
    }

    // A new proposal or request can return; unrelated workspace writes cannot.
    const context = draft
        ? `draft:${fingerprint(JSON.stringify(draft))}`
        : latestMessage
          ? `request:${conversation!.id}:${latestMessage.id}`
          : 'welcome';

    return (
        <ContinuationContent
            key={`${userId}:${context}`}
            context={context}
            userId={userId}
            draft={draft}
            latestMessage={latestMessage}
            date={date}
            now={now}
        />
    );
}

/** Store only a dismissal identity, never proposal or conversation contents. */
function fingerprint(value: string) {
    let hash = 2166136261;

    for (let i = 0; i < value.length; i++) {
        hash = Math.imul(hash ^ value.charCodeAt(i), 16777619);
    }

    return (hash >>> 0).toString(36);
}

function ContinuationContent({
    context,
    userId,
    draft,
    latestMessage,
    date,
    now,
}: {
    context: string;
    userId: number;
    draft: Plan | null | undefined;
    latestMessage: Message | undefined;
    date: string;
    now: Date;
}) {
    const storageKey = `expadu:today:continuation:${userId}`;
    const welcomeKey = `expadu:today:welcome:${userId}`;
    const fresh = context === 'welcome';
    const [dismissed, setDismissed] = useState(() => {
        try {
            const stored: unknown = JSON.parse(
                sessionStorage.getItem(storageKey) ?? '[]',
            );

            return (
                (Array.isArray(stored) && stored.includes(context)) ||
                (fresh && localStorage.getItem(welcomeKey) === 'dismissed')
            );
        } catch {
            return false;
        }
    });
    const passedStart =
        draft &&
        (draft.date < date ||
            (draft.date === date &&
                draft.slots.some(
                    (slot) => Date.parse(slot.start_at) < now.getTime(),
                )));

    function dismiss() {
        setDismissed(true);

        try {
            const stored: unknown = JSON.parse(
                sessionStorage.getItem(storageKey) ?? '[]',
            );
            const previous = Array.isArray(stored)
                ? stored.filter((item) => typeof item === 'string')
                : [];
            sessionStorage.setItem(
                storageKey,
                JSON.stringify([...new Set([...previous, context])].slice(-20)),
            );

            if (fresh) {
                localStorage.setItem(welcomeKey, 'dismissed');
            }
        } catch {
            // Dismiss for this visit when browser storage is unavailable.
        }

        const agenda = document.getElementById('today-agenda-heading');

        if (agenda) {
            agenda.setAttribute('tabindex', '-1');
            agenda.focus({ preventScroll: true });
            agenda.addEventListener(
                'blur',
                () => agenda.removeAttribute('tabindex'),
                { once: true },
            );
        }
    }

    if (dismissed) {
        return null;
    }

    return (
        <section
            className="today-composer"
            aria-labelledby="today-composer-heading"
        >
            <div className="today-section-heading">
                <h2 id="today-composer-heading">
                    <IconSparkles size={19} stroke={ICON_STROKE} />
                    {draft
                        ? 'Pick up where you left off'
                        : latestMessage
                          ? 'Your last request'
                          : 'A little help with your day'}
                </h2>
                <button
                    type="button"
                    className="today-icon-button"
                    aria-label={
                        fresh
                            ? 'Dismiss Composer introduction'
                            : 'Dismiss Composer continuation'
                    }
                    onClick={dismiss}
                >
                    <IconX size={17} />
                </button>
            </div>
            {draft ? (
                <>
                    <p>
                        <strong>
                            {dateLabel(draft.date)} ·{' '}
                            {draft.slots.length
                                ? `${draft.slots.length} ${draft.slots.length === 1 ? 'activity' : 'activities'} to review`
                                : 'Plan changes to review'}
                        </strong>
                    </p>
                    <p className="today-muted">
                        {passedStart
                            ? 'Some proposed times have passed. Check the time before applying changes.'
                            : 'These changes are pending. Your confirmed plans stay as they are until you apply them.'}
                    </p>
                    <Link
                        className="today-text-link"
                        href={composer({ query: { plan: draft.date } })}
                    >
                        Review plan
                    </Link>
                </>
            ) : latestMessage ? (
                <>
                    <p className="today-conversation-excerpt">
                        {latestMessage.text}
                    </p>
                    <Link className="today-text-link" href={composer()}>
                        Continue in Composer
                    </Link>
                </>
            ) : (
                <>
                    <p>
                        Composer helps you bring places, events and practical
                        next steps together.
                    </p>
                    <div className="today-starting-actions">
                        <Link
                            href={composer({
                                query: { prompt: 'Help me plan my day', date },
                            })}
                        >
                            Plan a day
                        </Link>
                        <Link
                            href={composer({
                                query: {
                                    prompt: 'Find something to do nearby',
                                    date,
                                },
                            })}
                        >
                            Find something to do
                        </Link>
                    </div>
                    <p className="today-muted text-xs">
                        Choose a starting point, then send when you’re ready.
                    </p>
                </>
            )}
        </section>
    );
}

export function UpcomingPlans({ plans }: { plans: SavedPlan[] }) {
    if (!plans.length) {
        return null;
    }

    return (
        <details className="today-upcoming">
            <summary>
                Coming up
                <span>
                    {plans.length} {plans.length === 1 ? 'day' : 'days'}
                </span>
            </summary>
            <div>
                {plans.map((plan) => (
                    <Link
                        key={plan.date}
                        href={composer({ query: { plan: plan.date! } })}
                        className="today-upcoming-row"
                    >
                        <span className="today-date-tile">
                            {dateLabel(plan.date!)
                                .split(' ')
                                .slice(0, 2)
                                .join(' ')}
                        </span>
                        <span>
                            <strong>{plan.slots[0].name}</strong>
                            <small>
                                {dateLabel(plan.date!)} ·{' '}
                                {plan.slots[0].start_time}
                                {plan.slots.length > 1
                                    ? ` · +${plan.slots.length - 1} more`
                                    : ''}
                            </small>
                        </span>
                    </Link>
                ))}
            </div>
        </details>
    );
}

export function SavedIdeas({
    items,
    onOpen,
}: {
    items: Slot[];
    onOpen: (slot: Slot) => void;
}) {
    if (!items.length) {
        return null;
    }

    return (
        <section className="today-saved" aria-labelledby="today-saved-heading">
            <div className="today-section-heading">
                <h2 id="today-saved-heading">Saved for later</h2>
                <IconBookmark size={18} stroke={ICON_STROKE} />
            </div>
            <div>
                {items.slice(0, 3).map((slot) => (
                    <button
                        key={slot.id}
                        type="button"
                        className="today-saved-row"
                        onClick={() => onOpen(slot)}
                    >
                        <span className="today-saved-icon">
                            <IconMapPin size={21} stroke={ICON_STROKE} />
                        </span>
                        <span>
                            <strong>{slot.name}</strong>
                            <small>
                                {[
                                    slot.category.replaceAll('_', ' '),
                                    slot.veedel,
                                    slot.available === false
                                        ? 'Availability to check'
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </small>
                        </span>
                    </button>
                ))}
            </div>
            {items.length > 3 && (
                <Link className="today-text-link" href={composer()}>
                    Continue with {items.length} saved ideas
                </Link>
            )}
        </section>
    );
}
