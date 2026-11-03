import { useEffect } from 'react';

/** Whether something between the finger and the page can still scroll this way. */
function scrollsThatWay(from: EventTarget | null, dx: number, dy: number) {
    const vertical = Math.abs(dy) >= Math.abs(dx);
    let element = from instanceof Element ? from : null;

    while (element && element !== document.body) {
        if (element instanceof HTMLInputElement && element.type === 'range') {
            return true;
        }

        const style = getComputedStyle(element);
        const overflow = vertical ? style.overflowY : style.overflowX;

        if (overflow === 'auto' || overflow === 'scroll') {
            // The finger moves against the scroll: dragging up reads further down.
            const room = vertical
                ? dy < 0
                    ? element.scrollHeight -
                      element.clientHeight -
                      element.scrollTop
                    : element.scrollTop
                : dx < 0
                  ? element.scrollWidth -
                    element.clientWidth -
                    element.scrollLeft
                  : element.scrollLeft;

            if (room > 1) {
                return true;
            }
        }

        element = element.parentElement;
    }

    return false;
}

/**
 * While a panel is open over the page, a drag scrolls what's under the finger
 * or nothing, never the page behind. overscroll-behavior can't do this alone:
 * it only holds on an element that overflows, so a drag on a panel's header or
 * box, or on a conversation too short to scroll, reached the page (owner's
 * phone, 3 Nov 2026). Touch only; two fingers still zoom.
 */
export function useContainTouch(active: boolean) {
    useEffect(() => {
        if (!active) {
            return;
        }

        let start: { x: number; y: number } | null = null;
        let allowed: boolean | null = null;

        const onStart = (event: TouchEvent) => {
            const touch = event.touches[0];
            start =
                event.touches.length === 1
                    ? { x: touch.clientX, y: touch.clientY }
                    : null;
            allowed = null;
        };
        const onMove = (event: TouchEvent) => {
            if (!start || event.touches.length > 1) {
                return;
            }

            // Decided once a gesture: the browser owns it once it scrolls.
            if (allowed === null) {
                const dx = event.touches[0].clientX - start.x;
                const dy = event.touches[0].clientY - start.y;

                if (dx === 0 && dy === 0) {
                    return;
                }

                allowed = scrollsThatWay(event.target, dx, dy);
            }

            if (!allowed && event.cancelable) {
                event.preventDefault();
            }
        };

        document.addEventListener('touchstart', onStart, {
            capture: true,
            passive: true,
        });
        document.addEventListener('touchmove', onMove, {
            capture: true,
            passive: false,
        });

        return () => {
            document.removeEventListener('touchstart', onStart, {
                capture: true,
            });
            document.removeEventListener('touchmove', onMove, {
                capture: true,
            });
        };
    }, [active]);
}
