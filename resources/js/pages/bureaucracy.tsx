import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { BureaucracyRightPanel } from '@/components/bureaucracy/bureaucracy-right-panel';
import { DocumentCard } from '@/components/bureaucracy/document-card';
import { OfficeCard } from '@/components/bureaucracy/office-card';
import { TaskCard } from '@/components/bureaucracy/task-card';
import { useTabState } from '@/hooks/use-tab-state';
import { useTracker } from '@/hooks/use-tracker';
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

// Backend shape from BureaucracyController
type DbTask = {
    id: number;
    title: string;
    description: string | null;
    urgency: string;
    phase: string | null;
    deadline_type: string;
    deadline_days: number | null;
    documents_required: string[];
    links: string[];
    completed_at: string | null;
    absolute_deadline: string | null;
    days_remaining: number | null;
    deadline_urgency: string;
};

type TaskProgress = {
    completed: number;
    total: number;
    percent: number;
};

function deadlineLabel(t: DbTask): string {
    if (t.completed_at) {
return 'Completed';
}

    if (t.days_remaining === null) {
return 'No deadline';
}

    if (t.days_remaining < 0) {
return `Overdue by ${Math.abs(t.days_remaining)} days`;
}

    if (t.days_remaining === 0) {
return 'Due today';
}

    if (t.days_remaining <= 3) {
return `${t.days_remaining} days left — urgent`;
}

    if (t.days_remaining <= 7) {
return `${t.days_remaining} days left`;
}

    if (t.absolute_deadline) {
        const d = new Date(t.absolute_deadline);

        return `Due ${d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })} · ${t.days_remaining} days`;
    }

    return `${t.days_remaining} days left`;
}

function urgencyTagFromDeadline(t: DbTask): TaskTag {
    if (t.completed_at) {
return { label: 'Done', bg: '#D4F0E6', color: '#0A7C52' };
}

    if (t.deadline_urgency === 'overdue') {
return { label: 'Overdue', bg: '#FDE8E6', color: '#C4271A' };
}

    if (t.deadline_urgency === 'critical') {
return { label: 'Critical', bg: '#FDE8E6', color: '#C4271A' };
}

    if (t.deadline_urgency === 'urgent') {
return { label: 'Urgent', bg: '#FDF0D4', color: '#C47D0E' };
}

    if (t.urgency === 'critical' || t.urgency === 'high') {
return { label: 'Urgent', bg: '#FDE8E6', color: '#C4271A' };
}

    return { label: 'Pending', bg: '#EFEDE7', color: '#6B6860' };
}

function dbTaskToTaskData(t: DbTask): TaskData {
    const done = !!t.completed_at;
    const urgent =
        t.urgency === 'critical' ||
        t.urgency === 'high' ||
        t.deadline_urgency === 'overdue' ||
        t.deadline_urgency === 'critical';

    return {
        id: t.id,
        done,
        urgent,
        title: t.title,
        desc: t.description ?? '',
        tag: urgencyTagFromDeadline(t),
        steps: [],
        docs: t.documents_required ?? [],
        time: deadlineLabel(t),
        link: t.links?.[0] ?? null,
        linkLabel: t.links?.[0] ? 'More info ↗' : undefined,
    };
}

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
    id: string;
    name: string;
    address: string;
    category: string;
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

// No more SEED_TASKS — data comes from backend via dbTasks prop

const SEED_DOCS: DocData[] = [
    {
        emoji: '📋',
        de: 'Meldebescheinigung',
        en: 'Address Registration Certificate',
        desc: 'Proof that you have registered your address in Germany. Required for almost everything: bank accounts, Ausländerbehörde, tax office.',
        where: 'Issued at the Bürgeramt after Anmeldung',
        validity: 'Unlimited (content may go out of date)',
        tags: [
            { l: 'Essential', bg: '#FDE8E6', c: '#C4271A' },
            { l: 'Free', bg: '#D4F0E6', c: '#0A7C52' },
        ],
        detail: {
            what: 'A simple A4 document with your name, address, and registration date. Stamped by the Bürgeramt.',
            when: 'Any time you need to prove your German address to a third party.',
            howToGet:
                'Automatically issued after Anmeldung. Extra copies cost ~€5 at the Bürgeramt.',
            watchOut: 'Some services require it to be less than 3 months old.',
        },
    },
    {
        emoji: '🆔',
        de: 'Steuer-Identifikationsnummer',
        en: 'Tax Identification Number',
        desc: 'Your permanent 11-digit German tax number. Never changes, even if you move. Required by employers for payroll.',
        where: 'Sent by post from Bundeszentralamt für Steuern after Anmeldung',
        validity: 'Permanent — never expires',
        tags: [
            { l: 'Essential', bg: '#FDE8E6', c: '#C4271A' },
            { l: 'Automatic', bg: '#EBF0FD', c: '#1A4CD4' },
        ],
        detail: {
            what: 'An 11-digit number uniquely identifying you for tax purposes in Germany. Different from your Steuernummer (which is employer-specific).',
            when: 'Give it to your employer before your first payslip. Needed for tax returns.',
            howToGet:
                'Arrives automatically 2–4 weeks after Anmeldung. If lost, request at bundeszentralamt.de.',
            watchOut:
                'Do not confuse with Steuernummer (changes when you move) or USt-ID (for businesses).',
        },
    },
    {
        emoji: '🏥',
        de: 'Versicherungskarte',
        en: 'Health Insurance Card',
        desc: 'Your electronic health insurance card. Show it at every doctor visit, pharmacy, and hospital in Germany.',
        where: 'Sent by your health insurer after enrolment',
        validity: 'Renewed annually by insurer',
        tags: [{ l: 'Essential', bg: '#FDE8E6', c: '#C4271A' }],
        detail: {
            what: 'A chip card issued by your public health insurer (Krankenkasse). Contains your insurance data electronically.',
            when: 'Required at every healthcare appointment. Without it, you may be billed privately.',
            howToGet:
                'Automatically sent after enrolling with a Krankenkasse. Allow 1–2 weeks.',
            watchOut:
                'If you change insurers, your old card becomes invalid immediately.',
        },
    },
    {
        emoji: '📄',
        de: 'Lohnsteuerbescheinigung',
        en: 'Annual Payroll Tax Certificate',
        desc: 'Issued by your employer every January for the previous year. Needed for your tax return and proof of income.',
        where: 'Issued by your employer annually',
        validity: 'Annual document',
        tags: [{ l: 'Tax', bg: '#FDF0D4', c: '#C47D0E' }],
        detail: {
            what: 'A detailed breakdown of your gross pay, tax paid, social security contributions, and other deductions for the whole year.',
            when: 'Needed when filing a Steuererklärung (tax return). Also useful as proof of income for rental applications.',
            howToGet:
                'Your employer sends it electronically to the Finanzamt and gives you a copy in January/February.',
            watchOut:
                'Check it carefully — errors in the document affect your tax calculation.',
        },
    },
    {
        emoji: '📝',
        de: 'Wohnungsgeberbestätigung',
        en: 'Landlord Registration Confirmation',
        desc: 'A form your landlord must sign confirming you live at their property. Required for Anmeldung.',
        where: 'Provided by your landlord',
        validity: 'One-time use for Anmeldung',
        tags: [{ l: 'Required for Anmeldung', bg: '#EBF0FD', c: '#1A4CD4' }],
        detail: {
            what: 'A standardised form (Formular 18) that confirms your landlord has accepted you as a tenant at the registered address.',
            when: 'Must be presented at the Bürgeramt for Anmeldung. Without it, registration is impossible.',
            howToGet:
                'Download the form from the Bürgeramt website and ask your landlord to sign it. Some landlords provide it directly.',
            watchOut:
                'Your landlord is legally required to provide this within 2 weeks of you moving in.',
        },
    },
    {
        emoji: '🛂',
        de: 'Aufenthaltstitel',
        en: 'Residence Permit',
        desc: 'For non-EU citizens: official permit to live and work in Germany. Contains your visa conditions.',
        where: 'Issued by Ausländerbehörde',
        validity: 'Varies — typically 1–3 years initially',
        tags: [
            { l: 'Non-EU only', bg: '#EDE9FE', c: '#7C3AED' },
            { l: 'Critical', bg: '#FDE8E6', c: '#C4271A' },
        ],
        detail: {
            what: 'A physical card (since 2011) that serves as your official permission to reside in Germany. Contains biometric data and specifies what you are allowed to do.',
            when: 'Required to prove your legal right to work and live in Germany to employers, landlords, and authorities.',
            howToGet:
                'Apply at the Ausländerbehörde with extensive documentation. Allow 4–8 weeks for the card to be produced.',
            watchOut:
                'Apply before your current visa or entry stamp expires. Overstaying has serious consequences.',
        },
    },
];

// Slot data from the backend (BuergeramtService)
type SlotData = {
    name: string;
    address: string;
    category: string;
    status: string;
    next_slot: string | null;
    slots_today: number;
    booking_url: string;
};

/**
 * Convert backend slot data + monitors into OfficeData for the UI.
 */
function slotsToOffices(
    slots: Record<string, SlotData>,
    monitors: string[],
): OfficeData[] {
    return Object.entries(slots).map(([key, slot]) => {
        const isAvailable = slot.status === 'available';
        const isMostlyBooked = slot.status === 'mostly_booked';

        let nextSlot = 'No slots';

        if (slot.next_slot) {
            const d = new Date(slot.next_slot);
            const now = new Date();
            const diffDays = Math.round(
                (d.getTime() - now.getTime()) / (1000 * 60 * 60 * 24),
            );

            if (diffDays === 0) {
                nextSlot = `Today ${d.toLocaleTimeString('en-DE', { hour: '2-digit', minute: '2-digit' })}`;
            } else if (diffDays === 1) {
                nextSlot = `Tomorrow ${d.toLocaleTimeString('en-DE', { hour: '2-digit', minute: '2-digit' })}`;
            } else if (diffDays <= 7) {
                nextSlot = d.toLocaleDateString('en-DE', {
                    weekday: 'short',
                    day: 'numeric',
                    month: 'short',
                    hour: '2-digit',
                    minute: '2-digit',
                });
            } else {
                nextSlot = `In ${Math.ceil(diffDays / 7)} weeks`;
            }
        }

        let statusLabel = 'Fully booked';
        let color = '#C4271A';
        let colorS = '#FDE8E6';

        if (
            slot.status === 'check_online' ||
            slot.status === 'unavailable' ||
            slot.status === 'checking'
        ) {
            statusLabel = 'Check online →';
            color = '#1A4CD4';
            colorS = '#EBF0FD';
        } else if (isAvailable) {
            statusLabel =
                slot.slots_today === 1
                    ? '1 slot available'
                    : `${slot.slots_today} slots available`;
            color = '#0A7C52';
            colorS = '#D4F0E6';
        } else if (isMostlyBooked) {
            statusLabel = 'Mostly booked';
            color = '#C47D0E';
            colorS = '#FDF0D4';
        }

        const encodedAddress = encodeURIComponent(`${slot.address}, Köln`);

        return {
            id: key,
            name: slot.name,
            address: `${slot.address}, Köln`,
            category: slot.category ?? 'buergeramt',
            status: slot.status,
            nextSlot,
            distance: '',
            monitoring: monitors.includes(key),
            color,
            colorS,
            statusLabel,
            bookingUrl:
                slot.booking_url ||
                'https://termine.stadt-koeln.de/m/kundenzentren/extern/calendar/?uid=b5a5a394-ec33-4130-9af3-490f99517071',
            mapsUrl: `https://www.google.com/maps/dir/?api=1&destination=${encodedAddress}`,
        };
    });
}

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
        summary:
            'The tax office has received your 2023 tax return and is processing it. They say it may take up to 8 weeks due to high volume. You will receive your tax assessment (Steuerbescheid) by post once complete.',
        action: "No action required right now. Wait for the Steuerbescheid letter by post. If you haven't received anything in 8 weeks, call Finanzamt Köln-West on 0221 / 965 04-0.",
        deadline: null,
        urgency: 'low',
    },
    krankenkasse: {
        type: 'Health Insurance (Krankenkasse)',
        summary:
            "Your monthly health insurance contribution is changing to €285.50 from 1 April 2024. This is calculated from the standard rate of 14.6% plus your insurer's additional contribution of 1.3%. The payment will continue to be taken by direct debit from your bank account.",
        action: 'Make sure your bank account has enough funds on the first of each month. No other action needed — this change is automatic.',
        deadline: 'Effective from 1 April 2024',
        urgency: 'low',
    },
    vermieter: {
        type: 'Warning letter from landlord (Abmahnung)',
        summary:
            'This is a formal warning (Abmahnung) from your landlord or property management. They are warning you about two specific violations: parking bikes in the stairwell and making noise after 10pm. They are threatening to terminate your tenancy if this continues.',
        action: 'Stop the behaviours mentioned immediately. Move your bike from the stairwell today. Be quiet after 22:00. An Abmahnung is a legal document — a second one can be grounds for eviction. Consider writing a brief reply acknowledging receipt.',
        deadline: 'Immediate — stop today',
        urgency: 'high',
    },
    auslaender: {
        type: 'Ausländerbehörde (Immigration Office)',
        summary:
            'The immigration office is requesting additional documents to process your residence permit renewal. They need: your current work contract, your last 3 payslips, and proof of health insurance coverage.',
        action: 'Gather all three documents within 4 weeks and submit them to the Ausländerbehörde. Good news: your current residence status is legally valid while they process your application (§ 81 AufenthG), so you can continue working.',
        deadline: 'Within 4 weeks of letter date',
        urgency: 'medium',
    },
    jobcenter: {
        type: 'Jobcenter (Social Benefits Office)',
        summary:
            "This is a benefits decision letter. You have been approved for Bürgergeld (citizen's allowance) of €563 per month starting 1 March 2024. The money will be transferred to your bank account at the start of each month.",
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

// Filter labels are dynamic — computed from real task counts below

// ============================================================
// Page
// ============================================================

export default function Bureaucracy() {
    const { track } = useTracker();
    type BookingService = {
        key: string;
        name: string;
        name_en: string;
        emoji: string;
        duration: number;
        url: string;
    };

    const { slots, monitors, dbTasks, taskProgress, bookingServices } =
        usePage<{
            slots: Record<string, SlotData>;
            monitors: string[];
            dbTasks: DbTask[];
            taskProgress: TaskProgress;
            bookingServices?: BookingService[];
        }>().props;

    const offices = useMemo(
        () => slotsToOffices(slots ?? {}, monitors ?? []),
        [slots, monitors],
    );
    const monitoringCount = offices.filter((o) => o.monitoring).length;

    // Map backend tasks to frontend shape
    const tasks = useMemo(
        () => (dbTasks ?? []).map(dbTaskToTaskData),
        [dbTasks],
    );

    const FILTERS = useMemo(() => {
        const pending = tasks.filter((t) => !t.done).length;
        const done = tasks.filter((t) => t.done).length;
        const urgent = tasks.filter((t) => t.urgent && !t.done).length;

        return [
            { id: 'all', label: `All (${tasks.length})` },
            { id: 'pending', label: `Pending (${pending})` },
            { id: 'done', label: `Done (${done})` },
            { id: 'urgent', label: `Urgent (${urgent})` },
        ];
    }, [tasks]);

    const [activeTab, setActiveTab] = useTabState('checklist');
    const [taskFilter, setTaskFilter] = useState('all');
    const [expandedTask, setExpandedTask] = useState<number | null>(null);
    const [expandedDoc, setExpandedDoc] = useState<string | null>(null);
    const [docSearch, setDocSearch] = useState('');
    const [slotFilter, setSlotFilter] = useState('all');
    const [slotSearch, setSlotSearch] = useState('');

    // AI Translator state
    const [pasteText, setPasteText] = useState('');
    const [currentExample, setCurrentExample] = useState<string | null>(null);
    const [translating, setTranslating] = useState(false);
    const [translationResult, setTranslationResult] =
        useState<AiResponse | null>(null);
    const resultRef = useRef<HTMLDivElement>(null);

    // Derived from backend progress
    const doneCount =
        taskProgress?.completed ?? tasks.filter((t) => t.done).length;
    const totalCount = taskProgress?.total ?? tasks.length;
    const progressPct =
        taskProgress?.percent ??
        (totalCount > 0 ? Math.round((doneCount / totalCount) * 100) : 0);

    const filteredTasks = useMemo(() => {
        if (taskFilter === 'done') {
return tasks.filter((t) => t.done);
}

        if (taskFilter === 'pending') {
return tasks.filter((t) => !t.done);
}

        if (taskFilter === 'urgent') {
return tasks.filter((t) => t.urgent && !t.done);
}

        return tasks;
    }, [tasks, taskFilter]);

    const filteredDocs = useMemo(() => {
        const q = docSearch.toLowerCase().trim();

        if (!q) {
return SEED_DOCS;
}

        return SEED_DOCS.filter(
            (d) =>
                d.de.toLowerCase().includes(q) ||
                d.en.toLowerCase().includes(q) ||
                d.desc.toLowerCase().includes(q),
        );
    }, [docSearch]);

    // Handlers — toggle calls backend
    function toggleTaskDone(id: number) {
        const task = tasks.find((t) => t.id === id);

        if (task && !task.done) {
            track('task_completed', { task_id: id });
        }

        router.post(
            `/tasks/${id}/toggle`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    }

    function loadExample(key: string) {
        setPasteText(EXAMPLE_TEXTS[key] || '');
        setCurrentExample(key);
        setTranslationResult(null);
    }

    async function translateLetter() {
        if (!pasteText.trim()) {
return;
}

        setTranslating(true);
        setTranslationResult(null);

        // Simulate API delay
        await new Promise((r) => setTimeout(r, 1500 + Math.random() * 1000));

        const resp =
            currentExample && AI_RESPONSES[currentExample]
                ? AI_RESPONSES[currentExample]
                : {
                      type: 'German Official Document',
                      summary:
                          'This appears to be an official German document. The sender is requesting your attention to an administrative matter. The document outlines specific requirements or changes that affect you.',
                      action: 'Read the document carefully and note any deadlines. If unsure, contact the issuing office directly or seek advice from a local expat support organisation.',
                      deadline: null,
                      urgency: 'medium' as const,
                  };

        setTranslating(false);
        setTranslationResult(resp);

        // Scroll into view
        setTimeout(() => {
            resultRef.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
            });
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
                    style={{
                        background: 'rgba(246,245,241,.94)',
                        backdropFilter: 'blur(16px)',
                    }}
                >
                    <div className="flex items-center justify-between">
                        <span
                            className="shrink-0"
                            style={{
                                fontFamily: "'Fraunces', serif",
                                fontSize: 20,
                                fontWeight: 500,
                                letterSpacing: '-0.01em',
                            }}
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
                                    color:
                                        activeTab === t.id
                                            ? '#1A4CD4'
                                            : '#6B6860',
                                    borderBottom:
                                        activeTab === t.id
                                            ? '2px solid #1A4CD4'
                                            : '2px solid transparent',
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
                                    {doneCount === totalCount
                                        ? 'all done!'
                                        : "you're making good progress."}
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
                                            transition:
                                                'width .6s cubic-bezier(.32,1,.4,1)',
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
                                    <span>
                                        {totalCount - doneCount} remaining
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Filter pills */}
                        <div
                            className="flex gap-1.5 overflow-x-auto border-b border-[#E2DFD6]"
                            style={{
                                padding: '16px 24px 12px',
                                scrollbarWidth: 'none',
                            }}
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
                                        background:
                                            taskFilter === f.id
                                                ? '#1A4CD4'
                                                : 'white',
                                        color:
                                            taskFilter === f.id
                                                ? 'white'
                                                : '#6B6860',
                                        borderColor:
                                            taskFilter === f.id
                                                ? '#1A4CD4'
                                                : '#E2DFD6',
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
                                    onToggleExpand={() =>
                                        setExpandedTask(
                                            expandedTask === task.id
                                                ? null
                                                : task.id,
                                        )
                                    }
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
                            <span style={{ fontSize: 15, fontWeight: 700 }}>
                                Document Library
                            </span>
                            <span style={{ fontSize: 12, color: '#6B6860' }}>
                                32 documents
                            </span>
                        </div>

                        {/* Search */}
                        <div className="mb-4 flex items-center gap-[9px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-[#1A4CD4] focus-within:bg-white focus-within:shadow-[0_0_0_3px_#EBF0FD]">
                            <span style={{ fontSize: 15, color: '#AAA89F' }}>
                                🔍
                            </span>
                            <input
                                type="text"
                                placeholder="Search German documents…"
                                value={docSearch}
                                onChange={(e) => setDocSearch(e.target.value)}
                                className="flex-1 border-none bg-transparent text-sm outline-none placeholder:text-[#AAA89F]"
                                style={{
                                    fontFamily: "'Geist', sans-serif",
                                    fontSize: 14,
                                    color: '#18170F',
                                }}
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
                                onToggle={() =>
                                    setExpandedDoc(
                                        expandedDoc === doc.de ? null : doc.de,
                                    )
                                }
                            />
                        ))}

                        {filteredDocs.length === 0 && (
                            <div
                                className="py-12 text-center"
                                style={{ color: '#AAA89F' }}
                            >
                                <div style={{ fontSize: 36, marginBottom: 12 }}>
                                    🔍
                                </div>
                                <div
                                    style={{
                                        fontSize: 15,
                                        fontWeight: 600,
                                        color: '#6B6860',
                                        marginBottom: 6,
                                    }}
                                >
                                    No documents found
                                </div>
                                <div style={{ fontSize: 13 }}>
                                    Try a different search term
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* ════ SLOTS TAB ════ */}
                {activeTab === 'slots' && (
                    <div style={{ padding: '20px 24px' }}>
                        {/* Hero */}
                        <div
                            style={{
                                background: '#1A4CD4',
                                borderRadius: 20,
                                padding: '20px 22px',
                                color: 'white',
                                position: 'relative',
                                overflow: 'hidden',
                                marginBottom: 16,
                            }}
                        >
                            <div
                                className="pointer-events-none absolute"
                                style={{
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
                                Appointments · Cologne
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
                                Government offices &amp; locations
                            </div>
                            <div
                                style={{
                                    fontSize: 13,
                                    opacity: 0.8,
                                    position: 'relative',
                                    zIndex: 1,
                                    marginBottom: 14,
                                }}
                            >
                                Book online — select your service, then the
                                system shows available offices and times.
                            </div>
                            <a
                                href="https://termine.stadt-koeln.de/m/kundenzentren/extern/calendar/?uid=b5a5a394-ec33-4130-9af3-490f99517071"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="relative z-[1] inline-flex items-center gap-2 rounded-[9px] px-5 py-[10px] text-sm font-semibold transition-all hover:bg-white"
                                style={{
                                    background: 'rgba(255,255,255,.9)',
                                    color: '#1A4CD4',
                                    textDecoration: 'none',
                                }}
                            >
                                🏛️ Book Bürgeramt appointment →
                            </a>
                        </div>

                        {/* Search */}
                        <div className="mb-3 flex items-center gap-[9px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-[#1A4CD4] focus-within:bg-white focus-within:shadow-[0_0_0_3px_#EBF0FD]">
                            <span style={{ fontSize: 15, color: '#AAA89F' }}>
                                🔍
                            </span>
                            <input
                                type="text"
                                placeholder="Search offices…"
                                value={slotSearch}
                                onChange={(e) => setSlotSearch(e.target.value)}
                                className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F]"
                                style={{
                                    fontFamily: "'Geist', sans-serif",
                                    fontSize: 14,
                                }}
                            />
                            {slotSearch && (
                                <button
                                    onClick={() => setSlotSearch('')}
                                    className="cursor-pointer border-none bg-transparent text-[13px] text-[#AAA89F]"
                                >
                                    ✕
                                </button>
                            )}
                        </div>

                        {/* Category filter pills */}
                        <div
                            className="mb-4 flex gap-1.5 overflow-x-auto"
                            style={{ scrollbarWidth: 'none' }}
                        >
                            {[
                                { id: 'all', label: `All (${offices.length})` },
                                {
                                    id: 'buergeramt',
                                    label: `🏛️ Bürgeramt (${offices.filter((o) => o.category === 'buergeramt').length})`,
                                },
                                {
                                    id: 'auslaenderbehoerde',
                                    label: `🛂 Ausländerb. (${offices.filter((o) => o.category === 'auslaenderbehoerde').length})`,
                                },
                                {
                                    id: 'finanzamt',
                                    label: `📋 Finanzamt (${offices.filter((o) => o.category === 'finanzamt').length})`,
                                },
                                {
                                    id: 'kfz',
                                    label: `🚗 KFZ (${offices.filter((o) => o.category === 'kfz').length})`,
                                },
                            ].map((f) => (
                                <button
                                    key={f.id}
                                    onClick={() => setSlotFilter(f.id)}
                                    className="shrink-0 cursor-pointer rounded-full border px-3 py-[5px] transition-all"
                                    style={{
                                        fontSize: 12,
                                        fontWeight: 500,
                                        fontFamily: "'Geist', sans-serif",
                                        whiteSpace: 'nowrap',
                                        background:
                                            slotFilter === f.id
                                                ? '#1A4CD4'
                                                : 'white',
                                        color:
                                            slotFilter === f.id
                                                ? 'white'
                                                : '#6B6860',
                                        borderColor:
                                            slotFilter === f.id
                                                ? '#1A4CD4'
                                                : '#E2DFD6',
                                    }}
                                >
                                    {f.label}
                                </button>
                            ))}
                        </div>

                        {/* Grouped office cards */}
                        {(() => {
                            const q = slotSearch.toLowerCase().trim();
                            const filtered = offices.filter((o) => {
                                const matchCategory =
                                    slotFilter === 'all' ||
                                    o.category === slotFilter;
                                const matchSearch =
                                    !q ||
                                    o.name.toLowerCase().includes(q) ||
                                    o.address.toLowerCase().includes(q);

                                return matchCategory && matchSearch;
                            });

                            if (filtered.length === 0) {
                                return (
                                    <div
                                        className="py-12 text-center"
                                        style={{ color: '#AAA89F' }}
                                    >
                                        <div
                                            style={{
                                                fontSize: 36,
                                                marginBottom: 12,
                                            }}
                                        >
                                            🔍
                                        </div>
                                        <div
                                            style={{
                                                fontSize: 15,
                                                fontWeight: 600,
                                                color: '#6B6860',
                                                marginBottom: 6,
                                            }}
                                        >
                                            No offices found
                                        </div>
                                        <div style={{ fontSize: 13 }}>
                                            Try a different filter or search
                                            term
                                        </div>
                                    </div>
                                );
                            }

                            const GROUPS = [
                                {
                                    key: 'buergeramt',
                                    label: 'Bürgeramt',
                                    emoji: '🏛️',
                                },
                                {
                                    key: 'auslaenderbehoerde',
                                    label: 'Ausländerbehörde',
                                    emoji: '🛂',
                                },
                                {
                                    key: 'finanzamt',
                                    label: 'Finanzamt',
                                    emoji: '📋',
                                },
                                {
                                    key: 'kfz',
                                    label: 'KFZ-Zulassungsstelle',
                                    emoji: '🚗',
                                },
                            ];

                            return GROUPS.map((group) => {
                                const groupOffices = filtered.filter(
                                    (o) => o.category === group.key,
                                );

                                if (groupOffices.length === 0) {
return null;
}

                                const bookingUrl = groupOffices[0]?.bookingUrl;

                                return (
                                    <div key={group.key} className="mb-5">
                                        <div className="mb-2 flex items-center justify-between">
                                            <span
                                                style={{
                                                    fontSize: 14,
                                                    fontWeight: 700,
                                                }}
                                            >
                                                {group.emoji} {group.label}
                                            </span>
                                            {bookingUrl && (
                                                <a
                                                    href={bookingUrl}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="transition-colors hover:text-[#1540B8]"
                                                    style={{
                                                        fontSize: 12,
                                                        color: '#1A4CD4',
                                                        fontWeight: 600,
                                                        textDecoration: 'none',
                                                    }}
                                                >
                                                    Book appointment →
                                                </a>
                                            )}
                                        </div>
                                        {groupOffices.map((office) => (
                                            <OfficeCard
                                                key={office.id}
                                                office={office}
                                                onToggleMonitor={() => {
                                                    router.post(
                                                        '/slots/toggle',
                                                        {
                                                            office_id:
                                                                office.id,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }}
                                            />
                                        ))}
                                    </div>
                                );
                            });
                        })()}
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
                            <div style={{ fontSize: 32, marginBottom: 10 }}>
                                🤖
                            </div>
                            <div
                                style={{
                                    fontFamily: "'Fraunces', serif",
                                    fontSize: 20,
                                    fontWeight: 500,
                                    marginBottom: 4,
                                }}
                            >
                                AI Letter Translator
                            </div>
                            <div
                                style={{
                                    fontSize: 13,
                                    color: '#6B6860',
                                    lineHeight: 1.5,
                                }}
                            >
                                Paste any German official letter or document.
                                Anker will explain what it means in plain
                                English and tell you exactly what to do next.
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
                            <div
                                style={{
                                    fontSize: 11,
                                    color: '#AAA89F',
                                    marginTop: 8,
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 5,
                                }}
                            >
                                🔒 Your text is never stored or used for
                                training
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
                            <div
                                style={{
                                    display: 'flex',
                                    gap: 6,
                                    flexWrap: 'wrap',
                                    marginBottom: 12,
                                }}
                            >
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
                                opacity:
                                    translating || !pasteText.trim() ? 0.5 : 1,
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
                                            animation:
                                                'spin .7s linear infinite',
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
                                        borderBottom:
                                            '1px solid rgba(10,124,82,.15)',
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 10,
                                    }}
                                >
                                    <span style={{ fontSize: 18 }}>✅</span>
                                    <div>
                                        <div
                                            style={{
                                                fontSize: 14,
                                                fontWeight: 700,
                                                color: '#0A7C52',
                                            }}
                                        >
                                            Translation complete
                                        </div>
                                        <div
                                            style={{
                                                fontSize: 12,
                                                color: '#0A7C52',
                                                opacity: 0.8,
                                            }}
                                        >
                                            {translationResult.type}
                                        </div>
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
                                                translationResult.urgency ===
                                                'high'
                                                    ? '#FDE8E6'
                                                    : translationResult.urgency ===
                                                        'medium'
                                                      ? '#FDF0D4'
                                                      : '#D4F0E6',
                                            color:
                                                translationResult.urgency ===
                                                'high'
                                                    ? '#C4271A'
                                                    : translationResult.urgency ===
                                                        'medium'
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
                                        <div
                                            style={{
                                                fontSize: 14,
                                                color: '#18170F',
                                                lineHeight: 1.6,
                                            }}
                                        >
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
                                            <span
                                                style={{
                                                    fontSize: 18,
                                                    flexShrink: 0,
                                                }}
                                            >
                                                👉
                                            </span>
                                            <div
                                                style={{
                                                    fontSize: 13,
                                                    color: '#1A4CD4',
                                                    lineHeight: 1.5,
                                                    fontWeight: 500,
                                                }}
                                            >
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
                                                <span
                                                    style={{
                                                        fontSize: 16,
                                                        flexShrink: 0,
                                                    }}
                                                >
                                                    ⏰
                                                </span>
                                                <span
                                                    style={{
                                                        fontSize: 13,
                                                        color: '#C47D0E',
                                                        fontWeight: 600,
                                                    }}
                                                >
                                                    Deadline:{' '}
                                                    {translationResult.deadline}
                                                </span>
                                            </div>
                                        )}
                                    </div>

                                    {/* Action buttons */}
                                    <div
                                        style={{
                                            display: 'flex',
                                            gap: 8,
                                            marginTop: 14,
                                        }}
                                    >
                                        <button
                                            className="cursor-pointer transition-all hover:bg-[#E2DFD6]"
                                            style={{
                                                flex: 1,
                                                padding: 10,
                                                borderRadius: 9,
                                                border: '1px solid #E2DFD6',
                                                background: '#EFEDE7',
                                                fontFamily:
                                                    "'Geist', sans-serif",
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
                                                fontFamily:
                                                    "'Geist', sans-serif",
                                                fontSize: 13,
                                                fontWeight: 600,
                                            }}
                                        >
                                            📋 Copy
                                        </button>
                                        <button
                                            onClick={() =>
                                                setActiveTab('checklist')
                                            }
                                            className="cursor-pointer transition-all hover:bg-[#1540B8]"
                                            style={{
                                                flex: 1,
                                                padding: 10,
                                                borderRadius: 9,
                                                border: 'none',
                                                background: '#1A4CD4',
                                                color: 'white',
                                                fontFamily:
                                                    "'Geist', sans-serif",
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
