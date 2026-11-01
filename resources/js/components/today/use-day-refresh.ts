import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import type { DayProjection } from '@/types/day';

const berlinDate = (at = new Date()) =>
    new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin' }).format(at);

/**
 * Keeps Today's day current without a reload: re-reads it when the server
 * says it next changes (a plan starts or ends, the time to leave, midnight,
 * including the 25-hour day when the clocks go back), and when the tab
 * comes back after the day moved on (an appointment that elapsed while
 * away, a new day after midnight).
 */
export function useDayRefresh(day: DayProjection | undefined): void {
    useEffect(() => {
        if (!day) {
            return;
        }

        const reload = () =>
            router.reload({ only: ['day'], preserveUrl: true });
        const due = day.refresh_at ? Date.parse(day.refresh_at) : NaN;
        const midnight = Date.parse(day.day_end);
        const next = Math.min(
            Number.isNaN(due) ? Infinity : due,
            Number.isNaN(midnight) ? Infinity : midnight,
        );
        // Timers can't wait longer than ~24 days; a day never needs to.
        const wait = Math.min(
            Math.max(next - Date.now(), 1_000),
            6 * 3_600_000,
        );
        const timer = Number.isFinite(next)
            ? window.setTimeout(reload, wait)
            : undefined;

        const onReturn = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            const stale =
                berlinDate() !== day.date ||
                (Number.isFinite(next) && Date.now() >= next);

            if (stale) {
                reload();
            }
        };

        document.addEventListener('visibilitychange', onReturn);
        window.addEventListener('focus', onReturn);

        return () => {
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', onReturn);
            window.removeEventListener('focus', onReturn);
        };
    }, [day]);
}
