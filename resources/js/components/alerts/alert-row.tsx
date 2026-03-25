import { Link, router } from '@inertiajs/react';

type AlertData = {
    id: number; type: string; title: string; body: string | null;
    deep_link: string | null; read_at: string | null; created_at: string;
};

const typeConfig: Record<string, { emoji: string; bg: string; badge: string }> = {
    system: { emoji: '🚇', bg: 'bg-warn-soft', badge: 'bg-warn-soft text-warn' },
    social: { emoji: '🗣️', bg: 'bg-accent-soft', badge: 'bg-accent-soft text-primary' },
    reminder: { emoji: '📅', bg: 'bg-success-soft', badge: 'bg-success-soft text-success' },
};

function timeAgo(dateStr: string): string {
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins} min ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

export function AlertRow({ alert }: { alert: AlertData }) {
    const config = typeConfig[alert.type] || typeConfig.system;
    const isUnread = !alert.read_at;

    function handleClick() {
        if (isUnread) {
            router.post(`/alerts/${alert.id}/read`, {}, { preserveScroll: true });
        }
    }

    const content = (
        <div
            onClick={handleClick}
            className={`flex items-start gap-3 border-b border-border px-4 py-3.5 transition-colors hover:bg-secondary/50 ${isUnread ? 'bg-accent-soft/30' : ''}`}
        >
            <div className={`flex size-10 shrink-0 items-center justify-center rounded-xl text-lg ${config.bg}`}>
                {config.emoji}
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                    <span className="text-sm font-semibold">{alert.title}</span>
                    {isUnread && <span className="mt-1 size-2 shrink-0 rounded-full bg-primary" />}
                </div>
                {alert.body && <div className="mt-0.5 text-xs text-muted-foreground line-clamp-2">{alert.body}</div>}
                <div className="mt-1.5 flex items-center gap-2">
                    <span className="text-[10px] text-muted-foreground">{timeAgo(alert.created_at)}</span>
                    <span className={`rounded-full px-1.5 py-0.5 text-[9px] font-bold capitalize ${config.badge}`}>{alert.type}</span>
                </div>
            </div>
        </div>
    );

    if (alert.deep_link) {
        return <Link href={alert.deep_link} onClick={handleClick} className="block">{content}</Link>;
    }
    return content;
}
