(function () {
    const M = ExpaduModel,
        S = ExpaduStore,
        I = ExpaduIcon;
    const entries = [...M.places, ...M.events];
    const rail = document.querySelector('.today-event-rail');
    if (rail) {
        const lead = ExpaduEventPosters.curate(M.events).feature;
        const candidates = lead
            ? [lead, ...M.events.filter((item) => item !== lead)]
            : M.events;
        const picks = TodaySlides.eventDecks(candidates)
            .map((deck) => deck[0])
            .filter(Boolean);
        rail.innerHTML = picks
            .map((item, index) =>
                ExpaduEventFestival.card(item, index, index === 0, {
                    standalone: true,
                }),
            )
            .join('')
            .replaceAll('data-detail=', 'data-board-detail=');
        ExpaduEventFestival.bindRail(rail);
    }
    const eventCredit = document.querySelector('.event-board-credit');
    if (eventCredit)
        eventCredit.innerHTML = ExpaduEventFestival.credit(M.events);
    window.ExpaduPlanning?.refresh();
    function syncPlanActions() {
        const shared = S.read(),
            studio = S.studio();
        const ids = new Set([
            ...shared.draft.map((x) => x.id),
            ...(studio?.proposal?.items || []).map((x) =>
                x.id.replace(/^event:/, ''),
            ),
        ]);
        document.querySelectorAll('[data-board-plan]').forEach((b) => {
            const added = ids.has(b.dataset.boardPlan);
            b.disabled = added;
            b.innerHTML = added ? `Added ${I('check')}` : `Plan ${I('plus')}`;
        });
    }
    window.addEventListener('expadu:change', syncPlanActions);
    window.addEventListener('pageshow', syncPlanActions);
    window.addEventListener('storage', syncPlanActions);
    syncPlanActions();
    document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-board-detail],[data-board-plan]');
        if (!b) return;
        if (b.dataset.boardPlan) {
            const item = entries.find((x) => x.id === b.dataset.boardPlan);
            ExpaduPlanning.open(item);
            return;
        }
        if (b.dataset.boardDetail) {
            const item = entries.find((x) => x.id === b.dataset.boardDetail),
                isEvent = item.type === 'event';
            if (isEvent) {
                ExpaduEventSheet.open(item, 'about');
                return;
            }
            ExpaduPlaceSheet.open(item, 'about');
        }
    });
})();
