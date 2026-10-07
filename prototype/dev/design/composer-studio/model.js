(function (root) {
    const clone = (x) => JSON.parse(JSON.stringify(x));
    const identity = (item) => String(item.id).replace(/^event:/, '');
    const iso = (d) =>
        `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    // The previews share one sample "today" (connected/sample-day.js) so a
    // plan made on Today is "today" here too; without it, the real date.
    function today() {
        return root.ExpaduSampleDay?.date || iso(new Date());
    }
    function nowTime() {
        if (root.ExpaduSampleDay?.time) return root.ExpaduSampleDay.time;
        const d = new Date();
        return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    }
    function addDays(date, days) {
        const d = new Date(date + 'T12:00:00');
        d.setDate(d.getDate() + days);
        return iso(d);
    }
    const weekdays = [
        'sunday',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
    ];
    function nextWeekday(name) {
        const d = new Date(today() + 'T12:00:00');
        d.setDate(d.getDate() + ((weekdays.indexOf(name) - d.getDay() + 7) % 7));
        return iso(d);
    }
    function saturday() {
        return nextWeekday('saturday');
    }
    const fresh = () => ({
        version: 1,
        saved: [],
        plans: [],
        proposal: null,
        response: null,
        prompt: '',
        date: saturday(),
    });
    const titles = {
        walk: 'Blücherpark',
        greenbelt: 'Innerer Grüngürtel',
        'pitch-ivens': 'Bolzplatz · Ivenshofweg',
        'table-kassel': 'Tischtennisplatte · Kasselberger Weg',
        'play-fahr': 'Spielplatz · Fährgasse',
        'swim-seven': 'Badestelle See 7',
        appointment: 'Your appointment',
    };
    function normalizeTime(value) {
        const match = String(value)
            .trim()
            .match(/^(\d{1,2}):?(\d{2})$/);
        if (!match || Number(match[1]) > 23 || Number(match[2]) > 59)
            return null;
        return `${match[1].padStart(2, '0')}:${match[2]}`;
    }
    function timeWheelValues() {
        const values = (length) =>
            Array.from({ length }, (_, index) =>
                String(index).padStart(2, '0'),
            );
        return { hours: values(24), minutes: values(60) };
    }
    const validDate = (value) =>
        /^\d{4}-\d{2}-\d{2}$/.test(value) &&
        iso(new Date(value + 'T12:00:00')) === value;
    function bookmark(s, id) {
        s.saved = s.saved.includes(id)
            ? s.saved.filter((x) => x !== id)
            : [...s.saved, id];
    }
    function propose(s, ids, date = s.date, baseId = null) {
        if (!validDate(date)) throw Error('Choose a valid date for your plan.');
        if (
            s.proposal &&
            (s.proposal.date !== date ||
                (baseId && s.proposal.baseId !== baseId))
        )
            throw Error(
                'Finish or discard your current proposal before starting another day.',
            );
        const existing = baseId
            ? s.plans.find((p) => p.id === baseId)
            : s.plans.find((p) => p.date === date);
        if (baseId && !existing)
            throw Error(
                'This plan is no longer available. Open My Plans to choose another.',
            );
        const draft = s.proposal
            ? clone(s.proposal)
            : existing
              ? {
                    ...clone(existing),
                    baseId: existing.id,
                    baseRevision: existing.revision,
                }
              : {
                    date,
                    items:
                        s.reviewDay?.date === date
                            ? clone(s.reviewDay.agenda || []).filter(
                                  (item) =>
                                      !item.completed &&
                                      !s.consumedReviewItems?.[date]?.includes(
                                          identity(item),
                                      ),
                              )
                            : [],
                    baseId: null,
                    baseRevision: null,
                };
        // Reserve incoming fixed time without changing the user's item order.
        const additions = [...new Set(ids)];
        const reserved =
            additions.includes('appointment') &&
            !draft.items.some((x) => x.id === 'appointment')
                ? [{ start: '15:00', end: '15:30' }]
                : [];
        for (const id of additions) {
            const sample = root.ExpaduPlaces?.byId(id);
            const source =
                root.ExpaduModel?.places.find(
                    (place) => place.id === id && place.type === 'place',
                ) || (sample ? root.ExpaduPlaces.item(sample) : null);
            const title = titles[id] || source?.title;
            if (!title || draft.items.some((x) => x.id === id)) continue;
            const preferred = draft.items.some((x) => !x.fixed)
                ? '16:00'
                : '13:00';
            // On the sample day itself, nothing is suggested in the past.
            const soonest =
                root.ExpaduSampleDay && date === today()
                    ? clock(Math.ceil((minutes(nowTime()) + 15) / 30) * 30)
                    : '00:00';
            const at =
                id === 'appointment'
                    ? ['15:00', '15:30']
                    : nextFreeTime(
                          [...draft.items, ...reserved],
                          preferred > soonest ? preferred : soonest,
                      );
            if (!at)
                throw Error(
                    `There is no free one-hour slot left for ${title}. Change a time or choose another day.`,
                );
            const item = {
                ...(!titles[id] && source ? clone(source) : {}),
                id,
                title,
                start: at[0],
                end: at[1],
                fixed: id === 'appointment',
            };
            const collision = draft.items.find((x) => overlaps(item, x));
            if (collision)
                throw Error(
                    `${item.title} cannot be added at ${item.start}–${item.end}: ${collision.title} is already scheduled then. Edit its time first.`,
                );
            draft.items.push(item);
        }
        if (s.proposal) s.proposal.items = draft.items;
        else s.proposal = draft;
        s.date = date;
        return s.proposal;
    }
    const minutes = (value) =>
        Number(value.slice(0, 2)) * 60 + Number(value.slice(3));
    const clock = (value) =>
        `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
    const overlaps = (a, b) => a.start < b.end && b.start < a.end;
    function nextFreeTime(items, preferred = '13:00', duration = 60) {
        let start = minutes(preferred);
        for (const item of [...items].sort((a, b) =>
            a.start.localeCompare(b.start),
        )) {
            if (
                start < minutes(item.end) &&
                start + duration > minutes(item.start)
            )
                start = minutes(item.end);
        }
        return start + duration <= 1439
            ? [clock(start), clock(start + duration)]
            : null;
    }
    function setTime(s, id, start, end) {
        const item = s.proposal?.items.find((x) => x.id === id);
        if (!item) throw Error('This activity is no longer available.');
        if (item.fixed) throw Error('This appointment has a fixed time.');
        start = normalizeTime(start);
        end = normalizeTime(end);
        if (!start || !end || start >= end)
            throw Error('Choose valid times, with the end after the start.');
        if (
            item.attendance === 'visit' &&
            ((item.listedStart && start < item.listedStart) ||
                (item.listedEnd && end > item.listedEnd))
        )
            throw Error('Keep your visit within the listed visiting hours.');
        const collision = s.proposal.items.find(
            (x) => x.id !== id && overlaps({ start, end }, x),
        );
        if (collision)
            throw Error(
                `${collision.title} already occupies ${collision.start}–${collision.end}. Choose a time outside that interval.`,
            );
        const changed = item.start !== start || item.end !== end;
        const before = [...s.proposal.items].sort((a, b) =>
            a.start.localeCompare(b.start),
        );
        const origins = new Map(
            before.map((entry, index) => [
                entry.id,
                `${before[index - 1]?.id || ''}:${before[index - 1]?.end || ''}`,
            ]),
        );
        item.start = start;
        item.end = end;
        if (changed) {
            const after = [...s.proposal.items].sort((a, b) =>
                a.start.localeCompare(b.start),
            );
            after.forEach((entry, index) => {
                const origin = `${after[index - 1]?.id || ''}:${after[index - 1]?.end || ''}`;
                if (
                    entry.journey &&
                    (entry.id === id || origins.get(entry.id) !== origin)
                )
                    entry.journey.status = 'stale';
            });
        }
    }
    function moveLater(s, id) {
        const item = s.proposal?.items.find((x) => x.id === id);
        if (!item) throw Error('This activity is no longer available.');
        const duration = minutes(item.end) - minutes(item.start);
        const start = Math.max(960, minutes(item.start) + 60);
        const slot = nextFreeTime(
            s.proposal.items.filter((x) => x.id !== id),
            clock(start),
            duration,
        );
        if (!slot)
            throw Error(
                'There is no later slot left today. Choose another time or day.',
            );
        setTime(s, id, slot[0], slot[1]);
    }
    function filterReplacementPlaces(
        catalogue,
        proposal,
        saved,
        { query = '', category = '', savedOnly = false } = {},
    ) {
        const fold = (value) =>
            value
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase();
        const search = fold(query.trim());
        return catalogue.filter(
            (place) =>
                !proposal.items.some((item) => item.id === place.id) &&
                (!savedOnly || saved.includes(place.id)) &&
                (!category || place.category === category) &&
                fold(
                    `${place.title} ${place.label} ${place.category} ${place.area}`,
                ).includes(search),
        );
    }
    function replacePlace(s, itemId, replacementId) {
        const p = s.proposal;
        const item = p?.items.find((x) => x.id === itemId);
        if (!item) throw Error('This activity is no longer available.');
        if (item.fixed) throw Error('A fixed appointment cannot be replaced.');
        if (
            !Object.hasOwn(titles, replacementId) ||
            replacementId === 'appointment'
        )
            throw Error('That replacement is not available.');
        if (p.items.some((x) => x.id === replacementId))
            throw Error('That place is already in your proposal.');
        item.id = replacementId;
        item.title = titles[replacementId];
    }
    function conflicts(p) {
        const errors = [];
        const items = p.items;
        for (const x of items)
            if (
                !/^([01]\d|2[0-3]):[0-5]\d$/.test(x.start) ||
                !/^([01]\d|2[0-3]):[0-5]\d$/.test(x.end) ||
                x.start >= x.end
            )
                errors.push(
                    `Choose valid times, with the end after the start for ${x.title}.`,
                );
        for (const x of items) {
            if (
                x.attendance === 'visit' &&
                ((x.listedStart && x.start < x.listedStart) ||
                    (x.listedEnd && x.end > x.listedEnd))
            )
                errors.push(
                    `Keep ${x.title} within its listed visiting hours.`,
                );
        }
        const ordered = [...items].sort((a, b) =>
            a.start.localeCompare(b.start),
        );
        for (let i = 0; i < ordered.length; i++)
            for (let j = i + 1; j < ordered.length; j++)
                if (overlaps(ordered[i], ordered[j])) {
                    const duration =
                        Math.min(
                            minutes(ordered[i].end),
                            minutes(ordered[j].end),
                        ) -
                        Math.max(
                            minutes(ordered[i].start),
                            minutes(ordered[j].start),
                        );
                    const amount =
                        duration % 60 === 0
                            ? `${duration / 60} hour${duration === 60 ? '' : 's'}`
                            : `${duration} minutes`;
                    errors.push(
                        `${ordered[i].title} and ${ordered[j].title} overlap by ${amount}. Edit their times to continue.`,
                    );
                }
        return errors;
    }
    function confirm(s) {
        const p = s.proposal;
        if (!p || (!p.items.length && !p.baseId))
            throw Error('Add an activity before confirming your plan.');
        if (!validDate(p.date))
            throw Error('Choose a valid date for your plan.');
        if (p.items.some((x) => x.type === 'event' && x.date !== p.date))
            throw Error('This event has a fixed date.');
        if (conflicts(p).length) throw Error(conflicts(p)[0]);
        const old = s.plans.find((x) => x.id === p.baseId);
        if (p.baseId && (!old || old.revision !== p.baseRevision))
            throw Error(
                'This plan changed since you opened it. Reopen the latest plan before applying changes.',
            );
        if (s.plans.some((x) => x.date === p.date && x.id !== p.baseId))
            throw Error(
                'You already have a plan on this date. Open it to add these activities.',
            );
        if (!p.items.length && old) {
            // A cancelled confirmed day is authoritative even when Today still
            // holds the original review context. New explicit items remain usable.
            s.consumedReviewItems ||= {};
            s.consumedReviewItems[p.date] = [
                ...new Set([
                    ...(s.consumedReviewItems[p.date] || []),
                    ...old.items.map(identity),
                    ...(s.reviewDay?.date === p.date
                        ? s.reviewDay.agenda || []
                        : []
                    ).map(identity),
                ]),
            ];
            s.plans = s.plans.filter((x) => x.id !== old.id);
            s.proposal = null;
            return old.id;
        }
        const id = old?.id || `plan-${p.date}`;
        const next = {
            id,
            date: p.date,
            items: clone(p.items),
            revision: (old?.revision || 0) + 1,
        };
        s.plans = s.plans.filter((x) => x.id !== id).concat(next);
        s.proposal = null;
        return id;
    }
    function addScheduled(s, item, date = s.date) {
        if (item.type === 'event' && item.date !== date)
            throw Error(
                'This event has a fixed date. Open its original day to add it.',
            );
        const start = normalizeTime(item.start),
            end = normalizeTime(item.end);
        const title = String(item.title || '').trim();
        if (!title || !start || !end || start >= end)
            throw Error(
                'Add a title and valid times, with the end after the start.',
            );
        const candidate = clone(s);
        propose(candidate, [], date);
        if (candidate.proposal.items.some((x) => x.id === item.id))
            throw Error('This activity is already in your plan.');
        candidate.proposal.items.push({
            ...item,
            title: title.slice(0, 100),
            start,
            end,
        });
        const errors = conflicts(candidate.proposal);
        if (errors.length) throw Error(errors[0]);
        s.proposal = candidate.proposal;
        s.date = date;
        return s.proposal;
    }
    function updateRecordedAppointment(s, item, date) {
        const original = [s.proposal, ...s.plans]
            .filter(Boolean)
            .find((p) => p.items.some((x) => x.sourceId === item.sourceId));
        if (!original) return addScheduled(s, item, date);
        if (original.date !== date)
            throw Error(
                'This appointment moved to another day. Remove it from the earlier plan, then add it to the new day.',
            );
        const candidate = clone(s);
        propose(candidate, [], date);
        candidate.proposal.items = candidate.proposal.items.filter(
            (x) => x.sourceId !== item.sourceId,
        );
        addScheduled(candidate, item, date);
        s.proposal = candidate.proposal;
        s.date = date;
        return s.proposal;
    }
    const months = [
        'january',
        'february',
        'march',
        'april',
        'may',
        'june',
        'july',
        'august',
        'september',
        'october',
        'november',
        'december',
    ];
    // "30 September" or "September 30", in the working date's year.
    function namedDate(text, s) {
        const pattern = months.join('|');
        const match =
            text.match(new RegExp(`\\b(\\d{1,2})(?:st|nd|rd|th)?\\s+(${pattern})\\b`, 'i')) ||
            text.match(new RegExp(`\\b(${pattern})\\s+(\\d{1,2})(?:st|nd|rd|th)?\\b`, 'i'));
        if (!match) return null;
        const [day, month] = /\d/.test(match[1])
            ? [match[1], match[2]]
            : [match[2], match[1]];
        const year = today().slice(0, 4);
        const value = `${year}-${String(months.indexOf(month.toLowerCase()) + 1).padStart(2, '0')}-${day.padStart(2, '0')}`;
        return validDate(value) ? value : null;
    }
    function requestDate(text, s) {
        const explicit = text.match(/\b\d{4}-\d{2}-\d{2}\b/);
        if (explicit) return explicit[0];
        const named = namedDate(text, s);
        if (named) return named;
        if (/\b(this )?weekend\b/i.test(text)) return saturday();
        if (/\btomorrow\b/i.test(text)) return addDays(today(), 1);
        const day = text.toLowerCase().match(new RegExp(`\\b(${weekdays.join('|')})\\b`));
        if (day) return nextWeekday(day[1]);
        if (/\b(today|tonight|this (morning|afternoon|evening|week))\b/i.test(text))
            return today();
        return s.date;
    }
    // Places named in a journey question: "from A to B", or "get to B by 14:00".
    // The sample day's appointment trip, when a question names it by time or
    // as the appointment: "When should I leave for my 14:00?".
    function leaveFor(t) {
        if (!/\bwhen (should|do|must) i (leave|set off|go)\b|\bleave for my\b/.test(t))
            return null;
        const a = root.ExpaduSampleDay?.appointment;
        const j = a?.journey;
        if (!j) return null;
        const time = t.match(/\b(\d{1,2}:\d{2})\b/)?.[1];
        if (time ? normalizeTime(time) !== a.start : !/appointment|blue card/.test(t))
            return null;
        return { from: j.from, to: j.to, arriveBy: a.start, appointmentId: a.id };
    }
    function journeyPlaces(text) {
        const sentence =
            text
                .split(/[?!]|\.\s/)
                .find((part) => /\b(to|from)\b/i.test(part)) || '';
        const by = text.match(/\bby\s+(\d{1,2}:\d{2})\b/i);
        const arrive = by ? { arriveBy: normalizeTime(by[1]) } : {};
        const both = sentence.match(/\bfrom\s+(.+?)\s+to\s+(.+?)(?:\s+(?:by|at|on|before)\b.*)?$/i);
        if (both) return { from: both[1].trim(), to: both[2].trim(), ...arrive };
        const to = sentence.match(
            /\b(?:get to|go to|directions to|journey to|route to|way to)\s+(.+?)(?:\s+(?:by|at|on|before|from)\b.*)?$/i,
        );
        return { ...(to ? { to: to[1].trim() } : {}), ...arrive };
    }
    // Plans a person mentions: "I already have Language class in Ehrenfeld at 18:00."
    function mentionedPlans(text) {
        const match = text.match(/\bI (?:already )?have (.+?)(?:\.|$)/i);
        if (!match) return [];
        return match[1]
            .split(/,\s*|\s+and\s+/)
            .map((part) =>
                part.match(/^(.+?)(?:\s+in\s+(.+?))?\s+at\s+(\d{1,2}:\d{2})$/i),
            )
            .filter(Boolean)
            .map((m) => {
                const start = normalizeTime(m[3]);
                const end = start ? clock(Math.min(minutes(start) + 60, 1439)) : null;
                return { title: m[1].trim(), location: m[2]?.trim() || '', start, end };
            })
            .filter((plan) => plan.start && plan.end);
    }
    const services = [
        ['doctors', /\b(doctor|gp|hausarzt|physician)s?\b/],
        ['dentists', /\b(dentist|zahnarzt)s?\b/],
        ['pharmacies', /\b(pharmac(y|ies)|apotheke|chemist)\b/],
        ['banks', /\b(bank|bank account)s?\b/],
        ['tax', /\btax (advisor|adviser|return)s?\b/],
        ['lawyers', /\b(lawyer|legal advice)s?\b/],
    ];
    const topics = [
        ['music', /\b(music|concerts?|gigs?|live band)\b/],
        ['exhibition', /\b(exhibitions?|museums?|art|gallery|galleries)\b/],
        ['film', /\b(films?|cinema|movies?)\b/],
        ['football', /\b(football|match|bundesliga|fc)\b/],
    ];
    const placeCategories = [
        ['Family', /\b(kids?|children|child|family|playground)\b/],
        ['Sport', /\b(table tennis|ping pong|kick ?about|pitch|sports?)\b/],
        ['Swimming', /\b(swim|swimming|lake|bathing)\b/],
    ];
    const find = (list, t) => list.find(([, pattern]) => pattern.test(t))?.[0] || null;
    // Everyday places near a point (connected/places.js): the kind asked for.
    const nearbyKinds = [
        ['pharmacy', /\b(pharmac(y|ies)|apotheke|chemist)\b/],
        ['bakery', /\b(baker(y|ies)|bread|croissants?)\b/],
        ['cafe', /\b(cafes?|coffee)\b/],
        ['grocery', /\b(grocer(y|ies)|supermarkets?|food shop)\b/],
        ['playground', /\bplaygrounds?\b/],
        ['library', /\b(librar(y|ies)|bibliothek)\b/],
        ['fastfood', /\b(fast food|takeaway|take-away|imbiss|quick bite|d(ö|o)ner|kebab)\b/],
        // Parks plus woods, lakes and nature areas.
        ['green', /\b(somewhere green|green spaces?|woods?|forest|nature)\b/],
        ['restaurant', /\b(restaurants?|dinner|lunch|(somewhere|place|bite) to eat)\b/],
        ['park', /\bparks?\b/],
    ];
    // Where "near" means. Before the appointment you're at Neumarkt.
    const nearbyAnchors = [
        ['wiener-platz', /\b(appointment|blue card|mulheim|muelheim|mülheim|wiener platz)\b/],
        ['rheinauhafen', /\brheinauhafen\b/],
        ['stadtgarten', /\bstadtgarten\b/],
        ['ehrenfeld', /\behrenfeld\b/],
        ['neumarkt', /\bneumarkt\b/],
    ];
    function nearby(t, date) {
        const place = find(nearbyKinds, t);
        if (!place) return null;
        const near = find(nearbyAnchors, t);
        // A park without a place stays general discovery ("a park to walk").
        if ((place === 'park' || place === 'green') && !near) return null;
        return {
            kind: 'nearby',
            date,
            place,
            near: near || 'neumarkt',
            open: /\b(open|still open|open now)\b/.test(t),
            after: /\bafter (my |the )?(blue card )?appointment\b/.test(t),
            wifi: /\b(wi-?fi|laptop|work from|(can|to) work)\b/.test(t),
        };
    }
    function respond(text, s) {
        // Curly apostrophes and accents read the same as typed ones.
        const t = text
                .toLowerCase()
                .replace(/[’‘]/g, "'")
                .replace(/é/g, 'e'),
            date = requestDate(text, s);
        if (!validDate(date)) return { kind: 'clarify', date: s.date };
        if (
            /^(hi|hello|hey|hallo|moin|good (morning|afternoon|evening))[!. ]*$|what can you do|how (can|do) you help|^help[?!. ]*$|who are you/.test(
                t,
            )
        )
            return { kind: 'help', date };
        // "When should I leave for my 14:00?" is about the appointment's trip.
        const leaving = leaveFor(t);
        if (leaving) return { kind: 'journey', date, ...leaving };
        // Getting somewhere, or planning a day, comes before paperwork words:
        // "How do I get to my Blue Card appointment?" is a journey question.
        if (
            /\b(how (do|can|should) i get to|get there|directions to|journey to|route to|faster way|quicker way|another route)\b/.test(
                t,
            )
        )
            return { kind: 'journey', date, ...journeyPlaces(text) };
        // Questions about your plans answer from them; they never change them.
        if (
            /\b(when|what time)\b.*\b(my|next)\b.*\b(appointment|plan|class)\b|\bnext (appointment|plan)\b|\bwhat('s| is)( on)? my (day|plan|schedule)\b/.test(
                t,
            )
        )
            return { kind: 'next', date, appointment: /appointment/.test(t) };
        if (/\b(rain|rainy|raining|indoors?|inside)\b/.test(t))
            return { kind: 'indoor', date };
        if (/\b(help me plan|plan my day)\b/.test(t))
            return {
                kind: 'proposal',
                date,
                appointment: /around (my|an?|the) .*appointment/.test(t),
            };
        // "Near my appointment" or "open now" means today unless a day is named.
        const places = nearby(t, requestDate(text, { ...s, date: today() }));
        if (places) return places;
        if (
            /visa|register|registration|anmeldung|permit|bureaucracy|blue card|paperwork|settle in/.test(
                t,
            )
        )
            return { kind: 'bureaucracy', date };
        const service = find(services, t);
        if (service) return { kind: 'service', date, service };
        if (
            /\b(events?|concerts?|exhibitions?|museums?|music|films?|cinema|what('s| is) on|things to do|going out|tonight)\b/.test(
                t,
            )
        )
            return {
                kind: 'event',
                date,
                topic: find(topics, t),
                days: /\bweekend\b/.test(t) ? 2 : /\b(this|next) week\b/.test(t) ? 7 : 1,
                evening: /\b(tonight|this evening|evening)\b/.test(t),
            };
        if (/journey|route|how.*get|transport/.test(t))
            return { kind: 'journey', date, ...journeyPlaces(text) };
        if (/\bmore (outdoor )?(places|ideas)\b/.test(t))
            return { kind: 'discovery', date, more: true };
        const category = find(placeCategories, t);
        if (category) return { kind: 'discovery', date, category };
        if (
            /\b(cafes?|coffee|work from|laptop|bars?|pubs?|restaurants?|eat|food|brunch|christmas market|open now|opening hours)\b/.test(
                t,
            )
        )
            return { kind: 'unavailable', date, topic: 'listing' };
        if (/indoors|no parks|quiet|volleyball|free|budget|30 minutes/.test(t))
            return { kind: 'unavailable', date };
        if (/what.*plan|show.*plan|what.*saturday|what.*doing/.test(t))
            return { kind: 'answer', date };
        if (/later|move.*park|change.*plan|adjust|revise/.test(t))
            return { kind: 'revise', date };
        if (/\bplan\b|appointment/.test(t))
            return {
                kind: 'proposal',
                date,
                appointment: /appointment/.test(t),
            };
        if (/outdoor|park|fresh air|walk|discover/.test(t))
            return { kind: 'discovery', date };
        return { kind: 'clarify', date };
    }
    const api = {
        today,
        nowTime,
        addDays,
        journeyPlaces,
        mentionedPlans,
        addScheduled,
        updateRecordedAppointment,
        normalizeTime,
        timeWheelValues,
        fresh,
        clone,
        iso,
        saturday,
        bookmark,
        propose,
        setTime,
        moveLater,
        replacePlace,
        filterReplacementPlaces,
        confirm,
        conflicts,
        respond,
        titles,
    };
    if (typeof module !== 'undefined') module.exports = api;
    root.StudioModel = api;
})(globalThis);
