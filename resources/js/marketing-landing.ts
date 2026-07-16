type RecommendationVariant = {
    title: string;
    meta: string;
    why: string;
};

type Recommendation = RecommendationVariant & {
    band: string;
    outdoor?: boolean;
    rain?: RecommendationVariant;
    alternative?: RecommendationVariant;
};

type Scenario = {
    label: string;
    cards: Recommendation[];
};

type Persona = {
    chips: string[];
    task: {
        title: string;
        deadline: string;
        meta: string;
        documents: string[];
        next: string;
    };
};

type DemoData = {
    scenarios: Record<string, Scenario>;
    personas: Record<string, Persona>;
};

const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

const getDemoData = (): DemoData | null => {
    const dataElement = document.getElementById('marketing-demo-data');

    if (!dataElement) {
        return null;
    }

    try {
        return JSON.parse(dataElement.textContent ?? '') as DemoData;
    } catch {
        return null;
    }
};

const demoData = getDemoData();

/* ── Composer and editable recommendations ────────────────────── */
(() => {
    if (!demoData) {
        return;
    }

    const personaRow = document.getElementById('personaRow');
    const chipRow = document.getElementById('chipRow');
    const prompt = document.getElementById('dPrompt');
    const plan = document.getElementById('plan');
    const clearButton = document.getElementById('wxSun');
    const rainButton = document.getElementById('wxRain');

    if (
        !personaRow ||
        !chipRow ||
        !prompt ||
        !plan ||
        !(clearButton instanceof HTMLButtonElement) ||
        !(rainButton instanceof HTMLButtonElement)
    ) {
        return;
    }

    let currentPersona = 'Employee';
    let currentScenario =
        demoData.personas[currentPersona]?.chips[0] ?? 'arrived';
    let rainIsComing = false;
    let timers: number[] = [];

    const shouldAnimateInstantly = (): boolean =>
        reducedMotion || document.visibilityState === 'hidden';

    const clearTimers = (): void => {
        timers.forEach((timer) => window.clearTimeout(timer));
        timers = [];
    };

    const schedule = (callback: () => void, delay: number): void => {
        timers.push(
            window.setTimeout(callback, shouldAnimateInstantly() ? 0 : delay),
        );
    };

    const createOperation = (
        className: string,
        label: string,
        title: string,
    ): HTMLButtonElement => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = label;
        button.title = title;

        return button;
    };

    const renderRecommendation = (
        recommendation: Recommendation,
    ): HTMLElement => {
        const active =
            rainIsComing && recommendation.outdoor && recommendation.rain
                ? recommendation.rain
                : recommendation;
        const card = document.createElement('article');
        card.className = 'pcard';

        const band = document.createElement('div');
        band.className = 'band';
        band.textContent = recommendation.band;

        const titleRow = document.createElement('div');
        titleRow.className = 't';
        const title = document.createElement('span');
        title.textContent = active.title;

        const operations = document.createElement('span');
        operations.className = 'ops';
        operations.setAttribute('aria-label', 'Edit this recommendation');
        const lock = createOperation('lock', '📌', 'Lock recommendation');
        const swap = createOperation('swap', '↻', 'Swap recommendation');
        const remove = createOperation('remove', '✕', 'Remove recommendation');
        operations.append(lock, swap, remove);
        titleRow.append(title, operations);

        const meta = document.createElement('div');
        meta.className = 'm';
        meta.textContent = active.meta;
        const reason = document.createElement('div');
        reason.className = 'why';
        reason.textContent = active.why;

        if (active === recommendation.rain) {
            const tag = document.createElement('span');
            tag.className = 'tag';
            tag.textContent = active.why;
            reason.replaceChildren(tag);
        }

        lock.addEventListener('click', () => {
            card.classList.toggle('locked');
        });

        remove.addEventListener('click', () => {
            card.classList.add('gone');
        });

        swap.addEventListener('click', () => {
            if (
                card.classList.contains('locked') ||
                !recommendation.alternative
            ) {
                return;
            }

            const alternative = recommendation.alternative;
            recommendation.alternative = {
                title: title.textContent ?? active.title,
                meta: meta.textContent ?? active.meta,
                why: reason.textContent ?? active.why,
            };
            card.classList.add('swapping');

            window.setTimeout(
                () => {
                    title.textContent = alternative.title;
                    meta.textContent = alternative.meta;
                    reason.textContent = alternative.why;
                    card.classList.remove('swapping');
                },
                reducedMotion ? 0 : 280,
            );
        });

        card.append(band, titleRow, meta, reason);

        return card;
    };

    const buildPlan = (scenario: Scenario): void => {
        scenario.cards.forEach((recommendation, index) => {
            schedule(
                () => {
                    if (index > 0) {
                        const connection = document.createElement('div');
                        connection.className = 'conn';
                        connection.textContent = `🚶 ${[6, 4, 8][index - 1] ?? 5} min · chained for you`;
                        plan.append(connection);
                        requestAnimationFrame(() =>
                            connection.classList.add('in'),
                        );
                    }

                    const card = renderRecommendation(recommendation);
                    plan.append(card);
                    requestAnimationFrame(() => card.classList.add('in'));
                },
                220 + index * 300,
            );
        });
    };

    const playScenario = (scenarioKey: string): void => {
        const scenario = demoData.scenarios[scenarioKey];

        if (!scenario) {
            return;
        }

        currentScenario = scenarioKey;
        clearTimers();
        plan.replaceChildren();
        prompt.textContent = '';
        prompt.classList.add('typing');
        let characterIndex = 0;

        const typePrompt = (): void => {
            if (shouldAnimateInstantly()) {
                prompt.textContent = scenario.label;
                prompt.classList.remove('typing');
                buildPlan(scenario);

                return;
            }

            if (characterIndex < scenario.label.length) {
                prompt.textContent += scenario.label[characterIndex++];
                timers.push(
                    window.setTimeout(typePrompt, 18 + Math.random() * 26),
                );

                return;
            }

            prompt.classList.remove('typing');
            schedule(() => buildPlan(scenario), 260);
        };

        typePrompt();
    };

    const renderChips = (): void => {
        chipRow.replaceChildren();
        const persona = demoData.personas[currentPersona];

        persona?.chips.forEach((scenarioKey) => {
            const scenario = demoData.scenarios[scenarioKey];

            if (!scenario) {
                return;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.scenario = scenarioKey;
            button.textContent = `“${scenario.label}”`;
            button.addEventListener('click', () => playScenario(scenarioKey));
            chipRow.append(button);
        });
    };

    personaRow
        .querySelectorAll<HTMLButtonElement>('button[data-persona]')
        .forEach((button) => {
            button.addEventListener('click', () => {
                const personaName = button.dataset.persona;

                if (!personaName || !demoData.personas[personaName]) {
                    return;
                }

                currentPersona = personaName;
                personaRow
                    .querySelectorAll<HTMLButtonElement>('button[data-persona]')
                    .forEach((candidate) => {
                        candidate.setAttribute(
                            'aria-pressed',
                            String(candidate === button),
                        );
                    });
                renderChips();
                document.dispatchEvent(
                    new CustomEvent('expadu:persona-changed', {
                        detail: currentPersona,
                    }),
                );
                playScenario(demoData.personas[currentPersona].chips[0]);
            });
        });

    clearButton.addEventListener('click', () => {
        rainIsComing = false;
        clearButton.setAttribute('aria-pressed', 'true');
        rainButton.setAttribute('aria-pressed', 'false');
        playScenario(currentScenario);
    });

    rainButton.addEventListener('click', () => {
        rainIsComing = true;
        clearButton.setAttribute('aria-pressed', 'false');
        rainButton.setAttribute('aria-pressed', 'true');
        playScenario(currentScenario);
    });

    renderChips();
    playScenario(currentScenario);
})();

/* ── Interactive paperwork checklist ───────────────────────────── */
(() => {
    if (!demoData) {
        return;
    }

    const title = document.getElementById('taskTitle');
    const deadline = document.getElementById('taskDl');
    const meta = document.getElementById('taskMeta');
    const documents = document.getElementById('docList');
    const progress = document.getElementById('progBar');
    const next = document.getElementById('nextTeaser');

    if (!title || !deadline || !meta || !documents || !progress || !next) {
        return;
    }

    const updateProgress = (persona: Persona): void => {
        const rows = Array.from(
            documents.querySelectorAll<HTMLElement>('.doc'),
        );
        const checked = rows.filter((row) =>
            row.classList.contains('on'),
        ).length;
        const percentage =
            rows.length === 0 ? 0 : (checked / rows.length) * 100;
        progress.style.width = `${percentage}%`;

        if (rows.length > 0 && checked === rows.length) {
            next.replaceChildren();
            next.append('✓ Ready for the next step. Next up: ');
            const strong = document.createElement('b');
            strong.textContent = persona.task.next;
            next.append(strong);
            next.classList.add('in');
        } else {
            next.classList.remove('in');
        }
    };

    const renderTask = (personaName: string): void => {
        const persona = demoData.personas[personaName];

        if (!persona) {
            return;
        }

        title.textContent = persona.task.title;
        deadline.textContent = persona.task.deadline;
        meta.textContent = persona.task.meta;
        documents.replaceChildren();
        next.classList.remove('in');

        persona.task.documents.forEach((documentName) => {
            const row = document.createElement('div');
            row.className = 'doc';
            row.setAttribute('role', 'checkbox');
            row.setAttribute('tabindex', '0');
            row.setAttribute('aria-checked', 'false');
            const box = document.createElement('i');
            row.append(box, documentName);

            const toggle = (): void => {
                row.classList.toggle('on');
                row.setAttribute(
                    'aria-checked',
                    String(row.classList.contains('on')),
                );
                updateProgress(persona);
            };

            row.addEventListener('click', toggle);
            row.addEventListener('keydown', (event) => {
                if (event.key === ' ' || event.key === 'Enter') {
                    event.preventDefault();
                    toggle();
                }
            });
            documents.append(row);
        });

        updateProgress(persona);
    };

    document.addEventListener('expadu:persona-changed', (event) => {
        if (event instanceof CustomEvent && typeof event.detail === 'string') {
            renderTask(event.detail);
        }
    });

    renderTask('Employee');
})();

/* ── Departures-board product proof ────────────────────────────── */
(() => {
    const board = document.getElementById('board');

    if (!board) {
        return;
    }

    type Departure = {
        line: string;
        color: string;
        destination: string;
        minutes: number;
        delay?: number;
    };

    const departures: Departure[] = [
        { line: '4', color: '#e97fb2', destination: 'Schlebusch', minutes: 2 },
        { line: '13', color: '#9ccc4e', destination: 'Sülzgürtel', minutes: 4 },
        {
            line: '18',
            color: '#7dc6e8',
            destination: 'Bonn Hbf',
            minutes: 7,
            delay: 3,
        },
        { line: 'S12', color: '#66aa44', destination: 'Hennef Bf', minutes: 9 },
        {
            line: '141',
            color: '#c5a3d9',
            destination: 'Bocklemünd',
            minutes: 12,
        },
    ];

    const createFlaps = (value: string): HTMLElement => {
        const flaps = document.createElement('span');
        flaps.className = 'flaps';

        Array.from(value).forEach((character) => {
            const flap = document.createElement('b');
            flap.textContent = character === ' ' ? '\u00a0' : character;
            flaps.append(flap);
        });

        return flaps;
    };

    const renderDepartures = (): void => {
        board.querySelectorAll('.brow').forEach((row) => row.remove());

        departures.forEach((departure) => {
            const row = document.createElement('div');
            row.className = `brow${departure.delay ? ' delayed' : ''}`;
            const line = document.createElement('span');
            line.className = 'lbadge';
            line.textContent = departure.line;
            line.style.backgroundColor = departure.color;

            const minutes = document.createElement('span');
            minutes.className = 'mins';
            const minuteValue = document.createElement('b');
            minuteValue.textContent = String(departure.minutes);
            const unit = document.createElement('span');
            unit.textContent = 'min';
            minutes.append(minuteValue, unit);

            if (departure.delay) {
                const delay = document.createElement('span');
                delay.className = 'late';
                delay.textContent = `+${departure.delay} ⓘ`;
                minutes.append(delay);
            }

            row.append(line, createFlaps(departure.destination), minutes);

            if (departure.delay) {
                const reroute = document.createElement('div');
                reroute.className = 'reroute';
                const strong = document.createElement('b');
                strong.textContent = 'Line 18 is late. ';
                reroute.append(
                    strong,
                    'Expadu shows an earlier alternative and checks whether your ticket covers it.',
                );
                row.append(reroute);
                row.tabIndex = 0;
                row.setAttribute('role', 'button');
                row.setAttribute('aria-expanded', 'false');

                const toggle = (): void => {
                    const isOpen = row.classList.toggle('open');
                    row.setAttribute('aria-expanded', String(isOpen));
                };

                row.addEventListener('click', toggle);
                row.addEventListener('keydown', (event) => {
                    if (event.key === ' ' || event.key === 'Enter') {
                        event.preventDefault();
                        toggle();
                    }
                });
            }

            board.append(row);
        });
    };

    renderDepartures();

    if (!reducedMotion) {
        window.setInterval(() => {
            const departure =
                departures[Math.floor(Math.random() * departures.length)];
            departure.minutes =
                departure.minutes > 1 ? departure.minutes - 1 : 10;
            const delayedWasOpen = board.querySelector('.brow.open') !== null;
            renderDepartures();

            if (delayedWasOpen) {
                board.querySelector('.brow.delayed')?.classList.add('open');
            }
        }, 5200);
    }
})();

/* ── Count-up and reveal effects ───────────────────────────────── */
(() => {
    const renderNumber = (element: HTMLElement, value: number): void => {
        element.replaceChildren();
        Array.from(String(value)).forEach((character) => {
            const flap = document.createElement('b');
            flap.textContent = character;
            element.append(flap);
        });
    };

    document.querySelectorAll<HTMLElement>('.flapnum').forEach((element) => {
        const target = Number.parseInt(element.dataset.number ?? '0', 10);

        if (reducedMotion || !Number.isFinite(target)) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (!entries[0]?.isIntersecting) {
                    return;
                }

                observer.disconnect();
                let start: number | null = null;

                const step = (timestamp: number): void => {
                    start ??= timestamp;
                    const progress = Math.min((timestamp - start) / 900, 1);
                    renderNumber(
                        element,
                        Math.round(target * (progress * (2 - progress))),
                    );

                    if (progress < 1) {
                        requestAnimationFrame(step);
                    }
                };

                requestAnimationFrame(step);
            },
            { threshold: 0.4 },
        );
        observer.observe(element);
    });

    const revealElements = document.querySelectorAll<HTMLElement>('.reveal');

    if (reducedMotion) {
        revealElements.forEach((element) => element.classList.add('in'));

        return;
    }

    const revealObserver = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in');
                    revealObserver.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.12 },
    );
    revealElements.forEach((element) => revealObserver.observe(element));
})();
