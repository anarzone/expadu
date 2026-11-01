/* Review controls for the redesign mock pages (sample content only).
   ?time=morning|midday|afternoon|evening|night previews Today's band colour;
   the sample day stays 13:10. ?display=geist swaps Bricolage titles back to
   Geist. ?tram=planned shows the planned 13:24 leading instead of the 13:14.
   Composer: a starter shows its sample answer. The concept viewer calls
   AppInColour.set() to change these without reloading. */
(function () {
    'use strict';
    const root = document.documentElement;
    const params = new URLSearchParams(location.search);
    const PERIODS = ['morning', 'midday', 'afternoon', 'evening', 'night'];
    const options = {
        time: params.get('time') || 'sample',
        display: params.get('display') || 'bricolage',
        tram: params.get('tram') || 'earlier',
        state: params.get('state') || 'start',
        answer: params.get('answer') || 'leave',
        ...(window.__appInColour || {}),
    };

    function apply() {
        // The sample day is 13:10, which is midday.
        root.dataset.band = PERIODS.includes(options.time)
            ? options.time
            : 'midday';
        root.dataset.display =
            options.display === 'geist' ? 'geist' : 'bricolage';
        document.querySelectorAll('[data-trip]').forEach((trip) => {
            trip.hidden =
                trip.dataset.trip !==
                (options.tram === 'planned' ? 'planned' : 'earlier');
        });
        const chat = document.querySelector('[data-composer]');
        if (chat) {
            chat.dataset.composer = options.state === 'chat' ? 'chat' : 'start';
            document.querySelectorAll('[data-answer]').forEach((answer) => {
                answer.hidden =
                    options.state === 'chat' && options.answer !== 'all'
                        ? answer.dataset.answer !== options.answer
                        : false;
            });
        }
    }

    document.addEventListener('click', (event) => {
        const starter = event.target.closest('[data-ask]');
        if (starter) {
            options.state = 'chat';
            options.answer = starter.dataset.ask;
            apply();
            document.querySelector('.rd-chat__feed')?.scrollTo?.({ top: 0 });
            return;
        }
        if (event.target.closest('[data-new-chat]')) {
            options.state = 'start';
            apply();
        }
    });

    window.AppInColour = {
        set(next) {
            Object.assign(options, next || {});
            apply();
            return { ...options };
        },
        get: () => ({ ...options }),
    };
    apply();
})();
