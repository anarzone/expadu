// Exact catalogue copy, historical design snapshot; current publication validity not established.
window.BureaucracySource = [
    {
        key: 'case.bc.first_application.prepare',
        title: 'Prepare the evidence for your first Blue Card application',
        description:
            'You currently hold a national D visa and want to change to an EU Blue Card.\nPrepare the passport, employment contract or binding offer, qualification\nevidence, health-insurance evidence, and any professional licence the Cologne\nauthority requests. The job offer must cover at least six months and the\nstatutory Blue Card conditions still have to be checked. This is preparation,\nnot a prediction that the application will be approved.',
        documents: [
            'Valid passport and national D visa',
            'Employment contract or binding job offer covering at least six months',
            'Qualification evidence and recognition evidence where required',
            'Health-insurance evidence',
            'Professional licence for a regulated profession, where applicable',
        ],
        verifiedAt: '2026-08-03',
        version: '2026-08-03.1',
        source: 'https://www.stadt-koeln.de/service/produkte/20321/index.html',
        law: 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html',
    },
    {
        key: 'case.bc.first_application.submit',
        title: 'Submit the Blue Card application before your D visa expires',
        description:
            'File the Cologne online residence application before the expiry date shown on\nyour national D visa. Keep the submission confirmation and continue checking\nofficial messages for requests or an appointment. The authority decides whether\nevery requirement in §18g is met; Expadu does not decide eligibility.',
        documents: [],
        verifiedAt: '2026-08-03',
        version: '2026-08-03.1',
        source: 'https://www.stadt-koeln.de/service/produkte/20321/index.html',
        law: 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html',
    },
];

// Titles and document labels copied from the historical core catalogue. No current guidance is inferred.
// Health insurance and Bank account no longer list a Meldebescheinigung: no allowed source says an
// insurer or bank needs it, and §31 ZKG contradicts it for basic accounts (owner, 8 Oct 2026).
window.BureaucracySource.push(
    ...[
        {
            key: 'core.anmeldung',
            title: 'Register your address (Anmeldung)',
            description: null,
            documents: [
                'Passport or EU ID card',
                'Wohnungsgeberbescheinigung (Cologne form, signed by landlord/main tenant)',
            ],
            verifiedAt: '2026-08-22',
            version: '2026-08-22.1',
            source: 'https://www.stadt-koeln.de/service/produkte/00415/index.html',
            law: 'https://www.gesetze-im-internet.de/bmg/__17.html',
        },
        {
            key: 'core.health_insurance',
            title: 'Sort out health insurance',
            description: null,
            documents: ['Passport or ID'],
            verifiedAt: '2026-08-22',
            version: '2026-08-22.1',
            source: 'https://www.make-it-in-germany.com/en/living-in-germany/money-insurance/health-insurance',
            law: 'https://www.gesetze-im-internet.de/sgb_5/__5.html',
        },
        {
            key: 'core.bank_account',
            title: 'Open a German bank account',
            description: null,
            documents: ['Passport or ID', 'Tax ID (if available)'],
            verifiedAt: '2026-08-22',
            version: '2026-08-22.1',
            source: 'https://www.make-it-in-germany.com/en/living-in-germany/money-insurance/bank-account',
            law: 'https://www.gesetze-im-internet.de/zkg/__31.html',
        },
        {
            key: 'core.steuer_id',
            title: 'Wait for your Steuer-ID (tax identification number)',
            description: null,
            documents: [],
            verifiedAt: '2026-08-22',
            version: '2026-08-22.1',
            source: 'https://www.gesetze-im-internet.de/ao_1977/__139b.html',
            law: 'https://www.gesetze-im-internet.de/ao_1977/__139b.html',
        },
    ],
);
