import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import {
    Toggle,
    ToggleCard,
    ToggleRow,
} from '@/components/settings/toggle-row';
import { TimeField } from '@/components/ui/time-field';
import { usePushSubscription } from '@/hooks/use-push-subscription';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { writeJson } from '@/lib/reliable-write';

/**
 * Per-type toggles. Keys map to the ActionBus preference gates
 * (see NotificationPreference::defaults): transit / checklist / events /
 * weather / rhine. (The `burgeramt` gate has no live producer since the
 * slot-checker was removed, so it isn't surfaced as a toggle.)
 */
const NOTIFICATION_TYPES: { id: string; label: string; sub: string }[] = [
    {
        id: 'transit',
        label: 'Transit disruptions',
        sub: "Delays on lines and trips you're taking",
    },
    {
        id: 'checklist',
        label: 'Bureaucracy deadlines',
        sub: 'Reminders before your paperwork is due',
    },
    {
        id: 'events',
        label: 'Event reminders',
        sub: "1 day before events you're attending",
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

/** What PUT /notification-preferences takes and answers (NotificationPreference::stateFor). */
type NotificationState = {
    preferences: Record<string, boolean>;
    quiet_from: string;
    quiet_to: string;
    /** HH:MM, or null: automatic, 45 minutes before the first plan. */
    brief_at: string | null;
};

/**
 * AI-written briefs (notification_brief_ai, AiBriefsController): off unless
 * they turn it on, under the notice they were shown.
 */
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

/** How long a time rests before it's saved: the wheels change it as they turn. */
const SETTLE_MS = 700;

/** A settings row with its own control: a label and a line under it, the control after. */
function SettingRow({
    label,
    sub,
    children,
}: {
    label: string;
    sub?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-b border-border px-4 py-[13px] last:border-b-0">
            {/* A toggle stays beside the words; wider controls drop under them on a phone. */}
            <div className="min-w-0 flex-[1_1_220px]">
                <div className="text-[13.5px] font-medium">{label}</div>
                {sub && (
                    <div className="mt-0.5 text-[11.5px] text-muted-foreground">
                        {sub}
                    </div>
                )}
            </div>
            <div className="flex items-center gap-2">{children}</div>
        </div>
    );
}

/** A compact choice of two or three, as the place sheet's chips. */
function Choice<T extends string>({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: T;
    options: { id: T; label: string }[];
    onChange: (value: T) => void;
}) {
    return (
        <div role="group" aria-label={label} className="flex gap-1.5">
            {options.map((option) => (
                <button
                    key={option.id}
                    type="button"
                    aria-pressed={value === option.id}
                    onClick={() => onChange(option.id)}
                    className={`min-h-9 rounded-full px-3 text-[12px] font-medium whitespace-nowrap transition-colors duration-150 motion-reduce:transition-none ${value === option.id ? 'bg-select-bg text-select-text' : 'bg-bg-quiet text-text-soft'}`}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

export default function NotificationsSettings() {
    const page = usePage<NotificationState>().props;
    const { preferences } = page;

    const {
        isReady,
        isSupported,
        isSubscribed,
        statusError,
        retryStatus,
        subscribe,
        unsubscribe,
    } = usePushSubscription();

    const [toggles, setToggles] =
        useState<Record<string, boolean>>(preferences);
    const [pushLoading, setPushLoading] = useState(false);

    const savingRef = useRef(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Quiet hours and the brief's time, saved once a turn of the wheels settles.
    const [quiet, setQuiet] = useState({
        from: page.quiet_from,
        to: page.quiet_to,
    });
    const [briefMode, setBriefMode] = useState<'auto' | 'set'>(
        page.brief_at ? 'set' : 'auto',
    );
    const [briefAt, setBriefAt] = useState(page.brief_at ?? '');
    const [timesError, setTimesError] = useState<string | null>(null);
    const settle = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(settle.current), []);

    function saveTimes(patch: Partial<Omit<NotificationState, 'preferences'>>) {
        window.clearTimeout(settle.current);
        setTimesError(null);
        settle.current = window.setTimeout(() => {
            setSaving(true);
            writeJson('/notification-preferences', 'PUT', patch)
                .catch((error) =>
                    setTimesError(
                        error instanceof Error
                            ? error.message
                            : 'We couldn’t save that. Please try again.',
                    ),
                )
                .finally(() => setSaving(false));
        }, SETTLE_MS);
    }

    function changeQuiet(next: { from: string; to: string }) {
        setQuiet(next);

        if (!next.from || !next.to) {
            return;
        }

        if (next.from === next.to) {
            window.clearTimeout(settle.current);
            setTimesError(
                'Quiet hours need to start and end at different times.',
            );

            return;
        }

        saveTimes({ quiet_from: next.from, quiet_to: next.to });
    }

    function changeBrief(mode: 'auto' | 'set', at: string) {
        setBriefMode(mode);
        setBriefAt(at);

        if (mode === 'auto') {
            saveTimes({ brief_at: null });
        } else if (at) {
            saveTimes({ brief_at: at });
        }
    }

    // AI wording for the briefs: its own switch and notice. The row shows
    // once the server answers for it.
    const [ai, setAi] = useState<AiBriefs | null>(null);
    const [noticeOpen, setNoticeOpen] = useState(false);
    const [aiNote, setAiNote] = useState<string | null>(null);

    useEffect(() => {
        let live = true;

        fetch(AI_BRIEFS_URL, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then((response) =>
                response.ok ? (response.json() as Promise<AiBriefs>) : null,
            )
            .then((state) => {
                if (live && state?.disclosure) {
                    setAi(state);
                }
            })
            .catch(() => undefined);

        return () => {
            live = false;
        };
    }, []);

    async function toggleAi() {
        if (!ai || savingRef.current) {
            return;
        }

        savingRef.current = true;
        setSaving(true);
        setError(null);
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
            setError(
                error instanceof Error
                    ? error.message
                    : 'We couldn’t save that. Please try again.',
            );
        } finally {
            savingRef.current = false;
            setSaving(false);
        }
    }

    async function toggle(id: string) {
        if (savingRef.current) {
            return;
        }

        savingRef.current = true;
        setSaving(true);
        setError(null);
        // A toggle not saved yet reads as on, as its default is.
        const next = { ...toggles, [id]: !(toggles[id] ?? true) };

        try {
            await writeJson('/notification-preferences', 'PUT', {
                preferences: { [id]: next[id] },
            });
            setToggles(next);
        } catch (error) {
            setError(
                error instanceof Error
                    ? error.message
                    : 'We couldn’t save that. Please try again.',
            );
        } finally {
            savingRef.current = false;
            setSaving(false);
        }
    }

    async function handlePushToggle() {
        if (pushLoading) {
            return;
        }

        setPushLoading(true);
        setError(null);
        const saved = isSubscribed ? await unsubscribe() : await subscribe();

        if (!saved) {
            setError(
                typeof Notification !== 'undefined' &&
                    Notification.permission === 'denied'
                    ? 'Notifications are blocked in this browser. You can allow them in your browser settings.'
                    : 'We couldn’t update push notifications. Please try again.',
            );
        }

        setPushLoading(false);
    }

    return (
        <AppLayout>
            <Head title="Notification settings" />

            <h1 className="sr-only">Notification settings</h1>

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Notifications"
                        description="Choose what Expadu pings you about, and where."
                    />

                    {/* Master push toggle */}
                    <ToggleCard>
                        <div className="flex items-center justify-between bg-bg-quiet px-4 py-[13px]">
                            <div>
                                <div className="text-[13.5px] font-semibold">
                                    Push notifications
                                </div>
                                <div className="mt-0.5 text-[11.5px] text-text-muted">
                                    {!isReady
                                        ? 'Checking this device…'
                                        : !isSupported
                                          ? 'Not supported on this browser'
                                          : statusError
                                            ? 'Status unavailable'
                                            : isSubscribed
                                              ? 'Enabled — you will receive alerts on this device'
                                              : 'Enable to receive alerts on this device'}
                                </div>
                            </div>
                            {isSupported && (
                                <Toggle
                                    label="Push notifications"
                                    on={isSubscribed}
                                    onClick={handlePushToggle}
                                    disabled={
                                        pushLoading || !isReady || !!statusError
                                    }
                                />
                            )}
                        </div>
                    </ToggleCard>
                    {statusError && (
                        <div
                            role="alert"
                            className="text-sm text-conflict-text"
                        >
                            {statusError}{' '}
                            <button
                                type="button"
                                className="min-h-11 font-semibold underline"
                                onClick={retryStatus}
                            >
                                Try again
                            </button>
                        </div>
                    )}

                    {/* The day's briefs (DayBriefs): on by default; the AI
                        wording is a separate opt-in. */}
                    <div>
                        <div className="mb-2 px-1">
                            <div className="text-[13.5px] font-semibold">
                                Your day
                            </div>
                            <div className="mt-0.5 text-[11.5px] text-text-muted">
                                A short note on what’s ahead, and quiet when you
                                need it.
                            </div>
                        </div>
                        <ToggleCard>
                            <ToggleRow
                                label="Morning and evening briefs"
                                sub="Before you set off, and the evening before an important day."
                                on={toggles.brief ?? true}
                                onToggle={() => void toggle('brief')}
                                disabled={saving}
                            />
                            {ai && (
                                <div className="border-b border-border last:border-b-0">
                                    <SettingRow
                                        label="Write my briefs with AI"
                                        sub={
                                            ai.available ? (
                                                <>
                                                    Off: we write them from a
                                                    template. On:{' '}
                                                    {ai.disclosure
                                                        .processor_name ??
                                                        'an AI service'}{' '}
                                                    words them from your day.{' '}
                                                    <button
                                                        type="button"
                                                        aria-expanded={
                                                            noticeOpen
                                                        }
                                                        onClick={() =>
                                                            setNoticeOpen(
                                                                !noticeOpen,
                                                            )
                                                        }
                                                        className="font-medium text-select-text underline"
                                                    >
                                                        Privacy notice
                                                    </button>
                                                </>
                                            ) : (
                                                'Not available right now. Your briefs are written from a template.'
                                            )
                                        }
                                    >
                                        <Toggle
                                            label="Write my briefs with AI"
                                            on={ai.enabled}
                                            onClick={() => void toggleAi()}
                                            disabled={
                                                saving ||
                                                (!ai.available && !ai.enabled)
                                            }
                                        />
                                    </SettingRow>
                                    {noticeOpen && (
                                        <div className="space-y-1.5 px-4 pb-[13px] text-[11.5px] text-muted-foreground">
                                            {(ai.disclosure.notice ?? []).map(
                                                (line) => (
                                                    <p key={line}>{line}</p>
                                                ),
                                            )}
                                            {ai.disclosure
                                                .processor_privacy_url && (
                                                <a
                                                    href={
                                                        ai.disclosure
                                                            .processor_privacy_url
                                                    }
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="inline-block font-medium text-select-text underline"
                                                >
                                                    {ai.disclosure
                                                        .processor_name ??
                                                        'The AI service'}
                                                    ’s privacy policy
                                                </a>
                                            )}
                                        </div>
                                    )}
                                    {aiNote && (
                                        <p
                                            role="status"
                                            className="px-4 pb-[13px] text-[11.5px] text-muted-foreground"
                                        >
                                            {aiNote}
                                        </p>
                                    )}
                                </div>
                            )}
                            {(toggles.brief ?? true) && (
                                <SettingRow
                                    label="Brief time"
                                    sub={
                                        briefMode === 'auto'
                                            ? '45 min before your first plan'
                                            : 'Every morning at this time'
                                    }
                                >
                                    <Choice
                                        label="Brief time"
                                        value={briefMode}
                                        options={[
                                            { id: 'auto', label: 'Automatic' },
                                            { id: 'set', label: 'Set time' },
                                        ]}
                                        onChange={(mode) =>
                                            changeBrief(mode, briefAt)
                                        }
                                    />
                                    {briefMode === 'set' && (
                                        <div className="w-[72px]">
                                            <TimeField
                                                label="Brief time"
                                                className="is-compact"
                                                value={briefAt}
                                                onChange={(at) =>
                                                    changeBrief('set', at)
                                                }
                                            />
                                        </div>
                                    )}
                                </SettingRow>
                            )}
                            <SettingRow
                                label="Quiet hours"
                                sub="Only what’s urgent, or close to what you asked for, comes through."
                            >
                                <div className="w-[72px]">
                                    <TimeField
                                        label="Quiet from"
                                        className="is-compact"
                                        value={quiet.from}
                                        onChange={(from) =>
                                            changeQuiet({ ...quiet, from })
                                        }
                                    />
                                </div>
                                <span aria-hidden="true">–</span>
                                <div className="w-[72px]">
                                    <TimeField
                                        label="Quiet until"
                                        className="is-compact"
                                        value={quiet.to}
                                        onChange={(to) =>
                                            changeQuiet({ ...quiet, to })
                                        }
                                    />
                                </div>
                            </SettingRow>
                        </ToggleCard>
                        {timesError && (
                            <p
                                role="alert"
                                className="mt-2 px-1 text-sm text-conflict-text"
                            >
                                {timesError}
                            </p>
                        )}
                    </div>

                    {/* Per-type toggles. These also gate the in-app alerts
                        feed, so they stay meaningful when device push is off —
                        the sub-label spells that out to avoid the "do these do
                        anything?" confusion. */}
                    <div>
                        <div className="mb-2 px-1">
                            <div className="text-[13.5px] font-semibold">
                                Alert types
                            </div>
                            <div className="mt-0.5 text-[11.5px] text-text-muted">
                                {isSubscribed
                                    ? 'Shown in your alerts feed and pushed to this device.'
                                    : 'Shown in your alerts feed. Turn on push above to also get them on this device.'}
                            </div>
                        </div>
                        <ToggleCard>
                            {NOTIFICATION_TYPES.map((setting) => (
                                <ToggleRow
                                    key={setting.id}
                                    label={setting.label}
                                    sub={setting.sub}
                                    on={!!toggles[setting.id]}
                                    onToggle={() => void toggle(setting.id)}
                                    disabled={saving}
                                />
                            ))}
                        </ToggleCard>
                    </div>
                    {error && (
                        <p role="alert" className="text-sm text-conflict-text">
                            {error}
                        </p>
                    )}
                    {saving && (
                        <p role="status" className="text-sm text-text-muted">
                            Saving…
                        </p>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
