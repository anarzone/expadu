/* Shared date choice for Composer and connected planning forms. */
(function () {
    const iso = (date) =>
        `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const label = (value) =>
        new Date(value + 'T12:00:00').toLocaleDateString('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    // The previews' shared sample day, where a page has one.
    const today = () => window.ExpaduSampleDay?.date || iso(new Date());
    function enhance(root = document) {
        root.querySelectorAll(
            'input[type="date"]:not([data-date-enhanced]):not([readonly])',
        ).forEach((input) => {
            input.dataset.dateEnhanced = 'true';
            const wrapper = document.createElement('div');
            wrapper.className = 'shared-date';
            input.before(wrapper);
            wrapper.append(input);
            input.hidden = true;
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'shared-date-toggle';
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Choose date');
            const panel = document.createElement('div');
            panel.className = 'shared-calendar';
            panel.hidden = true;
            wrapper.append(toggle, panel);
            let month = new Date(
                (input.value || today()) + 'T12:00:00',
            );
            month.setDate(1);
            function close() {
                panel.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
            }
            function sync() {
                if (input.value) {
                    month = new Date(input.value + 'T12:00:00');
                    month.setDate(1);
                }
                toggle.textContent = input.value
                    ? label(input.value) + ' ▾'
                    : 'Choose a date ▾';
            }
            function render() {
                const first = new Date(month);
                first.setDate(1 - ((month.getDay() + 6) % 7));
                const cells = Array.from({ length: 42 }, (_, i) => {
                    const d = new Date(first);
                    d.setDate(first.getDate() + i);
                    return d;
                });
                panel.innerHTML = `<div class="shared-calendar-head"><button type="button" data-month="-1" aria-label="Previous month">‹</button><strong>${month.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })}</strong><button type="button" data-month="1" aria-label="Next month">›</button></div><div class="shared-calendar-week" aria-hidden="true">${['M', 'T', 'W', 'T', 'F', 'S', 'S'].map((x) => `<span>${x}</span>`).join('')}</div><div class="shared-calendar-days">${cells.map((d) => `<button type="button" data-day="${iso(d)}" aria-label="${label(iso(d))}" aria-pressed="${iso(d) === input.value}" ${(input.min && iso(d) < input.min) || (input.max && iso(d) > input.max) ? 'disabled' : ''} class="${d.getMonth() !== month.getMonth() ? 'outside' : ''}">${d.getDate()}</button>`).join('')}</div><button type="button" class="shared-calendar-today" data-day="${today()}">Today</button>`;
            }
            toggle.onclick = () => {
                panel.hidden = !panel.hidden;
                toggle.setAttribute('aria-expanded', String(!panel.hidden));
                if (!panel.hidden) render();
            };
            panel.onclick = (event) => {
                const button = event.target.closest('button');
                if (!button) return;
                if (button.dataset.month) {
                    month.setMonth(
                        month.getMonth() + Number(button.dataset.month),
                    );
                    render();
                    panel
                        .querySelector(`[data-month="${button.dataset.month}"]`)
                        .focus();
                }
                if (button.dataset.day) {
                    input.value = button.dataset.day;
                    sync();
                    close();
                    toggle.focus();
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            };
            wrapper.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !panel.hidden) {
                    event.preventDefault();
                    event.stopPropagation();
                    close();
                    toggle.focus();
                }
            });
            input.addEventListener('change', sync);
            sync();
        });
    }
    document.addEventListener('click', (event) => {
        document.querySelectorAll('.shared-date').forEach((wrapper) => {
            // Month navigation replaces the clicked button before this handler runs.
            // The original event path still identifies that as an inside click.
            if (
                !event.composedPath?.().includes(wrapper) &&
                !wrapper.contains(event.target)
            ) {
                wrapper.querySelector('.shared-calendar').hidden = true;
                wrapper
                    .querySelector('.shared-date-toggle')
                    .setAttribute('aria-expanded', 'false');
            }
        });
    });
    window.ExpaduDate = { enhance };
    enhance();
    new MutationObserver(() => enhance()).observe(document.body, {
        childList: true,
        subtree: true,
    });
})();
