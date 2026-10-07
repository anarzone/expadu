/* An appointment is an arranged commitment; never offer leisure time ideas. */
(function () {
    const U = ExpaduUI;
    function open(item) {
        U.open(
            'Add your appointment',
            `<section class="place-sheet appointment-sheet"><div class="appointment-heading"><span>${U.icon('calendar')}</span><div><h3>${U.esc(item.title)}</h3><p>${U.esc(item.location || 'Location not added')}</p></div></div><div class="place-sheet-panel"><div data-appointment-planning></div></div></section>`,
        );
        const container = document.querySelector('[data-appointment-planning]');
        ExpaduPlanning.mount(item, container);
        const form = container.querySelector('form');
        const footer = form.querySelector('.actions');
        const scroll = document.createElement('div');
        scroll.className = 'place-sheet-scroll';
        for (const child of [...form.childNodes])
            if (child !== footer) scroll.append(child);
        form.prepend(scroll);
        const setup = document.createElement('div');
        setup.className = 'visit-setup';
        for (const selector of ['.selection-day', '.selection-times'])
            setup.append(scroll.querySelector(selector));
        scroll.prepend(setup);
        const label = setup.querySelector('.selection-day > span');
        label.textContent = 'Appointment date';
        const note = document.createElement('small');
        note.className = 'appointment-footer-note';
        note.textContent = 'Review in Composer';
        footer.prepend(note);
        form.querySelector('.shared-date-toggle')?.focus({
            preventScroll: true,
        });
    }
    window.ExpaduAppointmentSheet = { open };
})();
