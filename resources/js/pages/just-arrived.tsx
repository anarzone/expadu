import { Head } from '@inertiajs/react';
import { useState, useCallback, useEffect  } from 'react';
import type {MouseEvent} from 'react';
import { JourneyRightPanel } from '@/components/journey/journey-right-panel';
import {
    StepCard
    
    
} from '@/components/journey/step-card';
import type {StepData, StepTag} from '@/components/journey/step-card';
import AppLayout from '@/layouts/app-layout';

// ════════════════════════════════════════
// DATA
// ════════════════════════════════════════
type Phase = { id: string; label: string; icon: string; days: string };

const phases: Phase[] = [
    { id: 'arrival', label: 'Arrival', icon: '✈️', days: 'Day 1–3' },
    { id: 'week1', label: 'Week 1', icon: '🏠', days: 'Days 4–7' },
    { id: 'week2', label: 'Week 2', icon: '📋', days: 'Days 8–14' },
    { id: 'month1', label: 'Month 1', icon: '🏛️', days: 'Weeks 3–4' },
    { id: 'month2', label: 'Month 2', icon: '🏥', days: 'Weeks 5–8' },
    { id: 'month3', label: 'Month 3+', icon: '🌟', days: 'Ongoing' },
];

const initialSteps: StepData[] = [
    // ARRIVAL
    {
        id: 1,
        phase: 'arrival',
        done: true,
        skipped: false,
        urgent: false,
        upcoming: false,
        title: 'Get a German SIM card',
        desc: 'You need a German phone number for almost everything — bank accounts, appointments, and verifications.',
        tag: { label: 'Done', bg: '#D4F0E6', color: '#0A7C52' },
        timing: '15 min · In-store',
        link: null,
        instructions: [
            'Go to a Telekom, O2, or Vodafone shop',
            'Bring your passport for identity verification',
            'Choose a monthly plan — Telekom has the best coverage',
            'You can get a prepaid SIM if you prefer no contract',
        ],
        docs: ['Passport'],
        tip: {
            text: '"Telekom is pricier but coverage is much better around Cologne, especially the U-Bahn."',
            author: 'Sarah K.',
        },
        actions: [
            {
                label: 'Find nearest Telekom',
                url: 'https://www.telekom.de/kontakt/shops',
            },
        ],
    },
    {
        id: 2,
        phase: 'arrival',
        done: true,
        skipped: false,
        urgent: false,
        upcoming: false,
        title: 'Save emergency contacts',
        desc: 'Know the essential German emergency numbers before you need them.',
        tag: { label: 'Done', bg: '#D4F0E6', color: '#0A7C52' },
        timing: '5 min',
        link: null,
        instructions: [
            'Emergency: 112',
            'Police: 110',
            'Medical emergencies: 112',
            'Poison control: 0800 192 0087',
            'ADAC (roadside): 01802 222 222',
        ],
        docs: [],
        tip: null,
        actions: [],
    },
    {
        id: 3,
        phase: 'arrival',
        done: true,
        skipped: false,
        urgent: false,
        upcoming: false,
        title: 'Find temporary accommodation',
        desc: 'Airbnb, WG-Gesucht, or a hotel for your first week while you find a permanent place.',
        tag: { label: 'Done', bg: '#D4F0E6', color: '#0A7C52' },
        timing: 'Variable',
        link: null,
        instructions: [
            'WG-Gesucht.de is the main platform for shared flats in Cologne',
            'Immobilienscout24 for private apartments',
            'Join Facebook groups: "Apartments in Cologne / Köln"',
            'Be ready to provide: passport, proof of income or employment contract',
        ],
        docs: ['Passport', 'Employment contract (if available)'],
        tip: {
            text: '"Ehrenfeld and Nippes are popular with expats — good transport, international vibe."',
            author: 'Anna B.',
        },
        actions: [
            {
                label: 'WG-Gesucht Cologne',
                url: 'https://www.wg-gesucht.de/wg-zimmer-in-Koeln.73.0.1.0.html',
            },
        ],
    },

    // WEEK 1
    {
        id: 4,
        phase: 'week1',
        done: true,
        skipped: false,
        urgent: false,
        upcoming: false,
        title: 'Register your address (Anmeldung)',
        desc: 'Required within 14 days of moving in. Every other step depends on this. Book as early as possible.',
        tag: { label: 'Done', bg: '#D4F0E6', color: '#0A7C52' },
        timing: '~1 hour · Bürgeramt appointment',
        link: 'https://termine.stadt-koeln.de/m/buergeramt/',
        instructions: [
            'Book appointment at termine.stadt-koeln.de (try Deutz — less busy)',
            'Bring: passport, rental contract (Mietvertrag), landlord confirmation (Wohnungsgeberbestätigung)',
            'You receive Meldebescheinigung on the spot — keep multiple copies',
        ],
        docs: [
            'Passport',
            'Mietvertrag (rental contract)',
            'Wohnungsgeberbestätigung (landlord form)',
        ],
        tip: {
            text: '"Book Bürgeramt Deutz — far less busy than Innenstadt. Got a slot next day."',
            author: 'Sarah K.',
        },
        actions: [
            {
                label: 'Book Bürgeramt',
                url: 'https://termine.stadt-koeln.de/m/buergeramt/',
            },
            {
                label: 'Get directions',
                url: 'https://maps.google.com/?q=Bürgeramt+Deutz+Köln',
            },
        ],
    },
    {
        id: 5,
        phase: 'week1',
        done: false,
        skipped: false,
        urgent: true,
        upcoming: false,
        title: 'Open a German bank account',
        desc: 'Required for salary payments, rent, and direct debits. N26 is the fastest — fully online, no branch visit.',
        tag: { label: 'Urgent', bg: '#FDE8E6', color: '#C4271A' },
        timing: '30 min online · Active in 1–3 days',
        link: 'https://n26.com',
        instructions: [
            'Go to n26.com and start application',
            'You need: passport, German address (Meldebescheinigung helps)',
            'Complete video identity verification (VideoIdent)',
            'Your IBAN arrives digitally — share with employer immediately',
        ],
        docs: [
            'Passport',
            'Meldebescheinigung (helpful but not always required)',
        ],
        tip: {
            text: '"N26 opened in 10 minutes. Give your employer the IBAN on day one."',
            author: 'Mehmet A.',
        },
        actions: [
            { label: 'Open N26 account', url: 'https://n26.com' },
            { label: 'Compare banks', url: 'https://www.check24.de/konto/' },
        ],
    },
    {
        id: 6,
        phase: 'week1',
        done: false,
        skipped: false,
        urgent: false,
        upcoming: false,
        title: 'Enrol in health insurance',
        desc: 'Mandatory in Germany. Must be done before starting work. TK (Techniker Krankenkasse) is recommended for expats — English app and support.',
        tag: { label: 'Pending', bg: '#EFEDE7', color: '#6B6860' },
        timing: '30 min online · Card in 1–2 weeks',
        link: 'https://www.tk.de',
        instructions: [
            'Apply at tk.de — choose "Mitglied werden"',
            'You need: passport, work contract',
            'Receive membership number immediately — give to employer',
            'Insurance card arrives by post in 1–2 weeks',
        ],
        docs: ['Passport', 'Work contract'],
        tip: {
            text: '"TK has an English app and English-speaking customer service. Worth the extra €10/month."',
            author: 'Claire D.',
        },
        actions: [
            {
                label: 'Apply at TK',
                url: 'https://www.tk.de/service/app/2055900/start.app',
            },
            {
                label: 'Compare insurers',
                url: 'https://www.check24.de/krankenversicherung/',
            },
        ],
    },

    // WEEK 2
    {
        id: 7,
        phase: 'week2',
        done: false,
        skipped: false,
        urgent: false,
        upcoming: false,
        title: 'Wait for your Tax ID (Steuer-ID)',
        desc: 'Automatically sent by post to your Anmeldung address. Takes 2–4 weeks. No action needed — just wait.',
        tag: { label: 'In progress', bg: '#EBF0FD', color: '#1A4CD4' },
        timing: 'Automatic · 2–4 weeks after Anmeldung',
        link: null,
        instructions: [
            'The Bundeszentralamt für Steuern sends it automatically',
            'You will receive an 11-digit number by post',
            'Give this number to your employer for payroll',
            'If not received in 4 weeks, request at bundeszentralamt.de',
        ],
        docs: [],
        tip: {
            text: '"Check your letterbox every day from week 2. It comes in a plain white envelope — easy to miss!"',
            author: 'Anna B.',
        },
        actions: [
            {
                label: 'Lost your Steuer-ID?',
                url: 'https://www.bzst.de/DE/Privatpersonen/SteuerlicheIdentifikationsnummer/steuerlicheidentifikationsnummer_node.html',
            },
        ],
    },
    {
        id: 8,
        phase: 'week2',
        done: false,
        skipped: false,
        urgent: false,
        upcoming: false,
        title: 'Set up internet at home',
        desc: 'Most German apartments need a new contract. Glasfaser (fibre) is available in much of Cologne. Order early — activation takes 2–4 weeks.',
        tag: { label: 'Pending', bg: '#EFEDE7', color: '#6B6860' },
        timing: '20 min to order · 2–4 weeks to activate',
        link: null,
        instructions: [
            'Check availability: Telekom, Vodafone, or NetCologne',
            'NetCologne is Cologne-specific and often cheaper',
            'Order online — needs your address and IBAN',
            'Book installation appointment when prompted',
        ],
        docs: ['IBAN (for direct debit)'],
        tip: {
            text: '"NetCologne has great deals in Ehrenfeld. Telekom is pricier but more reliable."',
            author: 'Carlos M.',
        },
        actions: [
            { label: 'Check NetCologne', url: 'https://www.netcologne.de' },
            {
                label: 'Check Telekom',
                url: 'https://www.telekom.de/zuhause/internet',
            },
        ],
    },

    // MONTH 1
    {
        id: 9,
        phase: 'month1',
        done: false,
        skipped: false,
        urgent: true,
        upcoming: false,
        title: 'Register with Ausländerbehörde (non-EU)',
        desc: 'Essential for non-EU citizens. You need a residence permit (Aufenthaltstitel) before your entry visa or stamp expires.',
        tag: { label: 'Urgent', bg: '#FDE8E6', color: '#C4271A' },
        timing: 'Appointment 1–2 hours · Permit in 4–8 weeks',
        link: 'https://www.stadt-koeln.de',
        instructions: [
            'Book appointment at Ausländerbehörde — book early, slots fill fast',
            'Bring: passport, work contract, proof of income, health insurance, Meldebescheinigung, biometric photo',
            'Pay fee ~€100 on the day',
            'Receive temporary confirmation — permit arrives by post in 4–8 weeks',
        ],
        docs: [
            'Passport',
            'Work contract',
            'Last 3 payslips',
            'Health insurance card',
            'Meldebescheinigung',
            'Biometric photo (35x45mm)',
            '~€100 fee',
        ],
        tip: {
            text: '"Bring more documents than they ask for. They often request extras on the day."',
            author: 'Yuki T.',
        },
        actions: [
            {
                label: 'Book appointment',
                url: 'https://www.stadt-koeln.de/service/aemter/ordnungsamt-auslaenderangelegenheiten',
            },
            {
                label: 'Biometric photo finder',
                url: 'https://www.google.com/maps/search/Passfoto+Köln',
            },
        ],
    },
    {
        id: 10,
        phase: 'month1',
        done: false,
        skipped: false,
        urgent: false,
        upcoming: false,
        title: 'Register with a GP (Hausarzt)',
        desc: 'You need a regular doctor for referrals, sick notes, and prescriptions. Find one who accepts new patients with your insurer.',
        tag: { label: 'Pending', bg: '#EFEDE7', color: '#6B6860' },
        timing: '15 min to find · First appointment varies',
        link: 'https://www.doctolib.de',
        instructions: [
            'Search doctolib.de for GPs in your area — filter by insurer',
            'Call to check they accept new patients',
            'Bring insurance card (Versicherungskarte) to first appointment',
            'Ask specifically if they speak English when booking',
        ],
        docs: ['Health insurance card (Versicherungskarte)'],
        tip: {
            text: '"Dr. Müller in Ehrenfeld speaks excellent English and accepts TK. Book 2 weeks ahead."',
            author: 'Sarah K.',
        },
        actions: [
            {
                label: 'Find GP on Doctolib',
                url: 'https://www.doctolib.de/allgemeinmediziner/koln',
            },
        ],
    },

    // MONTH 2
    {
        id: 11,
        phase: 'month2',
        done: false,
        skipped: false,
        urgent: false,
        upcoming: true,
        title: 'Get a Deutschlandticket',
        desc: '€58/month — unlimited travel on all public transport across Germany. Best value transit deal for daily commuters.',
        tag: { label: 'Upcoming', bg: '#EFEDE7', color: '#AAA89F' },
        timing: '10 min online',
        link: null,
        instructions: [
            'Order at kvb.koeln or in the DB Navigator app',
            'Needs a German IBAN for direct debit',
            'Digital ticket available immediately in the app',
            'Works on all KVB trams, buses, and regional trains in NRW',
        ],
        docs: ['German IBAN'],
        tip: null,
        actions: [
            {
                label: 'Get Deutschlandticket',
                url: 'https://www.kvb.koeln/tickets/uebersicht/deutschlandticket',
            },
        ],
    },
    {
        id: 12,
        phase: 'month2',
        done: false,
        skipped: false,
        urgent: false,
        upcoming: true,
        title: 'File your first tax return',
        desc: 'Not always mandatory but usually results in a refund. Use Taxfix or WISO — both have English options.',
        tag: { label: 'Upcoming', bg: '#EFEDE7', color: '#AAA89F' },
        timing: '2–4 hours · Due 31 July for previous year',
        link: null,
        instructions: [
            'Collect: Lohnsteuerbescheinigung from employer, Steuer-ID, bank statements',
            'Use Taxfix (beginner-friendly, English) or WISO (more complete)',
            'Submit electronically — refund typically arrives in 6–8 weeks',
            'If complex situation, hire a Steuerberater (tax advisor)',
        ],
        docs: [
            'Steuer-ID',
            'Lohnsteuerbescheinigung (from employer in Jan/Feb)',
        ],
        tip: null,
        actions: [
            { label: 'Try Taxfix', url: 'https://taxfix.de' },
            {
                label: 'Find Steuerberater',
                url: 'https://www.google.com/maps/search/Steuerberater+Expat+Köln',
            },
        ],
    },

    // MONTH 3+
    {
        id: 13,
        phase: 'month3',
        done: false,
        skipped: false,
        urgent: false,
        upcoming: true,
        title: 'Join language exchange community',
        desc: 'Meet locals, practise German, and make friends. Anker has language exchange meetups every week in Ehrenfeld.',
        tag: { label: 'Upcoming', bg: '#EDE9FE', color: '#7C3AED' },
        timing: 'Ongoing',
        link: null,
        instructions: [
            'Browse language partners in the Language Exchange section',
            'Join a weekly meetup at Café Schmitz or Hallmackenreuther',
            'Set up drop-in mode so nearby partners can find you',
            'Aim for 2+ sessions per week for meaningful progress',
        ],
        docs: [],
        tip: {
            text: '"The Wednesday evening meetup at Café Schmitz is the most welcoming for beginners."',
            author: 'Anna B.',
        },
        actions: [{ label: 'Find language partners', url: '#' }],
    },
    {
        id: 14,
        phase: 'month3',
        done: false,
        skipped: false,
        urgent: false,
        upcoming: true,
        title: 'Explore your neighbourhood',
        desc: 'Cologne has fantastic markets, parks, and culture. Use the Anker Explore section to find the best spots near you.',
        tag: { label: 'Ongoing', bg: '#EDE9FE', color: '#7C3AED' },
        timing: "Whenever you're ready",
        link: null,
        instructions: [
            'Visit the Alter Markt and Neumarkt for weekend markets',
            'Explore the Rheinufer (riverside walk) — best in summer',
            'Check Anker Events for expat meetups, concerts, and Karneval guide',
            'Join a neighbourhood Facebook group for local recommendations',
        ],
        docs: [],
        tip: null,
        actions: [],
    },
];

// Phases considered "done" by default
const defaultDonePhases = new Set(['arrival', 'week1']);

// ════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════
function getStats(steps: StepData[]) {
    const total = steps.length;
    const done = steps.filter((s) => s.done).length;
    const pct = Math.round((done / total) * 100);

    return { total, done, pct };
}

function getHeadline(pct: number): string {
    const headlines = [
        "Welcome to Cologne — let's get you settled.",
        'Day 1 — start with the essentials.',
        'Week 1 — the most important steps.',
        "Week 2 — you're settling in well.",
        'Month 1 — making real progress.',
        'Month 2 — nearly there.',
        "You're settled! Keep exploring. 🎉",
    ];
    const idx = Math.min(Math.floor(pct / 17), headlines.length - 1);

    return headlines[idx];
}

function currentActivePhase(steps: StepData[]): string {
    const pending = steps.find((s) => !s.done && !s.upcoming);

    return pending ? pending.phase : 'month3';
}

function groupByPhase(steps: StepData[]): Record<string, StepData[]> {
    const groups: Record<string, StepData[]> = {};

    for (const s of steps) {
        if (!groups[s.phase]) {
groups[s.phase] = [];
}

        groups[s.phase].push(s);
    }

    return groups;
}

// ════════════════════════════════════════
// TOAST
// ════════════════════════════════════════
function showToast(msg: string) {
    const t = document.createElement('div');
    t.style.cssText = `position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#18170F;color:white;padding:11px 18px;border-radius:14px;font-size:13px;font-weight:500;z-index:9000;box-shadow:0 8px 24px rgba(0,0,0,.15);white-space:nowrap;transition:opacity .3s;max-width:90vw;text-align:center;`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => {
        t.style.opacity = '0';
        setTimeout(() => t.remove(), 300);
    }, 2500);
}

// ════════════════════════════════════════
// COMPONENT
// ════════════════════════════════════════
export default function JustArrived() {
    const [steps, setSteps] = useState<StepData[]>(() => {
        const base = JSON.parse(JSON.stringify(initialSteps)) as StepData[];

        try {
            const saved = JSON.parse(
                localStorage.getItem('journey_steps') ?? '{}',
            ) as Record<string, string>;

            for (const s of base) {
                if (saved[s.id] === 'done') {
                    s.done = true;
                    s.skipped = false;
                    s.tag = { label: 'Done', bg: '#D4F0E6', color: '#0A7C52' };
                } else if (saved[s.id] === 'skipped') {
                    s.skipped = true;
                    s.tag = {
                        label: 'Skipped',
                        bg: '#EFEDE7',
                        color: '#AAA89F',
                    };
                }
            }
        } catch {
            /* ignore */
        }

        return base;
    });

    // Persist step state to localStorage
    useEffect(() => {
        const state: Record<string, string> = {};

        for (const s of steps) {
            if (s.done) {
state[s.id] = 'done';
} else if (s.skipped) {
state[s.id] = 'skipped';
}
        }

        localStorage.setItem('journey_steps', JSON.stringify(state));
    }, [steps]);
    const [expandedStep, setExpandedStep] = useState<number | null>(null);
    const [activePhaseFilters, setActivePhaseFilters] = useState<Set<string>>(
        new Set(),
    );

    const stats = getStats(steps);
    const headline = getHeadline(stats.pct);
    const curPhase = currentActivePhase(steps);
    const byPhase = groupByPhase(steps);

    // ── Step actions ──
    const markDone = useCallback((id: number) => {
        setSteps((prev) =>
            prev.map((s) =>
                s.id === id
                    ? {
                          ...s,
                          done: true,
                          skipped: false,
                          tag: {
                              label: 'Done',
                              bg: '#D4F0E6',
                              color: '#0A7C52',
                          },
                      }
                    : s,
            ),
        );
        setExpandedStep(null);
        showToast('✓ Step completed!');
    }, []);

    const undoStep = useCallback((id: number) => {
        setSteps((prev) =>
            prev.map((s) => {
                if (s.id !== id) {
return s;
}

                const tag: StepTag = s.urgent
                    ? { label: 'Urgent', bg: '#FDE8E6', color: '#C4271A' }
                    : { label: 'Pending', bg: '#EFEDE7', color: '#6B6860' };

                return { ...s, done: false, skipped: false, tag };
            }),
        );
        showToast('↩ Step marked as not done');
    }, []);

    const skipStep = useCallback((id: number) => {
        setSteps((prev) =>
            prev.map((s) =>
                s.id === id
                    ? {
                          ...s,
                          skipped: true,
                          tag: {
                              label: 'Skipped',
                              bg: '#EFEDE7',
                              color: '#AAA89F',
                          },
                      }
                    : s,
            ),
        );
        setExpandedStep(null);
        showToast('Step skipped — you can come back to this later');
    }, []);

    const toggleStep = useCallback((id: number) => {
        setExpandedStep((prev) => (prev === id ? null : id));
    }, []);

    const handleCheckbox = useCallback(
        (id: number, e: MouseEvent) => {
            e.stopPropagation();
            const step = steps.find((s) => s.id === id);

            if (!step || step.upcoming) {
return;
}

            if (step.done) {
                undoStep(id);
            } else {
                markDone(id);
            }
        },
        [steps, undoStep, markDone],
    );

    // ── Phase filter ──
    const togglePhaseFilter = useCallback((phaseId: string) => {
        setActivePhaseFilters((prev) => {
            const next = new Set(prev);

            if (next.has(phaseId)) {
                next.delete(phaseId);
            } else {
                next.add(phaseId);
            }

            return next;
        });
    }, []);

    // ── Determine chip state per phase ──
    function getChipState(phaseId: string): 'done' | 'active' | 'upcoming' {
        const isSelected = activePhaseFilters.has(phaseId);

        if (isSelected) {
return 'active';
}

        const isDone =
            defaultDonePhases.has(phaseId) &&
            (byPhase[phaseId] ?? []).every((s) => s.done);

        if (isDone) {
return 'done';
}

        if (phaseId === curPhase && activePhaseFilters.size === 0) {
return 'active';
}

        return 'upcoming';
    }

    // ── Chip styles ──
    function chipStyle(
        state: 'done' | 'active' | 'upcoming',
    ): React.CSSProperties {
        const base: React.CSSProperties = {
            display: 'flex',
            alignItems: 'center',
            gap: 5,
            padding: '5px 11px',
            borderRadius: 100,
            fontSize: 11,
            fontWeight: 600,
            cursor: 'pointer',
            transition: 'all 0.2s',
            border: '1.5px solid transparent',
        };

        if (state === 'done') {
            base.background = '#D4F0E6';
            base.color = '#0A7C52';
        } else if (state === 'active') {
            base.background = '#1A4CD4';
            base.color = 'white';
            base.borderColor = '#1A4CD4';
        } else {
            base.background = '#EFEDE7';
            base.color = '#AAA89F';
            base.borderColor = '#E2DFD6';
        }

        return base;
    }

    function dotColor(state: 'done' | 'active' | 'upcoming'): string {
        if (state === 'active') {
return 'white';
}

        if (state === 'done') {
return '#0A7C52';
}

        return '#AAA89F';
    }

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Just Arrived', href: '/just-arrived' }]}
            rightPanel={<JourneyRightPanel />}
        >
            <Head title="Just Arrived" />

            {/* ── Journey Hero ── */}
            <div
                style={{
                    padding: '24px 24px 20px',
                    background: '#FFFFFF',
                    borderBottom: '1px solid #E2DFD6',
                }}
            >
                <div
                    style={{
                        fontSize: 10,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.10em',
                        color: '#AAA89F',
                        marginBottom: 8,
                    }}
                >
                    Your settlement journey · Non-EU · Cologne
                </div>
                <div
                    style={{
                        fontFamily: "'Fraunces', serif",
                        fontSize: 26,
                        fontWeight: 400,
                        lineHeight: 1.2,
                        marginBottom: 16,
                        color: '#18170F',
                    }}
                >
                    {headline}
                </div>

                {/* Progress bar */}
                <div style={{ marginBottom: 8 }}>
                    <div
                        style={{
                            height: 6,
                            background: '#EFEDE7',
                            borderRadius: 20,
                            overflow: 'hidden',
                        }}
                    >
                        <div
                            style={{
                                height: 6,
                                borderRadius: 20,
                                background: '#1A4CD4',
                                transition:
                                    'width 0.6s cubic-bezier(0.32, 1, 0.4, 1)',
                                width: `${stats.pct}%`,
                            }}
                        />
                    </div>
                    <div
                        style={{
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                            marginTop: 6,
                        }}
                    >
                        <span
                            style={{
                                fontFamily: "'Geist Mono', monospace",
                                fontSize: 12,
                                color: '#AAA89F',
                            }}
                        >
                            {stats.done} of {stats.total} steps
                        </span>
                        <span
                            style={{
                                fontFamily: "'Geist Mono', monospace",
                                fontSize: 12,
                                fontWeight: 600,
                                color: '#1A4CD4',
                            }}
                        >
                            {stats.pct}%
                        </span>
                    </div>
                </div>

                {/* Phase chips */}
                <div
                    style={{
                        display: 'flex',
                        gap: 6,
                        flexWrap: 'wrap',
                        marginTop: 14,
                    }}
                >
                    {phases.map((phase) => {
                        const state = getChipState(phase.id);
                        const isDone = state === 'done';

                        return (
                            <div
                                key={phase.id}
                                style={chipStyle(state)}
                                onClick={() => togglePhaseFilter(phase.id)}
                            >
                                <div
                                    style={{
                                        width: 6,
                                        height: 6,
                                        borderRadius: '50%',
                                        flexShrink: 0,
                                        background: dotColor(state),
                                    }}
                                />
                                <span>
                                    {isDone && !activePhaseFilters.has(phase.id)
                                        ? '✓ '
                                        : ''}
                                    {phase.icon} {phase.label}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* ── Steps Feed ── */}
            <div>
                {phases.map((phase) => {
                    const phaseSteps = byPhase[phase.id] ?? [];

                    if (phaseSteps.length === 0) {
return null;
}

                    const visible =
                        activePhaseFilters.size === 0 ||
                        activePhaseFilters.has(phase.id);

                    if (!visible) {
return null;
}

                    const allDone = phaseSteps.every((s) => s.done);
                    const doneCnt = phaseSteps.filter((s) => s.done).length;
                    const badgeBg = allDone ? '#D4F0E6' : '#EBF0FD';
                    const badgeCol = allDone ? '#0A7C52' : '#1A4CD4';

                    return (
                        <div key={phase.id}>
                            {/* Milestone card for completed phase */}
                            {allDone && (
                                <div
                                    style={{
                                        margin: '0 24px 16px',
                                        background:
                                            'linear-gradient(135deg, #0A7C52 0%, #059669 100%)',
                                        borderRadius: 14,
                                        padding: '16px 18px',
                                        color: 'white',
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 14,
                                    }}
                                >
                                    <span
                                        style={{ fontSize: 28, flexShrink: 0 }}
                                    >
                                        🎉
                                    </span>
                                    <div>
                                        <div
                                            style={{
                                                fontFamily: "'Fraunces', serif",
                                                fontSize: 16,
                                                fontWeight: 500,
                                                marginBottom: 2,
                                            }}
                                        >
                                            {phase.label} complete!
                                        </div>
                                        <div
                                            style={{
                                                fontSize: 12,
                                                opacity: 0.85,
                                            }}
                                        >
                                            All {phaseSteps.length} steps done —
                                            great work.
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Phase header */}
                            <div
                                style={{
                                    padding: '16px 24px',
                                    background: '#EFEDE7',
                                    borderBottom: '1px solid #E2DFD6',
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 12,
                                }}
                            >
                                <span style={{ fontSize: 24, flexShrink: 0 }}>
                                    {phase.icon}
                                </span>
                                <div>
                                    <div
                                        style={{
                                            fontSize: 10,
                                            fontWeight: 700,
                                            textTransform: 'uppercase',
                                            letterSpacing: '0.09em',
                                            color: '#AAA89F',
                                            marginBottom: 2,
                                        }}
                                    >
                                        {phase.days}
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 15,
                                            fontWeight: 600,
                                            color: '#18170F',
                                        }}
                                    >
                                        {phase.label}
                                    </div>
                                </div>
                                <span
                                    style={{
                                        marginLeft: 'auto',
                                        fontFamily: "'Geist Mono', monospace",
                                        fontSize: 11,
                                        fontWeight: 600,
                                        padding: '3px 10px',
                                        borderRadius: 20,
                                        flexShrink: 0,
                                        background: badgeBg,
                                        color: badgeCol,
                                    }}
                                >
                                    {doneCnt}/{phaseSteps.length}
                                </span>
                            </div>

                            {/* Step items */}
                            {phaseSteps.map((step, i) => (
                                <StepCard
                                    key={step.id}
                                    step={step}
                                    expanded={expandedStep === step.id}
                                    animationDelay={i * 0.04}
                                    onToggle={() => toggleStep(step.id)}
                                    onCheckbox={(e) =>
                                        handleCheckbox(step.id, e)
                                    }
                                    onMarkDone={() => markDone(step.id)}
                                    onSkip={() => skipStep(step.id)}
                                />
                            ))}
                        </div>
                    );
                })}
            </div>
        </AppLayout>
    );
}
