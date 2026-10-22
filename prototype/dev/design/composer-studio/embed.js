/* Composer's answer in place (owner chose B, "Composer in place" canvas,
   22 Oct 2026): another page's box shows this page in a card under it, with
   ?embed=1. Only the latest exchange shows; the earlier ones fold behind
   "N earlier messages". Follow-ups come from the box as messages, and the
   card is told this page's height and the answer's first line. Links leave
   the card for the page itself. */
(function () {
    if (
        new URLSearchParams(location.search).get('embed') !== '1' ||
        window.parent === window
    )
        return;
    document.documentElement.classList.add('is-embedded');
    const tell = (data) => window.parent.postMessage(data, location.origin);
    let showEarlier = false,
        latest = null;
    function fold() {
        const feed = document.querySelector('.chat-feed');
        if (!feed) return;
        const entries = [...feed.querySelectorAll(':scope > .chat-entry')];
        const asked = entries.filter((x) => x.classList.contains('chat-user'));
        const last = asked.at(-1);
        const from = last ? entries.indexOf(last) : 0;
        entries.forEach((x, i) => x.classList.toggle('is-earlier', i < from));
        feed.classList.toggle('show-earlier', showEarlier);
        const earlier = asked.length - 1;
        let pill = feed.querySelector(':scope > .embed-earlier');
        if (earlier > 0) {
            if (!pill) {
                pill = document.createElement('button');
                pill.type = 'button';
                pill.className = 'embed-earlier';
                pill.addEventListener('click', () => {
                    showEarlier = !showEarlier;
                    fold();
                });
                feed.prepend(pill);
            }
            const label = showEarlier
                ? 'Hide earlier messages'
                : `${earlier} earlier ${earlier === 1 ? 'message' : 'messages'}`;
            if (pill.textContent !== label) pill.textContent = label;
        } else pill?.remove();
        // A new answer starts at its top, and its first line is read out.
        const id = last?.dataset.chatEntry || null;
        const answer = entries.slice(from + 1).find((x) => x.classList.contains('chat-assistant'));
        const said = answer?.querySelector('h3, p')?.textContent.trim() || '';
        if (id !== latest && said) {
            latest = id;
            showEarlier = false;
            feed.classList.remove('show-earlier');
            // The page scrolls itself to a new answer; the card shows it
            // from its top instead.
            [0, 250, 700].forEach((ms) => setTimeout(() => window.scrollTo({ top: 0, behavior: 'instant' }), ms));
            tell({ type: 'expadu:embed-answer', text: said });
        }
        size();
    }
    function size() {
        tell({
            type: 'expadu:embed-size',
            height: Math.ceil(document.querySelector('#main')?.getBoundingClientRect().height || document.documentElement.scrollHeight),
        });
    }
    window.addEventListener('message', (event) => {
        if (event.origin !== location.origin || event.data?.type !== 'expadu:ask') return;
        const field = document.querySelector('#studio-request');
        const form = document.querySelector('#studio-form');
        if (!field || !form) return;
        field.value = event.data.text;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        form.requestSubmit();
    });
    // Escape inside the card closes it, as it does in the box.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !document.querySelector('dialog[open], [role="dialog"]:not([hidden])'))
            tell({ type: 'expadu:embed-close' });
    });
    document.addEventListener(
        'click',
        (event) => {
            const link = event.target.closest('a[href]');
            if (link && !link.target && !link.getAttribute('href').startsWith('#')) link.target = '_top';
        },
        true,
    );
    new MutationObserver(fold).observe(document.body, { childList: true, subtree: true });
    new ResizeObserver(size).observe(document.body);
    window.addEventListener('load', fold);
    fold();
})();
