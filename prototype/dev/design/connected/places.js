/* Places around you: one model and one photo-free card, shared by Today,
   Composer, Events and the "around here" map. Data comes from
   connected/sample-places.js (real places, © OpenStreetMap contributors).

   Rules from the places data review (7 October 2026):
   - A card needs no photo: a map snippet, the kind of place and the facts the
     listing records. A failed snippet falls back to the kind's icon.
   - Unknown facts say "not listed". Listed hours are the listing's, not a live
     check, and assumed hours (hoursAssumed) never show as open.
   - Distances are straight lines; the middle of a large area (a park) says
     "about", and nothing is called an entrance.
   - Cuisine is a fixed slot for restaurants and fast food. Outdoor seating
     and diet show only when recorded (a missing tag never means "no");
     Wi-Fi only for libraries. Sources that disagree say so.
   - Woods, lakes and nature areas have no hours or address slot. */
(function (root) {
    const data = root.ExpaduSamplePlaces || { anchors: {}, places: [] };
    const esc = (text) =>
        String(text ?? '').replace(
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
    // Stroke icons in the shared 24px style.
    const shapes = {
        pharmacy: '<path d="M3 5h18v16H3zM8 5V2h8v3M12 9v8M8 13h8"/>',
        cafe: '<path d="M3 8h14v7a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8Zm14 1h2a3 3 0 0 1 0 6h-2M6 2v3m5-3v3"/>',
        bakery: '<path d="M4 15c-1-4 2-8 8-8s9 4 8 8c-1 3-4 4-8 4s-7-1-8-4Z"/><path d="m9 8 2 10m4-10-2 10"/>',
        grocery:
            '<path d="M3 8h18l-2 12H5L3 8Zm5 0 3-5m5 5-3-5M9 12v5m6-5v5"/>',
        playground: '<path d="M4 21V9l5-5 5 5v12M4 13h10m4 8V11m-4 10 7-10"/>',
        park: '<path d="M12 22V12m0 0c-4 0-7-3-7-7 4 0 7 3 7 7Zm0 0c4 0 7-3 7-7-4 0-7 3-7 7ZM5 22h14"/>',
        library:
            '<path d="M12 5C9 3 5 3 2 4v16c3-1 7-1 10 1 3-2 7-2 10-1V4c-3-1-7-1-10 1Zm0 0v16"/>',
        restaurant:
            '<path d="M7 2v20M4 2v6a3 3 0 0 0 6 0V2m7 20V2c-2 1-3 4-3 8h3"/>',
        culture:
            '<path d="M3 21h18M5 21V10m14 11V10M9 21V10m6 11V10M2 10h20L12 3 2 10Z"/>',
        fastfood:
            '<path d="M4 11a8 6 0 0 1 16 0H4Zm-1 3h18M5 17h14a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3Z"/>',
        nature: '<path d="M12 2 6 10h3l-4 6h14l-4-6h3l-6-8Zm0 14v6"/>',
        outdoor: '<path d="M3 10a9 6 0 0 1 18 0H3Zm9 0v11M7 21h10M8 15h8"/>',
        leaf: '<path d="M5 19c0-8 5-14 15-14 0 10-6 15-14 15H5v-1Zm0 0 8-8"/>',
        wifi: '<path d="M2 9a15 15 0 0 1 20 0M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0"/><circle cx="12" cy="19.5" r="1"/>',
        wheelchair:
            '<circle cx="12" cy="4" r="2"/><path d="M12 7v6h5l2 6M12 10H8m3 3a5 5 0 1 0 4 8"/>',
        clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        pin: '<path d="M12 22s7-6 7-12a7 7 0 0 0-14 0c0 6 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/>',
        map: '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Zm6-3v15m6-12v15"/>',
        bookmark: '<path d="M6 3h12v18l-6-4-6 4V3Z"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        check: '<path d="m5 12 4 4 10-10"/>',
    };
    const icon = (name) =>
        `<svg class="place-icon" viewBox="0 0 24 24" aria-hidden="true">${shapes[name] || shapes.pin}</svg>`;
    const kinds = {
        pharmacy: { many: 'Pharmacies', one: 'Pharmacy' },
        cafe: { many: 'Cafés', one: 'Café' },
        bakery: { many: 'Bakeries', one: 'Bakery' },
        grocery: { many: 'Groceries', one: 'Groceries' },
        playground: { many: 'Playgrounds', one: 'Playground' },
        park: { many: 'Parks', one: 'Park' },
        library: { many: 'Libraries', one: 'Library' },
        restaurant: { many: 'Restaurants', one: 'Restaurant' },
        fastfood: {
            many: 'Fast food places',
            one: 'Fast food place',
            chip: 'Fast food',
        },
        nature: { many: 'Woods & nature', one: 'Woods & nature' },
        culture: { many: 'Culture', one: 'Culture' },
    };
    // Kinds where food facts apply, and where the cuisine slot is fixed.
    const eating = ['cafe', 'restaurant', 'fastfood', 'bakery'];
    const cuisineSlot = ['restaurant', 'fastfood'];
    // A recorded fact: { value, status: known | conflicting }; anything else
    // is not listed.
    const practical = (place, key) => {
        const entry = place.practical?.[key];
        return entry && ['known', 'conflicting'].includes(entry.status)
            ? entry
            : null;
    };
    const cuisineNames = {
        bagel: 'Bagels',
        breakfast: 'Breakfast',
        burger: 'Burgers',
        cake: 'Cake',
        coffee_shop: 'Coffee',
        crepe: 'Crêpes',
        friture: 'Chips',
        ice_cream: 'Ice cream',
        kebab: 'Kebab',
        italian_pizza: 'Pizza',
        noodle: 'Noodles',
        sandwich: 'Sandwiches',
        seafood: 'Seafood',
        steak_house: 'Steak',
    };
    const cuisineName = (value) =>
        cuisineNames[value] ||
        value.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
    // "Italian · Pizza", "Sources disagree" or '' when not listed.
    function cuisine(place) {
        const entry = practical(place, 'cuisine');
        if (!entry) return '';
        if (entry.status === 'conflicting') return 'Sources disagree';
        return String(entry.value)
            .split(';')
            .map((value) => value.trim().toLowerCase())
            .filter(Boolean)
            .map(cuisineName)
            .filter((name, index, names) => names.indexOf(name) === index)
            .slice(0, 3)
            .join(' · ');
    }
    const dietWords = {
        'diet:vegetarian': 'vegetarian',
        'diet:vegan': 'vegan',
        'diet:halal': 'halal',
        'diet:kosher': 'kosher',
        'diet:gluten_free': 'gluten-free',
    };
    const capital = (text) => text.replace(/^./, (c) => c.toUpperCase());
    // Recorded diet options; `no` only when asked for (the facts list).
    function diets(place, { negatives = false } = {}) {
        return Object.entries(dietWords)
            .map(([key, word]) => {
                const entry = practical(place, key);
                if (!entry) return null;
                if (entry.status === 'conflicting')
                    return negatives
                        ? `${capital(word)}: sources disagree`
                        : null;
                return (
                    {
                        yes: `${capital(word)} options`,
                        only: `${capital(word)} only`,
                        limited: `Some ${word} options`,
                    }[entry.value] ||
                    (negatives && entry.value === 'no'
                        ? `No ${word} options`
                        : null)
                );
            })
            .filter(Boolean);
    }
    // One short chip on a card: "Vegetarian & vegan options"; the facts list
    // keeps every recorded option.
    function dietChips(place) {
        if (!eating.includes(place.kind)) return [];
        const options = diets(place);
        const same = (suffix) =>
            options.length > 1 &&
            options.every((text) => text.endsWith(suffix));
        if (
            same(' options') &&
            options.every((text) => !text.startsWith('Some'))
        )
            return [
                `${options
                    .map((text, i) =>
                        i
                            ? text.replace(/ options$/, '').toLowerCase()
                            : text.replace(/ options$/, ''),
                    )
                    .join(' & ')} options`,
            ];
        return options.slice(0, 1);
    }
    // Outdoor seating is yes, no or where it is ("garden", "terrace").
    const outdoorText = (place) => {
        const entry = practical(place, 'outdoor_seating');
        if (!entry || !entry.value) return '';
        if (entry.status === 'conflicting') return 'Sources disagree';
        const value = String(entry.value).toLowerCase();
        return value === 'no'
            ? 'No'
            : value === 'yes'
              ? 'Yes'
              : `Yes, ${value.replace(/_/g, ' ')}`;
    };
    const wifiText = (place) => {
        const entry = practical(place, 'internet_access');
        if (!entry) return '';
        if (entry.status === 'conflicting') return 'Sources disagree';
        return (
            {
                wlan: 'Yes',
                yes: 'Yes',
                terminal: 'Public computers',
                no: 'No',
            }[entry.value] || ''
        );
    };
    // Places the sample day refers to, as starting points for "near".
    const anchorNames = {
        neumarkt: 'Neumarkt',
        'wiener-platz': 'Wiener Platz, Mülheim',
        rheinauhafen: 'Rheinauhafen',
        stadtgarten: 'Stadtgarten',
        ehrenfeld: 'Ehrenfeld',
    };
    const anchors = Object.fromEntries(
        Object.entries(data.anchors).map(([id, point]) => [
            id,
            { id, name: anchorNames[id] || id, ...point },
        ]),
    );
    const all = data.places;
    const byId = (id) =>
        all.find((place) => place.id === String(id).replace(/^place:/, ''));
    function km(a, b) {
        if (![a?.lat, a?.lng, b?.lat, b?.lng].every(Number.isFinite))
            return null;
        const rad = (x) => (x * Math.PI) / 180;
        const dLat = rad(b.lat - a.lat),
            dLng = rad(b.lng - a.lng);
        const h =
            Math.sin(dLat / 2) ** 2 +
            Math.cos(rad(a.lat)) *
                Math.cos(rad(b.lat)) *
                Math.sin(dLng / 2) ** 2;
        return 6371 * 2 * Math.asin(Math.sqrt(h));
    }
    // "350 m", "about 600 m" for the middle of an area, "1.4 km".
    function distance(place, from) {
        const value = km(from, place);
        if (value === null) return '';
        const text =
            value < 1
                ? `${Math.max(50, Math.round(value * 20) * 50)} m`
                : `${value < 10 ? value.toFixed(1) : Math.round(value)} km`;
        return place.point === 'area' ? `about ${text}` : text;
    }
    const days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
    const minutes = (time) => {
        const [h, m] = String(time).split(':').map(Number);
        return h * 60 + m;
    };
    // What the listing says about a time: open (until when), closed (and when
    // it opens next today) or not listed. Never a live check.
    function hours(place, at) {
        const week = place.hours?.week;
        if (!week || place.hoursAssumed)
            return { state: 'unknown', text: 'Hours not listed' };
        const day = days[new Date(`${at.date}T12:00:00`).getDay()];
        const now = minutes(at.time);
        // A slot ending after midnight ("18:00–02:00") runs into the next day.
        const slots = (week[day] || []).map(([from, until]) => {
            let end = minutes(until);
            if (end <= minutes(from)) end += 1440;
            return [minutes(from), end, from, until];
        });
        const open = slots.find(([from, until]) => from <= now && now < until);
        if (open)
            return {
                state: 'open',
                until: open[3],
                text: `Open until ${['24:00', '00:00'].includes(open[3]) ? 'midnight' : open[3]}`,
            };
        const next = slots.find(([from]) => from > now);
        if (next)
            return {
                state: 'closed',
                opens: next[2],
                text: `Opens at ${next[2]}`,
            };
        return {
            state: 'closed',
            text: slots.length ? 'Closed for the day' : 'Closed today',
        };
    }
    const wheelchairText = {
        yes: 'Step-free access',
        limited: 'Partly step-free',
        no: 'Not step-free',
    };
    // Nearest places of some kinds, nearest first. Places outside the area
    // still count: outer neighbourhoods have few listings.
    function near(from, { kinds: wanted, limit = 3, at, open } = {}) {
        return all
            .filter((place) => !wanted || wanted.includes(place.kind))
            .map((place) => ({ place, km: km(from, place) }))
            .filter(({ km: value }) => value !== null)
            .filter(({ place }) => !open || hours(place, at).state === 'open')
            .sort((a, b) => a.km - b.km)
            .slice(0, limit);
    }
    const snippet = (place) =>
        `<span class="place-map-snippet" data-kind="${esc(place.kind)}"><img src="../connected/place-maps/${esc(place.id)}.png" alt="" loading="lazy" decoding="async" onerror="this.remove()"><span class="place-map-fallback">${icon(place.kind)}</span></span>`;
    // A compact, photo-free card. `from` = {name, lat, lng}; `at` = {date, time}.
    // `attrs` lets a page use its own open/save/plan handlers (Composer).
    function card(
        place,
        {
            from,
            at,
            variant = 'tile',
            actions = true,
            plan: withPlan = true,
            saved = false,
            added = false,
            attrs = {},
        } = {},
    ) {
        const open = attrs.open || 'data-place-open',
            save = attrs.save || 'data-save-item',
            plan = attrs.plan || 'data-plan-item';
        // Woods and lakes have no opening hours to show.
        const when = at && place.kind !== 'nature' ? hours(place, at) : null;
        const away = from ? distance(place, from) : '';
        // A single line for a short list (Today): icon, name and the facts.
        if (variant === 'line')
            return `<article class="nearby-place nearby-place--line" data-place-card="${esc(place.id)}"><button class="nearby-place-open" type="button" ${open}="${esc(place.id)}"><span class="nearby-place-icon">${icon(place.kind)}</span><span class="nearby-place-copy"><strong>${esc(place.name)}</strong><small>${esc(place.label)}${away ? ` · ${esc(away)}` : ''}${when ? ` · <span class="place-hours is-${when.state}">${esc(when.text)}</span>` : ''}</small></span></button></article>`;
        const facts = [
            when
                ? `<span class="place-hours is-${when.state}">${icon('clock')}${esc(when.text)}</span>`
                : '',
            place.wheelchair
                ? `<span class="place-access">${icon('wheelchair')}${esc(wheelchairText[place.wheelchair] || place.wheelchair)}</span>`
                : '',
            eating.includes(place.kind) && outdoorText(place).startsWith('Yes')
                ? `<span class="place-detail">${icon('outdoor')}Outdoor seating</span>`
                : '',
            ...dietChips(place).map(
                (text) =>
                    `<span class="place-detail">${icon('leaf')}${esc(text)}</span>`,
            ),
            place.kind === 'library' &&
            ['Yes', 'Public computers'].includes(wifiText(place))
                ? `<span class="place-detail">${icon('wifi')}${wifiText(place) === 'Yes' ? 'Wi-Fi' : 'Public computers'}</span>`
                : '',
        ].join('');
        const dish = cuisineSlot.includes(place.kind)
            ? cuisine(place) === 'Sources disagree'
                ? 'Cuisine: sources disagree'
                : cuisine(place) || 'Cuisine not listed'
            : '';
        // Rows hide the kind line (the list is one kind), so cuisine moves
        // to the area line there.
        const dishMarkup = dish
            ? `<span class="place-cuisine${cuisine(place) && cuisine(place) !== 'Sources disagree' ? '' : ' is-unknown'}">${variant === 'row' ? '' : '· '}${esc(dish)}</span>`
            : '';
        return `<article class="nearby-place nearby-place--${variant}" data-place-card="${esc(place.id)}">
            <button class="nearby-place-open" type="button" ${open}="${esc(place.id)}" aria-label="${esc(place.name)}, ${esc(place.label)}${away ? `, ${esc(away)}` : ''}">
                ${snippet(place)}
                <span class="nearby-place-copy">
                    <span class="place-kind">${icon(place.kind)}${esc(place.label)}${variant === 'row' ? '' : dishMarkup}</span>
                    <strong>${esc(place.name)}</strong>
                    <small>${esc(place.veedel)}${away ? ` · ${esc(away)}` : ''}${variant === 'row' && dishMarkup ? ` · ${dishMarkup}` : ''}</small>
                    ${facts ? `<span class="nearby-place-facts">${facts}</span>` : ''}
                </span>
            </button>
            ${actions ? `<span class="nearby-place-actions"><button class="nearby-place-save" type="button" ${save}="${esc(place.id)}" aria-pressed="${saved}" aria-label="${saved ? 'Unsave' : 'Save'} ${esc(place.name)}" title="Save for later">${icon('bookmark')}</button>${withPlan ? `<button class="nearby-place-plan" type="button" ${plan}="${esc(place.id)}" ${added ? 'disabled aria-label="Added to your plan"' : `aria-label="Add ${esc(place.name)} to a plan"`} title="${added ? 'Added to your plan' : 'Add to plan'}">${icon(added ? 'check' : 'plus')}</button>` : ''}</span>` : ''}
        </article>`;
    }
    // What the listing records, with "Not listed" for the rest, plus where the
    // facts come from. Used by the place sheet and Composer's Quick look.
    function facts(place, { at } = {}) {
        const row = (label, value, link) =>
            `<div><dt>${label}</dt><dd${value && value !== 'Sources disagree' ? '' : ' class="is-unknown"'}>${value ? (link ? `<a href="${esc(link)}" target="_blank" rel="noopener">${esc(value)}</a>` : esc(value)) : 'Not listed'}</dd></div>`;
        const area = place.kind === 'nature';
        const when = at && !area ? hours(place, at) : null;
        const website = (() => {
            try {
                const url = new URL(place.website);
                return ['http:', 'https:'].includes(url.protocol)
                    ? url.href
                    : '';
            } catch {
                return '';
            }
        })();
        const outdoor = ['park', 'playground', 'nature'].includes(place.kind);
        const eats = eating.includes(place.kind);
        const week = place.hours?.week;
        const dayNames = {
            mon: 'Mon',
            tue: 'Tue',
            wed: 'Wed',
            thu: 'Thu',
            fri: 'Fri',
            sat: 'Sat',
            sun: 'Sun',
        };
        return `<dl class="place-facts">
            ${area ? '' : row('Today', when && when.state !== 'unknown' ? when.text : '')}
            ${area ? '' : row('Address', place.address)}
            ${cuisineSlot.includes(place.kind) || (eats && cuisine(place)) ? row('Cuisine', cuisine(place)) : ''}
            ${eats ? row('Outdoor seating', outdoorText(place)) : ''}
            ${eats ? row('Diet', diets(place, { negatives: true }).join(' · ')) : ''}
            ${place.kind === 'library' ? row('Wi-Fi', wifiText(place)) : ''}
            ${row('Wheelchair', wheelchairText[place.wheelchair] || '')}
            ${outdoor ? row('Access', place.access ? { yes: 'Public', public: 'Public', customers: 'Customers only', private: 'Private' }[place.access] || place.access : '') : ''}
            ${outdoor || place.kind === 'culture' ? row('Entry fee', place.fee ? { no: 'Free', free: 'Free', yes: 'Paid', paid: 'Paid' }[place.fee] || place.fee : '') : ''}
            ${row('Website', website ? new URL(website).hostname.replace(/^www\./, '') : '', website)}
            ${row('Phone', place.phone, place.phone ? `tel:${place.phone.replace(/\s/g, '')}` : '')}
        </dl>
        ${
            week
                ? `<details class="place-week"><summary>All listed hours</summary><dl>${Object.entries(
                      dayNames,
                  )
                      .map(
                          ([key, name]) =>
                              `<div><dt>${name}</dt><dd>${(week[key] || []).length ? week[key].map(([a, b]) => `${a}–${b}`).join(', ') : 'Closed'}</dd></div>`,
                      )
                      .join('')}</dl></details>`
                : ''
        }
        <p class="place-source">${area ? 'A large area: its point and distances are approximate.' : 'Listed hours, not a live check.'} Place data from <a href="${esc(place.source?.url || 'https://www.openstreetmap.org/')}" target="_blank" rel="noopener">OpenStreetMap</a>${place.source?.observed ? `, observed ${esc(new Date(place.source.observed + 'T12:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }))}` : ''}. Map © OpenStreetMap contributors, OpenFreeMap.</p>`;
    }
    // The shape the shared sheets and planning use for a place.
    const item = (place) => ({
        id: place.id,
        type: 'place',
        title: place.name,
        label: place.label,
        area: place.veedel,
        icon: place.kind,
        lat: place.lat,
        lng: place.lng,
        address: place.address,
        captured: 'OpenStreetMap, observed 28 Sept 2026',
        place,
    });
    // The Events sheet links a venue to its place by name: an exact name
    // first, then one name starting the other ("Flora und Botanischer Garten"
    // is "Flora"). The real app links venues to places by ID.
    const venue = (name) => {
        const text = String(name || '');
        return (
            all.find((place) => place.name === text) ||
            all.find(
                (place) =>
                    place.name.length > 4 &&
                    (text.startsWith(`${place.name} `) ||
                        place.name.startsWith(`${text} `)),
            ) ||
            null
        );
    };
    // A link to the "around here" map for a kind near a point.
    const mapLink = (kind, from) =>
        `../around-here/?${new URLSearchParams({
            ...(kind ? { kind } : {}),
            near: from.id || '',
            ...(from.id
                ? {}
                : { lat: from.lat, lng: from.lng, name: from.name }),
        })}`;
    root.ExpaduPlaces = {
        all,
        kinds,
        anchors,
        byId,
        near,
        hours,
        distance,
        km,
        card,
        facts,
        item,
        venue,
        icon,
        mapLink,
        wheelchairText,
        cuisine,
        diets,
        wifiText,
    };
})(globalThis);
