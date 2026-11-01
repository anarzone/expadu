import { router } from '@inertiajs/react';

/**
 * Steps inside a page that the browser's Back takes back one by one: a
 * screen opened over the page (Travel's map on a phone), a group picked in
 * it. Each step is a history entry that keeps Inertia's page, so a reload
 * still lands on the page.
 *
 * Inertia loads the page again on every Back (its popstate handler swaps the
 * page in afresh), which would close everything at once. Chromium runs a
 * window's listeners in the order they were added, capture or not, so this
 * one has to come first: app.tsx imports this module before the app starts,
 * and Inertia adds its own listener only then. A Back that is one of these
 * steps stops here; any other goes on to Inertia.
 */

type Step = { back: () => void; url: string };

const steps: Step[] = [];
// Entries a step took off itself (its own Back, ✕): their Back is no step.
let unwinding = 0;

const here = () =>
    typeof window === 'undefined'
        ? ''
        : `${window.location.pathname}${window.location.search}`;

if (typeof window !== 'undefined') {
    window.addEventListener('popstate', (event) => {
        if (unwinding) {
            unwinding -= 1;
            event.stopImmediatePropagation();

            return;
        }

        const step = steps.pop();

        if (!step) {
            return;
        }

        event.stopImmediatePropagation();
        step.back();
    });

    // Gone to another page: its steps are behind it now, and Back there
    // belongs to Inertia again. A partial reload keeps the page's address.
    router.on('navigate', (event) => {
        const url = new URL(event.detail.page.url, window.location.origin);

        if (steps.length && `${url.pathname}${url.search}` !== steps[0].url) {
            steps.length = 0;
        }
    });
}

/**
 * Adds a step: the browser's Back runs `back`. Returns the way to take it
 * off from the page itself (its own Back, ✕), which also takes off any step
 * added after it. Taking off a step already taken back does nothing.
 */
export function pushStep(back: () => void): () => void {
    const step: Step = { back, url: here() };
    window.history.pushState(window.history.state, '');
    steps.push(step);

    return () => {
        const at = steps.indexOf(step);

        if (at < 0) {
            return;
        }

        const count = steps.length - at;
        steps.splice(at);

        // Already on another page (it's going away with the page): its
        // entries stay behind that page's.
        if (here() !== step.url) {
            return;
        }

        unwinding += 1;
        window.history.go(-count);
    };
}
