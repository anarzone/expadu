import { useEffect, useRef, useState } from 'react';

/**
 * One split-flap cell — a mechanical airport-board character. The cell is a
 * dark rounded tile with a visible seam across the middle; on change the old
 * top half hinges down and the new bottom half swings in with a slight
 * mechanical settle. Cells mount BLANK and flip their character in, so the
 * board arrives with the classic cascading flutter instead of only animating
 * on rare digit changes.
 *
 * `order` staggers cells (left to right) like the real boards, whose motors
 * never start in perfect sync.
 */
function FlipChar({ char, order = 0 }: { char: string; order?: number }) {
    const [current, setCurrent] = useState(' ');
    const [next, setNext] = useState<string | null>(null);
    const settle = useRef<number | null>(null);
    const delayMs = order * 90;

    // Prop changed (or first mount from blank) → stage a flip. Render-phase
    // adjustment per the React "adjusting state when a prop changes" pattern.
    if (char !== current && next !== char) {
        setNext(char);
    }

    useEffect(() => {
        if (next === null) {
            return;
        }

        // Fallback commit — fires if onAnimationEnd never does (reduced
        // motion, tab hidden mid-flip).
        settle.current = window.setTimeout(() => {
            setNext((pending) => {
                if (pending !== null) {
                    setCurrent(pending);
                }

                return null;
            });
        }, 900 + delayMs);

        return () => {
            if (settle.current !== null) {
                window.clearTimeout(settle.current);
            }
        };
    }, [next, delayMs]);

    const commit = () => {
        setNext((pending) => {
            if (pending !== null) {
                setCurrent(pending);
            }

            return null;
        });
    };

    return (
        <span className="flipc">
            <span className="flipc-half flipc-top">
                <span>{next ?? current}</span>
            </span>
            <span className="flipc-half flipc-bottom">
                <span>{current}</span>
            </span>
            {next !== null && (
                <>
                    <span
                        key={`t-${next}`}
                        className="flipc-half flipc-top flipc-flip-top"
                        style={{ animationDelay: `${delayMs}ms` }}
                    >
                        <span>{current}</span>
                    </span>
                    <span
                        key={`b-${next}`}
                        className="flipc-half flipc-bottom flipc-flip-bottom"
                        style={{ animationDelay: `${delayMs + 320}ms` }}
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
 * A string of split-flap cells. Each character is its own cell, so when
 * "12" becomes "13" only the "3" flips — exactly like the mechanical boards.
 * Cells are keyed by position from the RIGHT edge so a length change
 * ("9" → "12") flips the aligned digits instead of remounting all of them.
 */
export function FlipText({ text }: { text: string }) {
    const chars = [...text];

    return (
        <span className="flipc-row" aria-label={text}>
            {chars.map((char, i) => (
                <FlipChar key={chars.length - i} char={char} order={i} />
            ))}
        </span>
    );
}
