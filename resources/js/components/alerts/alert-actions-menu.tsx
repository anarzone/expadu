import { router } from '@inertiajs/react';
import {
    IconBellOff,
    IconDotsVertical,
    IconThumbDown,
} from '@tabler/icons-react';
import { useEffect, useRef, useState } from 'react';

type Props = {
    alertId: number;
    subtype: string;
    onAction?: () => void;
};

/**
 * Top-right 3-dot menu on alert rows. Roadmap #6 (mute) + #7 (thumbs-down).
 *
 * Mute: stops push for this alert subtype for 24h. Card still shows in feed.
 * Don't show again: records the alert as user-dismissed (7-day suppression
 *   in ActionBus + negative signal for personalisation).
 */
export function AlertActionsMenu({ alertId, subtype, onAction }: Props) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const handleClickAway = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickAway);

        return () => {
            document.removeEventListener('mousedown', handleClickAway);
        };
    }, [open]);

    const callBackend = (
        path: string,
        body: Record<string, string | number>,
    ) => {
        router.post(path, body, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onAction?.(),
        });
    };

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                aria-label="More actions"
                onClick={(e) => {
                    e.stopPropagation();
                    setOpen((prev) => !prev);
                }}
                className="rounded-md p-1 text-muted-foreground opacity-60 hover:bg-secondary hover:opacity-100 focus:opacity-100 focus:outline-none"
            >
                <IconDotsVertical size={16} stroke={2} />
            </button>

            {open && (
                <div
                    className="absolute top-full right-0 z-50 mt-1 w-[200px] overflow-hidden rounded-lg border border-border bg-background shadow-lg"
                    role="menu"
                >
                    <button
                        type="button"
                        role="menuitem"
                        onClick={(e) => {
                            e.stopPropagation();
                            setOpen(false);
                            callBackend('/mutes', {
                                type: muteTypeFor(subtype),
                                key: 'all',
                                duration_seconds: 86_400,
                            });
                        }}
                        className="flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-[13px] hover:bg-secondary"
                    >
                        <IconBellOff
                            size={15}
                            stroke={1.8}
                            className="text-muted-foreground"
                        />
                        <span>Mute for 24h</span>
                    </button>
                    <button
                        type="button"
                        role="menuitem"
                        onClick={(e) => {
                            e.stopPropagation();
                            setOpen(false);
                            callBackend('/mutes/thumbs-down', {
                                action_key: `alert:${alertId}`,
                                card_type: subtype,
                            });
                        }}
                        className="flex w-full items-center gap-2.5 border-t border-border px-3 py-2.5 text-left text-[13px] hover:bg-secondary"
                    >
                        <IconThumbDown
                            size={15}
                            stroke={1.8}
                            className="text-muted-foreground"
                        />
                        <span>Don't show again</span>
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * Map alert subtype → MuteController-accepted type. The controller validates
 * against a fixed list of ScoredAction types; alerts use slightly different
 * subtype labels so we normalise here.
 */
function muteTypeFor(subtype: string): string {
    const map: Record<string, string> = {
        transit_disruption: 'transit_disruption',
        transit_delay: 'transit_delay',
        weather: 'weather_alert',
        weather_rain: 'weather_alert',
        weather_wind: 'weather_alert',
        weather_temp: 'weather_alert',
        rhine: 'rhine_level',
        buergeramt: 'buergeramt_slot',
        market_closure: 'market_closure',
        leave_by: 'leave_by',
    };

    return map[subtype] ?? 'transit_disruption';
}
