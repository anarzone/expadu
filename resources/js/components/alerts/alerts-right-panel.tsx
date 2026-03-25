import { useState } from 'react';

type AlertCounts = {
    unread: number;
    system: number;
    social: number;
    reminder: number;
};

const notificationSettings = [
    { id: 'transit', label: 'Transit disruptions', sub: 'Delays on your saved routes', defaultOn: true },
    { id: 'burgeramt', label: 'Bürgeramt slots', sub: 'New appointments available', defaultOn: true },
    { id: 'language', label: 'Language partner requests', sub: 'New connections and messages', defaultOn: true },
    { id: 'events', label: 'Event reminders', sub: '1 day before events you\'re attending', defaultOn: true },
    { id: 'digest', label: 'Weekly digest', sub: 'Monday morning events roundup', defaultOn: false },
    { id: 'rhine', label: 'Rhine flood alerts', sub: 'When water level exceeds threshold', defaultOn: true },
];

export function AlertsRightPanel({ counts }: { counts: AlertCounts }) {
    return (
        <>
            <NotificationSettings />
            <AlertSummary counts={counts} />
        </>
    );
}

function NotificationSettings() {
    const [toggles, setToggles] = useState<Record<string, boolean>>(
        Object.fromEntries(notificationSettings.map((s) => [s.id, s.defaultOn])),
    );

    function toggle(id: string) {
        setToggles((prev) => ({ ...prev, [id]: !prev[id] }));
    }

    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">Notification settings</span>
            </div>
            {notificationSettings.map((setting) => (
                <div
                    key={setting.id}
                    className="flex items-center justify-between border-b border-border px-4 py-[11px] last:border-b-0"
                >
                    <div>
                        <div className="text-[13px] font-medium">{setting.label}</div>
                        <div className="mt-px text-[11px] text-muted-foreground">{setting.sub}</div>
                    </div>
                    <button
                        onClick={() => toggle(setting.id)}
                        className={`relative h-[22px] w-10 shrink-0 rounded-full transition-colors duration-250 ${
                            toggles[setting.id] ? 'bg-success' : 'bg-border'
                        }`}
                    >
                        <span
                            className={`absolute top-[2px] left-[2px] size-[18px] rounded-full bg-white shadow-sm transition-transform duration-250 ${
                                toggles[setting.id] ? 'translate-x-[18px]' : 'translate-x-0'
                            }`}
                            style={{ transitionTimingFunction: 'cubic-bezier(0.32, 1, 0.4, 1)' }}
                        />
                    </button>
                </div>
            ))}
        </div>
    );
}

function AlertSummary({ counts }: { counts: AlertCounts }) {
    return (
        <div className="mb-3.5 overflow-hidden rounded-xl border border-border bg-card">
            <div className="border-b border-border px-4 py-3">
                <span className="text-[13px] font-bold">Alert summary</span>
            </div>
            <div className="flex flex-col gap-2.5 px-4 py-3.5">
                <SummaryRow label="Unread" value={counts.unread} />
                <SummaryRow label="System alerts" value={counts.system} />
                <SummaryRow label="Social" value={counts.social} />
                <SummaryRow label="Reminders" value={counts.reminder} />
            </div>
        </div>
    );
}

function SummaryRow({ label, value }: { label: string; value: number }) {
    return (
        <div className="flex justify-between text-[13px]">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-bold">{value}</span>
        </div>
    );
}
