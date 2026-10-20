// Catalogue copy from the backend's reviewed catalogue (codex/bureaucracy-backend-v2,
// database/seeders/data/bureaucracy, as of 8 Oct 2026): titles, descriptions and
// document lists exactly as written there. Nothing is inferred. `verifiedAt` is the
// daily automated source check as of the sample day (30 Sept 2026); `version` is the
// card's own content_version. Records keep their positions: plan.js points at them
// by index, so a new card goes at the end.
window.BureaucracySource = [
    {
        key: 'case.bc.first_application.prepare',
        title: 'Prepare the evidence for your first Blue Card application',
        description:
            'If you hold a national D visa and plan to apply for an EU Blue Card,\nprepare the passport, employment contract or binding offer, qualification\nevidence, health-insurance evidence, and any professional licence the Cologne\nauthority requests. The job offer must cover at least six months and the\nstatutory Blue Card conditions still have to be checked. This is preparation,\nnot a prediction that the application will be approved.',
        // The documents to bring are listed once, on the appointment step.
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.2',
        source: 'https://www.stadt-koeln.de/service/produkte/20321/index.html',
        law: 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html',
    },
    {
        key: 'case.bc.first_application.submit',
        title: 'Submit the Blue Card application before your D visa expires',
        description:
            'If you are pursuing an EU Blue Card from a national D visa, file the Cologne\nresidence application before the visa expires. Keep the submission confirmation and continue checking\nofficial messages for requests or an appointment. The authority decides whether\nevery requirement in §18g is met; Expadu does not decide eligibility.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-09-08.1',
        source: 'https://www.stadt-koeln.de/service/produkte/20321/index.html',
        law: 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html',
    },
    {
        key: 'core.anmeldung',
        title: 'Check and prepare your address registration (Anmeldung)',
        description:
            'Registration normally follows moving into a dwelling, with a two-week\nperiod under §17 BMG. Temporary stays and other situations can fall under\nexceptions in §27 BMG. Check the applicable procedure with the registration\nauthority if your circumstances are unclear. Employment or a permanent\nresidence title does not by itself answer this question.\nThis card helps you prepare; it does not decide that an exception is absent.\nAny displayed preparation target uses your move-in date, not your arrival\ndate or an appointment, and is not a confirmed personal legal deadline.',
        documents: [
            'Identity documents appropriate to your nationality and residence status',
            'Wohnungsgeberbescheinigung confirming actual move-in',
        ],
        // deadline_type days_since_move_in, deadline_days 14: the backend
        // dates the target from your move-in date (BuildTimeline). Data,
        // never copy.
        deadlineDays: 14,
        verifiedAt: '2026-09-30',
        version: '2026-09-08.1',
        source: 'https://www.stadt-koeln.de/service/produkte/00415/index.html',
        law: 'https://www.gesetze-im-internet.de/bmg/__17.html',
    },
    {
        key: 'core.health_insurance',
        title: 'Confirm health insurance cover for your stay',
        description:
            'Confirm which cover applies to your stay and when it starts. Statutory and\nprivate insurance have different entry conditions and exceptions; this card\ndoes not decide which system you qualify for. Check any period between\narrival and the start of employment with your insurer and, where relevant,\nthe German mission abroad. Do not assume you have an uninsured grace period.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-09-08.1',
        source: 'https://www.make-it-in-germany.com/en/living-in-germany/money-insurance/health-insurance',
        law: 'https://www.gesetze-im-internet.de/sgb_5/__5.html',
    },
    {
        key: 'core.bank_account',
        title: 'Review an account for your everyday payments',
        description:
            'If you need an account, compare costs, payment facilities and access to\nsupport. Document requirements depend on the provider and account type.\nA basic payment account (Basiskonto) has a separate statutory application\nframework under the ZKG. This card does not decide your entitlement or\nrequire you to replace an existing account.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-09-08.1',
        source: 'https://www.make-it-in-germany.com/en/living-in-germany/money-insurance/bank-account',
        law: 'https://www.gesetze-im-internet.de/zkg/__31.html',
    },
    {
        key: 'core.steuer_id',
        title: 'Find or recover your Steuer-ID (tax identification number)',
        description:
            'Your tax identification number stays the same for life, including after a\nmove or marriage. Check an existing income tax assessment or wage tax\ncertificate before requesting it again. If you do not have it, follow the\nBZSt procedure. This preparation task is not a delivery promise or deadline.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-07.1',
        source: 'https://online.portal.bzst.de/SharedDocs/Leistungsbeschreibung/DE/erneute_mitteilung_der_ID-Nr.html',
        law: 'https://www.gesetze-im-internet.de/ao_1977/__139b.html',
    },
    {
        key: 'bc.blue_card',
        title: 'Attend your Blue Card appointment with the originals',
        description:
            'Cologne reviews your online application and sends you an appointment invitation if it can issue the Blue Card.\nBring the originals of the documents listed below. In individual cases the office may ask for further documents.',
        documents: [
            'Valid passport or passport substitute',
            'Entry visa for qualified employment, where your entry required one',
            'Biometric passport photo, not older than three months',
            'Employment contract or binding job offer for at least six months',
            'Qualification proof (first application only)',
            'BA advance approval or the completed form "Erklärung zum Beschäftigungsverhältnis", if available',
            'Mietvertrag + bank statement showing your current rent payment',
            'Proof of full health insurance — only if you are not statutorily insured',
            'Berufsausübungserlaubnis, for regulated professions',
        ],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.2',
        source: 'https://www.stadt-koeln.de/service/produkte/20321/index.html',
        law: 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html',
    },
    // Guidance that isn't a task (kind context, plan.guidance[]): the
    // release's twelve context cards, exactly as the backend returns them
    // (title, description, official links, applies_if). Listed as "Good to
    // know" for whom they apply, never as processes.
    {
        key: 'ck.work_limits',
        kind: 'context',
        definition: 'residence.chancenkarte',
        definitionTitle: 'Opportunity Card (Chancenkarte)',
        topic: 'residence',
        urgency: 'medium',
        // applies_if: any one group, every condition in it (a list means
        // one of these values); an empty group applies to everyone.
        appliesIf: [
            {
                purpose: 'employment',
                permit_track: 'chancenkarte',
                citizenship_group: 'non_eu',
            },
        ],
        title: 'Know your Chancenkarte work limits',
        description:
            "While you search, the Chancenkarte only allows employment of at most 20 hours per week on average, plus trial employments of at most two weeks each that must be qualified (§20a AufenthG). The search card is issued for up to one year. With a qualifying job contract or binding offer and the Federal Employment Agency's approval, it can be extended by up to two years as a follow-up card — but only if you do not qualify for another work-related residence permit, so the plan is to move to a work permit. The card is only issued when your livelihood is secured. Typical finance requirement at issue: €13,092 for 12 months (~€1,091/month).",
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.gesetze-im-internet.de/aufenthg_2004/__20a.html',
        official: [
            'https://www.gesetze-im-internet.de/aufenthg_2004/__20a.html',
        ],
    },
    {
        key: 'shared.citizenship',
        kind: 'context',
        definition: 'residence.citizenship',
        definitionTitle: 'German citizenship',
        topic: 'residence',
        urgency: 'low',
        // applies_if: any one group, every condition in it (a list means
        // one of these values); an empty group applies to everyone.
        appliesIf: [{ citizenship_group: 'non_eu' }],
        title: 'German citizenship',
        description:
            'German citizenship is usually after five years of lawful residence in Germany, with German at level B1 (or a German school, study or vocational qualification) and proof of knowledge of the legal and social order, for example a passed naturalisation test (Einbürgerungstest). The fee is €255. Other conditions, such as your residence title and secured livelihood, also apply.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/service/produkt/einbuergerung-den-deutschen-staatsverband',
        official: [
            'https://www.stadt-koeln.de/service/produkt/einbuergerung-den-deutschen-staatsverband',
        ],
    },
    {
        key: 'shared.other_permits',
        kind: 'context',
        definition: 'residence.other_routes',
        definitionTitle: 'Other residence routes',
        topic: 'residence',
        urgency: 'low',
        // applies_if: any one group, every condition in it (a list means
        // one of these values); an empty group applies to everyone.
        appliesIf: [{ citizenship_group: 'non_eu' }],
        title: 'Other permit types: where to look',
        description:
            "Some paths we don't model in full. Here is where the law puts them. Vocational training in a company (§16a) has its own residence permit for training. Researchers (§18d) need a hosting agreement or contract with a research institution; intra-corporate transfers (§19) use the ICT Card for a temporary posting within the company group. Language courses (§16f) and searching for a training place (§17) have their own residence permits. Long-term residents of another EU country (§38a) who hold that status there can get a residence permit if they want to stay in Germany for more than 90 days. Ask the Ausländeramt which route applies to you.",
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/leben-in-koeln/soziales/auslaenderamt/index.html',
        official: [
            'https://www.stadt-koeln.de/leben-in-koeln/soziales/auslaenderamt/index.html',
        ],
    },
    {
        key: 'shared.passport_transfer',
        kind: 'context',
        definition: 'residence.passport_transfer',
        definitionTitle: 'Moving your permit to a new passport',
        topic: 'residence',
        urgency: 'low',
        // applies_if: any one group, every condition in it (a list means
        // one of these values); an empty group applies to everyone.
        appliesIf: [{ citizenship_group: 'non_eu' }],
        title: 'New passport? Transfer your permit (Übertrag)',
        description:
            'Your residence title stays valid when you get a new national passport. Carry both the old and the new passport if you kept the old one. If the old passport number is printed on your residence title, contact the Ausländeramt through its contact form and it will tell you the next steps; if it is not printed there, you do not need to do anything. Settlement permit holders whose card shows the passport number book an appointment at their Bezirksausländeramt and bring your old passport, your new passport and a digital biometric photo. Also worth knowing: a residence title generally expires if you leave Germany for a reason that is not temporary, or if you stay abroad for longer than six months without a longer period set by the Ausländerbehörde. Some exceptions apply, so ask before planning long stays away.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/artikel/73721/index.html',
        official: [
            'https://www.stadt-koeln.de/artikel/73721/index.html',
            'https://www.stadt-koeln.de/leben-in-koeln/soziales/auslaenderamt/niederlassungserlaubnis',
        ],
    },
    {
        key: 'shared.fiktionsbescheinigung',
        kind: 'context',
        definition: 'residence.pending_application',
        definitionTitle: 'While your application is pending',
        topic: 'residence',
        urgency: 'low',
        // applies_if: any one group, every condition in it (a list means
        // one of these values); an empty group applies to everyone.
        appliesIf: [{ citizenship_group: 'non_eu' }],
        title: 'Fiktionsbescheinigung: what that bridging paper really permits',
        description:
            'While a permit application is processing you may get a Fiktionsbescheinigung — and what it allows depends on which of its two forms you hold. The continuation form (§81 Abs. 4) keeps your current permit in force until the decision: you may keep working if your permit allowed it, and you may travel and return while it is valid. The form for a first application without a visa (§81 Abs. 3) does not allow work, and you may not leave Germany and re-enter on it. Check which form yours is before booking any flight.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/artikel/71447/index.html',
        official: [
            'https://www.stadt-koeln.de/artikel/71447/index.html',
            'https://www.gesetze-im-internet.de/aufenthg_2004/__81.html',
        ],
    },
    {
        key: 'shared.long_game',
        kind: 'context',
        definition: 'residence.settlement.general',
        definitionTitle: 'Permanent residence',
        topic: 'residence',
        urgency: 'low',
        // applies_if: any one group, every condition in it (a list means
        // one of these values); an empty group applies to everyone.
        appliesIf: [
            {
                citizenship_group: 'non_eu',
                current_residence_title: [
                    'national_d_visa',
                    'standard_work_permit',
                    'blue_card',
                    'family_reunification',
                ],
            },
        ],
        title: 'The long game: permanent residency',
        description:
            'Germany rewards staying. A Niederlassungserlaubnis (permanent residency) is usually after holding a residence permit for five years, with secured livelihood, at least 60 months of pension contributions (in a marriage, the contributions of one spouse can count for both), German at level B1, basic knowledge of the legal and social order (usually shown with the "Leben in Deutschland" test) and sufficient housing for your household. The Cologne fee is €113. Faster routes exist: three years on a skilled-worker permit (two years with a German degree or vocational training) under §18c; 27 months on a Blue Card, or 21 months with sufficient German; three years for family members of Germans (§28). The EU variant (Daueraufenthalt-EU, §9a) also generally needs five years. When a renewal comes due, check whether you already meet these conditions.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/leben-in-koeln/soziales/auslaenderamt/niederlassungserlaubnis',
        official: [
            'https://www.stadt-koeln.de/leben-in-koeln/soziales/auslaenderamt/niederlassungserlaubnis',
            'https://www.stadt-koeln.de/service/produkt/allgemeine-niederlassungserlaubnis',
        ],
    },
    {
        key: 'famde.ne_three_years',
        kind: 'context',
        definition: 'residence.settlement.german_family',
        definitionTitle: 'Permanent residence as family of a German',
        topic: 'residence',
        urgency: 'low',
        // applies_if: any one group, every condition in it (a list means
        // one of these values); an empty group applies to everyone.
        appliesIf: [{ purpose: 'family', sponsor: 'german' }],
        title: 'Permanent residency after three years (§28 Abs. 2)',
        description:
            'If you hold a residence permit under §28(1) as the family member of a German, you can apply for a Niederlassungserlaubnis after three years. Cologne lists these conditions: the family community with your German family member continues (you live together, or you regularly care for your German children); your livelihood is secured without benefits such as Bürgergeld; you have adequate health insurance; you have no criminal convictions in Germany, since even fines can prevent it; and your German is at B1 level.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/service/produkt/niederlassungserlaubnis-fuer-familienangehoerige-von-deutschen-personen',
        official: [
            'https://www.stadt-koeln.de/service/produkt/niederlassungserlaubnis-fuer-familienangehoerige-von-deutschen-personen',
        ],
    },
    {
        key: 'case.bc.verify_status_source',
        kind: 'context',
        definition: 'residence.status_verification',
        definitionTitle: 'Checking your residence title',
        topic: 'residence',
        urgency: 'high',
        // applies_if: any one group, every condition in it (a list means
        // one of these values); an empty group applies to everyone.
        // Only a title with no reviewed rule: another title, or a settlement
        // permit whose section you don't know (f98ed7b9).
        appliesIf: [
            {
                citizenship_group: 'non_eu',
                current_residence_title: ['other', 'settlement_permit_unknown'],
            },
        ],
        title: 'Verify an unrecognized title against the official source',
        description:
            'Expadu does not have a reviewed case rule for every residence title. Check the exact legal basis printed on the card or visa and confirm the route with the Cologne immigration authority before acting. General information shown here is not an eligibility decision.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/service/adressen/auslaenderamt',
        official: ['https://www.stadt-koeln.de/service/adressen/auslaenderamt'],
    },
    {
        key: 'stu.post_graduation',
        kind: 'context',
        definition: 'residence.student.job_search',
        definitionTitle: 'Job search after graduation',
        topic: 'residence',
        urgency: 'low',
        // applies_if: any one group, every condition in it (a list means
        // one of these values); an empty group applies to everyone.
        appliesIf: [{ purpose: 'study', citizenship_group: 'non_eu' }],
        title: 'After graduation: 18 months to find your job',
        description:
            'After successfully completing your degree in Germany, you can get a residence permit for up to 18 months to look for a job (§20 AufenthG). Your livelihood must be secured for the whole period, and the permit cannot be extended. The job you find does not have to match your degree, as long as you meet the requirements of a permit such as the one for skilled workers with an academic qualification or the EU Blue Card. You can also use the permit to prepare self-employment.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/service/produkte/00973/index.html',
        official: [
            'https://www.stadt-koeln.de/service/produkte/00973/index.html',
            'https://www.gesetze-im-internet.de/aufenthg_2004/__20.html',
        ],
    },
    {
        key: 'stu.work_rules',
        kind: 'context',
        definition: 'residence.student.work',
        definitionTitle: 'Working while you study',
        topic: 'work',
        urgency: 'low',
        // applies_if: any one group, every condition in it (a list means
        // one of these values); an empty group applies to everyone.
        appliesIf: [{ purpose: 'study', citizenship_group: 'non_eu' }],
        title: 'Working as a student: 140 working days per year',
        description:
            "Your student permit includes an Arbeitstagekonto: employment of up to 140 working days per year. For each calendar week the more favourable way of counting applies: either days with up to four hours of work count as half days, or if you work up to 20 hours in a lecture-time week, that week counts as two and a half days; outside lecture time each week counts as two and a half days however the hours are spread. Student jobs at a university or other research institution (studentische Nebentätigkeiten) don't count. Work beyond this account needs separate permission, so keep a simple tally.",
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/service/produkte/00973/index.html',
        official: [
            'https://www.stadt-koeln.de/service/produkte/00973/index.html',
            'https://www.gesetze-im-internet.de/aufenthg_2004/__16b.html',
        ],
    },
    {
        key: 'shared.verpflichtungserklaerung',
        kind: 'context',
        definition: 'residence.visitor_sponsorship',
        definitionTitle: 'Inviting a visitor',
        topic: 'residence',
        urgency: 'low',
        // applies_if: any one group, every condition in it (a list means
        // one of these values); an empty group applies to everyone.
        appliesIf: [{}],
        title: 'Inviting family or friends to visit? Verpflichtungserklärung',
        description:
            'If you invite visa-required family or friends to visit, they can use a Verpflichtungserklärung to show the German embassy or consulate that their costs are covered. You file it at the Ausländeramt (€29 per declaration; the fee is charged even if your income is not sufficient). You commit to covering public costs of their stay, for five years from their entry. You need your main residence in Cologne. File it at least three months before their visa appointment (by post, online contact form, or in person on Tuesday and Thursday mornings); the embassy usually recognises it for up to six months after issue. Employees bring payslips for the last three months and a bank statement showing the salary; self-employed people bring a Steuerberater certificate or their last tax assessment instead.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/service/produkte/00962/index.html',
        official: [
            'https://www.stadt-koeln.de/service/produkte/00962/index.html',
        ],
    },
    {
        key: 'stu.tax_return',
        kind: 'context',
        definition: 'tax.income_tax_return',
        definitionTitle: 'Income tax return',
        topic: 'tax',
        urgency: 'low',
        // applies_if: any one group, every condition in it (a list means
        // one of these values); an empty group applies to everyone.
        appliesIf: [{ purpose: 'study' }],
        title: 'Your first tax return: you can file one yourself',
        description:
            'Had wage tax deducted from your pay, for example on a Werkstudent contract? §46 EStG lists cases in which an assessment is required, for example if you were paid by several employers at the same time. Otherwise you can still apply for an assessment yourself, in particular to have the withheld wage tax credited against your income tax. You apply by filing an income tax return.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.gesetze-im-internet.de/estg/__46.html',
        official: [
            'https://www.gesetze-im-internet.de/estg/__46.html',
            'https://www.elster.de/eportal/start',
        ],
    },
    // Published but withheld from the release (not machine-confirmed): the
    // backend lists them only as coverage units, title and official page.
    {
        key: 'fre.residence_permit',
        title: 'Apply for the freelance residence permit (§21 Abs. 5)',
        state: 'unconfirmed',
        documents: [],
        source: 'https://www.stadt-koeln.de/service/produkte/01083/index.html',
        law: 'https://www.gesetze-im-internet.de/aufenthg_2004/__21.html',
    },
    {
        key: 'le.kindergeld',
        title: 'Apply for Kindergeld',
        state: 'unconfirmed',
        documents: [],
        source: 'https://www.arbeitsagentur.de/familie-und-kinder/kindergeld',
    },
    // Joining a spouse (family reunification, non-EU sponsor): the
    // residence.family.first steps and the integration course.
    {
        key: 'case.family.first_permit.prepare',
        title: "Prepare the joining spouse's first residence application",
        description:
            "Prepare and file the joining spouse's own residence application before the\nnational D visa expires. Use the Cologne family-reunification checklist for the\npassport, visa, marriage evidence, housing and livelihood evidence, and other\nrequested documents. A sponsor whose Blue Card is still pending needs a separate\nauthority check; do not assume that holder-specific exceptions already apply.",
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-07.1',
        source: 'https://www.stadt-koeln.de/service/produkte/20335/index.html',
        law: 'https://www.gesetze-im-internet.de/aufenthg_2004/__30.html',
    },
    {
        key: 'fam.residence_permits',
        title: 'Attend the permit appointment with the originals',
        description:
            'After you apply online, Cologne reviews the application and invites you to an\nappointment when it can issue the permit. Bring the originals of the documents\nbelow; Cologne may ask for further documents in individual cases.',
        documents: [
            'Recognised, valid national passport',
            'Copy of the entry visa, if you needed one',
            'Digital biometric photo',
            'Marriage certificate (first application only)',
            'Rental contract plus proof of the current rent',
            'A1 German certificate for the joining spouse',
            'Proof that the livelihood of all working family members is secured',
            'Proof of full health insurance, if you are not publicly insured',
        ],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/service/produkte/20335/index.html',
    },
    {
        key: 'fam.integration_course',
        title: 'Sign up for an Integration Course',
        description:
            "The Integrationskurs combines a German language course with an orientation course\non Germany's legal order, culture and history. The general course runs 700 lessons.\nA first residence permit for family reunification normally gives you a right to\ntake part, and that right lapses one year after the permit is issued, so sign up\nearly. If you cannot yet communicate in simple German, the Ausländerbehörde can\noblige you to attend when it issues your permit.",
        documents: ['Residence permit', 'Passport'],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.bamf.de/DE/Themen/Integration/ZugewanderteTeilnehmende/Integrationskurse/InhaltAblauf/inhaltablauf-node.html',
        law: 'https://www.gesetze-im-internet.de/aufenthg_2004/__44.html',
    },
    // Routes you may choose (kind option, plan.guidance[]): "Options for
    // you", never a task and never a claim that you qualify. One example:
    // a Blue Card holder's two settlement cards, as the catalogue words
    // them (non_eu_employee_blue_card.yaml).
    {
        key: 'bc.ne_fast_track',
        kind: 'option',
        definition: 'residence.settlement.blue_card',
        definitionTitle: 'Permanent residence with a Blue Card',
        topic: 'residence',
        urgency: 'low',
        appliesIf: [
            {
                citizenship_group: 'non_eu',
                current_residence_title: 'blue_card',
            },
        ],
        title: 'Blue Card fast track: permanent residence after 21 or 27 months',
        description:
            'Blue Card holders can get the Niederlassungserlaubnis (permanent residence)\nafter 27 months of Blue Card employment with pension or equivalent contributions and A1 German — or\nafter 21 months with B1 German. Cologne puts it as 21 or 27 months instead of three or five years.\nThe authority still checks the further conditions referenced in §18c, so keep\nyour contributions running and book a German course early. The Niederlassungserlaubnis\nfee is €113.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.gesetze-im-internet.de/aufenthg_2004/__18c.html',
        official: [
            'https://www.gesetze-im-internet.de/aufenthg_2004/__18c.html',
            'https://www.stadt-koeln.de/service/produkte/20321/index.html',
        ],
    },
    {
        key: 'case.bc.settlement.track_21_months',
        kind: 'option',
        definition: 'residence.settlement.blue_card',
        definitionTitle: 'Permanent residence with a Blue Card',
        topic: 'residence',
        urgency: 'medium',
        appliesIf: [
            {
                citizenship_group: 'non_eu',
                current_residence_title: 'blue_card',
                case_goal: 'settlement_permit',
                blue_card_qualifying_months: [
                    12, 13, 14, 15, 16, 17, 18, 19, 20,
                ],
                german_level: ['b1', 'b2', 'c1', 'c2'],
            },
        ],
        title: 'Track the 21-month Blue Card settlement route',
        description:
            'With B1 German and fewer than 21 documented qualifying months, the time\nrequirement for the 21-month Blue Card route is not yet met. Track qualifying\nemployment and pension or equivalent contributions. At 21 months, the authority\nmust still verify the remaining conditions referenced by §18c(2), including the\napplicable §9 checks. Time in Germany alone is not the statutory counter.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-07.1',
        source: 'https://www.gesetze-im-internet.de/aufenthg_2004/__18c.html',
        official: [
            'https://www.gesetze-im-internet.de/aufenthg_2004/__18c.html',
            'https://www.stadt-koeln.de/service/produkte/20321/index.html',
        ],
    },
    // nee.tax_return (codex/bureaucracy-backend-v2, 2026-10-08.1): serves
    // every employee, so the sample person's plan has it (the app build,
    // 20 Oct 2026). Copy exactly as the card writes it.
    {
        key: 'nee.tax_return',
        title: 'File your annual income tax return (Einkommensteuererklärung)',
        description:
            'As an employee, wage tax is deducted from your salary. A tax return is\nmandatory in the cases listed in §46 EStG — for example if you were paid by\nseveral employers at the same time. If none of those cases applies, you can\nstill file a return to have the wage tax already withheld credited against\nyour income tax. If you are required to file and do it yourself, the return\nis due seven months after the end of the calendar year; a different deadline\napplies when a tax adviser prepares it.',
        documents: [
            'Lohnsteuerbescheinigung (wage tax certificate) from each employer',
            'Receipts for expenses you want to claim',
        ],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.elster.de/eportal/start',
        law: 'https://www.gesetze-im-internet.de/estg/__46.html',
    },
];
