/* Settings (owner, 22 Oct 2026: "build it" for the reworked canvas, with
   two-factor hidden for now). A tile opens its edit in place; Escape or
   Cancel puts the tile back and focus on it. The default preview is a
   Google account; ?signin=email previews an email-and-password one, which
   adds the Password tile. Nothing here leaves the page: it is a preview. */
(() => {
    const main = document.querySelector('#main');
    if (!main) return;
    const S = window.ExpaduStore;
    const google =
        new URLSearchParams(location.search).get('signin') !== 'email';
    const shapes = {
        sliders: '<path d="M4 6h16M4 18h16M8 3v6m8 6v6"/>',
        user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        mail: '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6"/>',
        key: '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9m-4 4 3 3"/>',
        lock: '<rect x="4" y="10" width="16" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        pencil: '<path d="m3 16 12-12 5 5L8 21H3v-5Zm9-9 5 5"/>',
        spark: '<path d="M12 3l1.8 5.4L19 10l-5.2 1.6L12 17l-1.8-5.4L5 10l5.2-1.6z"/>',
        pin: '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        bell: '<path d="M4 17h16l-2-4V8a6 6 0 0 0-12 0v5l-2 4Zm5 4h6"/>',
        trash: '<path d="M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7m4-7v7"/>',
        out: '<path d="M14 4h5v16h-5M10 8l-4 4 4 4M6 12h11"/>',
    };
    const icon = (name, cls = '') =>
        `<svg${cls ? ` class="${cls}"` : ''} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${shapes[name]}</svg>`;
    const esc = (value) =>
        String(value).replace(
            /[&<>"']/g,
            (c) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[c],
        );

    const state = {
        name: 'Anar',
        email: 'you@example.com',
        city: 'Cologne',
        covered: true,
        edit: null,
        draft: null,
        saved: '',
        citySaved: '',
        learn: false,
        askOff: false,
        erased: false,
        del: 0,
        codeSent: false,
    };

    const actions = (save = 'Save') =>
        `<div class="settings-actions"><button type="submit" class="settings-primary">${save}</button><button type="button" class="settings-quiet" data-cancel>Cancel</button></div>`;
    const field = (id, label, attrs = '', value = '') =>
        `<label class="settings-field" for="${id}">${label}<input id="${id}" name="${id}" ${attrs} value="${esc(value)}" aria-describedby="${id}-error" /></label><p class="settings-error" id="${id}-error" hidden></p>`;
    const tile = (key, iconName, label, value, editLabel, extra = '') =>
        `<button type="button" class="settings-tile" data-open="${key}" aria-label="${esc(`${label}: ${value}. ${editLabel}`)}">${iconName ? icon(iconName) : ''}<span class="settings-tile-copy"><small>${label}</small><strong>${esc(value)}</strong></span>${extra}${icon('pencil', 'settings-tile-edit')}</button>`;

    function editor(key) {
        if (key === 'name')
            return `<form class="settings-edit" data-form="name" novalidate>${field('name', 'Name', 'autocomplete="name" placeholder="Full name"', state.name)}${actions()}</form>`;
        if (key === 'email')
            return `<form class="settings-edit" data-form="email" novalidate>${field('email', 'Email address', 'type="email" autocomplete="email"', state.email)}<p class="settings-hint">We email a link to the new address to confirm it.</p>${actions()}</form>`;
        if (key === 'password')
            return `<form class="settings-edit" data-form="password" novalidate><strong>Change password</strong>${field('current_password', 'Current password', 'type="password" autocomplete="current-password"')}${field('password', 'New password', 'type="password" autocomplete="new-password"')}${field('password_confirmation', 'Confirm password', 'type="password" autocomplete="new-password"')}${actions('Save password')}</form>`;
        const other = state.draft === 'other';
        return `<form class="settings-edit${state.still ? ' is-still' : ''}" data-form="city" novalidate><fieldset class="settings-choices"><legend>Your city</legend><label class="settings-choice"><input type="radio" name="city-kind" value="cologne"${other ? '' : ' checked'} /><span><strong>Cologne</strong>Reviewed Paperwork guidance</span></label><label class="settings-choice"><input type="radio" name="city-kind" value="other"${other ? ' checked' : ''} /><span><strong>Another city</strong>No reviewed guidance yet</span></label></fieldset>${other ? `${field('city', 'City name', 'autocomplete="address-level2" placeholder="Your city"', state.covered ? '' : state.city)}<p class="settings-hint paper">Paperwork covers Cologne so far, and doesn’t guess for other cities. Your answers stay.</p>` : ''}${actions()}</form>`;
    }

    function account() {
        const t = (key, ...rest) =>
            state.edit === key ? editor(key) : tile(key, ...rest);
        return `<div class="settings-eyebrow">${icon('sliders')}Settings</div>
            <h1 id="settings-title">Your account</h1>
            <p>How you sign in, and what Expadu keeps about you.</p>
            <div class="settings-tiles">
                ${t('name', 'user', 'Name', state.name, 'Edit')}
                ${t('email', 'mail', 'Email address', state.email, 'Edit')}
                <div class="settings-tile">${icon('key')}<span class="settings-tile-copy"><small>Sign-in</small><strong>${google ? 'Google' : 'Email and password'}</strong><span class="settings-tile-note">${google ? 'You sign in with Google, so there’s no Expadu password.' : `With ${esc(state.email)}`}</span></span></div>
                ${google ? '' : state.edit === 'password' ? editor('password') : `<button type="button" class="settings-tile" data-open="password" aria-label="Password. Change password">${icon('lock')}<span class="settings-tile-copy"><small>Password</small><strong class="settings-secret">••••••••</strong></span>${icon('pencil', 'settings-tile-edit')}</button>`}
            </div>
            <p class="settings-status" role="status"></p>
            <a class="settings-quiet settings-out" href="${S?.routes?.account || '../account/'}">${icon('out')}Sign out</a>`;
    }

    function learn() {
        return `<div class="settings-head"><span class="settings-chip learn">${icon('spark')}</span><div><h2 id="learn-title">Personalised suggestions</h2><p>Let Expadu learn from the places and events you open, save or skip.</p></div></div>
            <div class="settings-tile"><span class="settings-tile-copy"><strong id="learn-label">Learn what I like</strong><span class="settings-tile-note" id="learn-state">${state.learn ? 'On: suggestions follow what you open and save' : 'Off: everyone sees the same suggestions'}</span></span><button type="button" class="settings-switch" role="switch" aria-checked="${state.learn}" aria-labelledby="learn-label" aria-describedby="learn-state" data-learn></button></div>
            ${state.askOff ? `<div class="settings-confirm" role="group" aria-labelledby="off-title"><strong id="off-title">Turn off and delete what was learned?</strong><p>What Expadu learned from what you opened, saved or skipped is deleted, and can’t be brought back. Suggestions go back to the same ones everyone sees.</p><div class="settings-actions"><button type="button" class="settings-quiet" data-keep-on>Keep it on</button><button type="button" class="settings-primary" data-turn-off>Turn off and delete</button></div></div>` : ''}
            <p class="settings-status" role="status"></p>
            <div class="settings-learns"><span>It learns from</span><span>What you open</span><span>What you save</span><span>What you skip</span></div>`;
    }

    function city() {
        return `<div class="settings-head"><span class="settings-chip city">${icon('pin')}</span><div><h2 id="city-title">City</h2><p>Paperwork’s guidance depends on it. Expadu covers Cologne for now.</p></div></div>
            ${state.edit === 'city' ? editor('city') : tile('city', '', 'Your city', state.city, 'Change your city', `<span class="settings-badge${state.covered ? '' : ' none'}">${state.covered ? 'Reviewed guidance' : 'No reviewed guidance'}</span>`)}
            <p class="settings-status" role="status"></p>`;
    }

    function remove() {
        let body = '';
        if (state.del === 0)
            body = `<button type="button" class="settings-danger-outline" data-delete>Delete account</button>`;
        if (state.del === 1)
            body = `<form class="settings-confirm" data-form="delete" role="group" aria-labelledby="sure-title" novalidate><strong id="sure-title">Are you sure you want to delete your account?</strong><p>Once your account is deleted, all of its resources and data will also be permanently deleted.</p>${
                google
                    ? `<p>To confirm, enter the code we email to ${esc(state.email)}.</p>${
                          state.codeSent
                              ? `${field('code', 'Code from your email', 'class="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"')}<button type="button" class="settings-link" data-send-code>Send a new code</button>`
                              : `<button type="button" class="settings-quiet" style="justify-self:start" data-send-code>Email me a code</button>`
                      }`
                    : field(
                          'delete_password',
                          'Enter your password to confirm',
                          'class="short" type="password" autocomplete="current-password"',
                      )
            }<div class="settings-actions"><button type="button" class="settings-quiet" data-keep-account>Keep my account</button><button type="submit" class="settings-danger">Delete account</button></div></form>`;
        if (state.del === 2)
            body = `<p class="settings-done" role="status" tabindex="-1">In the app, your account and its data are deleted now and you’re signed out. Nothing is deleted in this preview.</p>`;
        return `<div class="settings-head"><span class="settings-chip delete">${icon('trash')}</span><div><h2 id="delete-title">Delete account</h2><p>Delete your account and all of its resources. This can’t be undone.</p></div></div>${body}`;
    }

    const later = (id, iconName, title, label, text) =>
        `<section class="settings-panel is-later" aria-labelledby="${id}-title"><div class="settings-head"><span class="settings-chip">${icon(iconName)}</span><h2 id="${id}-title">${title}</h2><span class="settings-later-label">${label}</span></div><p>${text}</p></section>`;

    main.innerHTML = `<div class="settings-page">
        <section class="settings-panel settings-account" data-panel="account" aria-labelledby="settings-title"></section>
        <div class="settings-side">
            <section class="settings-panel" data-panel="learn" aria-labelledby="learn-title"></section>
            <section class="settings-panel" data-panel="city" aria-labelledby="city-title"></section>
            <div class="settings-later">
                ${later('notify', 'bell', 'Notifications', 'With Alerts', 'What Expadu tells you about, and where, is set here once the new Alerts is designed.')}
                ${later('ai', 'sliders', 'AI limits', 'Later', 'Limits on how Expadu uses AI for you will be set here.')}
            </div>
            <section class="settings-panel" data-panel="delete" aria-labelledby="delete-title"></section>
        </div>
    </div>`;
    const panels = { account, learn, city, delete: remove };
    const panelOf = {
        name: 'account',
        email: 'account',
        password: 'account',
        city: 'city',
    };
    const status = {
        account: () => (state.edit ? '' : state.saved),
        learn: () =>
            state.erased && !state.learn
                ? 'Off. What Expadu had learned is deleted.'
                : '',
        city: () => (state.edit ? '' : state.citySaved),
    };
    // A status line is drawn empty and filled a moment later, so a screen
    // reader announces it as a change.
    function draw(name, focus) {
        const panel = main.querySelector(`[data-panel="${name}"]`);
        panel.innerHTML = panels[name]();
        if (focus) main.querySelector(focus)?.focus();
        const line = panel.querySelector('.settings-status');
        const text = status[name]?.();
        if (line && text) setTimeout(() => (line.textContent = text), 120);
    }
    Object.keys(panels).forEach((name) => draw(name));

    function open(key) {
        const was = state.edit;
        state.edit = key;
        state.still = false;
        state.saved = '';
        state.citySaved = '';
        state.draft =
            key === 'city' ? (state.covered ? 'cologne' : 'other') : null;
        if (was && panelOf[was] !== panelOf[key]) draw(panelOf[was]);
        draw(panelOf[key]);
        const first = main.querySelector(
            `[data-form="${key}"] input:not([type="radio"]), [data-form="${key}"] input:checked`,
        );
        first?.focus();
        if (first?.select && first.type !== 'radio') first.select();
    }
    function close(key, message = '') {
        state.edit = null;
        if (key === 'city') state.citySaved = message;
        else state.saved = message;
        draw(panelOf[key], `[data-open="${key}"]`);
    }
    function fail(form, id, message) {
        const input = form.querySelector(`#${id}`);
        const error = form.querySelector(`#${id}-error`);
        input.setAttribute('aria-invalid', 'true');
        error.textContent = message;
        error.hidden = false;
        input.focus();
        return false;
    }
    function clearErrors(form) {
        form.querySelectorAll('[aria-invalid]').forEach((i) =>
            i.removeAttribute('aria-invalid'),
        );
        form.querySelectorAll('.settings-error').forEach((e) => {
            e.hidden = true;
            e.textContent = '';
        });
    }
    const val = (form, id) => form.querySelector(`#${id}`).value;
    const save = {
        name(form) {
            const value = val(form, 'name').trim();
            if (!value)
                return fail(form, 'name', 'The name field is required.');
            state.name = value;
            return true;
        },
        email(form) {
            const value = val(form, 'email').trim();
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value))
                return fail(
                    form,
                    'email',
                    'The email field must be a valid email address.',
                );
            state.email = value;
            return true;
        },
        password(form) {
            if (!val(form, 'current_password'))
                return fail(
                    form,
                    'current_password',
                    'The current password field is required.',
                );
            if (val(form, 'password').length < 8)
                return fail(
                    form,
                    'password',
                    'The password field must be at least 8 characters.',
                );
            if (val(form, 'password') !== val(form, 'password_confirmation'))
                return fail(
                    form,
                    'password',
                    'The password field confirmation does not match.',
                );
            return true;
        },
        city(form) {
            if (state.draft !== 'other') {
                state.city = 'Cologne';
                state.covered = true;
                return true;
            }
            const value = val(form, 'city').trim();
            if (!value)
                return fail(form, 'city', 'The city field is required.');
            state.city = value;
            state.covered = /^(cologne|köln|koeln)$/i.test(value);
            return true;
        },
    };

    main.addEventListener('click', (event) => {
        const target = event.target.closest('button');
        if (!target) return;
        if (target.dataset.open) return open(target.dataset.open);
        if (target.hasAttribute('data-cancel')) {
            const key = target.closest('[data-form]').dataset.form;
            return close(key);
        }
        if (target.hasAttribute('data-learn')) {
            if (state.learn) {
                state.askOff = true;
                return draw('learn', '[data-keep-on]');
            }
            state.learn = true;
            state.erased = false;
            return draw('learn', '[data-learn]');
        }
        if (target.hasAttribute('data-keep-on')) {
            state.askOff = false;
            return draw('learn', '[data-learn]');
        }
        if (target.hasAttribute('data-turn-off')) {
            Object.assign(state, { learn: false, askOff: false, erased: true });
            return draw('learn', '[data-learn]');
        }
        if (target.hasAttribute('data-delete')) {
            state.del = 1;
            state.codeSent = false;
            return draw(
                'delete',
                google ? '[data-send-code]' : '#delete_password',
            );
        }
        if (target.hasAttribute('data-send-code')) {
            state.codeSent = true;
            return draw('delete', '#code');
        }
        if (target.hasAttribute('data-keep-account')) {
            state.del = 0;
            return draw('delete', '[data-delete]');
        }
    });
    main.addEventListener('change', (event) => {
        if (event.target.name !== 'city-kind') return;
        state.draft = event.target.value;
        // The open edit only grows or shrinks; it doesn't fade in again.
        state.still = true;
        draw('city', `input[name="city-kind"][value="${state.draft}"]`);
    });
    main.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.target;
        const key = form.dataset.form;
        clearErrors(form);
        if (key === 'delete') {
            const input = form.querySelector('#code, #delete_password');
            if (!input) return form.querySelector('[data-send-code]')?.focus();
            if (!input.value.trim())
                return fail(
                    form,
                    input.id,
                    input.id === 'code'
                        ? 'The code field is required.'
                        : 'The password field is required.',
                );
            state.del = 2;
            return draw('delete', '.settings-done');
        }
        if (save[key](form)) close(key, 'Saved.');
    });
    main.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const form = event.target.closest('[data-form]');
        if (form?.dataset.form === 'delete') {
            state.del = 0;
            return draw('delete', '[data-delete]');
        }
        if (form) return close(form.dataset.form);
        if (event.target.closest('.settings-confirm') && state.askOff) {
            state.askOff = false;
            draw('learn', '[data-learn]');
        }
    });
})();
