/* Reuse the approved Today Composer surface on department pages. */
(function () {
    const S = ExpaduStore;
    document.querySelector('#dock')?.remove();
    document.querySelector('.composer-dock')?.remove();
    document.body.classList.add('department-composer');
    document.body.insertAdjacentHTML(
        'beforeend',
        '<div class="composer-tray" id="composer-tray">\n            <button\n                type="button"\n                class="composer-launcher"\n                aria-label="Open Composer"\n                aria-expanded="false"\n                aria-controls="compose-form"\n                title="Composer"\n            >\n                <span data-icon="spark"></span>\n            </button>\n            <button\n                type="button"\n                class="composer-dismiss"\n                aria-label="Close Composer"\n                title="Close Composer"\n            >\n                <span data-icon="close"></span>\n            </button>\n            <button\n                class="included-ideas"\n                id="included-ideas"\n                data-composer-panel="plans"\n                hidden\n            >\n                <span data-icon="bookmark"></span\n                ><span id="included-label"></span\n                ><span data-icon="arrow"></span>\n            </button>\n            <button\n                type="button"\n                class="composer-resume"\n                id="composer-resume"\n                hidden\n            >\n                <span data-icon="chat"></span\n                ><span>Back to your conversation</span\n                ><span data-icon="arrow"></span>\n            </button>\n            <form id="compose-form" class="composer-form">\n                <div class="composer-entry">\n                    <textarea\n                        id="request"\n                        rows="1"\n                        maxlength="700"\n                        aria-label="Your request to Composer"\n                        placeholder="Ask, discover, or plan…"\n                    ></textarea>\n                </div>\n                <div class="today-composer-tools">\n                    <details class="today-composer-actions">\n                        <summary aria-label="Composer actions">\n                            <span data-icon="plus"></span>\n                        </summary>\n                        <div\n                            class="today-control-menu"\n                            aria-label="Composer actions"\n                        >\n                            <button type="button" data-composer-panel="plans">\n                                <span data-icon="calendar"></span>My Plans\n                            </button>\n                            <button type="button" data-composer-panel="saved">\n                                <span data-icon="bookmark"></span>Saved\n                            </button>\n                            <button type="button" data-composer-panel="history">\n                                <span data-icon="clock"></span>History\n                            </button>\n                            <button\n                                type="button"\n                                data-composer-action="appointment"\n                            >\n                                <span data-icon="calendar"></span>Add\n                                appointment\n                            </button>\n                            <button\n                                type="button"\n                                data-composer-action="examples"\n                            >\n                                <span data-icon="spark"></span>Try Composer\n                            </button>\n                            <button type="button" data-composer-action="new">\n                                <span data-icon="plus"></span>Start fresh\n                            </button>\n                        </div>\n                    </details>\n                    <button\n                        class="compose-button"\n                        type="submit"\n                        aria-label="Send request"\n                        disabled\n                    >\n                        <svg\n                            viewBox="0 0 24 24"\n                            fill="none"\n                            stroke="currentColor"\n                            stroke-width="1.8"\n                            stroke-linecap="round"\n                            stroke-linejoin="round"\n                            aria-hidden="true"\n                        >\n                            <path d="M12 19V5m-7 7 7-7 7 7" />\n                        </svg>\n                    </button>\n                </div>\n            </form>\n        </div>',
    );
    // Tablets and desktops: Composer sits in the header, beside the city,
    // instead of in the corner (owner chose A, canvas row 23, 16 Oct 2026).
    // ⌘K, or Ctrl K, opens it from anywhere.
    const mac = /Mac|iPhone|iPad/.test(navigator.platform || '');
    document
        .querySelector('#shell .header-utilities')
        ?.insertAdjacentHTML(
            'afterbegin',
            `<button type="button" class="composer-header" aria-label="Ask Composer" aria-keyshortcuts="${mac ? 'Meta+K' : 'Control+K'}" title="Ask Composer (${mac ? '⌘K' : 'Ctrl K'})"><span class="composer-header-mark">${window.ExpaduIcon?.('spark') || ''}</span><span class="composer-header-text">Ask Composer</span><kbd>${mac ? '⌘K' : 'Ctrl K'}</kbd></button>`,
        );
    const tray = document.querySelector('#composer-tray');
    const headerButton = document.querySelector('#shell .composer-header');
    // Composer answers in place (owner chose B on the "Composer in place"
    // canvas, 22 Oct 2026): Send no longer leaves the page. The answer shows
    // in a card under the box, above it on phones: the Composer page itself,
    // showing only the latest exchange (composer-studio/embed.js). Closing
    // keeps the conversation; a dot and "Back to your conversation" bring it
    // back, and Full Composer opens the whole page.
    const I = (name) => window.ExpaduIcon?.(name) || '';
    tray.querySelector('#compose-form').insertAdjacentHTML(
        'beforeend',
        '<p class="composer-ai-notice">Composer sends what you type to Anthropic, an AI service, to read your request · <a href="https://www.anthropic.com/legal/privacy" target="_blank" rel="noopener noreferrer">Privacy notice</a></p>',
    );
    tray.insertAdjacentHTML(
        'beforeend',
        `<section class="composer-answer" id="composer-answer" aria-label="Composer’s answer" hidden><div class="composer-answer-head"><span class="composer-answer-mark">${I('spark')}</span><strong>Composer</strong><button type="button" class="composer-answer-full" aria-label="Open full Composer">${I('expand')}<span>Full Composer</span></button><button type="button" class="composer-answer-close" aria-label="Close Composer">${I('close')}</button></div><iframe class="composer-answer-frame" title="Composer’s answer" src="about:blank"></iframe><p class="sr-only" aria-live="polite"></p></section>`,
    );
    tray.insertAdjacentHTML('beforebegin', '<div class="composer-backdrop" aria-hidden="true"></div>');
    document.body.insertAdjacentHTML(
        'beforeend',
        `<button type="button" class="composer-return-chip" hidden>${I('chat')}<span>Back to your conversation</span>${I('arrow')}</button>`,
    );
    const openComposer = () =>
        tray.querySelector('.composer-launcher')?.click();
    headerButton?.addEventListener('click', openComposer);
    // Closed with its ✕ or Escape, focus goes back to the header's button
    // (the corner one it would return to is hidden there).
    const backToHeader = () =>
        setTimeout(() => {
            if (
                !tray.classList.contains('is-active') &&
                headerButton?.offsetParent
            )
                headerButton.focus({ preventScroll: true });
        }, 260);
    tray.querySelector('.composer-dismiss').addEventListener(
        'click',
        backToHeader,
    );
    tray.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') backToHeader();
    });
    document.addEventListener('keydown', (event) => {
        if (
            !(event.metaKey || event.ctrlKey) ||
            event.altKey ||
            event.shiftKey ||
            event.key.toLowerCase() !== 'k'
        )
            return;
        event.preventDefault();
        openComposer();
    });
    document
        .querySelector('#included-ideas')
        .addEventListener('click', (event) => {
            event.stopPropagation();
            S.go('composer');
        });
    // A conversation to go back to (Composer was minimized, or you opened a
    // page from it) is a line at the top of the box; the buttons themselves
    // always open the box (owner, 16 Oct 2026).
    const resume = document.querySelector('#composer-resume');
    resume.addEventListener('click', (event) => {
        event.stopPropagation();
        S.go('composer', { resume: '1' });
    });
    const field = document.querySelector('#request');
    field.value = S.read().prompt || '';
    field.addEventListener('input', () =>
        S.update((s) => {
            s.prompt = field.value;
        }),
    );
    const form = document.querySelector('#compose-form');
    // What this page is showing goes with the request (connected/page-context.js),
    // so Composer can answer "Which of these…?". You can leave it out: its ✕
    // leaves out that topic; a new one (another trip picked) shows again.
    let leftOut = null;
    const pageContext = () => {
        const context = window.ExpaduPageContext?.();
        return context?.label && context.label !== leftOut ? context : null;
    };
    function showContext() {
        const context = pageContext();
        const shown = document.querySelector('.composer-page-context');
        // Focus moving inside the box, onto the ✕ too, keeps the line as it
        // is: drawing it again took the ✕ away from under the click.
        if (shown && shown.dataset.label === context?.label) return;
        shown?.remove();
        if (!context) return;
        const esc = window.ExpaduUI?.esc || ((x) => String(x));
        form.insertAdjacentHTML(
            'beforebegin',
            `<div class="composer-page-context"><span>About: ${esc(context.label)}</span><button type="button" aria-label="Don’t include ${esc(context.label)}">${window.ExpaduIcon?.('close') || '×'}</button></div>`,
        );
        const line = document.querySelector('.composer-page-context');
        line.dataset.label = context.label;
        line.querySelector('button').addEventListener('click', (event) => {
            event.stopPropagation();
            leftOut = context.label;
            line.remove();
            field.focus();
        });
    }
    document
        .querySelector('#composer-tray')
        .addEventListener('focusin', showContext);
    const answer = tray.querySelector('#composer-answer');
    const frame = answer.querySelector('iframe');
    const said = answer.querySelector('[aria-live]');
    const chip = document.querySelector('.composer-return-chip');
    const inPlace = () => !!S.read().answerInPlace;
    let frameHeight = 0;
    // The card takes the room the screen leaves: under a box at the top,
    // down to 60px above the bottom; over a box at the bottom, up to 12px
    // under the page's header.
    function fit() {
        if (answer.hidden) return;
        const box = tray.getBoundingClientRect();
        const top = box.top < innerHeight / 2;
        tray.classList.toggle('is-top', top);
        const head = answer.querySelector('.composer-answer-head').offsetHeight;
        const room = top
            ? innerHeight - box.bottom - 14 - 60
            : box.top -
              12 -
              Math.max(12, (document.querySelector('#shell .app-header')?.getBoundingClientRect().bottom || 0) + 12);
        frame.style.height = `${Math.max(120, Math.min(frameHeight || 240, room - head))}px`;
    }
    function showAnswer(ask) {
        answer.hidden = false;
        tray.classList.add('has-answer');
        if (ask || frame.getAttribute('src') === 'about:blank') {
            const url = new URL(S.routes.composer, document.baseURI || location.href);
            url.searchParams.set('embed', '1');
            frame.src = url.href;
        }
        fit();
    }
    window.addEventListener('message', (event) => {
        if (event.origin !== location.origin || event.source !== frame.contentWindow) return;
        const type = event.data?.type;
        if (type === 'expadu:embed-size') {
            frameHeight = event.data.height;
            fit();
        }
        if (type === 'expadu:embed-answer') said.textContent = event.data.text || '';
        if (type === 'expadu:embed-close') tray.querySelector('.composer-dismiss').click();
    });
    window.addEventListener('resize', fit);
    answer.querySelector('.composer-answer-close').addEventListener('click', () =>
        tray.querySelector('.composer-dismiss').click(),
    );
    answer.querySelector('.composer-answer-full').addEventListener('click', () =>
        S.go('composer', { resume: '1' }),
    );
    chip.addEventListener('click', openComposer);
    tray.addEventListener('click', (event) => {
        if (event.target.closest('[data-composer-action="new"]'))
            S.update((s) => {
                s.answerInPlace = false;
            });
    });
    // Closed with a conversation kept: a dot on the button that opens
    // Composer, and the way back under it (above the round button on phones).
    function placeChip() {
        if (chip.hidden) return;
        const at = headerButton?.offsetParent ? headerButton.getBoundingClientRect() : null;
        chip.classList.toggle('is-corner', !at);
        chip.style.top = at ? `${Math.round(at.bottom + 8)}px` : '';
        chip.style.left = at ? `${Math.round(at.left)}px` : '';
    }
    const backdrop = document.querySelector('.composer-backdrop');
    function syncAnswer() {
        const open = tray.classList.contains('is-active');
        const header = headerButton?.offsetParent ? document.querySelector('#shell .app-header') : null;
        backdrop.style.top = header ? `${Math.max(0, Math.round(header.getBoundingClientRect().bottom))}px` : '0px';
        backdrop.classList.toggle('is-on', open);
        const keep = inPlace();
        if (open && keep && answer.hidden) showAnswer(false);
        if (!keep && !answer.hidden) {
            answer.hidden = true;
            tray.classList.remove('has-answer');
        }
        chip.hidden = open || !keep;
        headerButton?.classList.toggle('has-conversation', keep && !open);
        tray.classList.toggle('has-conversation', keep && !open);
        placeChip();
    }
    new MutationObserver(syncAnswer).observe(tray, { attributes: true, attributeFilter: ['class'] });
    window.addEventListener('resize', placeChip);
    window.addEventListener('scroll', placeChip, { passive: true });
    // The first request goes to Composer's page in the card (as the handoff
    // it always was); later ones are asked there. The box empties for the
    // next question and keeps the focus.
    function submit() {
        const text = field.value.trim();
        if (!text) return;
        const context = pageContext();
        const following = !answer.hidden && frame.getAttribute('src') !== 'about:blank';
        S.update((s) => {
            s.pageContext = context || null;
            s.answerInPlace = true;
            if (!following) {
                s.prompt = text;
                s.composerHandoff = { id: `${Date.now()}-${Math.random()}`, submit: true, date: null, action: null };
            }
        });
        if (following) frame.contentWindow?.postMessage({ type: 'expadu:ask', text }, location.origin);
        else showAnswer(true);
        field.value = '';
        tray.querySelector('[type="submit"]').disabled = true;
        field.focus({ preventScroll: true });
    }
    form.addEventListener(
        'submit',
        (e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
            submit();
        },
        true,
    );
    field.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            submit();
        }
    });
    function sync() {
        const s = S.read(),
            label = document.querySelector('#included-label'),
            button = document.querySelector('#included-ideas');
        button.hidden = !s.draft.length;
        label.textContent = `${s.draft.length} waiting for review`;
        // An answer kept in place comes back in its card instead.
        resume.hidden =
            inPlace() ||
            !(S.isComposerMinimized?.() || S.canReturnToComposer?.());
    }
    window.addEventListener('expadu:change', sync);
    window.addEventListener('storage', sync);
    window.addEventListener('pageshow', sync);
    sync();
    syncAnswer();
})();
