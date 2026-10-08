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
    // Guidance that isn't a task (kind context, plan.guidance[]): cards
    // that apply to you are listed as "Good to know", never as processes.
    {
        key: 'shared.fiktionsbescheinigung',
        kind: 'context',
        title: 'Fiktionsbescheinigung: what that bridging paper really permits',
        appliesIf: { citizenship_group: 'non_eu' },
        description:
            'While a permit application is processing you may get a Fiktionsbescheinigung —\nand what it allows depends on which of its two forms you hold. The continuation\nform (§81 Abs. 4) keeps your current permit in force until the decision: you may\nkeep working if your permit allowed it, and you may travel and return while it is\nvalid. The form for a first application without a visa (§81 Abs. 3) does not\nallow work, and you may not leave Germany and re-enter on it. Check which form\nyours is before booking any flight.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/artikel/71447/index.html',
        law: 'https://www.gesetze-im-internet.de/aufenthg_2004/__81.html',
    },
    {
        key: 'shared.passport_transfer',
        kind: 'context',
        title: 'New passport? Transfer your permit (Übertrag)',
        appliesIf: { citizenship_group: 'non_eu' },
        description:
            'Your residence title stays valid when you get a new national passport. Carry\nboth the old and the new passport if you kept the old one. If the old passport\nnumber is printed on your residence title, contact the Ausländeramt through its\ncontact form and it will tell you the next steps; if it is not printed there,\nyou do not need to do anything. Settlement permit holders whose card shows the\npassport number book an appointment at their Bezirksausländeramt and bring your\nold passport, your new passport and a digital biometric photo. Also worth\nknowing: a residence title generally expires if you leave Germany for a reason\nthat is not temporary, or if you stay abroad for longer than six months without\na longer period set by the Ausländerbehörde. Some exceptions apply, so ask\nbefore planning long stays away.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/artikel/73721/index.html',
        law: 'https://www.gesetze-im-internet.de/aufenthg_2004/__51.html',
    },
    {
        key: 'shared.verpflichtungserklaerung',
        kind: 'context',
        title: 'Inviting family or friends to visit? Verpflichtungserklärung',
        appliesIf: {},
        description:
            'If you invite visa-required family or friends to visit, they can use a\nVerpflichtungserklärung to show the German embassy or consulate that their costs\nare covered. You file it at the Ausländeramt (€29 per declaration; the fee is\ncharged even if your income is not sufficient). You commit to covering public\ncosts of their stay, for five years from their entry. You need your main\nresidence in Cologne. File it at least three months before their visa appointment\n(by post, online contact form, or in person on Tuesday and Thursday mornings);\nthe embassy usually recognises it for up to six months after issue. Employees\nbring payslips for the last three months and a bank statement showing the salary;\nself-employed people bring a Steuerberater certificate or their last tax\nassessment instead.',
        documents: [],
        verifiedAt: '2026-09-30',
        version: '2026-10-08.1',
        source: 'https://www.stadt-koeln.de/service/produkte/00962/index.html',
        law: 'https://www.gesetze-im-internet.de/aufenthg_2004/__68.html',
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
];
