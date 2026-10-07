(function (root) {
    function entry(entry, ctx) {
        const {
            state: s,
            escape: E,
            icon: I,
            card,
            timeline,
            proposal,
            dateLabel,
        } = ctx;
        const c = s.conversation;
        const text = entry.text ? `<p>${E(entry.text)}</p>` : '';
        let body = text;
        if (entry.kind === 'pending')
            body = `<div class="pending-row">${ExpaduLoading.status('Thinking…')}<button class="plain-button" data-action="cancel-loading">Cancel</button></div>`;
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
        if (entry.kind === 'plan-cancelled' && entry.plan)
            body += `<details class="chat-plan-reference"><summary>${I('calendar')}View cancelled day</summary>${timeline(entry.plan)}</details>`;
        if (
            entry.kind === 'plan-cancelled' &&
            !ctx.historical &&
            c.lastApplied?.date === entry.planDate &&
            c.lastApplied.after === null &&
            c.lastApplied.cancellationEntryId === entry.id
        )
            body +=
                '<button class="plain-button" data-action="undo-plan">Undo cancellation</button>';
        if (entry.kind === 'plan-history' && !ctx.historical) {
            // Earlier versions stay in Version history; while the live plan
            // for that day appears further down, the old card isn't repeated.
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
            const version = c.versions.find((v) => v.id === entry.versionId);
            const draft =
                s.proposal?.date === entry.planDate ? s.proposal : null;
            const confirmed = s.plans.find((p) => p.date === entry.planDate);
            const active =
                entry.kind === 'plan' &&
                !ctx.historical &&
                c.activeDate === entry.planDate &&
                (draft || confirmed);
            const versions = c.versions.filter(
                (v) => v.plan.date === entry.planDate,
            );
            const history = `<details class="plan-versions"><summary>${I('clock')}Version history · ${versions.length}</summary>${versions
                .slice()
                .reverse()
                .map(
                    (v) =>
                        `<details class="version-record"><summary><span>${E(v.label)}</span><small>${v.status === 'confirmed' ? 'Confirmed' : 'Proposal'}</small></summary><p class="history-readonly">Snapshot · read only</p>${timeline(v.plan)}</details>`,
                )
                .join('')}</details>`;
            if (active) {
                body = `<div class="chat-plan-label"><span>${I('calendar')}${E(dateLabel(entry.planDate))}</span><small>${draft ? 'Proposed changes' : 'Current plan'}</small></div><section id="studio-response" class="chat-live-plan" aria-label="Active plan">${draft ? proposal() : `<section class="timeline-paper"><div class="paper-heading"><span class="confirmed-label">${I('check')}Confirmed plan</span><button class="plain-button" data-revise="${E(confirmed.id)}">Edit plan</button></div>${timeline(confirmed)}<div class="plan-management"><button class="plain-button" data-action="appointment">${I('plus')}Add appointment</button><button class="plain-button" data-cancel-day="${E(confirmed.date)}">Cancel day</button></div><div class="travel-note">${I('route')}Travel and availability still need checking.</div></section>`}</section>${history}${!draft && c.lastApplied?.date === entry.planDate ? `<button class="plain-button plan-undo" data-action="undo-plan">${I('back')}${c.lastApplied.before ? 'Undo this change' : 'Undo confirmation'}</button>` : ''}`;
            } else {
                body = `<details class="chat-plan-reference"><summary>${I('calendar')}${entry.kind === 'plan-history' ? 'Earlier plan' : 'Plan'} for ${E(dateLabel(entry.planDate))}<span>View snapshot</span></summary>${version ? `<p class="history-readonly">${E(version.label)} · read only</p>${timeline(version.plan)}` : '<p>This draft has no activities yet.</p>'}</details>${!ctx.historical && (draft || confirmed) ? `<button class="plain-button" data-open-plan="${E(entry.planDate)}">Open current plan</button>` : ''}`;
            }
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
                    text +
                    `<details class="chat-plan-reference"><summary>${I('pin')}Previous alternatives · view only</summary>${body.slice(text.length)}</details>`;
        }
        if (entry.kind === 'answer' && entry.plan)
            body += `<details class="chat-plan-reference"><summary>${I('calendar')}Earlier plan snapshot · ${E(dateLabel(entry.plan.date))}</summary><p class="history-readonly">Saved at the time of this reply · read only</p>${timeline(entry.plan)}</details>${!ctx.historical && (s.proposal?.date === entry.plan.date || s.plans.some((p) => p.date === entry.plan.date)) ? `<button class="plain-button" data-open-plan="${E(entry.plan.date)}">Open current plan</button>` : ''}`;
        // Next steps a reply offers: a request to send, a panel or another page.
        // Only the latest reply offers next steps, so they don't pile up.
        const section = c.chapters?.find((x) => x.id === c.currentId);
        const latest = section?.entries
            .filter((x) => x.role === 'assistant')
            .at(-1);
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
