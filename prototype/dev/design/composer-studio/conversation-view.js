(function (root) {
    function entry(entry, ctx) {
        const {
            state: s,
            escape: E,
            icon: I,
            card,
            timeline,
            dateLabel,
        } = ctx;
        const c = s.conversation;
        // A short verdict first ("Leave now — it’s 13:10."), then the detail.
        const title = entry.title
            ? `<h3 class="chat-answer-title">${E(entry.title)}</h3>`
            : '';
        const paragraph = entry.text ? `<p>${E(entry.text)}</p>` : '';
        // The trip and your checklist lead with their block; the
        // explanation follows it (the approved Composer design).
        // Your way as Travel's trip draws it (departures-next/way.js), the
        // line in its badge (labels.js). Answers saved before 18 Oct 2026
        // kept steps as label and value.
        const way = (entry.trip?.way || (entry.trip?.steps || []).map((x) => ({ kind: x.mode === 'walk' ? 'walk' : x.mode === 'arrive' ? 'end' : 'ride', line: x.line, time: '', title: x.label, sub: x.value }))).map((row, index, rows) => {
            const ride = row.line ? row : row.kind.includes('is-from-stop') ? rows.slice(0, index).reverse().find((r) => r.line) : null;
            const look = ride ? root.TravelLabels.look(ride.line, 'tram') : null;
            return { ...row, look, badge: row.line ? root.TravelLabels.line(row.line, look) : '' };
        });
        const blockFirst =
            (entry.kind === 'trip' && way.length) ||
            (entry.kind === 'checklist' && entry.record);
        const lead = title + (blockFirst ? '' : paragraph);
        let body = lead;
        // The trip, step by step, as Travel's trip shows it.
        if (entry.kind === 'trip' && way.length)
            body += `<section class="answer-way" aria-label="Your way"><h4 class="nav-way-title">Your way</h4>${root.TravelWay.list(way)}</section>${paragraph}${entry.trip.note ? `<p class="answer-note">${E(entry.trip.note)}</p>` : ''}<div class="answer-actions">${entry.trip.href ? `<a class="answer-button" href="${E(entry.trip.href)}">${E(entry.trip.label || 'Open in Travel')}${I('arrow')}</a>` : ''}<span>Scheduled sample times, not live.</span></div>`;
        // Your own checklist's progress, linked to Paperwork. Not guidance.
        if (entry.kind === 'checklist' && entry.record) {
            const r = entry.record;
            const share = r.total ? Math.round((r.prepared / r.total) * 100) : 0;
            // Then each document and how you've marked it.
            const docs = (r.documents || [])
                .map(
                    (d) =>
                        `<li${d.ready ? ' class="is-ready"' : ''}><span>${E(d.label)}</span><small>${E(d.state)}</small></li>`,
                )
                .join('');
            body += `<a class="answer-block answer-checklist" href="${E(r.href || '#')}"><span class="answer-tile">${I('document')}</span><span><small>Your ${E(r.title)} checklist · your records</small><strong>${E(`${r.prepared} of ${r.total} ready`)}</strong><span class="answer-progress" aria-hidden="true"><i style="width:${share}%"></i></span></span>${I('arrow')}</a>${docs ? `<ul class="answer-block answer-docs">${docs}</ul>` : ''}${paragraph}`;
        }
        // What a listing does and doesn't say.
        if (entry.kind === 'facts' && entry.facts?.length)
            body += `<dl class="answer-facts">${entry.facts
                .map(
                    (x) =>
                        `<div><dt>${E(x.label)}</dt><dd${x.known ? '' : ' class="is-unknown"'}>${E(x.value)}</dd></div>`,
                )
                .join('')}</dl>`;
        if (entry.kind === 'pending')
            body = `<div class="pending-row">${ExpaduLoading.status('Thinking…')}</div>`;
        if (entry.kind === 'cancelled' || entry.kind === 'failed')
            body += `<button class="plain-button" data-retry="${E(entry.id)}">Try again</button>`;
        if (entry.kind === 'discovery')
            body += `<div class="discovery-grid">${(entry.placeIds || []).map(ctx.place).filter(Boolean).map(card).join('')}</div>`;
        if (entry.kind === 'nearby')
            body += ctx.nearby ? ctx.nearby(entry) : '';
        if (['event', 'journey', 'bureaucracy'].includes(entry.kind))
            body += ctx.serviceCard ? ctx.serviceCard(entry) : '';
        if (entry.kind === 'department-context' && entry.context) {
            const item = entry.context;
            const baseFields = (typeof item.requirements === 'object'
                ? item.requirements
                : item.requirements
                  ? { requirements: item.requirements }
                  : null) || {
                from: item.from,
                to: item.to,
                date: item.date,
                time: item.time || item.depart || item.requestedTime,
                mode: item.mode,
                location: item.location,
            };
            // A trip request in words, not field names and ISO dates.
            const tripDay = /^\d{4}-\d{2}-\d{2}$/.test(item.date || '')
                ? new Date(`${item.date}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })
                : '';
            const fields =
                item.type === 'journey' && !item.legs && !item.requirements
                    ? {
                          from: item.from,
                          to: item.to,
                          when:
                              [
                                  tripDay,
                                  item.requestedTime
                                      ? `${item.preferences?.schedule === 'arrive' ? 'arrive by' : 'leave at'} ${item.requestedTime}`
                                      : '',
                              ]
                                  .filter(Boolean)
                                  .join(', ') || 'Leave now',
                          travel: { transit: 'Public transport', bike: 'Cycle', walk: 'Walk' }[item.mode] || item.mode,
                      }
                    : { ...baseFields };
            const service =
                item.servicePreference?.version === 1 &&
                item.servicePreference.kind === 'service-preference' &&
                item.servicePreference.isLive === false
                    ? item.servicePreference
                    : null;
            if (service) {
                let captured = service.capturedAt;
                try {
                    captured = new Intl.DateTimeFormat('en-GB', {
                        dateStyle: 'medium',
                        timeStyle: 'short',
                        ...(service.timezone
                            ? { timeZone: service.timezone }
                            : {}),
                    }).format(new Date(service.capturedAt));
                } catch {
                    /* Preserve the recorded timestamp when formatting fails. */
                }
                Object.assign(fields, {
                    selected_service: `Line ${service.line} towards ${service.headsign}`,
                    departure: `${service.timeBasis === 'rounded-countdown' ? 'About ' : ''}${service.time}`,
                    time_basis:
                        service.timeBasis === 'rounded-countdown'
                            ? 'Estimated from a rounded countdown'
                            : 'Saved capture',
                    captured,
                    boarding_point: service.boardingPointId
                        ? service.platform || 'Check before travelling'
                        : 'Unconfirmed',
                });
                delete fields.time;
            }
            const rows = Object.entries(fields).filter(
                ([, v]) => v !== '' && v != null && typeof v !== 'object',
            );
            const caution = service
                ? 'Saved service preference, not a confirmed route. Check current departures and the boarding point before travelling.'
                : item.type === 'appointment'
                  ? 'Your recorded appointment. Add its end time before scheduling it.'
                  : 'Your selected details are retained here. Live routes, providers and bookings are not connected in this preview.';
            body += `<section class="service-card"><h3>${E(item.title)}</h3><dl>${rows.map(([key, value]) => `<div><dt>${E(key.replaceAll('_', ' '))}</dt><dd>${E(value)}</dd></div>`).join('')}</dl><p class="detail-caution">${caution}</p>${item.type === 'appointment' ? `<button class="studio-button light" data-action="appointment" data-context-appointment="${E(item.id)}">Review appointment</button>` : `<a class="plain-button" href="${E(ExpaduHandoffs.reviewURL(item))}">Review ${item.type === 'journey' ? 'journey' : 'requirements'}</a>`}</section>`;
        }
        if (entry.kind === 'appointment-form')
            body +=
                '<button class="studio-button light" data-action="appointment">Add appointment</button>';
        // A change carries its Undo while it's the latest one (owner, 18 Oct
        // 2026, Composer review canvas board 3); a cancelled day too.
        const undoable =
            !ctx.historical &&
            !!c.lastApplied &&
            ((entry.changeId && c.lastApplied.id === entry.changeId) ||
                (entry.kind === 'plan-cancelled' &&
                    c.lastApplied.after === null &&
                    c.lastApplied.cancellationEntryId === entry.id));
        if (undoable)
            body += `<button class="plain-button change-undo" data-action="undo-plan">${I('back')}Undo</button>`;
        if (entry.kind === 'plan-history' && !ctx.historical) {
            // While the plan for that day appears further down, the earlier
            // card isn't repeated.
            const section = c.chapters?.find((x) => x.id === c.currentId);
            const entries = section?.entries || [];
            const index = entries.findIndex((x) => x.id === entry.id);
            if (
                entries
                    .slice(index + 1)
                    .some((x) => x.kind === 'plan' && x.planDate === entry.planDate)
            )
                return '';
        }
        if (entry.kind === 'plan' || entry.kind === 'plan-history') {
            const saved = s.plans.find((p) => p.date === entry.planDate);
            const active =
                entry.kind === 'plan' &&
                !ctx.historical &&
                c.activeDate === entry.planDate &&
                saved;
            // The day as it is now, changed in place: no proposal to
            // confirm, versions or snapshots (board 3).
            if (active)
                body = `<div class="chat-plan-label"><span>${I('calendar')}${E(dateLabel(entry.planDate))}</span><small>In your plans</small></div><section id="studio-response" class="chat-live-plan" aria-label="Your plan"><section class="timeline-paper">${timeline(saved, true)}<div class="plan-management"><button class="plain-button" data-action="appointment">${I('plus')}Add appointment</button><button class="plain-button" data-cancel-day="${E(saved.date)}">Cancel day</button></div><div class="travel-note">${I('route')}Travel and availability still need checking.</div></section></section>`;
            else
                body = `<div class="chat-plan-reference">${I('calendar')}<span>Plan for ${E(dateLabel(entry.planDate))}</span>${!ctx.historical && saved ? `<button class="plain-button" data-open-plan="${E(entry.planDate)}">Open</button>` : ''}</div>`;
        }
        if (entry.kind === 'replacements') {
            const date = entry.planDate || c.activeDate;
            const currentPlan =
                s.proposal?.date === date
                    ? s.proposal
                    : s.plans.find((p) => p.date === date);
            const valid =
                !ctx.historical &&
                !!currentPlan &&
                (!s.proposal || s.proposal.date === date) &&
                c.activeDate === date &&
                JSON.stringify(currentPlan) === entry.proposalToken;
            body += `<p class="chat-alternatives-count">${(entry.placeIds || []).length} alternatives · scroll to explore</p><div class="chat-alternatives" role="region" aria-label="Alternative places" tabindex="0">${(
                entry.placeIds || []
            )
                .map(ctx.place)
                .filter(Boolean)
                .map(
                    (p) =>
                        `<div class="alternative-row"><button data-chat-replace="${E(p.id)}" data-entry="${E(entry.id)}" ${valid ? '' : 'disabled'}>${I('pin')}<span><strong>${E(p.title)}</strong><small>${E(p.label)} · ${E(p.area)}</small></span>${I('arrow')}</button><button class="alternative-preview" data-preview="${E(p.id)}" aria-label="Preview ${E(p.title)}">${I('search')}</button></div>`,
                )
                .join(
                    '',
                )}</div>${valid ? `<button class="plain-button" data-browse-replacements="${E(entry.id)}">Browse more places</button>` : '<p class="history-readonly">The plan has changed since these suggestions. Ask for fresh alternatives.</p>'}`;
            if (!valid)
                body =
                    lead +
                    `<details class="chat-plan-reference"><summary>${I('pin')}Previous alternatives · view only</summary>${body.slice(lead.length)}</details>`;
        }
        if (entry.kind === 'answer' && entry.plan && !ctx.historical && s.plans.some((p) => p.date === entry.plan.date))
            body += `<button class="plain-button" data-open-plan="${E(entry.plan.date)}">Open the plan</button>`;
        // Next steps a reply offers: a request to send, a panel or another page.
        // Only the latest reply offers next steps, so they don't pile up.
        const section = c.chapters?.find((x) => x.id === c.currentId);
        const latest = section?.entries
            .filter((x) => x.role === 'assistant')
            .at(-1);
        // What Composer can do, as the home's cards: rows that ask (board 7).
        if (!ctx.historical && entry.options?.length && latest?.id === entry.id)
            body += `<div class="answer-options">${entry.options
                .map(
                    (x) =>
                        `<button class="answer-option is-${E(x.kind)}" data-prompt="${E(x.label)}"><span class="answer-option-icon">${I(x.icon)}</span><span><strong>${E(x.label)}</strong><small>${E(x.tag)} · ${E(x.detail)}</small></span>${I('arrow')}</button>`,
                )
                .join('')}</div>`;
        if (!ctx.historical && entry.suggestions?.length && latest?.id === entry.id)
            body += `<div class="reply-suggestions">${entry.suggestions
                .map((x) =>
                    x.href
                        ? `<a class="suggestion-chip" href="${E(x.href)}">${E(x.label)}${I('arrow')}</a>`
                        : x.nav
                          ? `<button class="suggestion-chip" data-nav="${E(x.nav)}">${E(x.label)}</button>`
                          : `<button class="suggestion-chip" data-prompt="${E(x.prompt)}">${E(x.label)}</button>`,
                )
                .join('')}</div>`;
        const from = entry.pageContext?.label
            ? `<small class="chat-context">${I('pin')}From ${E(entry.pageContext.label)}</small>`
            : '';
        return `<article class="chat-entry chat-${entry.role === 'user' ? 'user' : 'assistant'} ${entry.kind === 'plan' ? 'chat-plan' : ''}" data-chat-entry="${E(entry.id)}"><span class="chat-speaker">${entry.role === 'user' ? 'You' : I('spark') + 'Composer'}</span>${from}${body}</article>`;
    }
    root.StudioConversationView = { entry };
})(globalThis);
