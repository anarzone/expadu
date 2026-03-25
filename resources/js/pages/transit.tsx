import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { DepartureRow } from '@/components/transit/departure-row';
import { DisruptionCard } from '@/components/transit/disruption-card';
import { RoutineCard } from '@/components/transit/routine-card';
import { TabBar } from '@/components/transit/tab-bar';
import AppLayout from '@/layouts/app-layout';

const tabs = [
    { id: 'departures', label: 'Departures' },
    { id: 'plan', label: 'Plan' },
    { id: 'disruptions', label: 'Disruptions' },
    { id: 'routines', label: 'Routines' },
];

type TransitProps = {
    departures: Array<{ line: string; type: string; destination: string; platform: string; minutes: number; status: string; delay?: number }>;
    routes: Array<{ mode: string; emoji: string; line?: string; label: string; detail: string; duration: number; status: string; recommended?: boolean }>;
    disruptions: Array<{ id: number; severity: string; lines: string[]; title: string; description: string; started_at: string; estimated_end: string | null }>;
    routines: Array<{ id: number; name: string; emoji: string | null; from_stop: string; to_stop: string; days: string[]; departure_time: string; is_active: boolean }>;
    currentStop: string;
};

export default function Transit() {
    const { departures, routes, disruptions, routines, currentStop } = usePage<TransitProps>().props;
    const [tab, setTab] = useState('departures');
    const [dismissed, setDismissed] = useState<number[]>([]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Transit', href: '/transit' }]}>
            <Head title="Transit" />
            <div className="mx-auto w-full max-w-[680px]">
                <div className="border-b border-border px-6 py-4">
                    <h1 className="font-display text-xl font-medium">🚇 Transit</h1>
                    <span className="text-xs text-muted-foreground">Live departures and route planning</span>
                </div>

                <TabBar tabs={tabs} active={tab} onChange={setTab} />

                {tab === 'departures' && (
                    <div>
                        <div className="flex items-center justify-between border-b border-border px-4 py-3">
                            <span className="text-sm font-semibold">{currentStop}</span>
                            <div className="flex items-center gap-1.5 rounded-full bg-success-soft px-2 py-0.5 text-[11px] font-semibold text-success">
                                <span className="inline-block size-1.5 animate-pulse rounded-full bg-success" />
                                Live
                            </div>
                        </div>
                        {departures.map((d, i) => (
                            <DepartureRow key={i} departure={d} />
                        ))}
                    </div>
                )}

                {tab === 'plan' && (
                    <div className="p-4">
                        <div className="mb-4 space-y-2">
                            <input className="w-full rounded-lg border border-border bg-card px-3 py-2.5 text-sm outline-none focus:border-primary" placeholder="From" defaultValue="Home · Ehrenfeld" />
                            <input className="w-full rounded-lg border border-border bg-card px-3 py-2.5 text-sm outline-none focus:border-primary" placeholder="To" defaultValue="Work · Mediapark" />
                        </div>
                        <div className="space-y-2">
                            {routes.map((r, i) => (
                                <div key={i} className="flex items-center gap-3 rounded-xl border border-border bg-card p-3.5">
                                    <div className="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-lg">
                                        {r.emoji}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-sm font-semibold">
                                            {r.label}
                                            {r.recommended && <span className="ml-1.5 rounded-full bg-warn-soft px-1.5 py-0.5 text-[9px] font-bold uppercase text-warn">Best</span>}
                                        </div>
                                        <div className="text-xs text-muted-foreground">{r.detail}</div>
                                    </div>
                                    <div className="text-right">
                                        <div className="font-mono text-xl font-medium">{r.duration}</div>
                                        <div className="text-[10px] text-muted-foreground">min</div>
                                    </div>
                                    <div className={`size-2 rounded-full ${r.status === 'ok' ? 'bg-success' : 'bg-warn'}`} />
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {tab === 'disruptions' && (
                    <div className="space-y-2 p-4">
                        {disruptions.filter((d) => !dismissed.includes(d.id)).length === 0 ? (
                            <div className="py-12 text-center text-sm text-muted-foreground">No active disruptions</div>
                        ) : (
                            disruptions
                                .filter((d) => !dismissed.includes(d.id))
                                .map((d) => <DisruptionCard key={d.id} disruption={d} onDismiss={(id) => setDismissed([...dismissed, id])} />)
                        )}
                    </div>
                )}

                {tab === 'routines' && (
                    <div className="space-y-3 p-4">
                        {(routines as TransitProps['routines']).length === 0 ? (
                            <div className="py-12 text-center text-sm text-muted-foreground">
                                No routines yet. Save your regular commute to get departure alerts.
                            </div>
                        ) : (
                            (routines as TransitProps['routines']).map((r) => <RoutineCard key={r.id} routine={r} />)
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
