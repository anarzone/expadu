/* Reuse the approved Today Composer surface on department pages. */
(function () {
    const S = ExpaduStore;
    document.querySelector('#dock')?.remove();
    document.querySelector('.composer-dock')?.remove();
    document.body.classList.add('department-composer');
    document.body.insertAdjacentHTML(
        'beforeend',
        '<div class="composer-tray" id="composer-tray">\n            <button\n                type="button"\n                class="composer-launcher"\n                aria-label="Open Composer"\n                aria-expanded="false"\n                aria-controls="compose-form"\n                title="Composer"\n            >\n                <span data-icon="spark"></span>\n            </button>\n            <button\n                type="button"\n                class="composer-dismiss"\n                aria-label="Close Composer"\n                title="Close Composer"\n            >\n                <span data-icon="close"></span>\n            </button>\n            <button\n                class="included-ideas"\n                id="included-ideas"\n                data-composer-panel="plans"\n                hidden\n            >\n                <span data-icon="bookmark"></span\n                ><span id="included-label"></span\n                ><span data-icon="arrow"></span>\n            </button>\n            <form id="compose-form" class="composer-form">\n                <div class="composer-entry">\n                    <textarea\n                        id="request"\n                        rows="1"\n                        maxlength="700"\n                        aria-label="Your request to Composer"\n                        placeholder="Ask, discover, or plan…"\n                    ></textarea>\n                </div>\n                <div class="today-composer-tools">\n                    <details class="today-composer-actions">\n                        <summary aria-label="Composer actions">\n                            <span data-icon="plus"></span>\n                        </summary>\n                        <div\n                            class="today-control-menu"\n                            aria-label="Composer actions"\n                        >\n                            <button type="button" data-composer-panel="plans">\n                                <span data-icon="calendar"></span>My Plans\n                            </button>\n                            <button type="button" data-composer-panel="saved">\n                                <span data-icon="bookmark"></span>Saved\n                            </button>\n                            <button type="button" data-composer-panel="history">\n                                <span data-icon="clock"></span>History\n                            </button>\n                            <button\n                                type="button"\n                                data-composer-action="appointment"\n                            >\n                                <span data-icon="calendar"></span>Add\n                                appointment\n                            </button>\n                            <button\n                                type="button"\n                                data-composer-action="examples"\n                            >\n                                <span data-icon="spark"></span>Try Composer\n                            </button>\n                            <button type="button" data-composer-action="new">\n                                <span data-icon="plus"></span>Start fresh\n                            </button>\n                        </div>\n                    </details>\n                    <details class="today-approval">\n                        <summary aria-label="Plan change approval">\n                            <span data-approval-label>Ask first</span\n                            ><span data-icon="down"></span>\n                        </summary>\n                        <div\n                            class="today-control-menu"\n                            role="menu"\n                            aria-label="Plan change approval"\n                        >\n                            <button\n                                type="button"\n                                role="menuitemradio"\n                                aria-checked="true"\n                                data-today-approval="ask"\n                            >\n                                <span\n                                    ><strong>Ask before changes</strong\n                                    ><small\n                                        >Review and apply changes\n                                        yourself.</small\n                                    ></span\n                                ><span data-icon="check"></span>\n                            </button>\n                            <button\n                                type="button"\n                                role="menuitemradio"\n                                aria-checked="false"\n                                data-today-approval="routine"\n                            >\n                                <span\n                                    ><strong>Allow routine changes</strong\n                                    ><small\n                                        >Automatically apply reversible plan\n                                        edits.</small\n                                    ></span\n                                ><span data-icon="check"></span>\n                            </button>\n                            <p>Bookings and payments always need approval.</p>\n                        </div>\n                    </details>\n                    <button\n                        class="compose-button"\n                        type="submit"\n                        aria-label="Send request"\n                        disabled\n                    >\n                        <svg\n                            viewBox="0 0 24 24"\n                            fill="none"\n                            stroke="currentColor"\n                            stroke-width="1.8"\n                            stroke-linecap="round"\n                            stroke-linejoin="round"\n                            aria-hidden="true"\n                        >\n                            <path d="M12 19V5m-7 7 7-7 7 7" />\n                        </svg>\n                    </button>\n                </div>\n            </form>\n        </div>',
    );
    document
        .querySelector('#included-ideas')
        .addEventListener('click', (event) => {
            event.stopPropagation();
            S.go('composer');
        });
    const field = document.querySelector('#request');
    field.value = S.read().prompt || '';
    field.addEventListener('input', () =>
        S.update((s) => {
            s.prompt = field.value;
        }),
    );
    const form = document.querySelector('#compose-form');
    function submit() {
        if (!field.value.trim()) return;
        S.update((s) => {
            s.prompt = field.value.trim();
        });
        S.go('composer', { compose: '1' });
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
    }
    window.addEventListener('expadu:change', sync);
    window.addEventListener('storage', sync);
    sync();
})();
