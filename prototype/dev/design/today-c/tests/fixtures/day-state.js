/* Optional, memory-only review cases. Never seed the normal Today page. */
(function () {
    const scenario = new URLSearchParams(location.search).get('scenario');
    if (!['planned', 'journey', 'paperwork'].includes(scenario)) return;
    const NativeDate = Date;
    window.Date = class extends NativeDate {
        constructor(...args) {
            super(...(args.length ? args : ['2026-09-29T13:00:00']));
        }
        static now() {
            return new NativeDate('2026-09-29T13:00:00').getTime();
        }
    };
    localStorage.setItem('expadu-welcome-dismissed-v1', 'true');
    if (scenario !== 'paperwork')
        localStorage.setItem(
            'expadu-composer-studio-v1',
            JSON.stringify({
                plans: [
                    {
                        id: 'review-day',
                        date: '2026-09-29',
                        items: [
                            {
                                id: 'review-appointment',
                                type: 'appointment',
                                title: 'Your appointment',
                                start: '15:00',
                                end: '15:30',
                                venue: 'Neumarkt',
                            },
                            {
                                id: 'review-friends',
                                type: 'appointment',
                                title: 'Meet friends',
                                start: '18:00',
                                end: '19:00',
                                venue: 'Rudolfplatz',
                            },
                        ],
                    },
                ],
            }),
        );
    if (scenario === 'journey')
        localStorage.setItem(
            'expadu.journey-preview.v1',
            JSON.stringify({
                version: 1,
                routeId: 'r18',
                step: 2,
                title: 'Museum Ludwig',
                mode: 'transit',
                instruction: 'Appellhofplatz',
                condition: 'normal',
            }),
        );
    if (scenario === 'paperwork')
        document.addEventListener('DOMContentLoaded', () => {
            localStorage.setItem(
                'expadu-bureaucracy-design-v1',
                JSON.stringify(BureaucracyPreview.fresh()),
            );
            window.dispatchEvent(new Event('expadu:change'));
        });
})();
