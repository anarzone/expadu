import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import type { DayProjection } from '@/types/day';
import type { DayAround, DayFact, PaperworkGlance } from './day';
import {
    comingUp,
    dayFacts,
    planningDay,
    planningFacts,
    useComposerOrigin,
    weekdayOf,
} from './day';
import type { Surface } from './surface';
import type { Workspace } from './types';
import { askedConversations, conversationMeta } from './views';
import type { Collection } from './views';

const berlin = (iso: string, options: Intl.DateTimeFormatOptions) =>
    new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/Berlin',
        ...options,
    }).format(new Date(iso));

const NAV: { view: Collection; label: string; icon: IconName }[] = [
    { view: 'plans', label: 'My Plans', icon: 'calendar' },
    { view: 'saved', label: 'Saved', icon: 'bookmark' },
    { view: 'history', label: 'History', icon: 'clock' },
];

/** The conversations the rail lists: the open one first, then the most recent with messages. */
function railConversations(workspace: Workspace) {
    const active = workspace.conversations.find(
        (conversation) => conversation.id === workspace.active,
    );

    return [
        ...(active ? [active] : []),
        ...askedConversations(workspace.conversations).filter(
            (conversation) => conversation !== active,
        ),
    ].slice(0, 5);
}

/*
 * Full Composer's rail on a laptop (the Full Composer canvas, board 1): the
 * way back to the page it was opened from, a new conversation, the recent
 * conversations, My Plans, Saved and History in place, and Your day, each
 * of its facts asking about it (the old page's left column). Below 1000px
 * it isn't there: the panel's menu has the same.
 */
export function ComposerRail({
    surface,
    day,
    paperwork,
    around,
}: {
    surface: Surface;
    day?: DayProjection;
    paperwork?: PaperworkGlance;
    around?: DayAround;
}) {
    const origin = useComposerOrigin();
    const { engine, view, setView, fresh, resume, pendingFresh } = surface;
    const { workspace, busy } = engine;
    const conversations = railConversations(workspace);
    const empty =
        workspace.conversations.find((item) => item.id === workspace.active)
            ?.messages.length === 0;

    return (
        <aside className="composer-rail" aria-label="Composer">
            <button
                type="button"
                className="composer-rail-back"
                aria-label={`Back to ${origin.name}. Your conversation stays in Composer.`}
                onClick={() => router.visit(origin.url)}
            >
                <Icon name="back" variant="line" />
                {`Back to ${origin.name}`}
            </button>
            <button
                type="button"
                className="composer-rail-new"
                disabled={!!busy || empty || pendingFresh}
                onClick={() => void fresh()}
            >
                <Icon name="plus" variant="line" />
                New conversation
            </button>
            {conversations.length > 0 && (
                <div className="composer-rail-group">
                    <p className="composer-label">Conversations</p>
                    {conversations.map((conversation) => {
                        const current = conversation.id === workspace.active;

                        return (
                            <button
                                type="button"
                                key={conversation.id}
                                className="composer-rail-conversation"
                                aria-current={current ? 'true' : undefined}
                                data-open={
                                    current && view === 'chat' ? '' : undefined
                                }
                                disabled={!!busy}
                                onClick={() => void resume(conversation.id)}
                            >
                                <strong>
                                    {conversation.messages.length
                                        ? conversation.title
                                        : 'New conversation'}
                                </strong>
                                <span>
                                    {conversation.messages.length
                                        ? conversationMeta(conversation)
                                        : 'Just now'}
                                </span>
                            </button>
                        );
                    })}
                </div>
            )}
            <nav className="composer-rail-nav" aria-label="Your Composer">
                {NAV.map((item) => (
                    <button
                        type="button"
                        key={item.view}
                        aria-current={view === item.view ? 'page' : undefined}
                        onClick={() => setView(item.view)}
                    >
                        <Icon name={item.icon} variant="line" />
                        {item.label}
                    </button>
                ))}
            </nav>
            <YourDay
                day={day}
                paperwork={paperwork}
                around={around}
                workspace={workspace}
                onAsk={(prompt) => void surface.send(prompt)}
                onOpenPlan={(date) => {
                    if (workspace.plans[date]) {
                        void engine
                            .command({ action: 'open', date })
                            .then((opened) => opened && setView('chat'));
                    }
                }}
                onPlans={() => setView('plans')}
            />
        </aside>
    );
}

function YourDay({
    day,
    paperwork,
    around,
    workspace,
    onAsk,
    onOpenPlan,
    onPlans,
}: {
    day?: DayProjection;
    paperwork?: PaperworkGlance;
    around?: DayAround;
    workspace: Workspace;
    onAsk: (prompt: string) => void;
    onOpenPlan: (date: string) => void;
    onPlans: () => void;
}) {
    // While you plan another day, Your day is that day (owner, 18 Oct 2026,
    // board 9); "Show today" goes back, "Show ‹weekday›" returns to it.
    const [todayFor, setTodayFor] = useState<string | null>(null);

    if (!day) {
        return null;
    }

    const planning = planningDay(workspace, day.date);
    const showPlanning = planning && todayFor !== planning.date;
    const facts = showPlanning
        ? planningFacts(planning, workspace)
        : dayFacts(day, paperwork, around);
    const later = showPlanning ? [] : comingUp(workspace, day.date);

    if (facts.length === 0 && later.length === 0 && !planning) {
        return null;
    }

    return (
        <section className="composer-rail-day" aria-labelledby="composer-day">
            <div className="composer-rail-hair" />
            <p id="composer-day" className="composer-label">
                {showPlanning
                    ? `Your ${weekdayOf(planning.date)} · the day you’re planning`
                    : `Your day · ${berlin(day.now, { weekday: 'short', day: 'numeric', month: 'short' })} · ${berlin(day.now, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })}`}
            </p>
            {planning && (
                <button
                    type="button"
                    className="composer-rail-switch"
                    onClick={() =>
                        setTodayFor(showPlanning ? planning.date : null)
                    }
                >
                    {showPlanning
                        ? 'Show today'
                        : `Show ${weekdayOf(planning.date)}`}
                </button>
            )}
            {facts.map((fact) => (
                <DayFactRow
                    key={fact.key}
                    fact={fact}
                    onAsk={onAsk}
                    onOpenPlan={onOpenPlan}
                />
            ))}
            {later.length > 0 && (
                <>
                    <p className="composer-label">Coming up</p>
                    {later.map((plan) => (
                        <button
                            type="button"
                            key={plan.date}
                            className="composer-rail-fact"
                            onClick={onPlans}
                        >
                            <span className="composer-rail-time">
                                {plan.label.split(' ')[0]}
                            </span>
                            <span>
                                <strong>{plan.first.name}</strong>
                                <span>{`${plan.label} · ${plan.first.start_time}`}</span>
                            </span>
                        </button>
                    ))}
                </>
            )}
            {facts.length > 0 && (
                <p className="composer-view-note">Tap one to ask about it.</p>
            )}
        </section>
    );
}

/** One of Your day's facts: what it is and when; a tap asks about it or opens its day. */
function DayFactRow({
    fact,
    onAsk,
    onOpenPlan,
}: {
    fact: DayFact;
    onAsk: (prompt: string) => void;
    onOpenPlan: (date: string) => void;
}) {
    const [when, ...rest] = fact.small.split(' · ');
    const body = (
        <>
            <span className={`composer-rail-time ${fact.tile}`}>{when}</span>
            <span>
                <strong>{fact.strong}</strong>
                {rest.length > 0 && <span>{rest.join(' · ')}</span>}
            </span>
        </>
    );

    if (fact.href) {
        return (
            <Link className="composer-rail-fact" href={fact.href}>
                {body}
            </Link>
        );
    }

    return (
        <button
            type="button"
            className="composer-rail-fact"
            disabled={!fact.plan && !fact.prompt}
            onClick={() =>
                fact.plan
                    ? onOpenPlan(fact.plan)
                    : fact.prompt && onAsk(fact.prompt)
            }
        >
            {body}
        </button>
    );
}
