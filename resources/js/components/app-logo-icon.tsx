import type { HTMLAttributes } from 'react';

/**
 * The Expadu mark, drawn inline with a transparent background so it sits
 * directly on the sidebar — no paper tile. (The favicon.svg keeps its opaque
 * warm-paper tile because a home-screen icon must be opaque; the in-app logo
 * shouldn't.) The ink spine follows the text colour (currentColor) so it stays
 * visible in both themes; the cyan dot and orange arms are fixed brand colours
 * that read on either background.
 */
export default function AppLogoIcon({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            {...props}
            className={`flex size-[34px] shrink-0 items-center justify-center text-foreground ${className}`}
        >
            <svg
                viewBox="13 22 74 60"
                className="size-full"
                fill="none"
                role="img"
                aria-label="Expadu"
            >
                <g transform="translate(2.45 2.05)">
                    <circle cx="20.5" cy="50" r="6" fill="#05badd" />
                    <rect
                        x="30"
                        y="24"
                        width="13"
                        height="52"
                        rx="6"
                        fill="currentColor"
                    />
                    <rect
                        x="46"
                        y="24"
                        width="34"
                        height="13"
                        rx="6"
                        fill="#ff3902"
                        transform="rotate(-7 46 30)"
                    />
                    <rect
                        x="46"
                        y="43.5"
                        width="25"
                        height="13"
                        rx="6"
                        fill="#ff3902"
                        transform="rotate(-4 46 50)"
                    />
                    <rect
                        x="46"
                        y="63"
                        width="34"
                        height="13"
                        rx="6"
                        fill="#ff3902"
                        transform="rotate(-2 46 69)"
                    />
                </g>
            </svg>
        </div>
    );
}
