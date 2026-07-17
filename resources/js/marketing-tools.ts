/**
 * The free tools on /tools/* — pure client-side calculators. Constants are
 * injected by the server from the same engines the app uses (#tool-data),
 * results render locally, and key inputs sync to the URL so results are
 * shareable. Nothing is sent anywhere.
 */

const euro = (value: number): string =>
    value.toLocaleString('en-DE', {
        style: 'currency',
        currency: 'EUR',
        maximumFractionDigits: value % 1 === 0 ? 0 : 2,
    });

const euroWhole = (value: number): string => euro(Math.round(value));

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

/** Wire a chips-row: keeps aria-pressed in sync, calls back with data-v. */
const bindChips = (
    container: HTMLElement,
    onPick: (value: string, button: HTMLButtonElement) => void,
): void => {
    container.querySelectorAll('button').forEach((button) => {
        button.addEventListener('click', () => {
            container
                .querySelectorAll('button')
                .forEach((other) =>
                    other.setAttribute(
                        'aria-pressed',
                        String(other === button),
                    ),
                );
            onPick(button.dataset.v ?? '', button);
        });
    });
};

const pickedChip = (container: HTMLElement): string =>
    (
        container.querySelector(
            'button[aria-pressed="true"]',
        ) as HTMLButtonElement | null
    )?.dataset.v ?? '';

const setChip = (container: HTMLElement, value: string): void => {
    container.querySelectorAll('button').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.v === value));
    });
};

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
    const subsidy = document.getElementById('dt-subsidy') as HTMLInputElement;
    const fareChips = document.getElementById('dt-fare') as HTMLElement;

    const render = (): void => {
        const tripsPerWeek = Math.max(0, Number(trips.value) || 0);
        const fareKey = pickedChip(fareChips) || '1b';
        const farePrice = data.fares[fareKey] ?? data.fares['1b'];
        const employerEur = Math.min(
            data.dticket,
            Math.max(0, Number(subsidy.value) || 0),
        );

        (document.getElementById('dt-tripsOut') as HTMLElement).textContent =
            `${tripsPerWeek} trips`;
        (document.getElementById('dt-subOut') as HTMLElement).textContent =
            euroWhole(employerEur);

        // 4.33 weeks per average month; eezy caps pay-as-you-go.
        const rawSingles = tripsPerWeek * 4.33 * farePrice;
        const payAsYouGo = Math.min(rawSingles, data.eezyCap);
        const ticketNet = data.dticket - employerEur;
        const savedPerYear = (payAsYouGo - ticketNet) * 12;
        const scale = Math.max(payAsYouGo, ticketNet, 1);

        let verdict = 'Not yet — pay per ride.';
        let tone = 'warn';

        if (ticketNet <= payAsYouGo * 0.85 || rawSingles >= data.eezyCap) {
            verdict = 'Yes — get the ticket.';
            tone = 'good';
        } else if (ticketNet <= payAsYouGo * 1.15) {
            verdict = 'A toss-up — take it for the freedom.';
            tone = 'good';
        }

        const breakEvenTrips = Math.ceil(ticketNet / farePrice);
        const explain =
            savedPerYear > 0
                ? `That's about <b>${euroWhole(savedPerYear)} saved per year</b> — plus every bus and regional train in Germany.`
                : `Your break-even is <b>${breakEvenTrips} trips a month</b> (~${Math.ceil(breakEvenTrips / 4.33)} a week) at this fare.`;

        result.innerHTML =
            `<div class="verdict ${tone}">${verdict}</div>` +
            '<div class="cmp-bars">' +
            `<div class="cmp-bar a"><span><span>Pay-as-you-go (eezy-capped)</span><b>${euroWhole(payAsYouGo)}/mo</b></span><i><b style="width:${((payAsYouGo / scale) * 100).toFixed(1)}%"></b></i></div>` +
            `<div class="cmp-bar b"><span><span>Deutschlandticket${employerEur > 0 ? ' after employer share' : ''}</span><b>${euroWhole(ticketNet)}/mo</b></span><i><b style="width:${((ticketNet / scale) * 100).toFixed(1)}%"></b></i></div>` +
            '</div>' +
            `<p class="explain">${explain}</p>`;

        syncParam('trips', String(tripsPerWeek));
        syncParam('fare', fareKey);
        syncParam('subsidy', String(employerEur));
    };

    if (param('trips')) {
        trips.value = param('trips') ?? trips.value;
    }

    if (param('fare') && data.fares[param('fare') ?? '']) {
        setChip(fareChips, param('fare') ?? '1b');
    }

    if (param('subsidy')) {
        subsidy.value = param('subsidy') ?? subsidy.value;
    }

    bindChips(fareChips, render);
    form.addEventListener('input', render);
    render();
})();

/* ── Permanent-residency timeline ───────────────────────────────── */
(() => {
    const form = document.getElementById('tool-residency');
    const result = document.getElementById('ne-result');
    const data = readData<{
        tracks: Array<{
            key: string;
            months: number;
            label: string;
            note: string;
        }>;
        blueCardAltMonths: number;
        skilledDegreeMonths: number;
    }>();

    if (!form || !result || !data) {
        return;
    }

    const MONTH_NAMES = [
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
    const now = new Date();
    const maxYear = now.getFullYear();
    const minYear = maxYear - 20;

    const trackChips = document.getElementById('ne-track') as HTMLElement;
    const sinceInput = document.getElementById('ne-since') as HTMLInputElement;
    const yearVal = document.getElementById('ne-yearVal') as HTMLElement;
    const monthGrid = document.getElementById('ne-monthGrid') as HTMLElement;
    const trackNote = document.getElementById('ne-trackNote') as HTMLElement;

    let pickYear = maxYear - 2;

    const track = (): {
        key: string;
        months: number;
        label: string;
        note: string;
    } => {
        const key = pickedChip(trackChips) || 'skilled_worker';

        return data.tracks.find((entry) => entry.key === key) ?? data.tracks[0];
    };

    const effectiveMonths = (): number => {
        const current = track();

        if (current.key === 'blue_card') {
            return (document.getElementById('ne-b1') as HTMLInputElement)
                .checked
                ? current.months
                : data.blueCardAltMonths;
        }

        if (
            current.key === 'skilled_worker' &&
            (document.getElementById('ne-deg') as HTMLInputElement).checked
        ) {
            return data.skilledDegreeMonths;
        }

        return current.months;
    };

    const drawGrid = (): void => {
        yearVal.textContent = String(pickYear);
        (document.getElementById('ne-yearPrev') as HTMLButtonElement).disabled =
            pickYear <= minYear;
        (document.getElementById('ne-yearNext') as HTMLButtonElement).disabled =
            pickYear >= maxYear;
        monthGrid.innerHTML = '';

        MONTH_NAMES.forEach((name, index) => {
            const value = `${pickYear}-${String(index + 1).padStart(2, '0')}`;
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = name;
            button.disabled = pickYear === maxYear && index > now.getMonth();
            button.setAttribute(
                'aria-pressed',
                String(sinceInput.value === value),
            );
            button.addEventListener('click', () => {
                sinceInput.value = value;
                sinceInput.dispatchEvent(new Event('input', { bubbles: true }));
                drawGrid();
            });
            monthGrid.appendChild(button);
        });
    };

    const render = (): void => {
        const current = track();
        trackNote.textContent = current.note;
        (document.getElementById('ne-b1Field') as HTMLElement).hidden =
            current.key !== 'blue_card';
        (document.getElementById('ne-degField') as HTMLElement).hidden =
            current.key !== 'skilled_worker';

        if (!sinceInput.value) {
            result.innerHTML =
                '<p class="explain">Pick your permit and the month you\'ve held it since — your timeline appears here.</p>';

            return;
        }

        const months = effectiveMonths();
        const [year, month] = sinceInput.value.split('-').map(Number);
        const start = new Date(year, month - 1, 1);
        const end = new Date(year, month - 1 + months, 1);
        const total = end.getTime() - start.getTime();
        const done = Math.min(
            Math.max(now.getTime() - start.getTime(), 0),
            total,
        );
        const pct = total > 0 ? (done / total) * 100 : 100;
        const monthsLeft =
            (end.getFullYear() - now.getFullYear()) * 12 +
            (end.getMonth() - now.getMonth());
        const endLabel = end.toLocaleDateString('en-DE', {
            month: 'long',
            year: 'numeric',
        });
        const startLabel = start.toLocaleDateString('en-DE', {
            month: 'short',
            year: 'numeric',
        });

        const headline =
            monthsLeft <= 0
                ? `<div class="verdict good">You may already qualify 🎉</div><p class="explain">Your ${months}-month mark passed in <b>${endLabel}</b>.</p>`
                : `<div class="verdict good">Earliest application: <b>${endLabel}</b></div><p class="explain">That's in about <b>${monthsLeft} month${monthsLeft === 1 ? '' : 's'}</b> — you're <b>${Math.round(pct)}%</b> of the way there.</p>`;

        result.innerHTML =
            headline +
            `<div class="tl"><div class="tl-track"><div class="tl-fill" style="width:${Math.min(100, pct).toFixed(1)}%"></div><div class="tl-now" style="left:${Math.min(100, pct).toFixed(1)}%"></div></div>` +
            `<div class="tl-labels"><span>${startLabel}</span><span>${months} months</span><span>${endLabel}</span></div></div>`;

        syncParam('track', current.key);
        syncParam('since', sinceInput.value);
    };

    const restoredTrack = param('track');

    if (
        restoredTrack &&
        data.tracks.some((entry) => entry.key === restoredTrack)
    ) {
        setChip(trackChips, restoredTrack);
    }

    const restoredSince = param('since');

    if (restoredSince && /^\d{4}-\d{2}$/.test(restoredSince)) {
        sinceInput.value = restoredSince;
        pickYear = Math.min(
            maxYear,
            Math.max(minYear, Number(restoredSince.slice(0, 4))),
        );
    }

    (
        document.getElementById('ne-yearPrev') as HTMLButtonElement
    ).addEventListener('click', () => {
        pickYear = Math.max(minYear, pickYear - 1);
        drawGrid();
    });
    (
        document.getElementById('ne-yearNext') as HTMLButtonElement
    ).addEventListener('click', () => {
        pickYear = Math.min(maxYear, pickYear + 1);
        drawGrid();
    });

    bindChips(trackChips, render);
    form.addEventListener('input', render);
    drawGrid();
    render();
})();

/* ── Citizenship quiz ───────────────────────────────────────────── */
(() => {
    const quiz = document.getElementById('tool-citizenship');
    const result = document.getElementById('cz-result');
    const rules = readData<{
        standard_years: number;
        fast_years: number;
        spouse_residence_years: number;
    }>();

    if (!quiz || !result || !rules) {
        return;
    }

    const steps = Array.from(quiz.querySelectorAll<HTMLElement>('.quiz-step'));
    const back = document.getElementById('cz-back') as HTMLButtonElement;
    const progress = document.getElementById('cz-prog') as HTMLElement;
    const answers: Record<string, string> = {};
    let step = 0;

    const show = (index: number): void => {
        step = index;
        steps.forEach((el) =>
            el.classList.toggle('on', Number(el.dataset.s) === index),
        );
        progress.style.width = `${((index + 1) / steps.length) * 100}%`;
        back.hidden = index === 0;

        if (index < steps.length) {
            result.innerHTML = '';
        }
    };

    const verdict = (): void => {
        progress.style.width = '100%';
        const years = Number(answers.years);
        let trackLabel = 'the standard route (§10 StAG)';
        let need = rules.standard_years;

        if (answers.married === '1') {
            trackLabel = 'the spouse route (§9 StAG)';
            need = rules.spouse_residence_years;
        } else if (answers.german === 'c1') {
            trackLabel =
                'the fast track (§10 Abs. 3) — if the Behörde accepts your integration record';
            need = rules.fast_years;
        }

        const missing: string[] = [];

        if (answers.german === 'none') {
            missing.push('B1 German certificate');
        }

        if (answers.test === '0') {
            missing.push('naturalisation test');
        }

        if (answers.livelihood === '0') {
            missing.push('secured livelihood');
        }

        const shortYears = Math.max(0, need - years);
        const ok = shortYears === 0 && missing.length === 0;
        const headline = ok
            ? 'On paper, you can apply.'
            : shortYears === 0
              ? 'Residence time: done. A few boxes left.'
              : 'Not yet — but the path is clear.';

        result.innerHTML =
            `<div class="verdict ${ok ? 'good' : 'warn'}">${headline}</div>` +
            `<p class="explain">${
                shortYears === 0
                    ? `Your residence time already meets <b>${trackLabel}</b>.`
                    : `You reach <b>${trackLabel}</b> in about <b>${shortYears} more year${shortYears === 1 ? '' : 's'}</b> (${need} total).`
            }</p>` +
            (missing.length
                ? `<p class="explain">Still needed: <b>${missing.join('</b> · <b>')}</b>.</p>`
                : '') +
            '<p class="explain">Since the 2024 reform you generally <b>keep your original citizenship</b>.</p>' +
            '<p class="explain"><button type="button" class="quiz-back" id="cz-restart" style="margin-top:4px">↺ start over</button></p>';

        (
            document.getElementById('cz-restart') as HTMLButtonElement
        ).addEventListener('click', () => {
            Object.keys(answers).forEach((key) => delete answers[key]);
            show(0);
        });
    };

    quiz.querySelectorAll<HTMLButtonElement>('.quiz-opts button').forEach(
        (button) => {
            button.addEventListener('click', () => {
                answers[button.dataset.k ?? ''] = button.dataset.v ?? '';

                if (step < steps.length - 1) {
                    show(step + 1);
                } else {
                    verdict();
                }
            });
        },
    );

    back.addEventListener('click', () => show(Math.max(0, step - 1)));
})();

/* ── Netto-brutto (full 2026 wage-tax engine) ───────────────────── */
type LohnConfig = {
    tariff: {
        basic_allowance: number;
        zone2_end: number;
        zone2: { a: number; b: number };
        zone3_end: number;
        zone3: { a: number; b: number; c: number };
        zone4_end: number;
        zone4: { rate: number; c: number };
        zone5: { rate: number; c: number };
    };
    soli: { free_limit_single: number; rate: number; mitigation_rate: number };
    social: {
        bbg_health_year: number;
        bbg_pension_year: number;
        health_general: number;
        health_zusatz_avg: number;
        care_total: number;
        care_childless_surcharge: number;
        care_child_discount: number;
        care_saxony_shift: number;
        pension_total: number;
        unemployment_total: number;
    };
    allowances: { employee_lump_sum: number; special_expenses: number };
    church_rates: { default: number; by_bw: number };
    wage_tax: {
        kv_reduced_rate: number;
        v56: { w1: number; w2: number; w3: number };
        single_parent_relief: number;
        child_allowance_full: number;
        vsp_min_rate: number;
        vsp_min_cap: number;
        vsp_min_cap_iii: number;
    };
};

(() => {
    const form = document.getElementById('tool-netto');
    const result = document.getElementById('nb-result');
    const config = readData<LohnConfig>();

    if (!form || !result || !config) {
        return;
    }

    const CHURCH_8 = new Set(['BW', 'BY']);
    const CLASS_HINTS: Record<string, string> = {
        '1': 'Single, divorced or widowed — the default class.',
        '2': `Single parent — includes the €${config.wage_tax.single_parent_relief.toLocaleString('en-DE')} relief amount (§24b).`,
        '3': 'Married, the (much) higher earner — spouse takes V. Splitting tariff.',
        '4': 'Married, both earning similarly — each taxed like class I.',
        '5': 'Married, the lower earner — the allowances sit with your spouse in III.',
        '6': 'Second job — no allowances at all, every euro is taxed.',
    };

    /** §32a income-tax tariff on an annual zvE (floored to whole euros). */
    const tarif = (zvE: number): number => {
        const x = Math.floor(zvE);
        const t = config.tariff;

        if (x <= t.basic_allowance) {
            return 0;
        }

        if (x <= t.zone2_end) {
            const y = (x - t.basic_allowance) / 1e4;

            return Math.floor((t.zone2.a * y + t.zone2.b) * y);
        }

        if (x <= t.zone3_end) {
            const z = (x - t.zone2_end) / 1e4;

            return Math.floor((t.zone3.a * z + t.zone3.b) * z + t.zone3.c);
        }

        if (x <= t.zone4_end) {
            return Math.floor(t.zone4.rate * x - t.zone4.c);
        }

        return Math.floor(t.zone5.rate * x - t.zone5.c);
    };

    /**
     * Annual wage tax by class — §39b(2): splitting for III, the doubled-
     * difference formula with 14%-floor and 42%-corridor for V/VI
     * (cross-checked to the cent against the official BMF calculator).
     */
    const taxByClass = (taxable: number, taxClass: number): number => {
        const x = Math.floor(Math.max(0, taxable));

        if (taxClass === 3) {
            return 2 * tarif(Math.floor(x / 2));
        }

        if (taxClass === 5 || taxClass === 6) {
            const { w1, w2, w3 } = config.wage_tax.v56;
            const rate4 = config.tariff.zone4.rate;
            const rate5 = config.tariff.zone5.rate;
            const doubled = (zx: number): number => {
                const floored = Math.floor(zx);

                return Math.max(
                    2 * (tarif(floored * 1.25) - tarif(floored * 0.75)),
                    Math.floor(floored * 0.14),
                );
            };

            if (x > w2) {
                let tax = doubled(w2) + (x - w2) * rate4;

                if (x > w3) {
                    tax += (x - w3) * (rate5 - rate4);
                }

                return Math.floor(tax);
            }

            let tax = doubled(x);

            if (x > w1) {
                tax = Math.min(tax, doubled(w1) + Math.floor((x - w1) * rate4));
            }

            return tax;
        }

        return tarif(x);
    };

    const el = <T extends HTMLElement>(id: string): T =>
        document.getElementById(id) as T;
    const num = (id: string): number =>
        Math.max(0, Number(el<HTMLInputElement>(id).value) || 0);
    const checked = (id: string): boolean => el<HTMLInputElement>(id).checked;

    const periodChips = el<HTMLElement>('nb-period');
    const classChips = el<HTMLElement>('nb-class');
    const kvChips = el<HTMLElement>('nb-kvType');
    const gross = el<HTMLInputElement>('nb-gross');
    const slider = el<HTMLInputElement>('nb-grossR');
    let view: 'm' | 'y' = 'm';

    const row = (
        label: string,
        yearly: number,
        share: number,
        kind: 'tax' | 'si',
        divider: number,
    ): string =>
        `<div class="nb-row"><span>${label}</span><b>−${euroWhole(yearly / divider)}</b><i class="nb-bar"><i class="${kind}" style="width:${Math.min(100, share * 100).toFixed(1)}%"></i></i></div>`;

    const render = (): void => {
        const period = pickedChip(periodChips) || 'm';
        const taxClass = Number(pickedChip(classChips) || '1');
        const kvType = pickedChip(kvChips) || 'g';
        const land = el<HTMLSelectElement>('nb-land').value;
        const saxony = land === 'SN';
        const hasKids = checked('nb-kids');
        const church = checked('nb-church');
        const social = config.social;

        el<HTMLElement>('nb-kidsFields').hidden = !hasKids;
        el<HTMLElement>('nb-gkvFields').hidden = kvType !== 'g';
        el<HTMLElement>('nb-pkvFields').hidden = kvType !== 'p';
        el<HTMLElement>('nb-classHint').textContent =
            CLASS_HINTS[String(taxClass)];

        const cashYear =
            period === 'm' ? num('nb-gross') * 12 : num('nb-gross');
        const benefitYear = num('nb-benefit') * 12;
        const totalYear = cashYear + benefitYear;

        if (totalYear <= 0) {
            result.innerHTML =
                '<p class="explain">Enter your gross salary — the full breakdown appears here.</p>';

            return;
        }

        const zusatz = num('nb-zusatz') / 100;
        const pensionOn = checked('nb-rvOn');
        const unemploymentOn = checked('nb-avOn');
        const kvBase = Math.min(totalYear, social.bbg_health_year);
        const rvBase = Math.min(totalYear, social.bbg_pension_year);

        // Employee social insurance
        let kvEmployee = 0;
        let pvEmployee = 0;
        let pkvOutOfPocket = 0;
        let employerHealth = 0;

        if (kvType === 'g') {
            kvEmployee = (kvBase * (social.health_general + zusatz)) / 2;
            employerHealth =
                (kvBase * (social.health_general + zusatz)) / 2 +
                kvBase *
                    (social.care_total / 2 -
                        (saxony ? social.care_saxony_shift : 0));
            const pvRate =
                social.care_total / 2 +
                (saxony ? social.care_saxony_shift : 0) +
                (hasKids ? 0 : social.care_childless_surcharge) -
                (hasKids
                    ? social.care_child_discount *
                      Math.min(Math.max(num('nb-kidsU25') - 1, 0), 4)
                    : 0);
            pvEmployee = kvBase * Math.max(0, pvRate);
        } else {
            const premiumMonth = num('nb-prem');
            const capMonth =
                (social.bbg_health_year / 12) *
                    ((social.health_general + zusatz) / 2) +
                (social.bbg_health_year / 12) *
                    (social.care_total / 2 -
                        (saxony ? social.care_saxony_shift : 0));
            const subsidyMonth = checked('nb-subs')
                ? Math.min(premiumMonth / 2, capMonth)
                : 0;
            employerHealth = subsidyMonth * 12;
            pkvOutOfPocket = (premiumMonth - subsidyMonth) * 12;
        }

        const rvEmployee = pensionOn ? (rvBase * social.pension_total) / 2 : 0;
        const avEmployee = unemploymentOn
            ? (rvBase * social.unemployment_total) / 2
            : 0;

        // Vorsorgepauschale (§39b(2) S.5 Nr.3): the health part uses the
        // ermäßigter Satz (14.0% → employee 7.0% + Zusatz/2), not the 7.3%
        // actually withheld — confirmed against the official BMF calculator.
        const wt = config.wage_tax;
        const minVsp = Math.min(
            totalYear * wt.vsp_min_rate,
            taxClass === 3 ? wt.vsp_min_cap_iii : wt.vsp_min_cap,
        );
        const vspHealth =
            kvType === 'g'
                ? Math.max(
                      (kvBase * (wt.kv_reduced_rate + zusatz)) / 2 + pvEmployee,
                      minVsp,
                  )
                : Math.max(pkvOutOfPocket, minVsp);
        const vsp =
            (pensionOn ? (rvBase * social.pension_total) / 2 : 0) + vspHealth;

        const lumpSums =
            taxClass === 6
                ? 0
                : config.allowances.employee_lump_sum +
                  config.allowances.special_expenses;
        const zvE =
            totalYear -
            lumpSums -
            vsp -
            (taxClass === 2 ? wt.single_parent_relief : 0) -
            num('nb-freib');
        const tax = taxByClass(zvE, taxClass);

        // Church tax + Soli run on the tax recomputed with child allowances
        // (counters only exist for classes I–IV).
        const kfbYear =
            hasKids && taxClass <= 4
                ? Number(el<HTMLSelectElement>('nb-kfb').value) *
                  wt.child_allowance_full
                : 0;
        const taxAnnex =
            kfbYear > 0 ? taxByClass(zvE - kfbYear, taxClass) : tax;
        const soliLimit =
            config.soli.free_limit_single * (taxClass === 3 ? 2 : 1);
        const soli =
            taxAnnex <= soliLimit
                ? 0
                : Math.min(
                      taxAnnex * config.soli.rate,
                      (taxAnnex - soliLimit) * config.soli.mitigation_rate,
                  );
        const churchTax = church
            ? taxAnnex *
              (CHURCH_8.has(land)
                  ? config.church_rates.by_bw
                  : config.church_rates.default)
            : 0;

        const socialSum = kvEmployee + pvEmployee + rvEmployee + avEmployee;
        const taxes = tax + soli + churchTax;
        const netYear =
            totalYear - socialSum - taxes - pkvOutOfPocket - benefitYear;
        const employerYear =
            totalYear +
            employerHealth +
            (pensionOn ? (rvBase * social.pension_total) / 2 : 0) +
            (unemploymentOn ? (rvBase * social.unemployment_total) / 2 : 0);

        const divider = view === 'm' ? 12 : 1;
        const unit = view === 'm' ? ' per month' : ' per year';
        const alternate =
            view === 'm'
                ? `${euroWhole(netYear)} a year`
                : `${euroWhole(netYear / 12)} a month`;

        result.innerHTML =
            `<div class="nb-head"><div class="verdict good">${euroWhole(netYear / divider)} net${unit}</div>` +
            `<div class="mini-chips" id="nb-view"><button type="button" data-v="m" aria-pressed="${view === 'm'}">monthly</button><button type="button" data-v="y" aria-pressed="${view === 'y'}">yearly</button></div></div>` +
            `<p class="explain">${alternate} — you keep <b>${Math.round((netYear / totalYear) * 100)}%</b> of your gross.</p>` +
            `<div class="stat-chips"><span>taxes ${((taxes / totalYear) * 100).toFixed(1)}%</span><span>social security ${(((socialSum + pkvOutOfPocket) / totalYear) * 100).toFixed(1)}%</span>${benefitYear > 0 ? `<span>incl. ${euroWhole(benefitYear / divider)} non-cash</span>` : ''}</div>` +
            '<div class="nb-group">Taxes</div><div class="nb-rows">' +
            row('Income tax', tax, tax / totalYear, 'tax', divider) +
            (soli > 0
                ? row(
                      'Solidarity surcharge',
                      soli,
                      soli / totalYear,
                      'tax',
                      divider,
                  )
                : '') +
            (churchTax > 0
                ? row(
                      `Church tax (${CHURCH_8.has(land) ? '8' : '9'}%)`,
                      churchTax,
                      churchTax / totalYear,
                      'tax',
                      divider,
                  )
                : '') +
            '</div><div class="nb-group">Social security</div><div class="nb-rows">' +
            (kvType === 'g'
                ? row(
                      'Health insurance',
                      kvEmployee,
                      kvEmployee / totalYear,
                      'si',
                      divider,
                  ) +
                  row(
                      'Care insurance',
                      pvEmployee,
                      pvEmployee / totalYear,
                      'si',
                      divider,
                  )
                : row(
                      'Private health (after subsidy)',
                      pkvOutOfPocket,
                      pkvOutOfPocket / totalYear,
                      'si',
                      divider,
                  )) +
            (rvEmployee > 0
                ? row(
                      'Pension',
                      rvEmployee,
                      rvEmployee / totalYear,
                      'si',
                      divider,
                  )
                : '') +
            (avEmployee > 0
                ? row(
                      'Unemployment',
                      avEmployee,
                      avEmployee / totalYear,
                      'si',
                      divider,
                  )
                : '') +
            '</div>' +
            `<p class="explain emp-line">Your employer actually pays <b>${euroWhole(employerYear / divider)}${unit}</b> — ${euroWhole((employerYear - totalYear) / divider)} in contributions on top of your gross.</p>`;

        document
            .querySelectorAll<HTMLButtonElement>('#nb-view button')
            .forEach((button) => {
                button.addEventListener('click', () => {
                    view = button.dataset.v === 'y' ? 'y' : 'm';
                    render();
                });
            });

        syncParam('gross', String(num('nb-gross')));
        syncParam('period', period);
        syncParam('cls', String(taxClass));
        syncParam('land', land);
    };

    bindChips(periodChips, (value) => {
        if (value === 'y') {
            gross.value = String(Math.round(Number(gross.value) * 12));
            slider.min = '12000';
            slider.max = '180000';
            slider.step = '500';
        } else {
            gross.value = String(
                Math.round(Number(gross.value) / 12 / 50) * 50,
            );
            slider.min = '1000';
            slider.max = '15000';
            slider.step = '50';
        }

        slider.value = gross.value;
        render();
    });
    bindChips(classChips, render);
    bindChips(kvChips, render);

    slider.addEventListener('input', () => {
        gross.value = slider.value;
    });
    gross.addEventListener('input', () => {
        slider.value = gross.value;
    });

    if (param('period') === 'y') {
        setChip(periodChips, 'y');
        slider.min = '12000';
        slider.max = '180000';
        slider.step = '500';
        gross.value = '48000';
    }

    if (param('gross') && Number(param('gross')) > 0) {
        gross.value = String(Number(param('gross')));
        slider.value = gross.value;
    }

    if (param('cls') && CLASS_HINTS[param('cls') ?? '']) {
        setChip(classChips, param('cls') ?? '1');
    }

    if (param('land')) {
        const landSelect = el<HTMLSelectElement>('nb-land');

        if (
            [...landSelect.options].some(
                (option) => option.value === param('land'),
            )
        ) {
            landSelect.value = param('land') ?? 'NW';
        }
    }

    form.addEventListener('input', render);
    render();
})();
