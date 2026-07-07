import type { HTMLAttributes } from 'react';

/**
 * The Expadu mark — the same tile used for the favicon / installed-app icon, so
 * the in-app logo and the home-screen icon read as one brand. A hairline ring +
 * soft shadow keep the warm-paper tile legible against the paper sidebar in
 * light mode; it pops on its own in dark.
 */
export default function AppLogoIcon({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            {...props}
            className={`flex size-[34px] shrink-0 items-center justify-center overflow-hidden rounded-[9px] shadow-sm ring-1 ring-black/[0.06] dark:ring-white/10 ${className}`}
        >
            <img
                src="/favicon.svg"
                alt="Expadu"
                className="size-full"
                draggable={false}
            />
        </div>
    );
}
