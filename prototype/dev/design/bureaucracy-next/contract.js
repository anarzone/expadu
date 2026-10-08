/* The v2 plan contract (docs/contracts/bureaucracy-v2.md, schema
   bureaucracy.plan.1) projected from the local sample records in plan.js.
   Paperwork reads only this shape, as it will read the API; the rules here
   follow the backend's read model (ProcessDetails, PlanReadModel,
   PlanAttention, BuildTimeline). Sample data only: nothing is live. */
(function (root) {
    const B = root.BureaucracyPreview,
        P = root.BureaucracyPlan;
    const R = () => root.BureaucracySource || [];
    const ZONE = 'Europe/Berlin',
        JURISDICTION = 'de-nrw-cologne';
    // process-map.yaml: each sample task's process definition and topic.
    const definitions = {
        'blue-card': { id: 'residence.blue_card.first', topic: 'residence' },
        address: { id: 'address.registration', topic: 'address' },
        health: { id: 'health.coverage', topic: 'health' },
        bank: { id: 'banking.account', topic: 'money' },
        'tax-history': { id: 'tax.identification', topic: 'tax' },
        family: { id: 'residence.family.first', topic: 'residence' },
        integration: { id: 'education.integration_course', topic: 'education' },
    };
    // Each reviewed unit's process definition (process-map.yaml).
    const unitDefinitions = {
        'case.bc.first_application.prepare': 'residence.blue_card.first',
        'case.bc.first_application.submit': 'residence.blue_card.first',
        'bc.blue_card': 'residence.blue_card.first',
        'core.anmeldung': 'address.registration',
        'core.health_insurance': 'health.coverage',
        'core.bank_account': 'banking.account',
        'core.steuer_id': 'tax.identification',
        'case.family.first_permit.prepare': 'residence.family.first',
        'fam.residence_permits': 'residence.family.first',
        'fam.integration_course': 'education.integration_course',
    };
    // A context card names its own definition (source-data.js).
    const definitionOf = (r) => unitDefinitions[r.key] || r.definition || null;
    // Catalogue step kinds (process-map.yaml): an "action" step is the
    // submission, so its completion event is submission_recorded.
    const actionSteps = ['case.bc.first_application.submit'];
    // Each process's own short name (schema/process-titles.yaml,
    // processes[].title since f7e6f117). Names only, no figures.
    const processTitles = {
        'address.registration': 'Address registration',
        'banking.account': 'Bank account',
        'health.coverage': 'Health insurance',
        'residence.blue_card.first': 'EU Blue Card',
        'tax.identification': 'Tax ID',
        'residence.family.first': 'Joining your family',
        'education.integration_course': 'Integration course',
    };
    // processes[].title for one of this sample's occurrences.
    const titleOf = (key) => processTitles[definitions[key]?.id] || null;
    // config('bureaucracy_catalogue.topic_labels'): plain navigation copy,
    // not reviewed yet; the owner can rename them.
    const topicLabels = {
        address: 'Address',
        residence: 'Residence',
        tax: 'Tax',
        health: 'Health insurance',
        money: 'Money',
        family: 'Family',
        work: 'Work',
        education: 'Education',
        driving: 'Driving',
    };
    // Plain-English names for German document terms (schema/
    // document-terms.yaml, 8f4074c9). Names only; labels stay as they are.
    const documentTerms = {
        Wohnungsgeberbescheinigung: "Landlord's confirmation of move-in",
        Meldebescheinigung: 'Registration certificate',
        Mietvertrag: 'Rental contract',
        Warmmiete: 'Rent including heating and service charges',
        Lohnsteuerbescheinigung: 'Wage tax certificate',
        Rentenversicherungsverlauf: 'Pension insurance record',
        Versicherungsverlauf: 'Pension insurance record',
        Zulassungsbescheid: 'University admission letter',
        Immatrikulationsbescheinigung: 'Certificate of enrolment',
        Studienverlaufsplan: 'Study plan',
        Sperrkonto: 'Blocked bank account',
        Personalausweis: 'German identity card',
        Berufsausübungserlaubnis: 'Licence to practise your profession',
        'Vordruck Stellenbeschreibung': 'Job description form',
        'Erklärung zum Beschäftigungsverhältnis': 'Declaration of employment',
        Erstantrag: 'First application',
        'Steuer-ID': 'Tax identification number',
        Chancenkarte: 'Opportunity Card',
    };
    const termsIn = (label) =>
        Object.entries(documentTerms)
            .filter(([german]) => label.includes(german))
            .map(([german, english]) => ({ german, english }));
    // Which published step yields a document (a catalogue link, not a
    // prerequisite: the document never waits for it, and it says nothing
    // about your own progress). The sample's one link, by unit and position.
    const producedBy = {
        // "Proof of full health insurance" comes from the Health insurance
        // card (documents_required[].from: core.health_insurance).
        'bc.blue_card': {
            7: {
                unit_id: 'core.health_insurance',
                process_id: 'health.coverage',
            },
        },
        'fam.residence_permits': {
            7: {
                unit_id: 'core.health_insurance',
                process_id: 'health.coverage',
            },
        },
    };
    // Requirements whose reviewed label makes them conditional ("where
    // required", "where applicable", "if available"). Applicability is
    // data here, as in the backend, not read from the wording by the UI.
    const conditional = {
        // optional: true in the catalogue (entry visa, BA approval, private
        // health insurance proof, professional licence).
        'bc.blue_card': [1, 5, 7, 8],
    };
    // "waiting" in the local records is the backend's waiting_authority.
    const wire = (workflow) =>
        workflow === 'waiting' ? 'waiting_authority' : workflow;
    function offset(date) {
        const part = new Intl.DateTimeFormat('en-GB', {
            timeZone: ZONE,
            timeZoneName: 'shortOffset',
        })
            .formatToParts(new Date(`${date}T12:00:00Z`))
            .find((p) => p.type === 'timeZoneName').value;
        const m = part.match(/GMT([+-])(\d{1,2})(?::(\d{2}))?/);
        return m ? `${m[1]}${m[2].padStart(2, '0')}:${m[3] || '00'}` : '+00:00';
    }
    const now = () => {
        const day = root.ExpaduSampleDay;
        return day
            ? `${day.date}T${day.time}:00${offset(day.date)}`
            : new Date().toISOString();
    };
    // Calendar days from today in the jurisdiction's time zone.
    const daysFrom = (date) =>
        Math.round(
            (Date.parse(`${date}T12:00:00Z`) -
                Date.parse(`${P.today()}T12:00:00Z`)) /
                864e5,
        );
    const readiness = (state) => (state === 'unknown' ? 'missing' : state);
    function producesFrom(unit) {
        return Object.entries(producedBy).flatMap(([key, rows]) =>
            Object.entries(rows)
                .filter(([, link]) => link.unit_id === unit)
                .map(([j]) => ({
                    unit_id: key,
                    process_id: unitDefinitions[key] || null,
                    document_id: `${key}.document.${+j + 1}`,
                    label:
                        R().find((r) => r.key === key)?.documents?.[j] || null,
                })),
        );
    }
    function requirementsOf(p) {
        const started = p.started;
        let index = 0;
        return p.records.flatMap((n) => {
            const r = R()[n];
            return (r?.documents || []).map((label, j) => {
                const row = {
                    id: `${r.key}.document.${j + 1}`,
                    process_id: started ? p.id : null,
                    occurrence_key: p.id,
                    source_rule_id: r.key,
                    step_id: `${r.key}.complete`,
                    label,
                    readiness: readiness(p.docs[index] || 'unknown'),
                    applicability: conditional[r.key]?.includes(j)
                        ? 'conditional'
                        : 'required',
                    terms: termsIn(label),
                    produced_by: producedBy[r.key]?.[j] || null,
                    // The local record's position, for this preview's
                    // document actions.
                    index,
                };
                index += 1;
                return row;
            });
        });
    }
    // Your visa expiry: your answer when you've given one (Your situation,
    // the question card), otherwise the date Paperwork keeps.
    function expiryOf(s) {
        const f = answersOf(s).facts.visa_expires_at;
        return f?.state === 'value' && B.validDate(f.value) ? f.value : null;
    }
    function timelineOf(s, ps) {
        const rows = [];
        const expiry = expiryOf(s);
        if (expiry)
            rows.push({
                id: 'visa.expiry',
                kind: 'document_expiry',
                date: expiry,
                precision: 'calendar_date',
                timezone: ZONE,
                state: 'dated',
                overdue: daysFrom(expiry) < 0,
                provenance: 'confirmed_fact',
                legal_effect: 'not_assessed',
                fact_key: 'visa_expires_at',
                document: 'visa',
                process_id: null,
                occurrence_key: null,
                step_id: null,
                action_id: null,
            });
        for (const p of ps.filter((p) => p.started)) {
            const base = {
                process_id: p.id,
                occurrence_key: p.id,
                timezone: ZONE,
                workflow: wire(p.workflow),
            };
            // Every dated step row is a preparation_target: the backend has
            // no legal_due policy any more (process-map.yaml has no
            // temporal_policy since f761736d). Copy reads "Aim to … by".
            // Steps whose card dates them from the visa expiry
            // (deadline_type fact_date on visa_expires_at): both Blue Card
            // steps, and preparing the joining spouse's application.
            const fromExpiry = {
                'blue-card': [
                    ['case.bc.first_application.prepare', 0],
                    ['case.bc.first_application.submit', 1],
                ],
                family: [['case.family.first_permit.prepare', 0]],
            }[p.id];
            if (fromExpiry) {
                const known = !!expiry;
                // The card's own review is past due (review_required): its
                // dates pause until it's checked again.
                const paused = s.review?.dateReview && p.id === 'blue-card';
                fromExpiry.forEach(([unit, i]) =>
                    rows.push({
                        ...base,
                        id: `${p.id}:${unit}.due`,
                        kind: 'preparation_target',
                        source_rule_id: unit,
                        date: known && !paused ? expiry : null,
                        precision: 'calendar_date',
                        state: paused
                            ? 'review_required'
                            : known
                              ? 'dated'
                              : 'date_unknown',
                        needed_fact: known || paused ? null : 'visa_expires_at',
                        overdue: known && !paused && daysFrom(expiry) < 0,
                        conditional: true,
                        anchor_fact: 'visa_expires_at',
                        anchor_event_id:
                            known && !paused ? 'visa.expiry' : null,
                        step_id: `${unit}.complete`,
                        action_id: `${p.id}:${i}`,
                    }),
                );
            }
            if (p.id === 'address') {
                const d = P.registrationDeadline;
                // Your move-in date plus the card's own days, once you've
                // given it; until then the target waits for it.
                const moved = answersOf(s).facts.moved_in_at;
                const days = R().find(
                    (r) => r.key === 'core.anmeldung',
                )?.deadlineDays;
                const date =
                    moved?.state === 'value' &&
                    B.validDate(moved.value) &&
                    Number.isInteger(days)
                        ? new Date(
                              Date.parse(`${moved.value}T12:00:00Z`) +
                                  days * 864e5,
                          )
                              .toISOString()
                              .slice(0, 10)
                        : null;
                rows.push({
                    ...base,
                    id: `${p.id}:core.anmeldung.due`,
                    kind: 'preparation_target',
                    source_rule_id: 'core.anmeldung',
                    date,
                    precision: 'calendar_date',
                    state: date ? 'dated' : 'date_unknown',
                    needed_fact: date ? null : d.fact,
                    anchor_fact: d.fact,
                    overdue: !!date && daysFrom(date) < 0,
                    conditional: true,
                    step_id: 'core.anmeldung.complete',
                    action_id: `${p.id}:0`,
                    // Fixture only: generic wording with no figure. In the
                    // real plan the period is in the card's own
                    // source-checked text, never on the row.
                    target: d.rule,
                });
            }
            const a = p.appointment;
            if (a && B.validDate(a.date)) {
                const routable =
                    Number.isFinite(a.lat) && Number.isFinite(a.lng);
                const id = a.id || `${p.id}-appointment`;
                rows.push({
                    ...base,
                    id: `${p.id}:appointment:${id}`,
                    kind: 'appointment',
                    date: a.date,
                    starts_at: `${a.date}T${a.time}:00${offset(a.date)}`,
                    duration_minutes: Number.isInteger(a.duration)
                        ? a.duration
                        : null,
                    location: a.location
                        ? routable
                            ? { lat: a.lat, lng: a.lng, label: a.location }
                            : { label: a.location }
                        : null,
                    routable,
                    state: 'recorded',
                    legal_effect: 'not_assessed',
                    appointment_id: id,
                    event_id: id,
                    revision_event_id: id,
                    step_id: null,
                    action_id: null,
                });
            }
            const submitted = P.activeSubmission(s, p.id);
            if (p.submission) {
                const event = submitted?.id || `${p.id}-recorded-submission`;
                rows.push({
                    ...base,
                    id: `${p.id}:submission:${event}`,
                    kind: 'submission_recorded',
                    date: p.submission,
                    state: 'recorded',
                    event_id: event,
                    revision_event_id: event,
                    step_id: null,
                    action_id: null,
                });
            }
        }
        return rows;
    }
    const stepKinds = [
        'legal_due',
        'preparation_target',
        'authority_follow_up',
    ];
    function processOf(s, p, timeline, requirements, local, hidden) {
        const definition = definitions[p.id];
        const steps = p.records.flatMap((n, i) => {
            if (hidden?.has(R()[n]?.key)) return [];
            const r = R()[n];
            const id = `${p.id}:${i}`;
            const rows = requirements.filter((x) => x.source_rule_id === r.key);
            // Only a required document can be marked ready, so the count is
            // over the required ones, and the first to check is required
            // while one is open (as the backend does since 6268e24d).
            const required = rows.filter((x) => x.applicability === 'required');
            const open =
                required.find((x) => x.readiness !== 'confirmed_for_use') ||
                rows.find((x) => x.readiness !== 'confirmed_for_use');
            const action = local.find((a) => a.id === id);
            return {
                id,
                step_id: `${r.key}.complete`,
                guidance_id: r.key,
                title: r.title,
                description: r.description,
                // The backend's step status: blocked only for an unfinished
                // prerequisite (none here), not for a reported "blocked".
                status:
                    p.workflow === 'cancelled'
                        ? 'cancelled'
                        : action?.status === 'completed'
                          ? 'completed'
                          : ['submitted', 'waiting'].includes(p.workflow)
                            ? 'waiting'
                            : 'todo',
                position: i + 1,
                depends_on: [],
                verified_at: r.verifiedAt,
                review_due_at: null,
                content_version: r.version,
                sources: {
                    official: [
                        {
                            id: `${r.key}.action.1`,
                            url: r.source,
                            purpose: 'information',
                        },
                    ],
                    legal:
                        r.law && r.law !== r.source
                            ? [{ kind: 'primary', label: null, url: r.law }]
                            : [],
                },
                requirements: {
                    ready: required.filter(
                        (x) => x.readiness === 'confirmed_for_use',
                    ).length,
                    total: required.length,
                    optional: rows.length - required.length,
                },
                first_open_requirement: open
                    ? {
                          id: open.id,
                          label: open.label,
                          readiness: open.readiness,
                          applicability: open.applicability,
                          conditional: open.applicability !== 'required',
                          index: open.index,
                      }
                    : null,
                dates: timeline.filter((row) => row.action_id === id),
                completion_event: actionSteps.includes(r.key)
                    ? 'submission_recorded'
                    : 'step_completed',
                // guidance[].produces: what other steps need from this one.
                produces: producesFrom(r.key),
            };
        });
        const workflow = wire(p.workflow);
        const closed = ['completed', 'cancelled'].includes(workflow);
        const report = (p.reports || []).findLast(
            (r) => !r.withdrawn && r.event !== 'step',
        );
        // From the person's own paperwork only, never a legal reason.
        let blocking = null;
        if (['blocked', 'action_required'].includes(workflow))
            for (const step of steps) {
                const row = requirements.find(
                    (x) =>
                        x.source_rule_id === step.guidance_id &&
                        ['missing', 'needs_reconfirmation'].includes(
                            x.readiness,
                        ),
                );
                if (row) {
                    blocking = {
                        basis: 'requirement_readiness',
                        step_id: step.step_id,
                        requirement_id: row.id,
                        label: row.label,
                        readiness: row.readiness,
                        applicability: row.applicability,
                    };
                    break;
                }
            }
        return {
            id: p.started ? p.id : null,
            occurrence_key: p.id,
            definition_id: definition.id,
            version: p.started ? (p.reports || []).length + 1 : 0,
            // The process's own name; older releases fell back to the
            // first step's title.
            title: processTitles[definition.id] || steps[0]?.title || null,
            topic: definition.topic,
            topic_label: topicLabels[definition.topic],
            state: {
                workflow,
                report: report
                    ? {
                          event: report.event,
                          occurred_on: report.occurred_on || null,
                          note: report.note || null,
                      }
                    : null,
            },
            // The catalogue changed this task's own steps (review_required):
            // the person confirms before going on with the new ones.
            guidance_state:
                s.review?.changed === p.id ? 'review_required' : 'current',
            is_closed: closed,
            closed_on: closed ? p.closedOn || null : null,
            closed_event_id: closed ? report?.id || null : null,
            blocking_reason: blocking,
            // Whether "Track task" can still be undone (process_untracked);
            // never for a proposal.
            untrackable: p.started ? P.untrackable(s, p.id) : false,
            steps,
            progress: {
                completed: steps.filter((x) => x.status === 'completed').length,
                total: steps.length,
            },
        };
    }
    function attentionOf(timeline, processes) {
        const open = (row) => {
            const p = processes.find(
                (x) => x.occurrence_key === row.occurrence_key,
            );
            return (
                p &&
                !p.is_closed &&
                p.steps.find((x) => x.id === row.action_id)?.status !==
                    'completed'
            );
        };
        const rows = [];
        for (const row of timeline) {
            const appointment = row.kind === 'appointment';
            if (
                !appointment &&
                row.kind !== 'document_expiry' &&
                !stepKinds.includes(row.kind)
            )
                continue;
            if (stepKinds.includes(row.kind) && !open(row)) continue;
            if (
                stepKinds.includes(row.kind) &&
                row.state === 'date_unknown' &&
                row.needed_fact
            ) {
                rows.push({
                    ...row,
                    date: null,
                    days_remaining: null,
                    urgency: 'date_unknown',
                    label: null,
                });
                continue;
            }
            if (!row.date || !['dated', 'recorded'].includes(row.state))
                continue;
            if (appointment && row.starts_at <= now()) continue;
            const days = daysFrom(row.date);
            if (days > (appointment ? 7 : 14)) continue;
            rows.push({
                ...row,
                days_remaining: days,
                urgency: appointment
                    ? days === 0
                        ? 'appointment_today'
                        : days === 1
                          ? 'appointment_tomorrow'
                          : 'appointment_soon'
                    : days < 0
                      ? 'overdue'
                      : days <= 3
                        ? 'critical'
                        : days <= 7
                          ? 'urgent'
                          : 'upcoming',
            });
        }
        return rows.sort(
            (a, b) =>
                (a.days_remaining ?? Infinity) -
                    (b.days_remaining ?? Infinity) || a.id.localeCompare(b.id),
        );
    }
    // Your answers as the question protocol reads them: the ones you've
    // saved in Your situation, or, before you've opened it, the sample
    // day's (with the visa date Paperwork keeps).
    function answersOf(s) {
        const G = root.BureaucracyOrientation;
        const details = G?.allowed(s) && G.hydrate(s).details;
        if (details)
            return {
                facts: details.facts,
                deferred: details.deferred,
                conflicts: details.conflicts || {},
                history: details.history || [],
            };
        const seed = {
            ...B.sampleFacts(s),
            ...(B.validDate(s.expiry)
                ? { visa_expires_at: s.expiry }
                : s.expiryUnknown
                  ? { visa_expires_at: 'unknown' }
                  : {}),
        };
        const facts = {};
        for (const [key, value] of Object.entries(seed))
            facts[key] = {
                value,
                state: ['unknown', 'declined'].includes(value)
                    ? value
                    : 'value',
            };
        return {
            facts,
            deferred: s.questionSkipped ? ['visa_expires_at'] : [],
            conflicts: {},
            history: [],
        };
    }
    // plan.guidance[]: the context cards that apply to you (kind context,
    // actionable false, type info). Never processes, never a step. A card
    // applies when every condition of any one of its applies_if groups
    // holds (a list means one of these values; an empty group applies to
    // everyone). The most urgent come first, then catalogue order.
    const urgencyRank = { high: 0, medium: 1, low: 2 };
    function guidanceOf(s) {
        const facts = answersOf(s).facts;
        const said = (k) =>
            facts[k]?.state === 'value' ? facts[k].value : undefined;
        const holds = (group) =>
            Object.entries(group).every(([k, v]) =>
                Array.isArray(v) ? v.includes(said(k)) : said(k) === v,
            );
        return R()
            .filter(
                (r) =>
                    r.kind === 'context' &&
                    r.state !== 'unconfirmed' &&
                    (r.appliesIf || [{}]).some(holds),
            )
            .map((r, order) => ({ r, order }))
            .sort(
                (a, b) =>
                    urgencyRank[a.r.urgency] - urgencyRank[b.r.urgency] ||
                    a.order - b.order,
            )
            .map(({ r }) => ({
                id: r.key,
                definition_id: definitionOf(r),
                title: r.definitionTitle || r.title,
                unit_title: r.title,
                description: r.description,
                kind: 'context',
                type: 'info',
                actionable: false,
                topic: r.topic,
                urgency: r.urgency,
                actions: (r.official || [r.source]).map((url) => ({
                    url,
                    purpose: 'information',
                })),
                review: {
                    verified_at: r.verifiedAt,
                    content_version: r.version,
                },
            }));
    }
    // plan.questions (bureaucracy-v2.md "Questions and answer changes"):
    // at most one current question, in the registry's words. Three answers
    // or skips in a row pause the run (paused); an answer that keeps not
    // fitting stops it (answer_limit); two saved answers that disagree come
    // first (resolve_conflict). Skipped questions stay deferred.
    function questionsOf(s) {
        const RG = root.BureaucracyRegistry;
        // Pages that only read the plan (Today, Composer) don't load the
        // registry or ask questions.
        if (!RG)
            return {
                status: 'no_more_questions',
                session_id: null,
                question: null,
                remaining_information_count: 0,
                entry_state: 'none',
                deferred: [],
                candidates_count: 0,
            };
        const raw = answersOf(s);
        // An answer older than its reconfirm_after_days drops out of the
        // confirmed view, and its question is asked again.
        const due = Object.entries(raw.facts)
            .filter(
                ([k, f]) =>
                    f.at &&
                    RG.field(k)?.reconfirmAfterDays &&
                    (Date.parse(`${P.today()}T12:00:00Z`) - Date.parse(f.at)) /
                        864e5 >
                        RG.field(k).reconfirmAfterDays,
            )
            .map(([k]) => k);
        const a = {
            ...raw,
            facts: Object.fromEntries(
                Object.entries(raw.facts).filter(([k]) => !due.includes(k)),
            ),
        };
        const asked = RG.questions(a.facts, a.deferred);
        const open = asked.filter(
            (k) => !a.facts[k] && !a.deferred.includes(k),
        );
        const conflict = Object.keys(a.conflicts)[0] || null;
        // What you told us about your sponsor differs from their own linked
        // record (review_relationship): a review of your report, which
        // never changes their record.
        const rel = s.relationship;
        const related =
            !conflict &&
            rel &&
            !rel.reviewed &&
            a.facts[rel.fact]?.state === 'value' &&
            a.facts[rel.fact].value !== rel.linked
                ? rel.fact
                : null;
        const key = conflict || related || open[0] || null;
        const i = s.interview || {};
        const run =
            a.history.filter((h) => !h.seed).length -
            (i.mark || 0) +
            (i.skips || 0);
        const status = i.limit
            ? 'answer_limit'
            : !key
              ? 'no_more_questions'
              : run >= 3
                ? 'paused'
                : 'preview';
        const f = key && RG.field(key);
        return {
            status,
            // A pause or a stopped question can always carry on.
            can_resume: ['paused', 'answer_limit'].includes(status),
            session_id: run || a.deferred.length || i.limit ? 1 : null,
            question:
                status === 'preview' && f
                    ? {
                          fact_key: key,
                          kind: conflict
                              ? 'resolve_conflict'
                              : related
                                ? 'review_relationship'
                                : 'answer',
                          question: f.title,
                          why: f.why,
                          answer_schema: {
                              type: f.type,
                              options: f.options.map(([value]) => value),
                              date_semantics:
                                  f.type === 'date'
                                      ? key.endsWith('_expires_at')
                                          ? 'expiry'
                                          : 'historical'
                                      : null,
                              allows_not_applicable: f.allowsNotApplicable,
                          },
                          process_ids: RG.processIds(a.facts, key),
                          // Mock-only: what you said last time, when the
                          // question is back because it's due.
                          ...(due.includes(key)
                              ? {
                                    reconfirm: {
                                        value: raw.facts[key].value,
                                        confirmed_at: raw.facts[key].at,
                                    },
                                }
                              : {}),
                          can_skip: true,
                          remaining_before_pause: Math.max(0, 3 - run),
                          can_answer_unknown: !conflict && !related,
                          ...(related
                              ? {
                                    action: {
                                        type: 'review_relationships',
                                        fact_key: key,
                                        reason: 'report_mismatch',
                                        required_scopes: [
                                            'view_plan',
                                            'view_facts',
                                            'edit_facts',
                                        ],
                                    },
                                }
                              : {}),
                          ...(conflict
                              ? {
                                    action: {
                                        type: 'review_fact_conflict',
                                        fact_key: key,
                                        required_scopes: [
                                            'view_facts',
                                            'edit_facts',
                                        ],
                                    },
                                }
                              : {}),
                      }
                    : null,
            remaining_information_count: open.length,
            entry_state:
                status === 'paused'
                    ? 'paused'
                    : run || a.deferred.length
                      ? 'in_progress'
                      : 'none',
            deferred: [...a.deferred],
            candidates_count: asked.filter((k) => !a.facts[k]).length,
        };
    }
    function coverageOf() {
        return {
            state: 'partial',
            units: R()
                .map((r) =>
                    // A card the daily source check hasn't confirmed shows
                    // only its title and official pages (bureaucracy-v2.md,
                    // "Automatically checked cards").
                    r.state === 'unconfirmed'
                        ? {
                              definition_id: definitionOf(r),
                              unit_id: r.key,
                              title: r.title,
                              content_version: null,
                              verified_at: null,
                              review_due_at: null,
                              source_urls: {
                                  official: [],
                                  legal: [r.law || r.source].filter(Boolean),
                              },
                              state: 'unconfirmed',
                          }
                        : {
                              definition_id: definitionOf(r),
                              unit_id: r.key,
                              title: r.title,
                              content_version: r.version,
                              verified_at: r.verifiedAt,
                              review_due_at: null,
                              source_urls: {
                                  official: [r.source],
                                  legal:
                                      r.law && r.law !== r.source
                                          ? [r.law]
                                          : [],
                              },
                              // The sample catalogue is preparation guidance
                              // only.
                              state: 'partial',
                          },
                )
                .sort((a, b) => a.unit_id.localeCompare(b.unit_id)),
        };
    }
    const empty = (s) => ({
        schema_version: 'bureaucracy.plan.1',
        person_id: s.person,
        jurisdiction: JURISDICTION,
        evaluated_at: now(),
        overview: {
            next_actions: [],
            remaining_action_count: 0,
            question: null,
        },
        actions: [],
        processes: [],
        history: [],
        history_count: 0,
        progress: {
            total: 0,
            todo: { count: 0, ids: [] },
            blocked: { count: 0, ids: [] },
            waiting: { count: 0, ids: [] },
            completed: { count: 0, ids: [] },
        },
        timeline: [],
        attention: [],
        coming_up: [],
        questions: {
            status: 'permission_required',
            question: null,
            entry_state: null,
            deferred: [],
            candidates_count: null,
        },
        guidance: [],
        ai: {
            available: false,
            consent_scope: 'single_request',
            confirmation_required: true,
        },
        coverage: coverageOf(),
        paperwork: { available: false, reason: 'permission_required' },
    });
    // GET /people/{person}/plan, for the selected person, as the viewer's
    // scopes allow: without manage_evidence the paperwork is
    // permission_required (no requirements on the steps either); without
    // edit_facts no question is offered.
    function plan(s) {
        const p = build(s);
        if (!s.scopes) return p;
        const can = (scope) => s.scopes.includes(scope);
        return {
            ...p,
            scopes: [...s.scopes],
            processes: p.processes.map((x) => ({
                ...x,
                steps: x.steps.map((st) =>
                    can('manage_evidence')
                        ? st
                        : {
                              ...st,
                              requirements: null,
                              first_open_requirement: null,
                          },
                ),
            })),
            questions: can('edit_facts')
                ? p.questions
                : {
                      ...p.questions,
                      status: 'permission_required',
                      question: null,
                      entry_state: null,
                      deferred: [],
                      candidates_count: null,
                  },
            paperwork: can('manage_evidence')
                ? p.paperwork
                : { available: false, reason: 'permission_required' },
        };
    }
    function build(s) {
        // Outside the covered city: no city guidance, nothing guessed.
        if (s.entry === 'outside')
            return {
                ...empty(s),
                questions: {
                    ...empty(s).questions,
                    status: 'no_more_questions',
                },
                coverage: { state: 'outside_coverage', units: [] },
            };
        if (!B.canEdit(s)) return empty(s);
        P.hydrate(s);
        const local = P.projection(s).actions;
        const all = P.processes(s);
        // Unconfirmed cards never show their steps, documents or dates.
        const hidden = new Set(
            R()
                .filter((r) => r.state === 'unconfirmed')
                .map((r) => r.key),
        );
        const shown = (row) => !hidden.has(row.source_rule_id);
        const requirements = all.flatMap(requirementsOf).filter(shown);
        const timeline = timelineOf(s, all).filter(shown);
        const built = all
            .map((p) =>
                processOf(
                    s,
                    p,
                    timeline,
                    requirements.filter((r) => r.occurrence_key === p.id),
                    local,
                    hidden,
                ),
            )
            .filter((p) => p.steps.length);
        // A task whose guidance was withdrawn has no current guidance: it
        // moves to history (history_only) with the step states you
        // recorded, never silently dropped.
        const withdrawn = built.filter(
            (p) => p.id !== null && s.review?.withdrawn === p.occurrence_key,
        );
        const processes = built.filter((p) => !withdrawn.includes(p));
        const steps = processes.flatMap((p) =>
            p.steps.map((step) => ({ ...step, process: p })),
        );
        const bucket = (status) => {
            const ids = steps
                .filter((x) => x.status === status)
                .map((x) => x.id);
            return { count: ids.length, ids };
        };
        const progress = {
            total: steps.filter((x) => x.status !== 'cancelled').length,
            todo: bucket('todo'),
            blocked: bucket('blocked'),
            waiting: bucket('waiting'),
            completed: bucket('completed'),
        };
        const actions = steps
            .filter((x) => x.status === 'todo' && !x.process.is_closed)
            .map((x) => ({
                id: x.id,
                type: 'open_process',
                person_id: s.person,
                process_id: x.process.id,
                occurrence_key: x.process.occurrence_key,
                step_id: x.step_id,
                title: x.title,
                process_title: x.process.title,
                source_rule_id: x.guidance_id,
                dates: x.dates.filter((d) => stepKinds.includes(d.kind)),
                requires_process_start: x.process.id === null,
            }))
            // Earliest step date first; undated keep their order.
            .map((a, i) => ({ a, i }))
            .sort(
                (x, y) =>
                    (x.a.dates[0]?.date || '9999-12-31').localeCompare(
                        y.a.dates[0]?.date || '9999-12-31',
                    ) || x.i - y.i,
            )
            .map(({ a }) => a);
        const history = [
            ...withdrawn.map((p) => ({
                ...p,
                guidance_state: 'history_only',
                steps: p.steps.map((x) => ({
                    id: x.id,
                    title: x.title,
                    status: x.status,
                })),
            })),
            ...s.history.map((h) => {
                const r = R()[h.record];
                return {
                    id: h.id,
                    occurrence_key: h.id,
                    definition_id: definitions[h.id]?.id || null,
                    title: titleOf(h.id) || r?.title || null,
                    topic_label: topicLabels[definitions[h.id]?.topic] || null,
                    untrackable: false,
                    state: { workflow: h.workflow, report: null },
                    is_closed: true,
                    closed_on: h.closedOn || null,
                    closed_event_id: null,
                    steps: [],
                };
            }),
        ];
        const attention = attentionOf(timeline, processes);
        const questions = questionsOf(s);
        return {
            schema_version: 'bureaucracy.plan.1',
            person_id: s.person,
            jurisdiction: JURISDICTION,
            evaluated_at: now(),
            overview: {
                next_actions: actions.slice(0, 3),
                remaining_action_count: Math.max(0, actions.length - 3),
                question: questions.question,
            },
            actions,
            processes,
            history,
            history_count:
                history.length + processes.filter((p) => p.is_closed).length,
            progress,
            timeline,
            attention,
            coming_up: attention.filter(
                (row) =>
                    row.days_remaining !== null &&
                    row.days_remaining >= 0 &&
                    row.days_remaining <= 7,
            ),
            questions,
            guidance: guidanceOf(s),
            // AI fact extraction: a single request with consent, and nothing
            // saved until you confirm (scripted in this preview).
            ai: {
                available: true,
                consent_scope: 'single_request',
                confirmation_required: true,
            },
            coverage: coverageOf(),
            paperwork: {
                available: true,
                requirements,
                evidence: [],
                capabilities: {
                    upload: { available: false, reason: 'not_implemented' },
                    translation: {
                        available: false,
                        reason: 'not_implemented',
                    },
                },
            },
        };
    }
    // GET /people/{person}/facts, for the one sample fact this page asks.
    function facts(s) {
        const f = answersOf(s).facts.visa_expires_at;
        return {
            values: { visa_expires_at: expiryOf(s) },
            states: { visa_expires_at: f?.state || null },
        };
    }
    // The account holder's own records as Today and Composer show them: the
    // ready count, the visa date a deadline is anchored to and whether a
    // submission is recorded, all read from the plan Paperwork keeps (its
    // saved sample state). Each task's short copy comes from the shared
    // sample day. A closed or untracked task drops out.
    function records() {
        const fixtures = root.ExpaduSampleDay?.records || [];
        let stored = null;
        try {
            stored = JSON.parse(
                root.localStorage?.getItem('expadu-bureaucracy-design-v1'),
            );
        } catch {
            /* The sample state below still applies. */
        }
        const s = P.hydrate(
            stored &&
                Array.isArray(stored.docs) &&
                stored.docs.length === B.fresh().docs.length
                ? { ...B.fresh(), ...stored, person: 'self' }
                : B.fresh(),
        );
        const p = plan(s);
        return fixtures
            .map((record) => {
                const c = p.processes.find(
                    (x) => x.occurrence_key === record.id,
                );
                if (!c || c.id === null || c.is_closed) return null;
                const rows = p.timeline.filter(
                    (t) => t.occurrence_key === record.id,
                );
                const due = rows.find(
                    (t) => stepKinds.includes(t.kind) && t.anchor_event_id,
                );
                const expiry =
                    due && p.timeline.find((t) => t.id === due.anchor_event_id);
                return {
                    ...record,
                    prepared: c.steps.reduce(
                        (n, x) => n + (x.requirements?.ready || 0),
                        0,
                    ),
                    total: c.steps.reduce(
                        (n, x) => n + (x.requirements?.total || 0),
                        0,
                    ),
                    deadline: expiry?.date || null,
                    submitted: rows.some(
                        (t) => t.kind === 'submission_recorded',
                    ),
                };
            })
            .filter(Boolean);
    }
    root.BureaucracyContract = {
        plan,
        facts,
        records,
        titleOf,
        topicLabels,
    };
})(globalThis);
