import type { BlueHighlightData } from '@/types/home-feed';

export function BlueHighlightCard({ data }: { data: BlueHighlightData }) {
    return (
        <div className="relative overflow-hidden rounded-2xl bg-primary p-5 text-white">
            <div className="absolute -top-12 -right-12 size-44 rounded-full bg-white/5" />

            <div className="relative z-10">
                <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-[0.10em] opacity-60">
                    Today's highlights
                </div>

                {data.appointment && (
                    <div className="mb-3.5 flex items-center gap-3 rounded-lg border border-white/20 bg-white/15 p-3">
                        <span className="text-[22px]">🏛️</span>
                        <div className="min-w-0 flex-1">
                            <div className="text-[9px] font-bold uppercase tracking-[0.08em] opacity-65">
                                Upcoming
                            </div>
                            <div className="text-sm font-bold">{data.appointment.office_name}</div>
                        </div>
                    </div>
                )}

                {data.urgent_task && !data.appointment && (
                    <div className="mb-3.5 flex items-center gap-3 rounded-lg border border-white/20 bg-white/15 p-3">
                        <span className="text-[22px]">⚠️</span>
                        <div className="min-w-0 flex-1">
                            <div className="text-[9px] font-bold uppercase tracking-[0.08em] opacity-65">
                                Urgent task
                            </div>
                            <div className="text-sm font-bold">{data.urgent_task.title}</div>
                        </div>
                    </div>
                )}

                <div className="font-display text-[22px] font-normal leading-snug">
                    {data.headline}
                </div>
            </div>
        </div>
    );
}
