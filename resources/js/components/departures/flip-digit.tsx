import { useEffect, useRef, useState } from 'react';
import type { CSSProperties } from 'react';

/**
 * One split-flap tile — a single character cell on the airport-style board.
 * The tile is a dark rounded plate with a seam across the middle; on change the
 * old top leaf hinges down, THEN the new bottom leaf drops and settles with a
 * slight mechanical overshoot (sequential, not a simultaneous fold). Tiles
 * mount blank and flip their character in, so the board arrives with the
 * classic cascade. `order` staggers tiles left-to-right within a field.
 */
function Tile({ char, order = 0 }: { char: string; order?: number }) {
    const [current, setCurrent] = useState(' ');
    const [next, setNext] = useState<string | null>(null);
    const settle = useRef<number | null>(null);
    const stagger = Math.min(order, 12) * 22;

    // Prop changed (or first flip-in from blank) → stage a flip. Render-phase
    // state adjustment per the React "adjusting state when a prop changes" rule.
    if (char !== current && next !== char) {
        setNext(char);
    }

    useEffect(() => {
        if (next === null) {
            return;
        }

        // Fallback commit if onAnimationEnd never fires (reduced motion, tab
        // hidden mid-flip) — covers the stagger plus both leaf durations.
        settle.current = window.setTimeout(() => {
            setNext((pending) => {
                if (pending !== null) {
                    setCurrent(pending);
                }

                return null;
            });
        }, 1000 + stagger);

        return () => {
            if (settle.current !== null) {
                window.clearTimeout(settle.current);
            }
        };
    }, [next, stagger]);

    const commit = () =>
        setNext((pending) => {
            if (pending !== null) {
                setCurrent(pending);
            }

            return null;
        });

    return (
        <span className="tile">
            <span className="tile-half tile-top">
                <span>{next ?? current}</span>
            </span>
            <span className="tile-half tile-bottom">
                <span>{current}</span>
            </span>
            {next !== null && (
                <>
                    <span
                        key={`t-${next}`}
                        className="tile-half tile-top tile-flip-top"
                        style={{ animationDelay: `${stagger}ms` }}
                    >
                        <span>{current}</span>
                    </span>
                    <span
                        key={`b-${next}`}
                        className="tile-half tile-bottom tile-flip-bottom"
                        style={{ animationDelay: `${stagger + 250}ms` }}
                        onAnimationEnd={commit}
                    >
                        <span>{next}</span>
                    </span>
                </>
            )}
        </span>
    );
}

/**
 * A field of split-flap tiles rendering one string, one character per tile —
 * the building block of every board cell (line, destination, each time). Tiles
 * are keyed by position, so when the text changes only the tiles whose glyph
 * changed flip, exactly like a mechanical board.
 *
 * `width` fixes the tile count so columns line up row-to-row: shorter text is
 * padded with blank tiles (right-aligned pads on the left, e.g. times; left
 * pads on the right, e.g. destinations), longer text is clipped to the column.
 * Omit `width` to hug the content. `tone` colours the glyphs (via `--ink`).
 */
export function TileField({
    text,
    width,
    align = 'left',
    tone,
    label,
}: {
    text: string;
    width?: number;
    align?: 'left' | 'right';
    tone?: string;
    label?: string;
}) {
    let chars = [...text];

    if (width != null) {
        const blanks = Math.max(0, width - chars.length);
        chars =
            align === 'right'
                ? [...Array<string>(blanks).fill(' '), ...chars].slice(-width)
                : [...chars, ...Array<string>(blanks).fill(' ')].slice(
                      0,
                      width,
                  );
    }

    return (
        <span
            className="tile-field"
            aria-label={label ?? text}
            style={tone ? ({ '--ink': tone } as CSSProperties) : undefined}
        >
            {chars.map((ch, i) => (
                <Tile key={i} char={ch} order={i} />
            ))}
        </span>
    );
}
