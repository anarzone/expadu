import { Link } from '@inertiajs/react';
import type { SettlementProgressData } from '@/types/home-feed';

const situationLabels: Record<string, string> = {
    non_eu_employee: 'Non-EU employee',
    eu_employee: 'EU employee',
    student: 'Student',
    freelancer: 'Freelancer',
    family_reunification: 'Family reunification',
    digital_nomad: 'Digital nomad',
    other: 'Other',
};

export function ProgressCard({ data }: { data: SettlementProgressData }) {
    return (
        <Link
            href="/bureaucracy"
            prefetch
            className="block rounded-xl border border-border bg-card p-4 transition-all hover:shadow-sm"
        >
            <div className="mb-3 flex items-center justify-between">
                <div>
                    <div className="text-sm font-semibold">Settlement Progress</div>
                    <div className="mt-0.5 text-xs text-muted-foreground">
                        {data.situation ? situationLabels[data.situation] : 'Getting started'} · Day {data.days_since_arrival}
                    </div>
                </div>
                <span className="font-mono text-lg font-medium text-primary">{data.percent}%</span>
            </div>

            <div className="h-2 overflow-hidden rounded-full bg-secondary">
                <div
                    className="h-full rounded-full bg-primary transition-all duration-500"
                    style={{ width: `${data.percent}%` }}
                />
            </div>

            <div className="mt-2 text-xs text-muted-foreground">
                {data.completed} of {data.total} tasks complete
            </div>
        </Link>
    );
}
