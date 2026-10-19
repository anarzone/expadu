/* Sign in and create an account (owner chose A, "On the street", on the
   "Sign in and create an account" canvas, 19 Oct 2026). Each state has its
   address: #sign-in (the default), #mismatch (details don't match), #create,
   #check-email, #forgot, #sent and #new-password. The mock signs nobody in:
   Sign in, Google and Apple open Today, and the emailed links are samples
   that say so. */
(function () {
    'use strict';
    const TODAY = '../today-next/brief/';
    const KEY = 'expadu-account-email';
    const SAMPLE = 'you@example.com';
    const poster = document.querySelector('#account-poster');
    const switcher = document.querySelector('.account-switch');
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
    const remembered = () => {
        try {
            return sessionStorage.getItem(KEY) || '';
        } catch {
            return '';
        }
    };
    const remember = (email) => {
        try {
            sessionStorage.setItem(KEY, email);
        } catch {
            /* Shown from the form only; nothing else needs it. */
        }
    };
    const email = () => remembered() || SAMPLE;
    // A line to show on the next state drawn (after a new password).
    let carried = '';

    const google =
        '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 12h5a5 5 0 1 1-1.5-3.6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    const apple =
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15.5 3c.1 1.2-.4 2.3-1.1 3.1-.7.8-1.8 1.3-2.8 1.2-.1-1.1.4-2.3 1.1-3 .7-.8 1.9-1.3 2.8-1.3zM19 16.7c-.5 1.1-.7 1.6-1.3 2.6-.9 1.4-2.1 3-3.6 3-1.4 0-1.7-.9-3.6-.9-1.8 0-2.2.9-3.6.9-1.5 0-2.7-1.5-3.5-2.9C1.2 15.9 1 11.4 2.8 8.9 4 7.2 5.7 6.2 7.3 6.2c1.7 0 2.7.9 4.1.9 1.3 0 2.2-.9 4.1-.9 1.5 0 3 .8 4 2.2-3.5 1.9-2.9 6.9.5 8.3z"/></svg>';
    const mail =
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v12H4z M4 7l8 6 8-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
    const check =
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    const alert =
        '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7.5v5.5M12 16.5v.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

    const social =
        `<div class="account-social"><button type="button" data-social aria-label="Continue with Google">${google}Google</button><button type="button" data-social aria-label="Continue with Apple">${apple}Apple</button></div>` +
        '<p class="account-or">or with email</p>';
    const field = (id, label, type, complete, value = '') =>
        `<div class="account-field"><label for="${id}">${label}</label><div class="account-input"><input id="${id}" name="${id}" type="${type}" autocomplete="${complete}" required value="${esc(value)}"></div></div>`;
    // One password field with Show; the app sends it as its confirmation too.
    const password = (id, label, complete, { forgot = false, hint = false } = {}) =>
        `<div class="account-field"><div class="account-field-head"><label for="${id}">${label}</label>${forgot ? '<a href="#forgot">Forgot password?</a>' : ''}</div><div class="account-input"><input id="${id}" name="${id}" type="password" autocomplete="${complete}" required${hint ? ` minlength="8" aria-describedby="${id}-hint"` : ''}><button type="button" data-show aria-pressed="false" aria-controls="${id}">Show</button></div>${hint ? `<span class="account-hint" id="${id}-hint">At least 8 characters.</span>` : ''}</div>`;
    const message = (text, kind = '') =>
        `<p class="account-message${kind ? ` is-${kind}` : ''}" role="${kind === 'mismatch' ? 'alert' : 'status'}"${text ? '' : ' hidden'}>${{ mismatch: alert, done: check }[kind] || mail}<span>${text}</span></p>`;
    const sample = (href) =>
        `<a class="account-quiet" href="${href}">Sample: open the link</a>`;

    const states = {
        'sign-in': () => ({
            title: 'Sign in',
            switch: ['#create', 'New here?', 'Create an account'],
            html: `<form data-form="sign-in"><span class="account-label">Hallo again</span><h1 tabindex="-1">Welcome back</h1><p class="account-lead">Sign in to see your day.</p>${carried ? message(esc(carried), 'done') : ''}${social}${field('email', 'Email', 'email', 'email')}${password('password', 'Password', 'current-password', { forgot: true })}<label class="account-keep"><input type="checkbox" name="remember" checked>Keep me signed in</label><button class="account-primary" type="submit">Sign in</button></form>`,
        }),
        mismatch: () => ({
            title: 'Sign in',
            switch: ['#create', 'New here?', 'Create an account'],
            focus: '#password',
            html: `<form data-form="sign-in"><span class="account-label">Hallo again</span><h1 tabindex="-1">Welcome back</h1>${message('That email and password don’t match. Check both, or <a href="#forgot">reset your password</a>.', 'mismatch')}${social}${field('email', 'Email', 'email', 'email', email())}${password('password', 'Password', 'current-password', { forgot: true })}<label class="account-keep"><input type="checkbox" name="remember" checked>Keep me signed in</label><button class="account-primary" type="submit">Sign in</button></form>`,
        }),
        create: () => ({
            title: 'Create an account',
            switch: ['#sign-in', 'Have an account?', 'Sign in'],
            html: `<form data-form="create"><span class="account-label">Willkommen in Köln</span><h1 tabindex="-1">New in Cologne? Start here.</h1><p class="account-lead">Free to start. Cologne, for now.</p>${social}${field('name', 'Name', 'text', 'name')}${field('email', 'Email', 'email', 'email')}${password('password', 'Password', 'new-password', { hint: true })}<button class="account-primary" type="submit">Create account</button><p class="account-note">We handle your details as our <a href="/datenschutz" title="The app’s privacy policy">privacy policy</a> describes.</p></form>`,
        }),
        'check-email': () => ({
            title: 'Check your email',
            switch: ['#sign-in', 'Have an account?', 'Sign in'],
            html: `<div class="account-body"><span class="account-label">Fast geschafft</span><h1 tabindex="-1">Check your email</h1><p class="account-mail">We sent a link to <b>${esc(email())}</b>. Open it to finish creating your account.</p><p class="account-note">It works for 60 minutes. Not there? Look in spam.</p>${message('')}<button class="account-secondary" type="button" data-again>Send the link again</button><a class="account-quiet" href="#create">Wrong email? Start again</a>${sample(TODAY)}</div>`,
        }),
        forgot: () => ({
            title: 'Reset your password',
            switch: ['#create', 'New here?', 'Create an account'],
            html: `<form data-form="forgot"><a class="account-back" href="#sign-in">← Back to sign in</a><span class="account-label">Kein Problem</span><h1 tabindex="-1">Reset your password</h1><p class="account-lead">We’ll email you a link to choose a new one.</p>${field('email', 'Email', 'email', 'email', remembered())}<button class="account-primary" type="submit">Send the link</button></form>`,
        }),
        sent: () => ({
            title: 'Reset your password',
            switch: ['#create', 'New here?', 'Create an account'],
            html: `<div class="account-body"><a class="account-back" href="#sign-in">← Back to sign in</a><span class="account-label">Kein Problem</span><h1 tabindex="-1">Reset your password</h1>${message(`The link is on its way to <b>${esc(email())}</b>. It works for 60 minutes.`)}<button class="account-secondary" type="button" data-again>Send it again</button>${sample('#new-password')}</div>`,
        }),
        'new-password': () => ({
            title: 'Choose a new password',
            switch: ['#sign-in', 'Have an account?', 'Sign in'],
            html: `<form data-form="new-password"><span class="account-label">Neues Passwort</span><h1 tabindex="-1">Choose a new password</h1><p class="account-lead">For <b>${esc(email())}</b>.</p>${password('password', 'New password', 'new-password', { hint: true })}<button class="account-primary" type="submit">Save new password</button><p class="account-note">Then sign in with it. The link works once, for 60 minutes.</p></form>`,
        }),
    };

    let first = true;
    function draw() {
        const name = location.hash.slice(1);
        const state = (states[name] || states['sign-in'])();
        poster.innerHTML = state.html;
        carried = '';
        const [href, lead, action] = state.switch;
        switcher.href = href;
        switcher.innerHTML = `<span>${lead}</span><b>${action}</b>`;
        document.title = `Expadu — ${state.title}`;
        if (!first) {
            poster.classList.remove('is-pasted');
            void poster.offsetWidth;
            poster.classList.add('is-pasted');
        }
        const target = state.focus
            ? poster.querySelector(state.focus)
            : first
              ? null
              : poster.querySelector('h1');
        target?.focus({ preventScroll: !state.focus });
        if (!first) window.scrollTo({ top: 0, behavior: 'instant' });
        first = false;
    }

    poster.addEventListener('submit', (event) => {
        const form = event.target;
        event.preventDefault();
        // The 8-character rule holds however the value got there.
        for (const input of form.querySelectorAll('[minlength]'))
            input.setCustomValidity(
                input.value.length < input.minLength
                    ? 'At least 8 characters.'
                    : '',
            );
        if (!form.reportValidity()) return;
        const typed = form.querySelector('[name="email"]')?.value.trim();
        if (typed) remember(typed);
        const kind = form.dataset.form;
        if (kind === 'sign-in') location.href = TODAY;
        else if (kind === 'create') location.hash = 'check-email';
        else if (kind === 'forgot') location.hash = 'sent';
        else if (kind === 'new-password') {
            carried = 'Your password is changed. Sign in with it.';
            location.hash = 'sign-in';
        }
    });
    poster.addEventListener('input', (event) => {
        if (event.target.minLength > 0) event.target.setCustomValidity('');
    });
    poster.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.hasAttribute('data-social')) location.href = TODAY;
        if (button.hasAttribute('data-show')) {
            const input = document.getElementById(
                button.getAttribute('aria-controls'),
            );
            const shown = input.type === 'password';
            input.type = shown ? 'text' : 'password';
            button.textContent = shown ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', String(shown));
        }
        if (button.hasAttribute('data-again')) {
            const line = poster.querySelector('.account-message');
            line.hidden = false;
            line.querySelector('span').innerHTML =
                'A new link is on its way. Sample: nothing is sent.';
        }
    });
    window.addEventListener('hashchange', draw);
    draw();
})();
