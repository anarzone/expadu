import {
    IconBellOff,
    IconBellRinging,
    IconBrandSafari,
    IconX,
} from '@tabler/icons-react';
import { useCallback, useMemo, useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';
import { usePushSubscription } from '@/hooks/use-push-subscription';

const PUSH_DISMISS_KEY = 'push_prompt_dismissed_at';
const SAFARI_DISMISS_KEY = 'safari_prompt_dismissed_at';
const COOLDOWN_MS = 7 * 24 * 60 * 60 * 1000; // 7 days

function isIos(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    return /iPad|iPhone|iPod/.test(navigator.userAgent);
}

function isStandalone(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    return (
        'standalone' in navigator &&
        (navigator as unknown as { standalone: boolean }).standalone
    );
}

function isSafari(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    const ua = navigator.userAgent;

    return /Safari/.test(ua) && !/Chrome|CriOS|FxiOS/.test(ua);
}

function isDismissed(key: string): boolean {
    if (typeof localStorage === 'undefined') {
        return true;
    }

    const ts = localStorage.getItem(key);

    if (!ts) {
        return false;
    }

    return Date.now() - Number(ts) < COOLDOWN_MS;
}

/**
 * Smart push notification prompt card.
 *
 * Shows one of two prompts:
 * 1. iOS non-Safari: "Add to Home Screen via Safari" prompt
 * 2. Supported browser, not subscribed: "Enable push notifications" prompt
 *
 * Dismissible with 7-day cooldown via localStorage.
 */
export function PushPromptCard() {
    const { isSupported, isSubscribed, subscribe } = usePushSubscription();
    const [loading, setLoading] = useState(false);
    const [dismissed, setDismissed] = useState(false);

    const variant = useMemo(() => {
        if (isIos() && (!isSafari() || !isStandalone())) {
            if (!isDismissed(SAFARI_DISMISS_KEY)) {
                return 'safari' as const;
            }
        }

        if (isSupported && !isSubscribed) {
            if (!isDismissed(PUSH_DISMISS_KEY)) {
                return 'enable' as const;
            }
        }

        return null;
    }, [isSupported, isSubscribed]);

    const dismiss = useCallback(() => {
        const key =
            variant === 'safari' ? SAFARI_DISMISS_KEY : PUSH_DISMISS_KEY;

        localStorage.setItem(key, String(Date.now()));
        setDismissed(true);
    }, [variant]);

    const permissionDenied = useMemo(() => {
        if (typeof Notification === 'undefined') {
            return false;
        }

        return Notification.permission === 'denied';
    }, []);

    const handleEnable = useCallback(async () => {
        setLoading(true);
        const ok = await subscribe();

        setLoading(false);

        if (ok) {
            setDismissed(true);
        }
    }, [subscribe]);

    if (!variant || dismissed) {
        return null;
    }

    if (variant === 'safari') {
        return (
            <div className="relative overflow-hidden rounded-[9px] border border-[#1A4CD4]/15 bg-[#1A4CD4]/[0.06] p-4 dark:border-[#5B8DEF]/20 dark:bg-[#1A4CD4]/10">
                <button
                    onClick={dismiss}
                    className="absolute top-3 right-3 text-muted-foreground transition-colors hover:text-foreground"
                >
                    <IconX size={16} stroke={ICON_STROKE} />
                </button>
                <div className="flex items-start gap-3 pr-5">
                    <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#1A4CD4]/10 dark:bg-[#5B8DEF]/15">
                        <IconBrandSafari
                            size={20}
                            stroke={ICON_STROKE}
                            className="text-[#1A4CD4] dark:text-[#5B8DEF]"
                        />
                    </div>
                    <div className="min-w-0">
                        <div className="text-[13px] font-semibold">
                            Get the best experience
                        </div>
                        <div className="mt-0.5 text-[12px] leading-relaxed text-muted-foreground">
                            Open in Safari and tap{' '}
                            <span className="font-medium text-foreground">
                                Share → Add to Home Screen
                            </span>{' '}
                            to get push notifications and an app-like
                            experience.
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    if (permissionDenied) {
        return (
            <div className="relative overflow-hidden rounded-[9px] border border-amber-500/15 bg-amber-500/[0.06] p-4 dark:border-amber-400/20 dark:bg-amber-500/10">
                <button
                    onClick={dismiss}
                    className="absolute top-3 right-3 text-muted-foreground transition-colors hover:text-foreground"
                >
                    <IconX size={16} stroke={ICON_STROKE} />
                </button>
                <div className="flex items-start gap-3 pr-5">
                    <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 dark:bg-amber-400/15">
                        <IconBellOff
                            size={20}
                            stroke={ICON_STROKE}
                            className="text-amber-600 dark:text-amber-400"
                        />
                    </div>
                    <div className="min-w-0">
                        <div className="text-[13px] font-semibold">
                            Notifications blocked
                        </div>
                        <div className="mt-0.5 text-[12px] leading-relaxed text-muted-foreground">
                            To receive alerts, allow notifications in your
                            browser settings:{' '}
                            <span className="font-medium text-foreground">
                                Site settings → Notifications → Allow
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="relative overflow-hidden rounded-[9px] border border-success/15 bg-success/[0.06] p-4 dark:border-success/20 dark:bg-success/10">
            <button
                onClick={dismiss}
                className="absolute top-3 right-3 text-muted-foreground transition-colors hover:text-foreground"
            >
                <IconX size={16} stroke={ICON_STROKE} />
            </button>
            <div className="flex items-start gap-3 pr-5">
                <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-success/10 dark:bg-success/15">
                    <IconBellRinging
                        size={20}
                        stroke={ICON_STROKE}
                        className="text-success"
                    />
                </div>
                <div className="min-w-0">
                    <div className="text-[13px] font-semibold">
                        Stay in the loop
                    </div>
                    <div className="mt-0.5 text-[12px] leading-relaxed text-muted-foreground">
                        Get notified about transit disruptions, appointments,
                        and events.
                    </div>
                    <button
                        onClick={handleEnable}
                        disabled={loading}
                        className="mt-2.5 rounded-md bg-success px-3.5 py-1.5 text-[12px] font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        {loading ? 'Enabling...' : 'Enable notifications'}
                    </button>
                </div>
            </div>
        </div>
    );
}
