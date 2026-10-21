/* Keep the personal overview available, with a relevant next action above discovery. */
(function () {
    const S = ExpaduStore,
        I = ExpaduIcon,
        E = ExpaduUI.esc;
    const nextHost = document.querySelector('#today-next');
    const paperHost = document.querySelector('#today-paperwork');
    const connectionHost = document.querySelector('#today-connections');
    const paperCard = document.querySelector('#today-paperwork-card');
    if (!nextHost || !paperHost || !connectionHost || !paperCard) return;
    let current,
        connection,
        papers = [],
        paperIndex = 0;
    const localDay = (now) =>
        `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    const shortDate = (date) =>
        /^\d{4}-\d{2}-\d{2}$/.test(date || '')
            ? new Date(date + 'T12:00:00').toLocaleDateString('en-GB', {
                  day: 'numeric',
                  month: 'short',
              })
            : '';
    const title = (entry) => entry.item.title || entry.item.name;
    const place = (entry) => entry.item.venue || entry.item.location || '';
    const iconButton = (action, icon, label) =>
        `<button class="today-brief-icon" data-brief="${action}" aria-label="${E(label)}" title="${E(label)}">${I(icon)}</button>`;
    function renderNext(entry) {
        if (!entry) return '';
        const journey = entry.kind === 'journey';
        const caption = journey
            ? 'Resume journey'
            : entry.ongoing
              ? 'Now'
              : 'Up next';
        const hint = journey
            ? {
                  offline: 'Offline preview',
                  location: 'Location unavailable',
                  changed: 'Route changed',
              }[entry.item.condition] || entry.item.instruction
            : place(entry) ||
              (entry.kind === 'appointment'
                  ? 'Recorded appointment'
                  : `${entry.count} ${entry.count === 1 ? 'activity' : 'activities'} today`);
        const stamp = journey
            ? `${I(entry.item.mode === 'transit' ? 'train' : entry.item.mode)}<small>Preview</small>`
            : `<small>Today</small><strong>${E(entry.start)}</strong>`;
        return `<div class="today-next-card"><button class="today-next-main" data-brief="primary" aria-label="${E(journey ? 'Resume journey preview to ' + title(entry) : entry.kind === 'appointment' ? 'Open appointment: ' + title(entry) : 'Open today’s plan: ' + title(entry))}"><span class="today-time">${stamp}</span><span class="today-next-copy"><small>${caption}</small><strong>${E(title(entry))}</strong><span>${E(hint)}</span></span></button>${iconButton(!journey && place(entry) ? 'directions' : 'primary', !journey && place(entry) ? 'route' : 'arrow', !journey && place(entry) ? 'Get directions to ' + place(entry) : journey ? 'Resume journey preview' : 'Open your plan')}</div>`;
    }
    function overviewAction(action, label, accessibleLabel = label) {
        return `<button class="clarity-action" data-brief="${action}" aria-label="${E(accessibleLabel)}">${E(label)}${I('arrow')}</button>`;
    }
    function renderPaperOverview(bureau) {
        const p = BureaucracyPlan.projection(bureau);
        const total = p.total,
            done = p.counts.completed;
        const progress = total
            ? `<div class="c-progress" role="progressbar" aria-label="Recorded paperwork progress" aria-valuemin="0" aria-valuemax="${total}" aria-valuenow="${done}">${Array.from({ length: total }, (_, n) => `<span${n < done ? ' class="done"' : ''}></span>`).join('')}</div>`
            : '';
        return `<h3>${I('document')}Your paperwork</h3><div class="clarity-data"><strong>${total ? `${done} of ${total} actions done` : 'Get started'}</strong>${progress}</div>${overviewAction('paperwork-overview', total ? 'Overview' : 'Get started', total ? 'Open your paperwork overview' : 'Get started with paperwork')}${total ? '<small>Sample case · your records</small>' : '<small>At your pace</small>'}`;
    }
    function renderConnections(entry) {
        const journey = entry?.kind === 'journey';
        const hint = !entry
            ? 'Choose a destination'
            : journey
              ? 'Journey preview'
              : place(entry) || 'Location needs checking';
        const stamp =
            entry && !journey
                ? `<p class="clarity-context">${shortDate(entry.date)} · ${entry.start}</p>`
                : '';
        return `<h3>${I('train')}Your connections</h3><div class="clarity-data"><strong>${entry ? E(title(entry)) : 'Find a journey'}</strong><span>${E(hint)}</span></div>${stamp}${overviewAction('connection', journey ? 'Resume' : entry && place(entry) ? 'Directions' : 'Plan a route', journey ? 'Resume your journey preview' : entry && place(entry) ? 'Directions to ' + place(entry) : 'Find a journey')}`;
    }
    function renderPaperCard() {
        const paper = papers[paperIndex];
        const label = !paper
            ? 'Paperwork'
            : {
                  next: 'Next step',
                  missing: 'Missing document',
                  waiting: 'Waiting',
                  appointment: 'Appointment',
              }[paper.kind];
        const note = !paper
            ? 'Open overview'
            : paper.kind === 'appointment'
              ? `${shortDate(paper.date)} · ${paper.time || 'Time to check'}`
              : paper.kind === 'waiting'
                ? 'Awaiting response'
                : paper.kind === 'missing'
                  ? `${paper.missing} missing`
                  : `${paper.ready}/${paper.total} documents ready`;
        const controls =
            papers.length > 1
                ? `<div class="today-paper-controls"><button data-brief="paper-prev" aria-label="Previous paperwork item"${paperIndex === 0 ? ' disabled' : ''}>${I('back')}</button><span aria-live="polite">${paperIndex + 1}/${papers.length}</span><button data-brief="paper-next" aria-label="Next paperwork item"${paperIndex === papers.length - 1 ? ' disabled' : ''}>${I('arrow')}</button></div>`
                : '';
        return `<button class="paperwork-content" data-brief="paperwork" aria-label="${E(paper ? 'Open ' + paper.name : 'Open your paperwork overview')}"><span class="paperwork-head"><span class="card-category">${I('document')}${label}</span>${paper ? '<small>Sample</small>' : ''}</span><span class="paperwork-main"><strong>${E(paper?.name || 'Your situation')}</strong></span><span class="card-footline"><span>${E(note)}</span>${I('arrow')}</span></button>${controls}`;
    }
    function replace(host, html) {
        if (host.innerHTML === html) return;
        const focused = host.contains(document.activeElement)
            ? document.activeElement.dataset.brief
            : null;
        host.innerHTML = html;
        host.hidden = !html;
        if (focused) {
            let target = host.querySelector(`[data-brief="${focused}"]`);
            if (target?.disabled)
                target = host.querySelector(
                    '.today-paper-controls button:not(:disabled)',
                );
            target?.focus({ preventScroll: true });
        }
    }
    function sync() {
        let bureau = BureaucracyPreview.blank();
        try {
            const stored = JSON.parse(
                localStorage.getItem('expadu-bureaucracy-design-v1'),
            );
            if (stored?.docs?.length === 5)
                bureau = { ...BureaucracyPreview.fresh(), ...stored };
        } catch {}
        const now = new Date();
        const personal = TodaySlides.personal(bureau);
        papers = TodaySlides.paperwork(personal);
        paperIndex = Math.min(paperIndex, Math.max(0, papers.length - 1));
        current = TodayBrief.project({
            date: localDay(now),
            time: now.toTimeString().slice(0, 5),
            workspace: S.studio(),
            draft: S.read().draft,
            papers,
            journey: window.JourneySession?.read(),
        });
        connection = current.primary || current.later;
        replace(nextHost, renderNext(current.primary));
        replace(paperHost, renderPaperOverview(personal));
        replace(connectionHost, renderConnections(connection));
        replace(paperCard, renderPaperCard());
        document.body.toggleAttribute(
            'data-today-journey',
            current.primary?.kind === 'journey',
        );
    }
    function open(entry) {
        if (!entry) return;
        if (entry.kind === 'journey') S.go('departures', { journey: 'resume' });
        else if (entry.kind === 'appointment')
            S.go('bureaucracy', {
                person: 'self',
                process: entry.item.id,
                view: 'detail',
            });
        else S.go('composer', { date: entry.date, panel: 'plans' });
    }
    function directions(entry) {
        if (!entry || entry.kind === 'journey' || !place(entry)) return;
        S.go('departures', {
            to: place(entry),
            date: entry.date,
            arrive: entry.ongoing ? undefined : entry.start,
            activity: entry.kind === 'plan' ? entry.item.id : undefined,
            planDate: entry.kind === 'plan' ? entry.date : undefined,
        });
    }
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-brief]');
        if (!button || !current) return;
        const action = button.dataset.brief;
        if (action === 'primary') open(current.primary);
        if (action === 'paperwork-overview')
            S.go('bureaucracy', { person: 'self' });
        if (action === 'paperwork') {
            const paper = papers[paperIndex];
            S.go('bureaucracy', {
                person: 'self',
                process: paper?.id,
                view: paper?.view,
            });
        }
        if (action === 'paper-next' || action === 'paper-prev') {
            paperIndex = Math.max(
                0,
                Math.min(
                    papers.length - 1,
                    paperIndex + (action === 'paper-next' ? 1 : -1),
                ),
            );
            replace(paperCard, renderPaperCard());
        }
        if (action === 'connection') {
            if (connection?.kind === 'journey') open(connection);
            else if (connection && place(connection)) directions(connection);
            else S.go('departures');
        }
        if (action === 'directions') directions(current.primary);
    });
    for (const name of [
        'storage',
        'pageshow',
        'expadu:change',
        'expadu:journey-preview',
    ])
        window.addEventListener(name, sync);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) sync();
    });
    sync();
})();
