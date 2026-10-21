(function () {
    const I = ExpaduIcon;
    document.querySelector('#collection').innerHTML = [
        [
            'places',
            'pin',
            'Places',
            'A visual field guide to your surroundings.',
            'green',
            'New design',
        ],
        [
            'events',
            'calendar',
            'Events',
            'A programme for your day, with dates kept intact.',
            'peach',
            'New design',
        ],
        [
            'bureaucracy',
            'document',
            'Bureaucracy',
            'Your processes, paperwork and next steps.',
            'blue',
            'New design · Sample case',
        ],
        [
            'services',
            'grid',
            'Services',
            'Start with the help you need.',
            'yellow',
            'New design',
        ],
        [
            'composer',
            'spark',
            'Composer',
            'Bring your choices and requirements together.',
            'cyan',
            'New design',
        ],
        [
            'today',
            'sun',
            'Today',
            'Your overview and daily discoveries.',
            'blue',
            'Accepted Design C',
        ],
        [
            'departures',
            'train',
            'Departures',
            'Stops, route choices and the next step.',
            'ink',
            'Accepted design',
        ],
    ]
        .map(
            ([page, icon, title, note, color, state]) =>
                `<a href="${ExpaduStore.routes[page]}" class="${color}">${I(icon)}<span class="corner">${I('arrow')}</span><h2>${title}</h2><p>${note}</p><small>${state}</small></a>`,
        )
        .join('');
})();
