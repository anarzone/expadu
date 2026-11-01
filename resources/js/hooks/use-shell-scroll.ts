import { useEffect } from 'react';

const PHONE = '(max-width: 700px)';
const TYPING =
    "input:not([type='checkbox'], [type='radio'], [type='range']), textarea, select, [contenteditable='true']";

/**
 * The phone menu's manners (the mock's connected/section-bar.js):
 * - scrolling down, the floating sections and Composer's button step away;
 *   scrolling up, or reaching the page's top or end, brings them back
 *   (body.menu-away);
 * - they step aside while the keyboard is open, not merely while a field has
 *   the cursor, so they return when it closes (html.keyboard-open).
 */
export function useShellScroll(): void {
    useEffect(() => {
        const phone = window.matchMedia(PHONE);
        const body = document.body;
        let lastY = window.scrollY;

        const onScroll = () => {
            const page = document.scrollingElement ?? document.documentElement;
            const y = Math.max(0, page.scrollTop);

            if (Math.abs(y - lastY) < 8) {
                return;
            }

            const end = y + page.clientHeight >= page.scrollHeight - 2;
            body.classList.toggle(
                'menu-away',
                phone.matches && y > lastY && y > 80 && !end,
            );
            lastY = y;
        };
        const onPhoneChange = () => body.classList.remove('menu-away');

        // A keyboard is the visual viewport shrinking under a focused field.
        const viewport = window.visualViewport;
        const keyboard = () => {
            const open =
                phone.matches &&
                !!viewport &&
                viewport.scale === 1 &&
                !!document.activeElement?.matches(TYPING) &&
                window.innerHeight - viewport.height - viewport.offsetTop > 120;
            document.documentElement.classList.toggle('keyboard-open', open);
        };
        const afterBlur = () => window.setTimeout(keyboard);

        window.addEventListener('scroll', onScroll, { passive: true });
        phone.addEventListener('change', onPhoneChange);
        viewport?.addEventListener('resize', keyboard);
        viewport?.addEventListener('scroll', keyboard);
        document.addEventListener('focusin', keyboard);
        document.addEventListener('focusout', afterBlur);

        return () => {
            window.removeEventListener('scroll', onScroll);
            phone.removeEventListener('change', onPhoneChange);
            viewport?.removeEventListener('resize', keyboard);
            viewport?.removeEventListener('scroll', keyboard);
            document.removeEventListener('focusin', keyboard);
            document.removeEventListener('focusout', afterBlur);
            body.classList.remove('menu-away');
            document.documentElement.classList.remove('keyboard-open');
        };
    }, []);
}

/**
 * Phones: tapping the section you're in takes you to its top, as in an app.
 * A page with deeper screens hears it first through `expadu:section-again`
 * and can go back to its first screen instead by cancelling the event.
 * Returns true when the tap was handled (the caller skips navigation).
 */
export function sectionAgain(): boolean {
    if (!window.matchMedia(PHONE).matches) {
        return false;
    }

    const again = new CustomEvent('expadu:section-again', { cancelable: true });

    if (window.dispatchEvent(again)) {
        const still = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        window.scrollTo({ top: 0, behavior: still ? 'instant' : 'smooth' });
    }

    return true;
}
