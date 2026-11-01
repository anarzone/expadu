/* Shared interaction model for first use and reported life changes.
   Question routing is a review fixture, never a legal assessment. */
(function (root) {
    const B = root.BureaucracyPreview,
        D = root.BureaucracyDetails,
        G = root.BureaucracyOrientation;
    // The registry's first questions (question-protocol.yaml orientation).
    const core = [
        'citizenship_group',
        'arrival_planned',
        'current_residence_title',
    ];
    const changes = {
        job: {
            label: 'Changed jobs',
            icon: 'briefcase',
            group: 'Work',
            review: ['purpose', 'current_residence_title'],
        },
        stopped: {
            label: 'Stopped working',
            icon: 'briefcase',
            group: 'Work',
            review: ['purpose', 'current_residence_title'],
        },
        purpose: {
            label: 'A different reason for my stay',
            icon: 'spark',
            group: 'Work & study',
            review: ['purpose'],
        },
        home: {
            label: 'Moved home',
            icon: 'pin',
            group: 'Home',
            review: ['registration_status'],
        },
        permit: {
            label: 'A new visa or permit',
            icon: 'document',
            group: 'Residence',
            review: ['current_residence_title'],
        },
        family: {
            label: 'My family situation changed',
            icon: 'people',
            group: 'Family',
            review: ['purpose'],
        },
    };
    const applies = (s) => s?.person === 'self' && s.profileKind === 'personal';
    // "Now" on the preview's sample day, so dates match Today and Composer.
    const now = () =>
        root.ExpaduSampleDay
            ? `${root.ExpaduSampleDay.date}T${root.ExpaduSampleDay.time}:00`
            : new Date().toISOString();
    function state(s) {
        if (!G.allowed(s)) return null;
        const o = G.hydrate(s);
        o.details ||= D.create();
        o.situation ||= {
            stage: 'intro',
            batch: 0,
            origin: 'starter',
            changes: [],
            review: [],
            lastChecked: null,
            checkDue: false,
            changeDraft: null,
        };
        return o.situation;
    }
    function fresh() {
        const s = B.blank();
        state(s);
        return s;
    }
    function returning() {
        const s = fresh();
        s.scenario = 'returning';
        s.profileId = 'returning-design-v1';
        const o = G.hydrate(s);
        o.started = true;
        o.mode = 'review';
        o.details = D.create({
            arrival_planned: false,
            purpose: 'employment',
            citizenship_group: 'non_eu',
            current_residence_title: 'national_d_visa',
            visa_expires_at: '2026-10-01',
            registration_status: 'registered',
        });
        Object.assign(state(s), {
            stage: 'summary',
            checkDue: true,
            lastChecked: '2026-09-01T12:00:00Z',
        });
        return s;
    }
    // An EU citizen who has arrived: the everyday tasks open from their
    // answers, and no residence task, because none applies.
    function eu() {
        const s = fresh();
        s.scenario = 'eu';
        s.profileId = 'eu-design-v1';
        const o = G.hydrate(s);
        o.started = true;
        o.mode = 'review';
        o.details = D.create({
            citizenship_group: 'eu',
            arrival_planned: false,
            registration_status: 'not_registered',
            health_coverage_confirmed: false,
            bank_account_help_needed: true,
            tax_id_available: true,
        });
        Object.assign(state(s), { stage: 'summary' });
        return s;
    }
    // Joining a spouse who lives here (family reunification, non-EU
    // sponsor). They track the family permit; the integration course is a
    // proposal. They told us their spouse holds a Blue Card, but the
    // spouse's own linked record says a §18c settlement permit: a review of
    // their report (review_relationship), never of the spouse's record.
    function family() {
        const s = fresh();
        s.scenario = 'family';
        s.profileId = 'family-design-v1';
        const o = G.hydrate(s);
        o.started = true;
        o.mode = 'review';
        o.details = D.create({
            citizenship_group: 'non_eu',
            arrival_planned: false,
            current_residence_title: 'national_d_visa',
            visa_expires_at: '2026-11-20',
            purpose: 'family',
            entry_mode: 'd_visa',
            case_goal: 'family_reunification_permit',
            sponsor: 'non_eu',
            sponsor_current_title: 'blue_card',
            registration_status: 'registered',
            health_coverage_confirmed: true,
            bank_account_help_needed: false,
            tax_id_available: true,
        });
        Object.assign(state(s), { stage: 'summary' });
        s.relationship = {
            fact: 'sponsor_current_title',
            linked: 'settlement_permit_18c',
            reviewed: false,
        };
        root.BureaucracyPlan.start(s, 'family');
        // They have their passport and marriage certificate; nothing is
        // checked for this task yet.
        s.records.family.docs[0] = 'reported_available';
        s.records.family.docs[3] = 'reported_available';
        return s;
    }
    // Still planning the move, with a job offer: health insurance is the
    // one task now; the bank account starts after arrival, so it is listed
    // under "After you arrive" and asks nothing yet (cf1b1caa). They told
    // us about the account when they set up their profile.
    function planning() {
        const s = fresh();
        s.scenario = 'planning';
        s.profileId = 'planning-design-v1';
        const o = G.hydrate(s);
        o.started = true;
        o.mode = 'review';
        o.details = D.create({
            citizenship_group: 'non_eu',
            arrival_planned: true,
            purpose: 'employment',
            health_coverage_confirmed: false,
            bank_account_help_needed: true,
        });
        Object.assign(state(s), { stage: 'summary' });
        return s;
    }
    // A Blue Card holder working towards permanent residence: 16
    // qualifying months and B1 German. No task applies; the two settlement
    // routes show as options, never as a decision that they qualify.
    function settling() {
        const s = fresh();
        s.scenario = 'settling';
        s.profileId = 'settling-design-v1';
        const o = G.hydrate(s);
        o.started = true;
        o.mode = 'review';
        o.details = D.create({
            citizenship_group: 'non_eu',
            arrival_planned: false,
            current_residence_title: 'blue_card',
            residence_title_expires_at: '2028-06-30',
            purpose: 'employment',
            case_goal: 'settlement_permit',
            blue_card_qualifying_months: 16,
            german_level: 'b1',
            registration_status: 'registered',
            health_coverage_confirmed: true,
            bank_account_help_needed: false,
            tax_id_available: true,
        });
        Object.assign(state(s), { stage: 'summary' });
        return s;
    }
    // What concerns this person now, in the backend's order (registry.js).
    function questions(s) {
        const r = G.hydrate(s).details;
        return root.BureaucracyRegistry.questions(r.facts, r.deferred);
    }
    function next(s) {
        const j = state(s),
            r = G.hydrate(s).details;
        const key = questions(s).find(
            (k) => !r.facts[k] && !r.deferred.includes(k),
        );
        if (!key || j.batch >= 3) {
            j.stage = 'checkpoint';
            r.mode = 'summary';
            r.draft = null;
            return true;
        }
        j.stage = 'question';
        j.origin = 'starter';
        return D.edit(r, key);
    }
    function begin(s) {
        if (!applies(s)) return false;
        const j = state(s),
            o = G.hydrate(s);
        o.started = true;
        o.mode = 'review';
        if (o.details.draft || j.changeDraft) {
            j.stage = j.changeDraft ? 'change-review' : 'question';
            return true;
        }
        if (['question', 'change-review', 'change-picker'].includes(j.stage))
            return true;
        j.batch = 0;
        j.notice = null;
        // A new run of questions: the pause counts from here.
        s.interview = { mark: D.given(o.details), skips: 0 };
        if (
            !questions(s).some(
                (k) => !o.details.facts[k] && !o.details.deferred.includes(k),
            )
        )
            o.details.deferred = [];
        return next(s);
    }
    function afterAnswer(s) {
        const j = state(s),
            r = G.hydrate(s).details;
        j.review = j.review.filter((k) => k !== r.current);
        j.lastChecked = now();
        if (j.origin === 'starter') {
            j.batch++;
            return next(s);
        }
        j.stage = 'summary';
        j.notice = 'Your answer is updated here and in Composer.';
        return true;
    }
    function skip(s) {
        const r = G.hydrate(s).details;
        D.skip(r);
        s.interview = {
            ...s.interview,
            skips: (s.interview?.skips || 0) + 1,
        };
        return afterAnswer(s);
    }
    function edit(s, key) {
        if (!D.field(key)) return false;
        const j = state(s);
        G.hydrate(s).started = true;
        if (j.changeDraft || G.hydrate(s).details.draft) {
            j.stage = j.changeDraft ? 'change-review' : 'question';
            return true;
        }
        j.stage = 'question';
        j.origin = 'review';
        return D.edit(G.hydrate(s).details, key);
    }
    function openChanges(s) {
        const j = state(s);
        j.stage = j.changeDraft
            ? 'change-review'
            : G.hydrate(s).details.draft
              ? 'question'
              : 'change-picker';
        return true;
    }
    function report(s, kind) {
        if (!G.allowed(s) || !changes[kind]) return false;
        const j = state(s);
        if (j.changeDraft || G.hydrate(s).details.draft) {
            openChanges(s);
            return false;
        }
        G.hydrate(s).started = true;
        G.hydrate(s).mode = 'review';
        j.stage = 'change-review';
        j.changeDraft = { kind, effectiveFrom: null };
        return true;
    }
    function changeDate(s, value) {
        const j = state(s);
        if (!j.changeDraft || (value && !B.validDate(value))) return false;
        j.changeDraft.effectiveFrom = value || null;
        return true;
    }
    function confirmChange(s) {
        const j = state(s),
            draft = j.changeDraft;
        if (!draft || !changes[draft.kind]) return false;
        j.changes.unshift({ ...draft, reportedAt: now() });
        const relevant = changes[draft.kind].review.filter(
            (k) => k !== 'current_residence_title' || questions(s).includes(k),
        );
        j.review = [...new Set([...j.review, ...relevant])];
        j.changeDraft = null;
        j.stage = 'summary';
        j.checkDue = false;
        j.notice = 'Change recorded. Check the related answers below.';
        return true;
    }
    function keep(s, key) {
        const j = state(s),
            r = G.hydrate(s).details;
        if (!j.review.includes(key) || !r.facts[key]) return false;
        j.review = j.review.filter((k) => k !== key);
        r.facts[key].checkedAt = now();
        r.revision++;
        j.lastChecked = now();
        return true;
    }
    function check(s) {
        const j = state(s);
        j.checkDue = false;
        j.lastChecked = now();
        j.notice = 'Your answers are still up to date.';
        j.stage = 'summary';
        return true;
    }
    function status(s) {
        const j = state(s),
            r = G.hydrate(s).details;
        return j.review.length
            ? 'review'
            : questions(s).some((k) => r.facts[k]?.state !== 'value')
              ? 'incomplete'
              : j.checkDue
                ? 'check'
                : 'current';
    }
    function intent(text) {
        const t = String(text).trim().toLowerCase().replace(/[.!]$/, '');
        const patterns = [
            [
                'job',
                /^i (?:have |just |have just )?(?:changed jobs|started a new job|got a new job)$/,
            ],
            [
                'stopped',
                /^i (?:have |just |have just )?(?:stopped working|lost my job)$/,
            ],
            [
                'home',
                /^i (?:have |just |have just )?(?:moved home|moved house|moved to a new home)$/,
            ],
            [
                'permit',
                /^i (?:have |just |have just )?(?:got|received) a new (?:residence permit|visa)$/,
            ],
            ['family', /^my family situation (?:has )?changed$/],
        ];
        return patterns.find(([, p]) => p.test(t))?.[0] || null;
    }
    function activate(storage, name) {
        if (!['fresh', 'returning', 'existing'].includes(name)) return null;
        const current = JSON.parse(storage.getItem(G.key) || 'null');
        if (current && (current.scenario || 'existing') === name)
            return current;
        const key = 'expadu-bureaucracy-scenarios-v1'; // gitleaks:allow (storage key name, not a secret)
        const snapshots = JSON.parse(storage.getItem(key) || '{}');
        if (current) snapshots[current.scenario || 'existing'] = current;
        const selected =
            snapshots[name] ||
            (name === 'fresh'
                ? fresh()
                : name === 'returning'
                  ? returning()
                  : B.fresh());
        storage.setItem(key, JSON.stringify(snapshots));
        storage.setItem(G.key, JSON.stringify(selected));
        return selected;
    }
    root.BureaucracySituation = {
        core,
        changes,
        applies,
        state,
        fresh,
        returning,
        eu,
        family,
        planning,
        settling,
        questions,
        begin,
        afterAnswer,
        skip,
        edit,
        openChanges,
        report,
        changeDate,
        confirmChange,
        keep,
        check,
        status,
        intent,
        activate,
    };
})(globalThis);
