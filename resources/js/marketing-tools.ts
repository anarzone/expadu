/**
 * The free tools on /tools/* — pure client-side calculators. Constants are
 * injected by the server from the same engines the app uses (#tool-data),
 * results render locally, and inputs sync to the URL so results are
 * shareable. Nothing is sent anywhere.
 */

const euro = (value: number): string =>
    value.toLocaleString('en-DE', {
        style: 'currency',
        currency: 'EUR',
        maximumFractionDigits: value % 1 === 0 ? 0 : 2,
    });

const readData = <T>(): T | null => {
    const el = document.getElementById('tool-data');

    if (!el) {
        return null;
    }

    try {
        return JSON.parse(el.textContent ?? '') as T;
    } catch {
        return null;
    }
};

const syncParam = (key: string, value: string): void => {
    const url = new URL(window.location.href);
    url.searchParams.set(key, value);
    history.replaceState(null, '', url);
};

const param = (key: string): string | null =>
    new URL(window.location.href).searchParams.get(key);

/* ── Deutschlandticket break-even ───────────────────────────────── */
(() => {
    const form = document.getElementById('tool-dticket');
    const result = document.getElementById('dt-result');
    const data = readData<{
        fares: Record<string, number>;
        dticket: number;
        jobticket: number;
        eezyCap: number;
    }>();

    if (!form || !result || !data) {
        return;
    }

    const trips = document.getElementById('dt-trips') as HTMLInputElement;
    const fare = document.getElementById('dt-fare') as HTMLSelectElement;
    const subsidy = document.getElementById('dt-subsidy') as HTMLInputElement;

    const restore = (): void => {
        if (param('trips')) {
            trips.value = param('trips') ?? trips.value;
        }

        if (param('fare') && data.fares[param('fare') ?? '']) {
            fare.value = param('fare') ?? fare.value;
        }

        if (param('subsidy')) {
            subsidy.value = param('subsidy') ?? subsidy.value;
        }
    };

    const render = (): void => {
        const tripsPerWeek = Math.max(0, Number(trips.value) || 0);
        const farePrice = data.fares[fare.value] ?? data.fares['1b'];
        const employerEur = Math.min(
            data.dticket,
            Math.max(0, Number(subsidy.value) || 0),
        );

        // 4.33 weeks per average month; eezy caps pay-as-you-go at €63.
        const rawSingles = tripsPerWeek * 4.33 * farePrice;
        const paygEur = Math.min(rawSingles, data.eezyCap);
        const ticketEur = data.dticket - employerEur;
        const breakEvenTrips = Math.ceil(ticketEur / farePrice);
        const savingsYear = (paygEur - ticketEur) * 12;

        let verdict: string;
        let tone: 'good' | 'warn';

        if (ticketEur <= paygEur * 0.85 || rawSingles >= data.eezyCap) {
            verdict = 'Yes — get the ticket.';
            tone = 'good';
        } else if (ticketEur <= paygEur * 1.15) {
            verdict = 'A toss-up — take it for the freedom.';
            tone = 'good';
        } else {
            verdict = 'Not yet — pay per ride.';
            tone = 'warn';
        }

        result.innerHTML =
            `<div class="verdict ${tone}">${verdict}</div>` +
            `<div class="numbers">` +
            `<div><span>Pay-as-you-go</span><b>${euro(Math.round(paygEur))}/mo</b></div>` +
            `<div><span>Deutschlandticket${employerEur > 0 ? ' (after employer share)' : ''}</span><b>${euro(ticketEur)}/mo</b></div>` +
            `</div>` +
            `<p class="explain">${
                savingsYear > 0
                    ? `That's about <b>${euro(Math.round(savingsYear))} saved per year</b> — plus every bus and regional train in Germany.`
                    : `Your break-even is <b>${breakEvenTrips} trips a month</b> (~${Math.ceil(breakEvenTrips / 4.33)} a week) at this fare.`
            }</p>`;

        syncParam('trips', trips.value);
        syncParam('fare', fare.value);
        syncParam('subsidy', subsidy.value);
    };

    restore();
    form.addEventListener('input', render);
    render();
})();

/* ── Permanent-residency timeline ───────────────────────────────── */
(() => {
    const form = document.getElementById('tool-residency');
    const result = document.getElementById('ne-result');
    const data = readData<{
        tracks: { key: string; months: number; label: string; note: string }[];
        blueCardAltMonths: number;
        skilledDegreeMonths: number;
    }>();

    if (!form || !result || !data) {
        return;
    }

    const track = document.getElementById('ne-track') as HTMLSelectElement;
    const since = document.getElementById('ne-since') as HTMLInputElement;
    const b1 = document.getElementById('ne-b1') as HTMLInputElement;
    const degree = document.getElementById('ne-degree') as HTMLInputElement;
    const b1Field = document.getElementById('ne-b1-field') as HTMLElement;
    const degreeField = document.getElementById(
        'ne-degree-field',
    ) as HTMLElement;

    /* Custom month picker — the native month input pops browser-styled UI
       that clashes with the brand. Chips write YYYY-MM into the hidden
       #ne-since input, keeping the contract with render() and the URL. */
    const yearEl = document.getElementById('ne-year') as HTMLElement;
    const yPrev = document.getElementById('ne-yprev') as HTMLButtonElement;
    const yNext = document.getElementById('ne-ynext') as HTMLButtonElement;
    const monthGrid = document.getElementById('ne-months') as HTMLElement;
    const MONTHS = [
        'Jan',
        'Feb',
        'Mar',
        'Apr',
        'May',
        'Jun',
        'Jul',
        'Aug',
        'Sep',
        'Oct',
        'Nov',
        'Dec',
    ];
    const nowYear = new Date().getFullYear();
    const nowMonth = new Date().getMonth();
    const minYear = nowYear - 20;
    let pickYear = nowYear;

    const renderPicker = (): void => {
        yearEl.textContent = String(pickYear);
        yPrev.disabled = pickYear <= minYear;
        yNext.disabled = pickYear >= nowYear;
        monthGrid.innerHTML = '';
        MONTHS.forEach((name, i) => {
            const value = pickYear + '-' + String(i + 1).padStart(2, '0');
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.textContent = name;
            chip.disabled = pickYear === nowYear && i > nowMonth;
            chip.setAttribute('aria-pressed', String(since.value === value));
            chip.addEventListener('click', () => {
                since.value = value;
                since.dispatchEvent(new Event('input', { bubbles: true }));
                renderPicker();
            });
            monthGrid.appendChild(chip);
        });
    };

    const syncPicker = (): void => {
        const parts = /^(\d{4})-\d{2}$/.exec(since.value);

        if (parts) {
            pickYear = Math.min(nowYear, Math.max(minYear, Number(parts[1])));
        }

        renderPicker();
    };

    yPrev.addEventListener('click', () => {
        pickYear = Math.max(minYear, pickYear - 1);
        renderPicker();
    });
    yNext.addEventListener('click', () => {
        pickYear = Math.min(nowYear, pickYear + 1);
        renderPicker();
    });

    const restore = (): void => {
        if (
            param('track') &&
            data.tracks.some((t) => t.key === param('track'))
        ) {
            track.value = param('track') ?? track.value;
        }

        if (param('since')) {
            since.value = param('since') ?? '';
        }
    };

    const monthsForSelection = (): number => {
        const selected = data.tracks.find((t) => t.key === track.value);

        if (!selected) {
            return 60;
        }

        if (selected.key === 'blue_card' && !b1.checked) {
            return data.blueCardAltMonths;
        }

        if (selected.key === 'skilled_worker' && degree.checked) {
            return data.skilledDegreeMonths;
        }

        return selected.months;
    };

    const render = (): void => {
        b1Field.hidden = track.value !== 'blue_card';
        degreeField.hidden = track.value !== 'skilled_worker';

        const selected = data.tracks.find((t) => t.key === track.value);

        if (!since.value || !selected) {
            result.innerHTML = `<p class="explain">Pick your permit and the month you've held it since — the earliest date appears here.</p>`;

            return;
        }

        const months = monthsForSelection();
        const [year, month] = since.value.split('-').map(Number);
        const earliest = new Date(year, month - 1 + months, 1);
        const now = new Date();
        const monthsLeft =
            (earliest.getFullYear() - now.getFullYear()) * 12 +
            (earliest.getMonth() - now.getMonth());
        const label = earliest.toLocaleDateString('en-DE', {
            month: 'long',
            year: 'numeric',
        });

        result.innerHTML =
            monthsLeft <= 0
                ? `<div class="verdict good">You may already qualify.</div>` +
                  `<p class="explain">Your ${months}-month mark passed in <b>${label}</b>. ${selected.note}</p>`
                : `<div class="verdict good">Earliest application: <b>${label}</b></div>` +
                  `<p class="explain">That's in about <b>${monthsLeft} month${monthsLeft === 1 ? '' : 's'}</b>. ${selected.note}</p>`;

        syncParam('track', track.value);
        syncParam('since', since.value);
    };

    restore();
    syncPicker();
    form.addEventListener('input', render);
    render();
})();

/* ── Citizenship quiz ───────────────────────────────────────────── */
(() => {
    const form = document.getElementById('tool-citizenship');
    const result = document.getElementById('cz-result');
    const data = readData<{
        standard_years: number;
        fast_years: number;
        spouse_residence_years: number;
    }>();

    if (!form || !result || !data) {
        return;
    }

    const years = document.getElementById('cz-years') as HTMLInputElement;
    const married = document.getElementById('cz-married') as HTMLInputElement;
    const german = document.getElementById('cz-german') as HTMLSelectElement;
    const livelihood = document.getElementById(
        'cz-livelihood',
    ) as HTMLInputElement;
    const test = document.getElementById('cz-test') as HTMLInputElement;

    const render = (): void => {
        const lived = Math.max(0, Number(years.value) || 0);

        let track: string;
        let yearsNeeded: number;

        if (married.checked) {
            track = 'the spouse route (§9 StAG)';
            yearsNeeded = data.spouse_residence_years;
        } else if (german.value === 'c1') {
            track =
                'the fast track (§10 Abs. 3 StAG) — if the Behörde accepts your integration achievements';
            yearsNeeded = data.fast_years;
        } else {
            track = 'the standard route (§10 StAG)';
            yearsNeeded = data.standard_years;
        }

        const missing: string[] = [];

        if (german.value === 'none') {
            missing.push('B1 German certificate');
        }

        if (!test.checked) {
            missing.push('naturalisation test');
        }

        if (!livelihood.checked) {
            missing.push('secured livelihood (no basic benefits)');
        }

        const yearsShort = Math.max(0, yearsNeeded - lived);
        const residenceLine =
            yearsShort === 0
                ? `Your residence time already meets <b>${track}</b>.`
                : `You reach <b>${track}</b> in about <b>${yearsShort} more year${yearsShort === 1 ? '' : 's'}</b> (${yearsNeeded} total).`;

        const tone: 'good' | 'warn' =
            yearsShort === 0 && missing.length === 0 ? 'good' : 'warn';
        const verdict =
            tone === 'good'
                ? 'On paper, you can apply.'
                : yearsShort === 0
                  ? 'Residence time: done. A few boxes left.'
                  : 'Not yet — but the path is clear.';

        result.innerHTML =
            `<div class="verdict ${tone}">${verdict}</div>` +
            `<p class="explain">${residenceLine}</p>` +
            (missing.length > 0
                ? `<p class="explain">Still needed: <b>${missing.join('</b> · <b>')}</b>.</p>`
                : '') +
            `<p class="explain">Since the 2024 reform you generally <b>keep your original citizenship</b>.</p>`;
    };

    form.addEventListener('input', render);
    render();
})();
