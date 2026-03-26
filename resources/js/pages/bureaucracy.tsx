import { Head } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { BureaucracyRightPanel } from '@/components/bureaucracy/bureaucracy-right-panel';
import { DocumentCard } from '@/components/bureaucracy/document-card';
import { OfficeCard } from '@/components/bureaucracy/office-card';
import { TaskCard } from '@/components/bureaucracy/task-card';
import AppLayout from '@/layouts/app-layout';

// ============================================================
// Types
// ============================================================

export type TaskTag = { label: string; bg: string; color: string };

export type TaskData = {
    id: number;
    done: boolean;
    urgent: boolean;
    title: string;
    desc: string;
    tag: TaskTag;
    steps: string[];
    docs: string[];
    time: string;
    link: string | null;
    linkLabel?: string;
};

export type DocTag = { l: string; bg: string; c: string };

export type DocData = {
    emoji: string;
    de: string;
    en: string;
    desc: string;
    where: string;
    validity: string;
    tags: DocTag[];
    detail: {
        what: string;
        when: string;
        howToGet: string;
        watchOut: string;
    };
};

export type OfficeData = {
    name: string;
    address: string;
    status: string;
    nextSlot: string;
    distance: string;
    monitoring: boolean;
    color: string;
    colorS: string;
    statusLabel: string;
    bookingUrl: string;
    mapsUrl: string;
};

type AiResponse = {
    type: string;
    summary: string;
    action: string;
    deadline: string | null;
    urgency: 'low' | 'medium' | 'high';
};

// ============================================================
// Hardcoded data from prototype
// ============================================================

const SEED_TASKS: TaskData[] = [
    {
        id: 1, done: true, urgent: false,
        title: 'Register at Bürgeramt (Anmeldung)',
        desc: 'Register your address with the city within 14 days of moving in. Required for everything else.',
        tag: { label: 'Done', bg: '#D4F0E6', color: '#0A7C52' },
        steps: ['Book appointment at bürgeramt.de or via phone', 'Bring passport + rental contract (Mietvertrag)', 'Bring your landlord\'s Wohnungsgeberbestätigung form', 'Receive Meldebescheinigung on the spot'],
        docs: ['Passport', 'Mietvertrag', 'Wohnungsgeberbestätigung'],
        time: '~1 hour including travel', link: 'https://www.stadt-koeln.de', linkLabel: 'Book appointment ↗',
    },
    {
        id: 2, done: true, urgent: false,
        title: 'Apply for Tax Identification Number (Steuer-ID)',
        desc: 'Automatically sent by post to your registered address 2–4 weeks after Anmeldung. No action needed.',
        tag: { label: 'Done', bg: '#D4F0E6', color: '#0A7C52' },
        steps: ['Complete Anmeldung first', 'Wait 2–4 weeks for letter from Bundeszentralamt für Steuern', 'Give the 11-digit number to your employer'],
        docs: ['Meldebescheinigung (from Anmeldung)'],
        time: 'Automatic — no appointment needed', link: null,
    },
    {
        id: 3, done: true, urgent: false,
        title: 'Enrol in health insurance (Krankenversicherung)',
        desc: 'Mandatory in Germany. Choose a public insurer (TK, AOK, Barmer) or private. Must be done before starting work.',
        tag: { label: 'Done', bg: '#D4F0E6', color: '#0A7C52' },
        steps: ['Compare public insurers — TK and Barmer recommended for English support', 'Apply online or in person', 'Receive insurance card and membership number', 'Give membership number to employer'],
        docs: ['Passport', 'Work contract'],
        time: '30 min online, card arrives in 1–2 weeks', link: 'https://www.tk.de', linkLabel: 'Apply at TK ↗',
    },
    {
        id: 4, done: false, urgent: true,
        title: 'Open a German bank account',
        desc: 'Required for salary payments, rent, and direct debits. N26 and Deutsche Bank have English interfaces.',
        tag: { label: 'Urgent', bg: '#FDE8E6', color: '#C4271A' },
        steps: ['Choose: N26 (online, English) or Deutsche Bank (branch, more features)', 'Prepare passport + Meldebescheinigung', 'Apply online or visit branch', 'Verify identity via VideoIdent or in person', 'Receive IBAN — share with employer and landlord'],
        docs: ['Passport', 'Meldebescheinigung', 'Steuer-ID (helpful)'],
        time: '30 min to apply, account active in 1–3 days', link: 'https://n26.com', linkLabel: 'Open N26 account ↗',
    },
    {
        id: 5, done: false, urgent: true,
        title: 'Register with Ausländerbehörde (non-EU)',
        desc: 'Non-EU citizens must register with the immigration office and obtain a residence permit (Aufenthaltstitel).',
        tag: { label: 'Urgent', bg: '#FDE8E6', color: '#C4271A' },
        steps: ['Book appointment at Ausländerbehörde Cologne', 'Bring: passport, work contract, proof of income, health insurance, Meldebescheinigung, biometric photo', 'Pay fee ~€100', 'Receive temporary confirmation, permit by post in 4–8 weeks'],
        docs: ['Passport', 'Work contract', 'Health insurance card', 'Meldebescheinigung', 'Biometric photo', '€100 fee'],
        time: 'Appointment 1–2 hours, permit arrives weeks later', link: 'https://www.stadt-koeln.de', linkLabel: 'Book appointment ↗',
    },
    {
        id: 6, done: false, urgent: false,
        title: 'Get a German SIM card',
        desc: 'Needed for German phone number required by many services, banks, and the Bürgeramt.',
        tag: { label: 'Pending', bg: '#EFEDE7', color: '#6B6860' },
        steps: ['Visit a carrier shop: Telekom (best coverage), O2, Vodafone', 'Bring passport for identity verification', 'Choose a monthly plan — Telekom MagentaMobil from €15/mo'],
        docs: ['Passport'],
        time: '15 minutes in-store', link: null,
    },
    {
        id: 7, done: false, urgent: false,
        title: 'Register with a general practitioner (Hausarzt)',
        desc: 'You need a regular GP for referrals and prescriptions. Find one who accepts new patients and speaks English.',
        tag: { label: 'Pending', bg: '#EFEDE7', color: '#6B6860' },
        steps: ['Search doctolib.de for English-speaking GPs in Cologne', 'Check if they accept new patients with your insurer', 'Call to register — bring insurance card on first visit'],
        docs: ['Health insurance card (Versicherungskarte)'],
        time: '15 min to find, first appointment varies', link: 'https://www.doctolib.de', linkLabel: 'Find a GP ↗',
    },
    {
        id: 8, done: false, urgent: false,
        title: 'Set up internet at home',
        desc: 'Most apartments need a separate internet contract. Glasfaser (fibre) is available in much of Cologne.',
        tag: { label: 'Pending', bg: '#EFEDE7', color: '#6B6860' },
        steps: ['Check availability: Telekom, Vodafone, or NetCologne', 'Order online — needs your address and IBAN', 'Router arrives in 1–2 weeks', 'Activation takes up to 4 weeks for new connections'],
        docs: ['IBAN (for direct debit)', 'Rental address'],
        time: '20 min to order, 2–4 weeks to activate', link: null,
    },
    {
        id: 9, done: false, urgent: false,
        title: 'File your first German tax return (Steuererklärung)',
        desc: 'Not always mandatory but often results in a refund. Due by 31 July for the previous year.',
        tag: { label: 'Pending', bg: '#EFEDE7', color: '#6B6860' },
        steps: ['Use WISO Steuer or Taxfix app (English available)', 'Gather: Lohnsteuerbescheinigung from employer, Steuer-ID, bank statements', 'Submit electronically via ELSTER or tax app', 'Refund typically arrives 6–8 weeks after submission'],
        docs: ['Steuer-ID', 'Lohnsteuerbescheinigung', 'Bank statements'],
        time: '2–4 hours first time, faster with a tax advisor', link: 'https://www.taxfix.de', linkLabel: 'Try Taxfix ↗',
    },
    {
        id: 10, done: false, urgent: false,
        title: 'Get a Deutschlandticket (€58/month)',
        desc: 'Unlimited travel on all local and regional public transport across Germany. Best value transit deal.',
        tag: { label: 'Pending', bg: '#EFEDE7', color: '#6B6860' },
        steps: ['Order at kvb.koeln or DB Navigator app', 'Needs a German payment method (SEPA direct debit or credit card)', 'Digital ticket available immediately in the app'],
        docs: ['German bank IBAN (for direct debit)'],
        time: '10 minutes online', link: 'https://www.kvb.koeln', linkLabel: 'Get Deutschlandticket ↗',
    },
    {
        id: 11, done: false, urgent: false,
        title: 'Learn basic German (A1–A2)',
        desc: 'Not required but dramatically improves daily life. Many services, landlords, and government offices expect some German.',
        tag: { label: 'Ongoing', bg: '#EDE9FE', color: '#7C3AED' },
        steps: ['Start with Duolingo or Babbel for basics', 'Consider a Volkshochschule (VHS) course — subsidised and local', 'Join language exchange on Anker to practise with locals'],
        docs: [],
        time: 'Ongoing — 15 min/day makes a real difference', link: null,
    },
];

const SEED_DOCS: DocData[] = [
    {
        emoji: '📋', de: 'Meldebescheinigung', en: 'Address Registration Certificate',
        desc: 'Proof that you have registered your address in Germany. Required for almost everything: bank accounts, Ausländerbehörde, tax office.',
        where: 'Issued at the Bürgeramt after Anmeldung', validity: 'Unlimited (content may go out of date)',
        tags: [{ l: 'Essential', bg: '#FDE8E6', c: '#C4271A' }, { l: 'Free', bg: '#D4F0E6', c: '#0A7C52' }],
        detail: {
            what: 'A simple A4 document with your name, address, and registration date. Stamped by the Bürgeramt.',
            when: 'Any time you need to prove your German address to a third party.',
            howToGet: 'Automatically issued after Anmeldung. Extra copies cost ~€5 at the Bürgeramt.',
            watchOut: 'Some services require it to be less than 3 months old.',
        },
    },
    {
        emoji: '🆔', de: 'Steuer-Identifikationsnummer', en: 'Tax Identification Number',
        desc: 'Your permanent 11-digit German tax number. Never changes, even if you move. Required by employers for payroll.',
        where: 'Sent by post from Bundeszentralamt für Steuern after Anmeldung', validity: 'Permanent — never expires',
        tags: [{ l: 'Essential', bg: '#FDE8E6', c: '#C4271A' }, { l: 'Automatic', bg: '#EBF0FD', c: '#1A4CD4' }],
        detail: {
            what: 'An 11-digit number uniquely identifying you for tax purposes in Germany. Different from your Steuernummer (which is employer-specific).',
            when: 'Give it to your employer before your first payslip. Needed for tax returns.',
            howToGet: 'Arrives automatically 2–4 weeks after Anmeldung. If lost, request at bundeszentralamt.de.',
            watchOut: 'Do not confuse with Steuernummer (changes when you move) or USt-ID (for businesses).',
        },
    },
    {
        emoji: '🏥', de: 'Versicherungskarte', en: 'Health Insurance Card',
        desc: 'Your electronic health insurance card. Show it at every doctor visit, pharmacy, and hospital in Germany.',
        where: 'Sent by your health insurer after enrolment', validity: 'Renewed annually by insurer',
        tags: [{ l: 'Essential', bg: '#FDE8E6', c: '#C4271A' }],
        detail: {
            what: 'A chip card issued by your public health insurer (Krankenkasse). Contains your insurance data electronically.',
            when: 'Required at every healthcare appointment. Without it, you may be billed privately.',
            howToGet: 'Automatically sent after enrolling with a Krankenkasse. Allow 1–2 weeks.',
            watchOut: 'If you change insurers, your old card becomes invalid immediately.',
        },
    },
    {
        emoji: '📄', de: 'Lohnsteuerbescheinigung', en: 'Annual Payroll Tax Certificate',
        desc: 'Issued by your employer every January for the previous year. Needed for your tax return and proof of income.',
        where: 'Issued by your employer annually', validity: 'Annual document',
        tags: [{ l: 'Tax', bg: '#FDF0D4', c: '#C47D0E' }],
        detail: {
            what: 'A detailed breakdown of your gross pay, tax paid, social security contributions, and other deductions for the whole year.',
            when: 'Needed when filing a Steuererklärung (tax return). Also useful as proof of income for rental applications.',
            howToGet: 'Your employer sends it electronically to the Finanzamt and gives you a copy in January/February.',
            watchOut: 'Check it carefully — errors in the document affect your tax calculation.',
        },
    },
    {
        emoji: '📝', de: 'Wohnungsgeberbestätigung', en: 'Landlord Registration Confirmation',
        desc: 'A form your landlord must sign confirming you live at their property. Required for Anmeldung.',
        where: 'Provided by your landlord', validity: 'One-time use for Anmeldung',
        tags: [{ l: 'Required for Anmeldung', bg: '#EBF0FD', c: '#1A4CD4' }],
        detail: {
            what: 'A standardised form (Formular 18) that confirms your landlord has accepted you as a tenant at the registered address.',
            when: 'Must be presented at the Bürgeramt for Anmeldung. Without it, registration is impossible.',
            howToGet: 'Download the form from the Bürgeramt website and ask your landlord to sign it. Some landlords provide it directly.',
            watchOut: 'Your landlord is legally required to provide this within 2 weeks of you moving in.',
        },
    },
    {
        emoji: '🛂', de: 'Aufenthaltstitel', en: 'Residence Permit',
        desc: 'For non-EU citizens: official permit to live and work in Germany. Contains your visa conditions.',
        where: 'Issued by Ausländerbehörde', validity: 'Varies — typically 1–3 years initially',
        tags: [{ l: 'Non-EU only', bg: '#EDE9FE', c: '#7C3AED' }, { l: 'Critical', bg: '#FDE8E6', c: '#C4271A' }],
        detail: {
            what: 'A physical card (since 2011) that serves as your official permission to reside in Germany. Contains biometric data and specifies what you are allowed to do.',
            when: 'Required to prove your legal right to work and live in Germany to employers, landlords, and authorities.',
            howToGet: 'Apply at the Ausländerbehörde with extensive documentation. Allow 4–8 weeks for the card to be produced.',
            watchOut: 'Apply before your current visa or entry stamp expires. Overstaying has serious consequences.',
        },
    },
];

const SEED_OFFICES: OfficeData[] = [
    {
        name: 'Bürgeramt Deutz', address: 'Kalker Hauptstr. 247–273, 51103 Köln',
        status: 'available', nextSlot: 'Tomorrow 09:15', distance: '2.3 km',
        monitoring: true, color: '#0A7C52', colorS: '#D4F0E6', statusLabel: 'Slots available',
        bookingUrl: 'https://termine.stadt-koeln.de/m/buergeramt/',
        mapsUrl: 'https://www.google.com/maps/dir/?api=1&destination=Kalker+Hauptstr.+247,+51103+K%C3%B6ln',
    },
    {
        name: 'Bürgeramt Innenstadt', address: 'Laurenzplatz 4, 50667 Köln',
        status: 'busy', nextSlot: '3 weeks away', distance: '3.1 km',
        monitoring: true, color: '#C47D0E', colorS: '#FDF0D4', statusLabel: 'Fully booked',
        bookingUrl: 'https://termine.stadt-koeln.de/m/buergeramt/',
        mapsUrl: 'https://www.google.com/maps/dir/?api=1&destination=Laurenzplatz+4,+50667+K%C3%B6ln',
    },
    {
        name: 'Bürgeramt Ehrenfeld', address: 'Venloer Str. 10, 50672 Köln',
        status: 'available', nextSlot: 'Wed 14 March 11:30', distance: '0.6 km',
        monitoring: true, color: '#0A7C52', colorS: '#D4F0E6', statusLabel: '1 slot available',
        bookingUrl: 'https://termine.stadt-koeln.de/m/buergeramt/',
        mapsUrl: 'https://www.google.com/maps/dir/?api=1&destination=Venloer+Str.+10,+50672+K%C3%B6ln',
    },
    {
        name: 'Bürgeramt Mülheim', address: 'Wiener Platz 2a, 51065 Köln',
        status: 'busy', nextSlot: 'In 2 weeks', distance: '4.8 km',
        monitoring: false, color: '#C47D0E', colorS: '#FDF0D4', statusLabel: 'Mostly booked',
        bookingUrl: 'https://termine.stadt-koeln.de/m/buergeramt/',
        mapsUrl: 'https://www.google.com/maps/dir/?api=1&destination=Wiener+Platz+2a,+51065+K%C3%B6ln',
    },
];

const EXAMPLE_TEXTS: Record<string, string> = {
    finanzamt: `Sehr geehrte Damen und Herren,

hiermit teilen wir Ihnen mit, dass Ihre Steuererklärung für das Jahr 2023 bei uns eingegangen ist und derzeit bearbeitet wird. Aufgrund des hohen Aufkommens kann die Bearbeitung bis zu 8 Wochen in Anspruch nehmen.

Sie erhalten einen Steuerbescheid per Post, sobald die Prüfung abgeschlossen ist. Bei Rückfragen wenden Sie sich bitte an das Finanzamt Köln-West unter der Telefonnummer 0221 / 965 04-0.

Mit freundlichen Grüßen
Finanzamt Köln-West`,

    krankenkasse: `Sehr geehrte/r Versicherte/r,

Ihr Beitrag zur gesetzlichen Krankenversicherung wird ab dem 1. April 2024 auf monatlich 285,50 Euro festgesetzt. Dies entspricht dem allgemeinen Beitragssatz von 14,6% zuzüglich des kassenindividuellen Zusatzbeitrags von 1,3%.

Der Beitrag wird weiterhin per SEPA-Lastschrift von Ihrem Konto eingezogen. Bitte stellen Sie sicher, dass Ihr Konto ausreichend gedeckt ist.

Mit freundlichen Grüßen
Techniker Krankenkasse`,

    vermieter: `Abmahnung

Sehr geehrte/r Herr/Frau [Name],

wir weisen Sie hiermit darauf hin, dass in Ihrer Wohnung wiederholt gegen die Hausordnung verstoßen wurde. Konkret handelt es sich um das Abstellen von Fahrrädern im Treppenhaus sowie um Lärmbelästigung nach 22:00 Uhr.

Wir fordern Sie auf, dieses Verhalten umgehend einzustellen. Bei weiteren Verstößen behalten wir uns vor, das Mietverhältnis zu kündigen.

Mit freundlichen Grüßen
Hausverwaltung Köln GmbH`,

    auslaender: `Sehr geehrte Frau/Herr [Name],

Ihr Antrag auf Verlängerung der Aufenthaltserlaubnis wurde geprüft. Wir teilen Ihnen mit, dass zusätzliche Unterlagen für die Bearbeitung erforderlich sind.

Bitte reichen Sie innerhalb von 4 Wochen folgende Dokumente nach: aktueller Arbeitsvertrag, letzte drei Gehaltsabrechnungen sowie Nachweis über ausreichenden Krankenversicherungsschutz.

Ihr Aufenthaltsstatus gilt bis zur Entscheidung als rechtmäßig (§ 81 Abs. 4 AufenthG).

Ausländerbehörde Köln`,

    jobcenter: `Bescheid über Leistungen zur Sicherung des Lebensunterhalts

Sehr geehrte/r [Name],

aufgrund Ihres Antrags vom 15. Februar 2024 haben wir Ihren Anspruch auf Bürgergeld geprüft. Ab dem 1. März 2024 erhalten Sie monatlich 563,00 Euro Regelleistung.

Der Betrag wird am Monatsanfang auf das von Ihnen angegebene Konto überwiesen. Bitte melden Sie Änderungen in Ihren Einkommens- und Vermögensverhältnissen unverzüglich.

Jobcenter Köln`,
};

const AI_RESPONSES: Record<string, AiResponse> = {
    finanzamt: {
        type: 'Finanzamt (Tax Office)',
        summary: 'The tax office has received your 2023 tax return and is processing it. They say it may take up to 8 weeks due to high volume. You will receive your tax assessment (Steuerbescheid) by post once complete.',
        action: 'No action required right now. Wait for the Steuerbescheid letter by post. If you haven\'t received anything in 8 weeks, call Finanzamt Köln-West on 0221 / 965 04-0.',
        deadline: null,
        urgency: 'low',
    },
    krankenkasse: {
        type: 'Health Insurance (Krankenkasse)',
        summary: 'Your monthly health insurance contribution is changing to €285.50 from 1 April 2024. This is calculated from the standard rate of 14.6% plus your insurer\'s additional contribution of 1.3%. The payment will continue to be taken by direct debit from your bank account.',
        action: 'Make sure your bank account has enough funds on the first of each month. No other action needed — this change is automatic.',
        deadline: 'Effective from 1 April 2024',
        urgency: 'low',
    },
    vermieter: {
        type: 'Warning letter from landlord (Abmahnung)',
        summary: 'This is a formal warning (Abmahnung) from your landlord or property management. They are warning you about two specific violations: parking bikes in the stairwell and making noise after 10pm. They are threatening to terminate your tenancy if this continues.',
        action: 'Stop the behaviours mentioned immediately. Move your bike from the stairwell today. Be quiet after 22:00. An Abmahnung is a legal document — a second one can be grounds for eviction. Consider writing a brief reply acknowledging receipt.',
        deadline: 'Immediate — stop today',
        urgency: 'high',
    },
    auslaender: {
        type: 'Ausländerbehörde (Immigration Office)',
        summary: 'The immigration office is requesting additional documents to process your residence permit renewal. They need: your current work contract, your last 3 payslips, and proof of health insurance coverage.',
        action: 'Gather all three documents within 4 weeks and submit them to the Ausländerbehörde. Good news: your current residence status is legally valid while they process your application (§ 81 AufenthG), so you can continue working.',
        deadline: 'Within 4 weeks of letter date',
        urgency: 'medium',
    },
    jobcenter: {
        type: 'Jobcenter (Social Benefits Office)',
        summary: 'This is a benefits decision letter. You have been approved for Bürgergeld (citizen\'s allowance) of €563 per month starting 1 March 2024. The money will be transferred to your bank account at the start of each month.',
        action: 'No action needed to receive the payments. However, you must immediately report any changes to your income or assets to the Jobcenter — failure to do so can result in repayment demands.',
        deadline: 'Report any income changes immediately',
        urgency: 'low',
    },
};

const EXAMPLE_PILLS = [
    { key: 'finanzamt', emoji: '📋', label: 'Finanzamt letter' },
    { key: 'krankenkasse', emoji: '🏥', label: 'Krankenkasse notice' },
    { key: 'vermieter', emoji: '🏠', label: 'Vermieter warning' },
    { key: 'auslaender', emoji: '🏛️', label: 'Ausländerbehörde' },
    { key: 'jobcenter', emoji: '💼', label: 'Jobcenter notice' },
];

const TABS = [
    { id: 'checklist', label: 'Checklist' },
    { id: 'documents', label: 'Documents' },
    { id: 'slots', label: 'Slots' },
    { id: 'translator', label: 'AI Translator' },
];

const FILTERS = [
    { id: 'all', label: 'All (11)' },
    { id: 'pending', label: 'Pending (8)' },
    { id: 'done', label: 'Done (3)' },
    { id: 'urgent', label: 'Urgent (2)' },
];

// ============================================================
// Page
// ============================================================

export default function Bureaucracy() {
    const [tasks, setTasks] = useState<TaskData[]>(SEED_TASKS);
    const [activeTab, setActiveTab] = useState('checklist');
    const [taskFilter, setTaskFilter] = useState('all');
    const [expandedTask, setExpandedTask] = useState<number | null>(null);
    const [expandedDoc, setExpandedDoc] = useState<string | null>(null);
    const [docSearch, setDocSearch] = useState('');
    const [alertOn, setAlertOn] = useState(true);

    // AI Translator state
    const [pasteText, setPasteText] = useState('');
    const [currentExample, setCurrentExample] = useState<string | null>(null);
    const [translating, setTranslating] = useState(false);
    const [translationResult, setTranslationResult] = useState<AiResponse | null>(null);
    const resultRef = useRef<HTMLDivElement>(null);

    // Derived
    const doneCount = useMemo(() => tasks.filter((t) => t.done).length, [tasks]);
    const totalCount = tasks.length;
    const progressPct = Math.round((doneCount / totalCount) * 100);

    const filteredTasks = useMemo(() => {
        if (taskFilter === 'done') return tasks.filter((t) => t.done);
        if (taskFilter === 'pending') return tasks.filter((t) => !t.done);
        if (taskFilter === 'urgent') return tasks.filter((t) => t.urgent && !t.done);
        return tasks;
    }, [tasks, taskFilter]);

    const filteredDocs = useMemo(() => {
        const q = docSearch.toLowerCase().trim();
        if (!q) return SEED_DOCS;
        return SEED_DOCS.filter(
            (d) => d.de.toLowerCase().includes(q) || d.en.toLowerCase().includes(q) || d.desc.toLowerCase().includes(q),
        );
    }, [docSearch]);

    // Handlers
    function toggleTaskDone(id: number) {
        setTasks((prev) =>
            prev.map((t) => {
                if (t.id !== id) return t;
                const newDone = !t.done;
                let tag: TaskTag;
                if (newDone) {
                    tag = { label: 'Done', bg: '#D4F0E6', color: '#0A7C52' };
                } else if (t.urgent) {
                    tag = { label: 'Urgent', bg: '#FDE8E6', color: '#C4271A' };
                } else {
                    tag = { label: 'Pending', bg: '#EFEDE7', color: '#6B6860' };
                }
                return { ...t, done: newDone, tag };
            }),
        );
    }

    function loadExample(key: string) {
        setPasteText(EXAMPLE_TEXTS[key] || '');
        setCurrentExample(key);
        setTranslationResult(null);
    }

    async function translateLetter() {
        if (!pasteText.trim()) return;
        setTranslating(true);
        setTranslationResult(null);

        // Simulate API delay
        await new Promise((r) => setTimeout(r, 1500 + Math.random() * 1000));

        const resp =
            currentExample && AI_RESPONSES[currentExample]
                ? AI_RESPONSES[currentExample]
                : {
                      type: 'German Official Document',
                      summary: 'This appears to be an official German document. The sender is requesting your attention to an administrative matter. The document outlines specific requirements or changes that affect you.',
                      action: 'Read the document carefully and note any deadlines. If unsure, contact the issuing office directly or seek advice from a local expat support organisation.',
                      deadline: null,
                      urgency: 'medium' as const,
                  };

        setTranslating(false);
        setTranslationResult(resp);

        // Scroll into view
        setTimeout(() => {
            resultRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 100);
    }

    function switchTab(tab: string) {
        setActiveTab(tab);
    }

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Bureaucracy', href: '/bureaucracy' }]}
            rightPanel={<BureaucracyRightPanel onSwitchTab={switchTab} />}
        >
            <Head title="Bureaucracy" />
            <div className="mx-auto w-full max-w-[680px]">
                {/* ── Sticky header: title + tabs ── */}
                <div
                    className="sticky top-0 z-50 border-b border-[#E2DFD6] px-6 py-3.5"
                    style={{ background: 'rgba(246,245,241,.94)', backdropFilter: 'blur(16px)' }}
                >
                    <div className="flex items-center justify-between">
                        <span
                            className="shrink-0"
                            style={{ fontFamily: "'Fraunces', serif", fontSize: 20, fontWeight: 500, letterSpacing: '-0.01em' }}
                        >
                            Bureaucracy Helper
                        </span>
                    </div>
                    <div className="mt-2 flex gap-0">
                        {TABS.map((t) => (
                            <button
                                key={t.id}
                                onClick={() => setActiveTab(t.id)}
                                className="cursor-pointer border-none bg-transparent px-3 py-2 transition-all"
                                style={{
                                    fontFamily: "'Geist', sans-serif",
                                    fontSize: 13,
                                    fontWeight: 600,
                                    color: activeTab === t.id ? '#1A4CD4' : '#6B6860',
                                    borderBottom: activeTab === t.id ? '2px solid #1A4CD4' : '2px solid transparent',
                                }}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* ════ CHECKLIST TAB ════ */}
                {activeTab === 'checklist' && (
                    <>
                        {/* Progress hero */}
                        <div style={{ padding: '20px 24px 0' }}>
                            <div
                                style={{
                                    background: '#1A4CD4',
                                    borderRadius: 20,
                                    padding: '22px 24px',
                                    color: 'white',
                                    position: 'relative',
                                    overflow: 'hidden',
                                }}
                            >
                                {/* Decorative circle */}
                                <div
                                    style={{
                                        position: 'absolute',
                                        top: -50,
                                        right: -50,
                                        width: 180,
                                        height: 180,
                                        background: 'rgba(255,255,255,.05)',
                                        borderRadius: '50%',
                                    }}
                                />
                                <div
                                    style={{
                                        fontSize: 10,
                                        fontWeight: 700,
                                        textTransform: 'uppercase',
                                        letterSpacing: '0.10em',
                                        opacity: 0.65,
                                        marginBottom: 6,
                                    }}
                                >
                                    Your settlement checklist · Non-EU employee
                                </div>
                                <div
                                    style={{
                                        fontFamily: "'Fraunces', serif",
                                        fontSize: 24,
                                        fontWeight: 400,
                                        lineHeight: 1.2,
                                        marginBottom: 16,
                                        position: 'relative',
                                        zIndex: 1,
                                    }}
                                >
                                    {doneCount} of {totalCount} tasks complete —
                                    <br />
                                    {doneCount === totalCount ? 'all done!' : "you're making good progress."}
                                </div>
                                {/* Progress bar */}
                                <div
                                    style={{
                                        background: 'rgba(255,255,255,.2)',
                                        borderRadius: 20,
                                        height: 6,
                                        marginBottom: 8,
                                    }}
                                >
                                    <div
                                        style={{
                                            background: 'white',
                                            borderRadius: 20,
                                            height: 6,
                                            width: `${progressPct}%`,
                                            transition: 'width .6s cubic-bezier(.32,1,.4,1)',
                                        }}
                                    />
                                </div>
                                <div
                                    style={{
                                        fontFamily: "'Geist Mono', monospace",
                                        fontSize: 13,
                                        opacity: 0.8,
                                        display: 'flex',
                                        justifyContent: 'space-between',
                                    }}
                                >
                                    <span>{doneCount} done</span>
                                    <span>{totalCount - doneCount} remaining</span>
                                </div>
                            </div>
                        </div>

                        {/* Filter pills */}
                        <div
                            className="flex gap-1.5 overflow-x-auto border-b border-[#E2DFD6]"
                            style={{ padding: '16px 24px 12px', scrollbarWidth: 'none' }}
                        >
                            {FILTERS.map((f) => (
                                <button
                                    key={f.id}
                                    onClick={() => setTaskFilter(f.id)}
                                    className="shrink-0 cursor-pointer rounded-full border px-3 py-[5px] transition-all"
                                    style={{
                                        fontSize: 12,
                                        fontWeight: 500,
                                        fontFamily: "'Geist', sans-serif",
                                        whiteSpace: 'nowrap',
                                        background: taskFilter === f.id ? '#1A4CD4' : 'white',
                                        color: taskFilter === f.id ? 'white' : '#6B6860',
                                        borderColor: taskFilter === f.id ? '#1A4CD4' : '#E2DFD6',
                                    }}
                                >
                                    {f.label}
                                </button>
                            ))}
                        </div>

                        {/* Task list */}
                        <div>
                            {filteredTasks.map((task) => (
                                <TaskCard
                                    key={task.id}
                                    task={task}
                                    expanded={expandedTask === task.id}
                                    onToggleExpand={() => setExpandedTask(expandedTask === task.id ? null : task.id)}
                                    onToggleDone={() => toggleTaskDone(task.id)}
                                />
                            ))}
                        </div>
                    </>
                )}

                {/* ════ DOCUMENTS TAB ════ */}
                {activeTab === 'documents' && (
                    <div style={{ padding: '20px 24px' }}>
                        {/* Section header */}
                        <div className="mb-3 flex items-center justify-between">
                            <span style={{ fontSize: 15, fontWeight: 700 }}>Document Library</span>
                            <span style={{ fontSize: 12, color: '#6B6860' }}>32 documents</span>
                        </div>

                        {/* Search */}
                        <div
                            className="mb-4 flex items-center gap-[9px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-[#1A4CD4] focus-within:bg-white focus-within:shadow-[0_0_0_3px_#EBF0FD]"
                        >
                            <span style={{ fontSize: 15, color: '#AAA89F' }}>🔍</span>
                            <input
                                type="text"
                                placeholder="Search German documents…"
                                value={docSearch}
                                onChange={(e) => setDocSearch(e.target.value)}
                                className="flex-1 border-none bg-transparent text-sm outline-none placeholder:text-[#AAA89F]"
                                style={{ fontFamily: "'Geist', sans-serif", fontSize: 14, color: '#18170F' }}
                            />
                            {docSearch && (
                                <button
                                    onClick={() => setDocSearch('')}
                                    className="cursor-pointer border-none bg-transparent text-[13px] text-[#AAA89F]"
                                >
                                    ✕
                                </button>
                            )}
                        </div>

                        {/* Document cards */}
                        {filteredDocs.map((doc) => (
                            <DocumentCard
                                key={doc.de}
                                doc={doc}
                                expanded={expandedDoc === doc.de}
                                onToggle={() => setExpandedDoc(expandedDoc === doc.de ? null : doc.de)}
                            />
                        ))}

                        {filteredDocs.length === 0 && (
                            <div className="py-12 text-center" style={{ color: '#AAA89F' }}>
                                <div style={{ fontSize: 36, marginBottom: 12 }}>🔍</div>
                                <div style={{ fontSize: 15, fontWeight: 600, color: '#6B6860', marginBottom: 6 }}>No documents found</div>
                                <div style={{ fontSize: 13 }}>Try a different search term</div>
                            </div>
                        )}
                    </div>
                )}

                {/* ════ SLOTS TAB ════ */}
                {activeTab === 'slots' && (
                    <div style={{ padding: '20px 24px' }}>
                        {/* Slot hero */}
                        <div
                            style={{
                                background: 'linear-gradient(135deg, #1B3A8A 0%, #1A4CD4 100%)',
                                borderRadius: 20,
                                padding: '20px 22px',
                                color: 'white',
                                position: 'relative',
                                overflow: 'hidden',
                                marginBottom: 16,
                            }}
                        >
                            {/* Decorative circle */}
                            <div
                                style={{
                                    position: 'absolute',
                                    bottom: -40,
                                    right: -40,
                                    width: 140,
                                    height: 140,
                                    background: 'rgba(255,255,255,.06)',
                                    borderRadius: '50%',
                                }}
                            />
                            <div
                                style={{
                                    fontSize: 10,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.10em',
                                    opacity: 0.65,
                                    marginBottom: 5,
                                }}
                            >
                                Bürgeramt · Cologne
                            </div>
                            <div
                                style={{
                                    fontFamily: "'Fraunces', serif",
                                    fontSize: 20,
                                    fontWeight: 400,
                                    marginBottom: 12,
                                    position: 'relative',
                                    zIndex: 1,
                                }}
                            >
                                Appointment slot monitor — Cologne offices
                            </div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10, position: 'relative', zIndex: 1 }}>
                                <div
                                    style={{
                                        width: 10,
                                        height: 10,
                                        borderRadius: '50%',
                                        background: '#4ADE80',
                                        flexShrink: 0,
                                        animation: 'pulse 2s infinite',
                                    }}
                                />
                                <div>
                                    <div style={{ fontSize: 14, fontWeight: 600 }}>Monitoring 4 offices · Checking every 15 min</div>
                                    <div style={{ fontSize: 12, opacity: 0.75, marginTop: 3 }}>Last checked: just now</div>
                                </div>
                            </div>
                        </div>

                        {/* Global alert toggle */}
                        <div
                            onClick={() => setAlertOn(!alertOn)}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                padding: '12px 14px',
                                background: '#EFEDE7',
                                borderRadius: 9,
                                marginBottom: 10,
                                cursor: 'pointer',
                            }}
                        >
                            <div>
                                <div style={{ fontSize: 13, fontWeight: 600 }}>🔔 Instant slot alerts</div>
                                <div style={{ fontSize: 12, color: '#6B6860', marginTop: 1 }}>
                                    Push notification the moment a slot opens
                                </div>
                            </div>
                            <div
                                style={{
                                    width: 44,
                                    height: 24,
                                    borderRadius: 20,
                                    background: alertOn ? '#0A7C52' : '#E2DFD6',
                                    position: 'relative',
                                    transition: 'background .25s',
                                    flexShrink: 0,
                                }}
                            >
                                <div
                                    style={{
                                        position: 'absolute',
                                        top: 3,
                                        left: alertOn ? 23 : 3,
                                        width: 18,
                                        height: 18,
                                        borderRadius: '50%',
                                        background: 'white',
                                        transition: 'left .25s cubic-bezier(.32,1,.4,1)',
                                        boxShadow: '0 1px 4px rgba(0,0,0,.2)',
                                    }}
                                />
                            </div>
                        </div>

                        {/* Section header */}
                        <div className="mb-3 flex items-center justify-between">
                            <span style={{ fontSize: 15, fontWeight: 700 }}>Cologne Offices</span>
                            <span style={{ fontSize: 12, color: '#1A4CD4', fontWeight: 600, cursor: 'pointer' }}>Add office</span>
                        </div>

                        {/* Office cards */}
                        {SEED_OFFICES.map((office, i) => (
                            <OfficeCard key={i} office={office} />
                        ))}
                    </div>
                )}

                {/* ════ AI TRANSLATOR TAB ════ */}
                {activeTab === 'translator' && (
                    <div style={{ padding: '20px 24px' }}>
                        {/* Hero */}
                        <div
                            style={{
                                background: '#EFEDE7',
                                borderRadius: 20,
                                padding: '20px 22px',
                                marginBottom: 16,
                                border: '1px solid #E2DFD6',
                            }}
                        >
                            <div style={{ fontSize: 32, marginBottom: 10 }}>🤖</div>
                            <div style={{ fontFamily: "'Fraunces', serif", fontSize: 20, fontWeight: 500, marginBottom: 4 }}>
                                AI Letter Translator
                            </div>
                            <div style={{ fontSize: 13, color: '#6B6860', lineHeight: 1.5 }}>
                                Paste any German official letter or document. Anker will explain what it means in plain English and tell you exactly what to do next.
                            </div>
                        </div>

                        {/* Paste area */}
                        <div
                            className="paste-area-wrapper transition-all focus-within:shadow-[0_0_0_3px_#EBF0FD]"
                            style={{
                                background: 'white',
                                border: '1.5px dashed #E2DFD6',
                                borderRadius: 14,
                                padding: 16,
                                marginBottom: 12,
                                position: 'relative',
                            }}
                        >
                            <div
                                style={{
                                    fontSize: 11,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.08em',
                                    color: '#AAA89F',
                                    marginBottom: 8,
                                }}
                            >
                                Paste German text here
                            </div>
                            <textarea
                                value={pasteText}
                                onChange={(e) => {
                                    setPasteText(e.target.value);
                                    setCurrentExample(null);
                                    setTranslationResult(null);
                                }}
                                placeholder="Sehr geehrte Damen und Herren, hiermit teilen wir Ihnen mit…"
                                rows={5}
                                className="placeholder:text-[#AAA89F]"
                                style={{
                                    width: '100%',
                                    border: 'none',
                                    background: 'transparent',
                                    fontFamily: "'Geist', sans-serif",
                                    fontSize: 14,
                                    color: '#18170F',
                                    outline: 'none',
                                    resize: 'none',
                                    lineHeight: 1.6,
                                    minHeight: 100,
                                }}
                            />
                            <div style={{ fontSize: 11, color: '#AAA89F', marginTop: 8, display: 'flex', alignItems: 'center', gap: 5 }}>
                                🔒 Your text is never stored or used for training
                            </div>
                        </div>

                        {/* Example pills */}
                        <div style={{ marginBottom: 12 }}>
                            <div
                                style={{
                                    fontSize: 11,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.08em',
                                    color: '#AAA89F',
                                    marginBottom: 8,
                                }}
                            >
                                Or try an example
                            </div>
                            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 12 }}>
                                {EXAMPLE_PILLS.map((pill) => (
                                    <button
                                        key={pill.key}
                                        onClick={() => loadExample(pill.key)}
                                        className="cursor-pointer transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] hover:text-[#1A4CD4]"
                                        style={{
                                            fontSize: 12,
                                            padding: '5px 11px',
                                            borderRadius: 100,
                                            background: 'white',
                                            border: '1px solid #E2DFD6',
                                            color: '#6B6860',
                                            whiteSpace: 'nowrap',
                                            fontFamily: "'Geist', sans-serif",
                                        }}
                                    >
                                        {pill.emoji} {pill.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Translate button */}
                        <button
                            onClick={translateLetter}
                            disabled={translating || !pasteText.trim()}
                            className="cursor-pointer transition-all hover:enabled:bg-[#1540B8]"
                            style={{
                                width: '100%',
                                padding: 13,
                                borderRadius: 9,
                                border: 'none',
                                background: '#1A4CD4',
                                color: 'white',
                                fontFamily: "'Geist', sans-serif",
                                fontSize: 15,
                                fontWeight: 600,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                gap: 8,
                                marginBottom: 16,
                                opacity: translating || !pasteText.trim() ? 0.5 : 1,
                            }}
                        >
                            {translating ? (
                                <>
                                    <div
                                        style={{
                                            width: 16,
                                            height: 16,
                                            border: '2px solid rgba(255,255,255,.3)',
                                            borderTopColor: 'white',
                                            borderRadius: '50%',
                                            animation: 'spin .7s linear infinite',
                                        }}
                                    />
                                    <span>Translating…</span>
                                </>
                            ) : (
                                <>
                                    <span>✨</span>
                                    <span>Translate & Explain</span>
                                </>
                            )}
                        </button>

                        {/* Translation result */}
                        {translationResult && (
                            <div
                                ref={resultRef}
                                style={{
                                    background: 'white',
                                    border: '1px solid #E2DFD6',
                                    borderRadius: 14,
                                    overflow: 'hidden',
                                    animation: 'fadeUp .4s ease both',
                                }}
                            >
                                {/* Header */}
                                <div
                                    style={{
                                        padding: '14px 16px',
                                        background: '#D4F0E6',
                                        borderBottom: '1px solid rgba(10,124,82,.15)',
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 10,
                                    }}
                                >
                                    <span style={{ fontSize: 18 }}>✅</span>
                                    <div>
                                        <div style={{ fontSize: 14, fontWeight: 700, color: '#0A7C52' }}>Translation complete</div>
                                        <div style={{ fontSize: 12, color: '#0A7C52', opacity: 0.8 }}>{translationResult.type}</div>
                                    </div>
                                    <span
                                        style={{
                                            marginLeft: 'auto',
                                            fontSize: 10,
                                            fontWeight: 700,
                                            padding: '3px 9px',
                                            borderRadius: 20,
                                            textTransform: 'uppercase',
                                            letterSpacing: '0.05em',
                                            background:
                                                translationResult.urgency === 'high'
                                                    ? '#FDE8E6'
                                                    : translationResult.urgency === 'medium'
                                                      ? '#FDF0D4'
                                                      : '#D4F0E6',
                                            color:
                                                translationResult.urgency === 'high'
                                                    ? '#C4271A'
                                                    : translationResult.urgency === 'medium'
                                                      ? '#C47D0E'
                                                      : '#0A7C52',
                                        }}
                                    >
                                        {translationResult.urgency} priority
                                    </span>
                                </div>

                                {/* Body */}
                                <div style={{ padding: 16 }}>
                                    {/* Summary */}
                                    <div style={{ marginBottom: 16 }}>
                                        <div
                                            style={{
                                                fontSize: 10,
                                                fontWeight: 700,
                                                textTransform: 'uppercase',
                                                letterSpacing: '0.08em',
                                                color: '#AAA89F',
                                                marginBottom: 6,
                                            }}
                                        >
                                            What this letter says
                                        </div>
                                        <div style={{ fontSize: 14, color: '#18170F', lineHeight: 1.6 }}>
                                            {translationResult.summary}
                                        </div>
                                    </div>

                                    {/* Action */}
                                    <div style={{ marginBottom: 16 }}>
                                        <div
                                            style={{
                                                fontSize: 10,
                                                fontWeight: 700,
                                                textTransform: 'uppercase',
                                                letterSpacing: '0.08em',
                                                color: '#AAA89F',
                                                marginBottom: 6,
                                            }}
                                        >
                                            What you need to do
                                        </div>
                                        <div
                                            style={{
                                                background: '#EBF0FD',
                                                borderRadius: 9,
                                                padding: '12px 14px',
                                                display: 'flex',
                                                alignItems: 'flex-start',
                                                gap: 10,
                                            }}
                                        >
                                            <span style={{ fontSize: 18, flexShrink: 0 }}>👉</span>
                                            <div style={{ fontSize: 13, color: '#1A4CD4', lineHeight: 1.5, fontWeight: 500 }}>
                                                {translationResult.action}
                                            </div>
                                        </div>

                                        {/* Deadline */}
                                        {translationResult.deadline && (
                                            <div
                                                style={{
                                                    background: '#FDF0D4',
                                                    borderRadius: 9,
                                                    padding: '10px 14px',
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    gap: 10,
                                                    marginTop: 10,
                                                }}
                                            >
                                                <span style={{ fontSize: 16, flexShrink: 0 }}>⏰</span>
                                                <span style={{ fontSize: 13, color: '#C47D0E', fontWeight: 600 }}>
                                                    Deadline: {translationResult.deadline}
                                                </span>
                                            </div>
                                        )}
                                    </div>

                                    {/* Action buttons */}
                                    <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
                                        <button
                                            className="cursor-pointer transition-all hover:bg-[#E2DFD6]"
                                            style={{
                                                flex: 1,
                                                padding: 10,
                                                borderRadius: 9,
                                                border: '1px solid #E2DFD6',
                                                background: '#EFEDE7',
                                                fontFamily: "'Geist', sans-serif",
                                                fontSize: 13,
                                                fontWeight: 600,
                                            }}
                                        >
                                            💾 Save translation
                                        </button>
                                        <button
                                            className="cursor-pointer transition-all hover:bg-[#E2DFD6]"
                                            style={{
                                                flex: 1,
                                                padding: 10,
                                                borderRadius: 9,
                                                border: '1px solid #E2DFD6',
                                                background: '#EFEDE7',
                                                fontFamily: "'Geist', sans-serif",
                                                fontSize: 13,
                                                fontWeight: 600,
                                            }}
                                        >
                                            📋 Copy
                                        </button>
                                        <button
                                            onClick={() => setActiveTab('checklist')}
                                            className="cursor-pointer transition-all hover:bg-[#1540B8]"
                                            style={{
                                                flex: 1,
                                                padding: 10,
                                                borderRadius: 9,
                                                border: 'none',
                                                background: '#1A4CD4',
                                                color: 'white',
                                                fontFamily: "'Geist', sans-serif",
                                                fontSize: 13,
                                                fontWeight: 600,
                                            }}
                                        >
                                            Add to checklist
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* CSS keyframe animations */}
            <style>{`
                @keyframes pulse {
                    0%, 100% { opacity: 1; transform: scale(1); }
                    50% { opacity: 0.5; transform: scale(0.7); }
                }
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
                @keyframes fadeUp {
                    from { opacity: 0; transform: translateY(12px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .paste-area-wrapper:focus-within {
                    border-style: solid;
                    border-color: #1A4CD4;
                }
            `}</style>
        </AppLayout>
    );
}
