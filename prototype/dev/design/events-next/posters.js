/* Shared event imagery: approved photographs, otherwise original editorial art. */
(function () {
    const E = ExpaduUI.esc;
    const artwork = new Set([
        'exhibition-ludwig',
        'kunst-machen',
        'schach',
        'forest',
        'garden',
        'tandem',
        'film',
    ]);
    function approvedMedia(item) {
        const media = item.media;
        return media?.rights_status === 'approved' &&
            media.health_status === 'active' &&
            media.credit &&
            /^https?:\/\//.test(media.source_url || '') &&
            /^(https?:\/\/|\/(?!\/))/.test(media.url || '')
            ? media
            : null;
    }
    function image(item, eager = false) {
        const name = artwork.has(item.id) ? item.id : 'exhibition-ludwig';
        const fallback = `../events-next/art/${name}.svg`;
        return `<img class="event-poster-image" src="${E(approvedMedia(item)?.url || fallback)}" data-event-fallback="${fallback}" alt="" width="600" height="750" loading="${eager ? 'eager' : 'lazy'}" decoding="async">`;
    }
    if (typeof document !== 'undefined')
        document.addEventListener(
            'error',
            (event) => {
                const img = event.target;
                if (!img.matches?.('.event-poster-image[data-event-fallback]'))
                    return;
                const fallback = img.dataset.eventFallback;
                delete img.dataset.eventFallback;
                img.src = fallback;
            },
            true,
        );
    const I = (name) => ExpaduUI.icon(name);
    const time = (item) =>
        `${E(item.start)}${item.end ? '–' + E(item.end) : ' · End TBC'}`;
    const dateLabel = (item) =>
        item.date
            ? new Date(item.date + 'T12:00:00').toLocaleDateString('en-GB', {
                  day: 'numeric',
                  month: 'short',
              })
            : '';
    const save = (item) =>
        `<button class="event-poster-save" data-save-item="${E(item.id)}" aria-label="Save ${E(item.title)}" aria-pressed="false" title="Save for later">${I('bookmark')}</button>`;
    function feature(item, context = 'events') {
        if (!item) return '';
        const id = E(item.id);
        return `<article class="event-feature event-feature--${context}" data-event-card="${id}" aria-label="Featured sample event">
            <div class="event-feature-scene"><button class="event-feature-image" data-detail="${id}" aria-label="Explore ${E(item.title)}">${image(item, true)}</button>${save(item)}<span class="event-feature-caption">${I('spark')}Sample pick</span></div>
            <div class="event-feature-content"><span class="event-feature-category">${I(item.icon || 'calendar')}${E(item.category)}</span><h2><button data-detail="${id}" lang="de">${E(item.title)}</button></h2>
            <p class="event-feature-schedule">${I('calendar')}<span>${E(dateLabel(item))}<span class="event-meta-separator" aria-hidden="true"></span>${time(item)}</span></p>
            <p class="event-feature-venue">${I('pin')}<span>${E(item.venue)}<small>${E(item.area || '')}</small></span></p>
            <div class="event-feature-actions"><span class="event-feature-price">${I('ticket')}${E(item.price || 'Price to check')}</span><button class="event-feature-plan" data-plan-item="${id}" aria-label="Plan to go to ${E(item.title)}">Plan to go ${I('plus')}</button></div></div>
        </article>`;
    }
    function card(item, index = 0) {
        const id = E(item.id);
        return `<article class="event-poster" data-event-card="${id}">
            <button class="event-poster-open" data-detail="${id}" aria-label="Explore ${E(item.title)}">
                <span class="event-poster-scene">${image(item, index < 3)}<span class="event-poster-category">${I(item.icon || 'calendar')}${E(item.category)}</span></span>
                <span class="event-poster-info"><span class="event-poster-time">${E(dateLabel(item))}${item.date ? '<span class="event-meta-separator" aria-hidden="true"></span>' : ''}${time(item)}</span><strong class="event-poster-title" role="heading" aria-level="3" lang="de">${E(item.title)}</strong><span class="event-poster-venue">${I('pin')}${E(item.venue)}</span><span class="event-poster-admission">${I('ticket')}${E(item.price || 'Price to check')}</span></span>
            </button>${save(item)}
            <button class="event-poster-plan" data-plan-item="${id}" aria-label="Plan to go to ${E(item.title)}" title="Plan to go">${I('plus')}</button>
        </article>`;
    }
    function curate(items) {
        const unique = [
            ...new Map(
                items.map((item) => [item.occurrence || item.id, item]),
            ).values(),
        ];
        // An editorial flag belongs to the catalogue, never to the current sort order.
        const lead = unique.find((item) => item.discovery?.featured) || null;
        const rest = unique.filter((item) => item !== lead);
        const groups = [
            {
                title: 'An afternoon out',
                icon: 'sun',
                items: rest.filter((item) => item.start < '18:00'),
            },
            {
                title: 'Into the evening',
                icon: 'spark',
                items: rest.filter((item) => item.start >= '18:00'),
            },
        ].filter((group) => group.items.length);
        return { feature: lead, groups };
    }
    function collection(group) {
        return `<section class="event-collection" aria-label="${E(group.title)}"><div class="event-collection-heading"><h2>${I(group.icon)}${E(group.title)}</h2><span>${group.items.length} ${group.items.length === 1 ? 'event' : 'events'}</span></div><div class="event-collection-rail">${group.items.map(card).join('')}</div></section>`;
    }
    function today(items) {
        const { feature: lead } = curate(items);
        const seen = new Set(lead ? [lead.category] : []);
        const others = items.filter((item) => item !== lead);
        const supporting = others
            .filter((item) => {
                if (seen.has(item.category)) return false;
                seen.add(item.category);
                return true;
            })
            .concat(others)
            .filter(
                (item, index, all) =>
                    all.findIndex(
                        (x) =>
                            (x.occurrence || x.id) ===
                            (item.occurrence || item.id),
                    ) === index,
            )
            .slice(0, 2);
        return `${lead ? feature(lead, 'today') : ''}<div class="today-event-support"><div class="today-event-support-heading"><h2>More good plans</h2><a href="../events-next/?discover=1${lead?.date ? '&amp;date=' + E(lead.date) : ''}" aria-label="Explore all events">${I('arrow')}</a></div><div class="today-event-rail">${supporting.map(card).join('')}</div></div>`.replaceAll(
            'data-detail=',
            'data-board-detail=',
        );
    }
    function credit(items) {
        return `Original illustrations · Sample programme${items
            .filter(approvedMedia)
            .map(
                (item) =>
                    `<br><a href="${E(item.media.source_url)}" target="_blank" rel="noopener">${E(item.title)}: ${E(item.media.credit)}</a>`,
            )
            .join('')}`;
    }
    function render(items, { discover = false } = {}) {
        if (!items.length) return '';
        const body = discover
            ? curate(items).groups.map(collection).join('')
            : `<div class="event-poster-grid">${items.map(card).join('')}</div>`;
        return `${body}<p class="event-poster-credit">${credit(items)}</p>`;
    }
    window.ExpaduEventPosters = {
        image,
        render,
        credit,
        approvedMedia,
        feature,
        card,
        curate,
        today,
    };
})();
