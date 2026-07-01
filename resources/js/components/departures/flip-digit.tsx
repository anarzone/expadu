import { useEffect, useRef, useState } from 'react';

/**
 * One split-flap cell — an airport-board character that flips on change. The
 * static halves always show a full character (top = incoming, bottom =
 * outgoing); on change two overlay flaps rotate on the X axis: the old top
 * hinges down, then the new bottom hinges in. Transform-only, so the flip
 * stays smooth, and a timer commits the swap even when the animation is
 * suppressed (prefers-reduced-motion).
 */
function FlipChar({ char }: { char: string }) {
    const [current, setCurrent] = useState(char);
    const [next, setNext] = useState<string | null>(null);
    const settle = useRef<number | null>(null);

    // Prop changed → stage a flip. Render-phase adjustment (per the React
    // "adjusting state when a prop changes" pattern) — settles immediately
    // because the condition is false once next === char.
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
        }, 560);

        return () => {
            if (settle.current !== null) {
                window.clearTimeout(settle.current);
            }
        };
    }, [next]);

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
                    >
                        <span>{current}</span>
                    </span>
                    <span
                        key={`b-${next}`}
                        className="flipc-half flipc-bottom flipc-flip-bottom"
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
                <FlipChar key={chars.length - i} char={char} />
            ))}
        </span>
    );
}
