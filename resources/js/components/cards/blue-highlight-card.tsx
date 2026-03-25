import { Link } from '@inertiajs/react';
import type { BlueHighlightData } from '@/types/home-feed';

export function BlueHighlightCard({ data }: { data: BlueHighlightData }) {
    return (
        <div className="relative overflow-hidden rounded-2xl bg-[#1A4CD4] p-5 text-white">
            {/* Decorative circle */}
            <div className="absolute -top-[50px] -right-[50px] size-[180px] rounded-full bg-white/5" />

            <div className="relative z-10">
                {/* Label */}
                <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-[0.10em] opacity-60">
                    Today's highlights
                </div>

                {/* Urgent item block */}
                {data.appointment && (
                    <Link
                        href="/bureaucracy"
                        className="mb-3.5 flex items-center gap-3 rounded-lg border border-white/20 bg-white/[0.15] p-3 transition-colors hover:bg-white/[0.22]"
                    >
                        <span className="shrink-0 text-[22px]">🏛️</span>
                        <div className="min-w-0 flex-1">
                            <div className="mb-0.5 text-[9px] font-bold uppercase tracking-[0.08em] opacity-65">
                                ⚠️ Urgent · {data.appointment.is_tomorrow ? 'Tomorrow' : 'Upcoming'}
                            </div>
                            <div className="text-sm font-bold leading-tight">{data.appointment.office_name}</div>
                            {data.appointment.notes && (
                                <div className="mt-0.5 text-[11px] opacity-75">{data.appointment.notes}</div>
                            )}
                        </div>
                        <div className="shrink-0 text-right font-mono">
                            <span className="text-base font-semibold leading-none">
                                {data.appointment.time.split(':')[0]}
                            </span>
                            <small className="block text-[9px] opacity-60">
                                :{data.appointment.time.split(':')[1]}
                            </small>
                        </div>
                    </Link>
                )}

                {data.urgent_task && !data.appointment && (
                    <Link
                        href="/bureaucracy"
                        className="mb-3.5 flex items-center gap-3 rounded-lg border border-white/20 bg-white/[0.15] p-3 transition-colors hover:bg-white/[0.22]"
                    >
                        <span className="shrink-0 text-[22px]">⚠️</span>
                        <div className="min-w-0 flex-1">
                            <div className="mb-0.5 text-[9px] font-bold uppercase tracking-[0.08em] opacity-65">
                                ⚠️ Urgent · {data.urgent_task.deadline_days ? `${data.urgent_task.deadline_days} days` : 'Action needed'}
                            </div>
                            <div className="text-sm font-bold leading-tight">{data.urgent_task.title}</div>
                            {data.urgent_task.documents_required.length > 0 && (
                                <div className="mt-0.5 text-[11px] opacity-75">
                                    Bring {data.urgent_task.documents_required.slice(0, 3).join(' · ')}
                                </div>
                            )}
                        </div>
                    </Link>
                )}

                {/* Day headline with weather context */}
                <div className="mb-4 font-display text-[22px] font-normal leading-snug whitespace-pre-line">
                    {data.headline}
                </div>

                {/* Divider */}
                {data.timeline_rows.length > 0 && (
                    <div className="mb-3 h-px bg-white/[0.12]" />
                )}

                {/* Timeline rows */}
                <div className="flex flex-col gap-[7px]">
                    {data.timeline_rows.map((row, i) => (
                        <div
                            key={i}
                            className="flex items-center gap-3 rounded-lg bg-white/[0.11] px-3.5 py-[11px] transition-colors hover:bg-white/[0.18]"
                        >
                            <span className="shrink-0 text-[17px]">{row.emoji}</span>
                            <div className="min-w-0 flex-1">
                                <div className="text-[13px] font-semibold leading-tight">{row.title}</div>
                                <div className="text-[11px] opacity-[0.72]">{row.subtitle}</div>
                            </div>
                            <div className="shrink-0 font-mono text-lg font-medium leading-none">
                                {row.value}
                                <small className="block text-right text-[10px] opacity-60">{row.unit}</small>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
