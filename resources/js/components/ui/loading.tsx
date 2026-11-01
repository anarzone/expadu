import { useEffect, useState } from 'react';

/*
 * Loading, the app's one way (the mock's connected/loading.*): keep what is
 * already on screen, and show a placeholder only when the work takes longer
 * than 140ms, so quick answers never flash. One quiet placeholder per
 * module, sized like the content it stands in for.
 */

export const LOADING_DELAY_MS = 140;

/** True once `active` has lasted `delay` ms; false again as soon as it ends. */
export function useDelayedLoading(
    active: boolean,
    delay = LOADING_DELAY_MS,
): boolean {
    const [late, setLate] = useState(false);

    useEffect(() => {
        if (!active) {
            return;
        }

        const timer = setTimeout(() => setLate(true), delay);

        return () => {
            clearTimeout(timer);
            setLate(false);
        };
    }, [active, delay]);

    return active && late;
}

/** Quiet lines standing in for a module's text while it loads. */
export function LoadingLines({
    lines = 2,
    label = 'Loading',
}: {
    lines?: number;
    label?: string;
}) {
    return (
        <div className="ui-loading-lines" role="status" aria-label={label}>
            {Array.from({ length: lines }, (_, i) => (
                <span key={i} className="ui-loading-line" />
            ))}
        </div>
    );
}

/** Composer's spark and a line saying what is happening. */
export function LoadingStatus({ label }: { label: string }) {
    return (
        <span className="ui-loading-status" role="status">
            <svg
                className="ui-loading-spark"
                viewBox="0 0 48 48"
                fill="none"
                aria-hidden="true"
            >
                <path
                    className="spark-outline"
                    d="M24 4 29.5 18.5 44 24 29.5 29.5 24 44 18.5 29.5 4 24 18.5 18.5Z"
                />
                <g className="spark-tips">
                    <path d="M18.5 18.5 24 4 29.5 18.5" />
                    <path d="M29.5 18.5 44 24 29.5 29.5" />
                    <path d="M29.5 29.5 24 44 18.5 29.5" />
                    <path d="M18.5 29.5 4 24 18.5 18.5" />
                </g>
            </svg>
            <span>{label}</span>
        </span>
    );
}
