import { Deferred, Head, usePage } from '@inertiajs/react';
import { useDayRefresh } from '@/components/today/use-day-refresh';
import { YourDay } from '@/components/today/your-day';
import { LoadingLines, useDelayedLoading } from '@/components/ui/loading';
import AppLayout from '@/layouts/app-layout';
import type { DayProjection } from '@/types/day';
import '../../css/today.css';

/**
 * Today (the mock's today-next/brief/): one canvas, Your day on the left and
 * what's next on the right; phones read it as one column in priority
 * order. No title row: the section menu says where you are.
 */
export default function Today() {
    const { auth, day } = usePage<{ day?: DayProjection }>().props;
    const firstName = auth.user.name.trim().split(/\s+/)[0];

    useDayRefresh(day);

    return (
        <AppLayout>
            <Head title="Today" />
            <h1 className="sr-only">Today, {firstName}</h1>
            <div className="today-workspace">
                <div className="daily-canvas">
                    <div className="day-area">
                        <Deferred data="day" fallback={<DayLoading />}>
                            {day ? <YourDay day={day} /> : <DayLoading />}
                        </Deferred>
                    </div>
                    <div className="next-area" />
                </div>
            </div>
        </AppLayout>
    );
}

/** Your day's place while it loads: nothing for 140ms, then quiet lines. */
function DayLoading() {
    const late = useDelayedLoading(true);

    return (
        <section className="day-panel" aria-busy="true">
            <div className="day-heading">
                <h2>Your day</h2>
            </div>
            {late && <LoadingLines lines={3} label="Loading your day" />}
        </section>
    );
}
