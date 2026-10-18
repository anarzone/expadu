/**
 * A completion count nobody can audit is an assertion, not information.
 *
 * "8 of 12 tasks complete" was accurate and useless. The 8 finished tasks sat
 * in a collapsed section below two other collapsibles, "Do next" listed only 2,
 * and nothing on screen said where the other 2 lived — so the number read as
 * the app inventing progress on someone's behalf. Same defect on both surfaces:
 * one aggregate spanning sections that are each rendered somewhere else.
 *
 * This makes the arithmetic add up in view. Every task counted in the total
 * belongs to exactly one named segment, each segment carries its own share of
 * the bar, and selecting one reveals the section that holds those tasks. No new
 * claim is made about anyone's case — it only re-presents what the plan already
 * says.
 */

export type SegmentTone = 'done' | 'now' | 'later' | 'waiting';

export type ProgressSegment = {
    key: string;
    /** Lower case: it is read inline after the count — "2 coming up". */
    label: string;
    count: number;
    tone: SegmentTone;
    /** Reveal the section these tasks are rendered in. */
    onSelect: () => void;
};

/**
 * Colour means done, and nothing else. Tinting the unfinished segments with the
 * primary made a bar with zero completed tasks read as a fifth of the way
 * along — the same false progress this component exists to stop. So `done`
 * alone gets the accent, and the remaining lanes are greys distinguished only
 * from each other.
 *
 * Grey rather than green/amber/red: those already carry status meaning on these
 * pages, and borrowing them would imply a judgement — "coming up" is not a
 * warning.
 */
const TONE_FILL: Record<SegmentTone, string> = {
    done: 'bg-primary',
    now: 'bg-muted-foreground/50',
    later: 'bg-muted-foreground/28',
    waiting: 'bg-muted-foreground/15',
};

const TONE_TEXT: Record<SegmentTone, string> = {
    done: 'text-primary',
    now: 'text-foreground',
    later: 'text-muted-foreground',
    waiting: 'text-muted-foreground',
};

/**
 * Scroll a revealed section into view. The sticky page header would otherwise
 * cover the heading the user just asked to see, hence `scroll-mt-*` on every
 * target rather than a magic offset here.
 */
export function revealSection(id: string): void {
    const target = document.getElementById(id);

    if (!target) {
        return;
    }

    const reduceMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    target.scrollIntoView({
        behavior: reduceMotion ? 'auto' : 'smooth',
        block: 'start',
    });
}

export function ProgressBreakdown({
    segments,
    label,
}: {
    segments: ProgressSegment[];
    /**
     * Names what the total counts, e.g. "Your confirmed actions". Omit where
     * the surrounding card already states the headline count, so the page does
     * not say "8 of 12" twice.
     */
    label?: string;
}) {
    const shown = segments.filter((segment) => segment.count > 0);
    const total = shown.reduce((sum, segment) => sum + segment.count, 0);

    if (total === 0) {
        return null;
    }

    const done = shown
        .filter((segment) => segment.tone === 'done')
        .reduce((sum, segment) => sum + segment.count, 0);

    return (
        <div>
            {label !== undefined && (
                <div className="mb-1.5 flex items-center justify-between gap-3 text-[11px] font-semibold text-muted-foreground">
                    <span>{label}</span>
                    <span>
                        {done} of {total} complete
                    </span>
                </div>
            )}

            <div
                role="img"
                aria-label={shown
                    .map((segment) => `${segment.count} ${segment.label}`)
                    .join(', ')}
                className="flex h-1.5 gap-px overflow-hidden rounded-full bg-secondary"
            >
                {shown.map((segment) => (
                    <span
                        key={segment.key}
                        style={{ flexGrow: segment.count }}
                        className={`h-full ${TONE_FILL[segment.tone]} first:rounded-l-full last:rounded-r-full`}
                    />
                ))}
            </div>

            {/* Buttons, not decoration: the count is the way into the section
                that justifies it. */}
            <div
                role="group"
                aria-label="Progress breakdown"
                className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5"
            >
                {shown.map((segment) => (
                    <button
                        key={segment.key}
                        type="button"
                        onClick={segment.onSelect}
                        // The count and its label are separate flex children
                        // with no whitespace between them, so the computed
                        // accessible name would run together as "5done".
                        aria-label={`${segment.count} ${segment.label}`}
                        className="group flex cursor-pointer items-center gap-1.5 rounded-full text-[12px] focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        <span
                            aria-hidden="true"
                            className={`size-2 shrink-0 rounded-full ${TONE_FILL[segment.tone]}`}
                        />
                        <span
                            className={`font-mono font-semibold ${TONE_TEXT[segment.tone]}`}
                        >
                            {segment.count}
                        </span>
                        <span className="text-muted-foreground underline decoration-transparent underline-offset-2 transition-colors group-hover:decoration-current">
                            {segment.label}
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}
