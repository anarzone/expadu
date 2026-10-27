import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { TimeField } from '@/components/ui/time-field';
import type { UsePushSubscriptionReturn } from '@/hooks/use-push-subscription';
import { writeJson } from '@/lib/reliable-write';
import { SettingsIcon } from './parts';

/*
 * Settings' Notifications (owner, 26 Oct 2026: direction A): this device's
 * push, the day's briefs (and their AI wording, opt-in), the way home,
 * quiet hours and the alert types. Every part shows what the server and
 * this browser say; switches save on tap, times once a turn settles.
 */

/** What PUT /notification-preferences takes and answers (NotificationPreference::stateFor). */
export type NotificationState = {
    preferences: Record<string, boolean>;
    quiet_from: string;
    quiet_to: string;
    /** HH:MM, or null: automatic, 45 minutes before the first plan. */
    brief_at: string | null;
};

/** AI-written briefs (notification_brief_ai, AiBriefsController). */
type AiBriefs = {
    enabled: boolean;
    available: boolean;
    disclosure: {
        notice_version: string;
        provider_version: string;
        processor_name?: string | null;
        processor_privacy_url?: string | null;
        notice?: string[];
    };
};

const AI_BRIEFS_URL = '/notification-preferences/ai-briefs';

/** Alert types: the ActionBus preference gates (NotificationPreference::defaults). */
const TYPES = [
    {
        id: 'transit',
        label: 'Transit disruptions',
        sub: 'Delays on lines and trips you’re taking',
    },
    {
        id: 'checklist',
        label: 'Paperwork deadlines',
        sub: 'Reminders before your paperwork is due',
    },
    {
        id: 'events',
        label: 'Event reminders',
        sub: '1 day before events you’re attending',
    },
    {
        id: 'weather',
        label: 'Weather alerts',
        sub: 'Rain, wind, and commute-affecting weather',
    },
    {
        id: 'rhine',
        label: 'Rhine flood alerts',
        sub: 'When water level exceeds threshold',
    },
];

/** How long a time rests before it's saved: the wheels change it as they turn. */
const SETTLE_MS = 700;

const failure = (error: unknown) =>
    error instanceof Error
        ? error.message
        : 'We couldn’t save that. Please try again.';

/** A switch tile: the label and its line, the switch after. */
function SwitchTile({
    id,
    label,
    note,
    on,
    busy,
    disabled,
    onToggle,
}: {
    id: string;
    label: string;
    note?: ReactNode;
    on: boolean;
    busy?: boolean;
    disabled?: boolean;
    onToggle: () => void;
}) {
    return (
        <div className="settings-tile">
            <span className="settings-tile-copy">
                <strong id={`${id}-label`}>{label}</strong>
                {note ? (
                    <span className="settings-tile-note" id={`${id}-note`}>
                        {note}
                    </span>
                ) : null}
            </span>
            <button
                type="button"
                className="settings-switch"
                role="switch"
                aria-checked={on}
                aria-labelledby={`${id}-label`}
                aria-describedby={note ? `${id}-note` : undefined}
                aria-busy={busy || undefined}
                disabled={disabled}
                onClick={onToggle}
            />
        </div>
    );
}

function Problem({ text }: { text: string | null }) {
    return text ? (
        <p className="settings-error" role="alert">
            {text}
        </p>
    ) : null;
}

export function NotificationsPanel({
    state,
    work,
    push,
}: {
    state: NotificationState;
    /** Work's usual leaving time; null without a Work place. */
    work: { leave_at: string | null } | null;
    /** This device's push (the page's, so the menu's line agrees). */
    push: UsePushSubscriptionReturn;
}) {
    const [pushBusy, setPushBusy] = useState(false);
    const [pushError, setPushError] = useState<string | null>(null);
    const blocked =
        typeof Notification !== 'undefined' &&
        Notification.permission === 'denied';

    const [toggles, setToggles] = useState(state.preferences);
    const [toggleBusy, setToggleBusy] = useState<string | null>(null);
    const [dayError, setDayError] = useState<string | null>(null);
    const [typesError, setTypesError] = useState<string | null>(null);
    const briefs = toggles.brief ?? true;

    const [briefMode, setBriefMode] = useState<'auto' | 'set'>(
        state.brief_at ? 'set' : 'auto',
    );
    const [briefAt, setBriefAt] = useState(state.brief_at ?? '');
    const [quiet, setQuiet] = useState({
        from: state.quiet_from,
        to: state.quiet_to,
    });
    const [quietError, setQuietError] = useState<string | null>(null);
    const settle = useRef<number | undefined>(undefined);

    const [ai, setAi] = useState<AiBriefs | null>(null);
    const [aiBusy, setAiBusy] = useState(false);
    const [noticeOpen, setNoticeOpen] = useState(false);
    const [aiNote, setAiNote] = useState<string | null>(null);

    useEffect(() => () => window.clearTimeout(settle.current), []);

    // The AI row shows only once the server says where it stands.
    useEffect(() => {
        let live = true;

        fetch(AI_BRIEFS_URL, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then((response) =>
                response.ok ? (response.json() as Promise<AiBriefs>) : null,
            )
            .then((answer) => {
                if (live && answer?.disclosure) {
                    setAi(answer);
                }
            })
            .catch(() => undefined);

        return () => {
            live = false;
        };
    }, []);

    /** The menu's line follows each save. */
    const refreshMenu = () =>
        router.reload({ only: ['notifications'], async: true });

    async function toggle(id: string, onError: (text: string) => void) {
        if (toggleBusy) {
            return;
        }

        const next = !(toggles[id] ?? true);
        setToggleBusy(id);

        try {
            await writeJson('/notification-preferences', 'PUT', {
                preferences: { [id]: next },
            });
            setToggles((now) => ({ ...now, [id]: next }));
            refreshMenu();
        } catch (error) {
            onError(failure(error));
        } finally {
            setToggleBusy(null);
        }
    }

    function saveTimes(
        patch: Partial<Omit<NotificationState, 'preferences'>>,
        onError: (text: string | null) => void,
    ) {
        window.clearTimeout(settle.current);
        onError(null);
        settle.current = window.setTimeout(() => {
            writeJson('/notification-preferences', 'PUT', patch)
                .then(refreshMenu)
                .catch((error) => onError(failure(error)));
        }, SETTLE_MS);
    }

    function changeBrief(mode: 'auto' | 'set', at: string) {
        setBriefMode(mode);
        setBriefAt(at);

        if (mode === 'auto') {
            saveTimes({ brief_at: null }, setDayError);
        } else if (at) {
            saveTimes({ brief_at: at }, setDayError);
        }
    }

    function changeQuiet(next: { from: string; to: string }) {
        setQuiet(next);

        if (!next.from || !next.to) {
            return;
        }

        if (next.from === next.to) {
            window.clearTimeout(settle.current);
            setQuietError(
                'Quiet hours need to start and end at different times.',
            );

            return;
        }

        saveTimes({ quiet_from: next.from, quiet_to: next.to }, setQuietError);
    }

    async function togglePush() {
        if (pushBusy) {
            return;
        }

        setPushBusy(true);
        setPushError(null);
        const ok = push.isSubscribed
            ? await push.unsubscribe()
            : await push.subscribe();

        if (!ok) {
            setPushError(
                typeof Notification !== 'undefined' &&
                    Notification.permission === 'denied'
                    ? 'Notifications are blocked in this browser. You can allow them in your browser settings.'
                    : 'We couldn’t update push notifications. Please try again.',
            );
        }

        setPushBusy(false);
    }

    async function toggleAi() {
        if (!ai || aiBusy) {
            return;
        }

        setAiBusy(true);
        setDayError(null);
        setAiNote(null);

        try {
            const answer = await writeJson<{
                enabled: boolean;
                in_flight?: number;
                message?: string;
            }>(
                AI_BRIEFS_URL,
                'PUT',
                ai.enabled
                    ? { enabled: false }
                    : {
                          enabled: true,
                          notice_version: ai.disclosure.notice_version,
                          provider_version: ai.disclosure.provider_version,
                      },
            );
            setAi({ ...ai, enabled: answer.enabled });

            if (answer.in_flight && answer.message) {
                setAiNote(answer.message);
            }
        } catch (error) {
            setDayError(failure(error));
        } finally {
            setAiBusy(false);
        }
    }

    // This device: what the browser says, nothing assumed.
    const device: {
        line: string;
        dot: 'off' | 'on' | 'warn';
        note?: string;
        action?: string;
        retry?: boolean;
    } = !push.isReady
        ? { line: 'Checking this device…', dot: 'off' }
        : !push.isSupported
          ? { line: 'Not supported on this browser', dot: 'off' }
          : blocked
            ? {
                  line: 'Blocked in this browser',
                  dot: 'warn',
                  note: 'Notifications are blocked in this browser. You can allow them in your browser settings.',
              }
            : push.statusError
              ? { line: 'Status unavailable', dot: 'warn', retry: true }
              : push.isSubscribed
                ? {
                      line: 'On · this browser',
                      dot: 'on',
                      note: 'Alerts also arrive on this device.',
                      action: 'Turn off',
                  }
                : {
                      line: 'Off on this device',
                      dot: 'off',
                      note: 'Alerts still show in your alerts feed.',
                      action: 'Turn on',
                  };

    const leave = work?.leave_at ?? null;

    return (
        <section className="settings-panel" aria-labelledby="notify-title">
            <div className="settings-head">
                <span className="settings-chip notify">
                    <SettingsIcon name="bell" />
                </span>
                <div>
                    <h2 id="notify-title">Notifications</h2>
                    <p>Choose what Expadu pings you about, and where.</p>
                </div>
            </div>

            <h3 className="settings-sub">This device</h3>
            <div className="settings-tiles">
                <div className="settings-tile">
                    <span className="settings-tile-copy">
                        <strong>Push notifications</strong>
                        <span className="settings-push" role="status">
                            <span
                                className={`settings-dot ${device.dot}`}
                                aria-hidden="true"
                            />
                            {device.line}
                        </span>
                        {device.note ? (
                            <span className="settings-tile-note">
                                {device.note}
                            </span>
                        ) : null}
                    </span>
                    {device.retry ? (
                        <button
                            type="button"
                            className="settings-quiet"
                            onClick={push.retryStatus}
                        >
                            Try again
                        </button>
                    ) : device.action ? (
                        <button
                            type="button"
                            className="settings-quiet"
                            aria-busy={pushBusy || undefined}
                            onClick={() => void togglePush()}
                        >
                            {device.action}
                        </button>
                    ) : null}
                </div>
            </div>
            <Problem text={pushError} />

            <h3 className="settings-sub">Your day</h3>
            <div className="settings-tiles">
                <SwitchTile
                    id="briefs"
                    label="Morning and evening briefs"
                    note="Before you set off, and the evening before an important day."
                    on={briefs}
                    busy={toggleBusy === 'brief'}
                    onToggle={() =>
                        void toggle('brief', (text) => setDayError(text))
                    }
                />
                {briefs ? (
                    <div className="settings-tile">
                        <span className="settings-tile-copy">
                            <strong>Brief time</strong>
                            <span className="settings-tile-note">
                                {briefMode === 'auto'
                                    ? '45 min before your first plan'
                                    : 'Every morning at this time'}
                            </span>
                        </span>
                        <span className="settings-tile-controls">
                            {briefMode === 'set' ? (
                                <span className="settings-time">
                                    <TimeField
                                        className="is-compact"
                                        label="Every morning at"
                                        value={briefAt}
                                        onChange={(at) =>
                                            changeBrief('set', at)
                                        }
                                    />
                                </span>
                            ) : null}
                            <span
                                className="settings-seg"
                                role="group"
                                aria-label="Brief time"
                            >
                                <button
                                    type="button"
                                    aria-pressed={briefMode === 'auto'}
                                    onClick={() => changeBrief('auto', briefAt)}
                                >
                                    Automatic
                                </button>
                                <button
                                    type="button"
                                    aria-pressed={briefMode === 'set'}
                                    onClick={() => changeBrief('set', briefAt)}
                                >
                                    Set time
                                </button>
                            </span>
                        </span>
                    </div>
                ) : null}
                {ai && (briefs || ai.enabled) ? (
                    <>
                        <SwitchTile
                            id="ai-briefs"
                            label="Write my briefs with AI"
                            note={
                                ai.available ? (
                                    <>
                                        Off: we write them from a template. On:{' '}
                                        {ai.disclosure.processor_name ??
                                            'an AI service'}{' '}
                                        words them from your day.{' '}
                                        <button
                                            type="button"
                                            className="settings-link"
                                            aria-expanded={noticeOpen}
                                            onClick={() =>
                                                setNoticeOpen(!noticeOpen)
                                            }
                                        >
                                            Privacy notice
                                        </button>
                                    </>
                                ) : (
                                    'Not available right now. Your briefs are written from a template.'
                                )
                            }
                            on={ai.enabled}
                            busy={aiBusy}
                            disabled={!ai.available && !ai.enabled}
                            onToggle={() => void toggleAi()}
                        />
                        {noticeOpen ? (
                            <div className="settings-notice">
                                {(ai.disclosure.notice ?? []).map((line) => (
                                    <p key={line}>{line}</p>
                                ))}
                                {ai.disclosure.processor_privacy_url ? (
                                    <a
                                        href={
                                            ai.disclosure.processor_privacy_url
                                        }
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {ai.disclosure.processor_name ??
                                            'The AI service'}
                                        ’s privacy policy
                                    </a>
                                ) : null}
                            </div>
                        ) : null}
                        {aiNote ? (
                            <p className="settings-notice" role="status">
                                {aiNote}
                            </p>
                        ) : null}
                    </>
                ) : null}
            </div>
            <Problem text={dayError} />

            <h3 className="settings-sub">The way home</h3>
            <div className="settings-tiles">
                <div className="settings-tile">
                    <span className="settings-tile-copy">
                        <strong>
                            {leave ? `Leave Work at ${leave}` : 'Leave Work at'}
                        </strong>
                        <span className="settings-tile-note">
                            {work === null
                                ? 'No Work place yet, so delay and weather alerts use 16:00–19:00 for the way home. Add Work in Your places with when you usually leave.'
                                : leave === null
                                  ? 'Not set, so delay and weather alerts use 16:00–19:00 for the way home.'
                                  : 'Delay and weather alerts follow your way home from then, on Work’s days.'}
                        </span>
                    </span>
                    <Link href="/profile#places" className="settings-quiet">
                        {work === null
                            ? 'Add Work'
                            : leave === null
                              ? 'Add a time'
                              : 'Change'}
                    </Link>
                </div>
            </div>

            <h3 className="settings-sub">Quiet hours</h3>
            <div className="settings-tiles">
                <div className="settings-tile">
                    <span className="settings-tile-copy">
                        <strong>
                            Quiet from {quiet.from} until {quiet.to}
                        </strong>
                        <span className="settings-tile-note">
                            Only what’s urgent, or close to what you asked for,
                            comes through.
                        </span>
                    </span>
                    <span className="settings-tile-controls">
                        <span className="settings-time">
                            <TimeField
                                className="is-compact"
                                label="Quiet from"
                                value={quiet.from}
                                onChange={(from) =>
                                    changeQuiet({ ...quiet, from })
                                }
                            />
                        </span>
                        <span aria-hidden="true">–</span>
                        <span className="settings-time">
                            <TimeField
                                className="is-compact"
                                label="Quiet until"
                                value={quiet.to}
                                onChange={(to) => changeQuiet({ ...quiet, to })}
                            />
                        </span>
                    </span>
                </div>
            </div>
            <Problem text={quietError} />

            <h3 className="settings-sub">Alert types</h3>
            <p className="settings-hint">
                {push.isSubscribed
                    ? 'Shown in your alerts feed and pushed to this device.'
                    : 'Shown in your alerts feed. Turn on push above to also get them on this device.'}
            </p>
            <div className="settings-tiles">
                {TYPES.map((type) => (
                    <SwitchTile
                        key={type.id}
                        id={`type-${type.id}`}
                        label={type.label}
                        note={type.sub}
                        on={toggles[type.id] ?? true}
                        busy={toggleBusy === type.id}
                        onToggle={() =>
                            void toggle(type.id, (text) => setTypesError(text))
                        }
                    />
                ))}
            </div>
            <Problem text={typesError} />
        </section>
    );
}

/** The menu's line for Notifications (and whether this device gets push). */
export function notificationsLine(
    state: NotificationState,
    pushOn: boolean,
): string {
    return `${(state.preferences.brief ?? true) ? 'Briefs on' : 'Briefs off'} · quiet ${state.quiet_from}–${state.quiet_to}${pushOn ? ' · push on' : ''}`;
}
