(function (root) {
    const escape = (text) =>
        String(text).replace(
            /[&<>"']/g,
            (char) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[char],
        );
    function spark() {
        return `<svg class="expadu-loading-spark" viewBox="0 0 48 48" fill="none" aria-hidden="true"><path class="spark-outline" d="M24 4 29.5 18.5 44 24 29.5 29.5 24 44 18.5 29.5 4 24 18.5 18.5Z"/><g class="spark-tips"><path d="M18.5 18.5 24 4 29.5 18.5"/><path d="M29.5 18.5 44 24 29.5 29.5"/><path d="M29.5 29.5 24 44 18.5 29.5"/><path d="M18.5 29.5 4 24 18.5 18.5"/></g></svg>`;
    }
    function status(label) {
        return `<span class="expadu-loading-status" role="status">${spark()}<span>${escape(label)}</span></span>`;
    }
    function skeleton(kind = 'list') {
        const row =
            '<div class="loading-row"><span class="loading-shape loading-thumb"></span><div><span class="loading-shape loading-line"></span><span class="loading-shape loading-line short"></span></div></div>';
        return `<div class="expadu-skeleton ${kind === 'cards' ? 'loading-cards' : ''}" aria-hidden="true">${kind === 'cards' ? Array.from({ length: 2 }, () => '<div class="loading-card"><div class="loading-shape loading-photo"></div><span class="loading-shape loading-line"></span><span class="loading-shape loading-line short"></span></div>').join('') : row.repeat(3)}</div>`;
    }
    // A delayed indicator never flashes for work that finishes immediately.
    function createGate({
        show,
        hide,
        delay = 140,
        setTimer = setTimeout,
        clearTimer = clearTimeout,
    }) {
        let ticket = 0,
            timer = null,
            visible = false;
        return {
            start() {
                const id = ++ticket;
                if (timer !== null) clearTimer(timer);
                if (visible) hide();
                visible = false;
                timer = setTimer(() => {
                    timer = null;
                    if (id === ticket) {
                        visible = true;
                        show();
                    }
                }, delay);
                return id;
            },
            finish(id) {
                if (id !== ticket) return;
                ++ticket;
                if (timer !== null) clearTimer(timer);
                timer = null;
                if (visible) hide();
                visible = false;
            },
        };
    }
    const api = { spark, status, skeleton, createGate };
    root.ExpaduLoading = api;
    if (typeof module !== 'undefined') module.exports = api;
})(globalThis);
