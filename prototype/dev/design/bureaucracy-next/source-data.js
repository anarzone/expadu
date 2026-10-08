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
];
