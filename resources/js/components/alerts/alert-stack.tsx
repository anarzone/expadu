import { useState } from 'react';
import { AlertRow } from '@/components/alerts/alert-row';
import type { AlertData } from '@/components/alerts/alert-row';

export type AlertStack = {
    stackType: 'stack';
    stackKey: string;
    subtype: string;
    latest: AlertData;
    count: number;
    alerts: AlertData[];
};

const subtypeLabels: Record<string, { emoji: string; label: string }> = {
    transit_disruption: { emoji: '🚇', label: 'transit disruptions' },
    transit_delay: { emoji: '🚇', label: 'transit delays' },
    weather: { emoji: '🌦️', label: 'weather alerts' },
    social_activity: { emoji: '🗣️', label: 'social updates' },
    generic: { emoji: '🔔', label: 'notifications' },
};

export function AlertStackRow({
    stack,
    onMarkRead,
    onDismiss,
}: {
    stack: AlertStack;
    onMarkRead?: (id: number) => void;
    onDismiss?: (id: number) => void;
}) {
    const [expanded, setExpanded] = useState(false);
    const info = subtypeLabels[stack.subtype] ?? subtypeLabels.generic;
    const unreadCount = stack.alerts.filter((a) => !a.read_at).length;

    function markAllInStackRead() {
        for (const alert of stack.alerts) {
            if (!alert.read_at) {
                onMarkRead?.(alert.id);
            }
        }
    }

    return (
        <div className="border-b border-border">
            {/* Stack header — always visible */}
            <div
                onClick={() => setExpanded(!expanded)}
                className={`flex cursor-pointer items-center gap-3 px-6 py-3 transition-colors hover:bg-secondary/50 ${
                    unreadCount > 0 ? 'bg-primary/[0.03]' : ''
                }`}
            >
                <span className="text-lg">{info.emoji}</span>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold">
                            {stack.latest.title}
                        </span>
                        <span className="shrink-0 rounded-full bg-muted px-1.5 py-px text-[10px] font-bold text-muted-foreground">
                            +{stack.count - 1}
                        </span>
                        {unreadCount > 0 && (
                            <span className="size-2 rounded-full bg-primary" />
                        )}
                    </div>
                    {stack.latest.body && (
                        <div className="mt-0.5 truncate text-[13px] text-muted-foreground">
                            {stack.latest.body}
                        </div>
                    )}
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {unreadCount > 0 && (
                        <button
                            onClick={(e) => {
                                e.stopPropagation();
                                markAllInStackRead();
                            }}
                            className="text-[11px] font-semibold text-primary"
                        >
                            Read all
                        </button>
                    )}
                    <svg
                        width="16"
                        height="16"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        className={`text-muted-foreground transition-transform ${expanded ? 'rotate-180' : ''}`}
                    >
                        <polyline points="6 9 12 15 18 9" />
                    </svg>
                </div>
            </div>

            {/* Expanded alerts */}
            {expanded && (
                <div className="border-t border-border bg-secondary/20">
                    {stack.alerts.map((alert) => (
                        <AlertRow
                            key={alert.id}
                            alert={alert}
                            onMarkRead={onMarkRead}
                            onDismiss={onDismiss}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
