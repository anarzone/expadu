import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { FlashToast } from '@/components/flash-toast';
import { OnboardingProgress } from '@/components/onboarding/onboarding-progress';
import { OptionalStep } from '@/components/onboarding/optional-step';
import { SituationStep } from '@/components/onboarding/situation-step';
import { VeedelStep } from '@/components/onboarding/veedel-step';
import { WelcomeStep } from '@/components/onboarding/welcome-step';
import { useTracker } from '@/hooks/use-tracker';
import { ARRIVAL_BOUNDS } from '@/lib/date-bounds';

// The form stores the friendly CHOICE ('job' | 'student' | …); the real
// Situation enum value is derived from choice + is_eu at submit time.
export type OnboardingData = {
    situation: string;
    is_eu: boolean | null;
    entry_mode: string;
    visa_expires_at: string;
    veedel: string;
    has_deutschlandticket: boolean;
    arrival_date: string;
    // Null means they have not answered whether they are here yet.
    arrival_planned: boolean | null;
    interests: string[];
    current_residence_title: string;
    residence_title_expires_at: string;
    case_goal: string;
    sponsor_current_title: string;
    documented_german_level: string;
    moved_in_at: string;
    address_registration_status: string;
};

/** 'job' resolves via the EU answer; everything else is already an enum value. */
export function resolveSituation(choice: string, isEu: boolean | null): string {
    if (choice === 'job') {
        return isEu ? 'eu_employee' : 'non_eu_employee';
    }

    return choice;
}

// Family implies the path regardless of citizenship; everyone else gets the
// EU follow-up (for 'job' it picks the enum value, for the rest it's stored).
const EU_QUESTION_CHOICES = [
    'job',
    'student',
    'freelancer',
    'digital_nomad',
    'other',
];

// Four screens, of which two ask anything required. Step 2 used to carry the
// branch question AND every residence detail — 8 questions over 2.5 viewports.
// Residence now shares the final, skippable screen with interests.
const TOTAL_STEPS = 4;

/**
 * A refresh used to empty the wizard and drop the user back on step 1, which
 * on a form asking for visa dates and permit types is a real cost — people
 * reload when they go looking for the document they are being asked about.
 *
 * sessionStorage rather than localStorage: the draft holds residence details,
 * so it should not outlive the tab on a shared machine. It is cleared the
 * moment the answers reach the server.
 */
const DRAFT_KEY = 'expadu:onboarding-draft';

const EMPTY_FORM: OnboardingData = {
    situation: '',
    is_eu: null,
    entry_mode: '',
    visa_expires_at: '',
    veedel: '',
    has_deutschlandticket: false,
    arrival_date: '',
    arrival_planned: null,
    interests: [],
    current_residence_title: '',
    residence_title_expires_at: '',
    case_goal: '',
    sponsor_current_title: '',
    documented_german_level: '',
    moved_in_at: '',
    address_registration_status: '',
};

function readDraft(): { step: number; data: OnboardingData } {
    try {
        const raw = sessionStorage.getItem(DRAFT_KEY);
        const saved = raw === null ? null : JSON.parse(raw);

        if (saved === null || typeof saved !== 'object') {
            return { step: 1, data: EMPTY_FORM };
        }

        // Only the keys we know about, so a stale draft from an older shape
        // cannot smuggle a field the form no longer has.
        const data = { ...EMPTY_FORM };

        for (const key of Object.keys(EMPTY_FORM) as Array<
            keyof OnboardingData
        >) {
            if (key in (saved.data ?? {})) {
                (data as Record<string, unknown>)[key] = saved.data[key];
            }
        }

        // Never resume past a step whose answer is missing — a draft written
        // before a field became required would otherwise strand the user on a
        // screen they cannot leave.
        const furthest =
            data.situation === ''
                ? 1
                : data.veedel === '' || data.arrival_planned === null
                  ? 2
                  : TOTAL_STEPS;
        const step = Math.min(
            Math.max(Number(saved.step) || 1, 1),
            Math.max(furthest, 1),
        );

        return { step, data };
    } catch {
        return { step: 1, data: EMPTY_FORM };
    }
}

export default function Onboarding() {
    const { track } = useTracker();
    const { veedels } = usePage<{
        veedels: Record<string, string[]>;
    }>().props;
    const [draft] = useState(readDraft);
    const [step, setStep] = useState(draft.step);

    const form = useForm<OnboardingData>(draft.data);

    // Keep the draft in step with the wizard so a reload resumes where they
    // were rather than starting over.
    useEffect(() => {
        try {
            sessionStorage.setItem(
                DRAFT_KEY,
                JSON.stringify({ step, data: form.data }),
            );
        } catch {
            // A full or disabled store just means no resume; never block the form.
        }
    }, [step, form.data]);

    function clearResidenceFacts(data: OnboardingData): OnboardingData {
        return {
            ...data,
            entry_mode: '',
            visa_expires_at: '',
            current_residence_title: '',
            residence_title_expires_at: '',
            case_goal: '',
            sponsor_current_title: '',
        };
    }

    function changeSituation(situation: string) {
        const data = clearResidenceFacts(form.data);

        form.setData({
            ...data,
            situation,
            is_eu: situation === 'family_reunification' ? false : null,
        });
    }

    function changeIsEu(isEu: boolean) {
        form.setData({
            ...clearResidenceFacts(form.data),
            is_eu: isEu,
        });
    }

    function changeEntryMode(entryMode: string) {
        form.setData({
            ...clearResidenceFacts(form.data),
            entry_mode: entryMode,
            current_residence_title:
                entryMode === 'd_visa' ? 'national_d_visa' : '',
        });
    }

    function next() {
        if (step < TOTAL_STEPS) {
            track('onboarding_step', { step });
            setStep(step + 1);
        }
    }

    function back() {
        if (step > 1) {
            setStep(step - 1);
        }
    }

    /**
     * Skip writes the optional fields as empty rather than quietly leaving
     * whatever was half-typed. ApplyOnboardingAnswers turns those into
     * retireKeys, so they are stored as "not answered" — never guessed.
     */
    function skipAndFinish() {
        track('onboarding_skip_optional');
        form.setData({
            ...clearResidenceFacts(form.data),
            interests: [],
            documented_german_level: '',
        });
        submit();
    }

    function submit() {
        track('onboarding_complete');
        form.transform((data) => ({
            ...data,
            situation: resolveSituation(data.situation, data.is_eu),
        }));
        form.post('/onboarding/complete', {
            // The answers are the server's now; nothing sensitive lingers in
            // the tab.
            onSuccess: () => sessionStorage.removeItem(DRAFT_KEY),
        });
    }

    const canProceed = () => {
        switch (step) {
            case 1:
                return true;
            case 2: {
                if (form.data.situation === '') {
                    return false;
                }

                // Entry details used to gate this screen too. They are optional
                // now: PendingAnswers asks for entry_mode on the Bureaucracy
                // page, so skipping defers the question instead of losing it.
                return !(
                    EU_QUESTION_CHOICES.includes(form.data.situation) &&
                    form.data.is_eu === null
                );
            }
            case 3:
                return (
                    form.data.veedel !== '' &&
                    // The address answer is optional — someone who has not moved
                    // in yet cannot give it. Answering "I can register here"
                    // still commits you to the date, because a registrable
                    // address without a move-in date has no clock to start.
                    (form.data.address_registration_status !== 'registrable' ||
                        form.data.moved_in_at !== '') &&
                    form.data.arrival_planned !== null &&
                    (form.data.arrival_planned ||
                        (form.data.arrival_date !== '' &&
                            // The backend enforces before_or_equal:today. Catch
                            // it here too so a typed-in future date can never
                            // reach the final submit and fail there, which left
                            // QA staring at a button that did nothing.
                            form.data.arrival_date <= ARRIVAL_BOUNDS.max))
                );
            case 4:
                return true;
            default:
                return false;
        }
    };

    /**
     * Why Continue is disabled, in the user's terms. A dead button with no
     * explanation was the most confusing thing in QA: answering "I can register
     * here" and "I'm here" silently requires two dates further up the screen,
     * and nothing on the page said so.
     */
    const missingReason = (): string | null => {
        if (canProceed()) {
            return null;
        }

        if (step === 2) {
            if (form.data.situation === '') {
                return 'Choose what brings you to Cologne.';
            }

            if (
                EU_QUESTION_CHOICES.includes(form.data.situation) &&
                form.data.is_eu === null
            ) {
                return 'Let us know whether you are an EU / EEA / Swiss citizen.';
            }

            return 'Let us know whether you are an EU / EEA / Swiss citizen.';
        }

        if (step === 3) {
            if (form.data.veedel === '') {
                return 'Pick your neighbourhood.';
            }

            if (
                form.data.address_registration_status === 'registrable' &&
                form.data.moved_in_at === ''
            ) {
                return 'Add your move-in date — it anchors the 14-day registration deadline.';
            }

            if (form.data.arrival_planned === null) {
                return 'Tell us whether you are already here.';
            }

            if (!form.data.arrival_planned && form.data.arrival_date === '') {
                return 'Add the date you arrived in Germany.';
            }

            if (
                !form.data.arrival_planned &&
                form.data.arrival_date > ARRIVAL_BOUNDS.max
            ) {
                return 'Your arrival date is in the future — pick "Still planning" instead, or correct the date.';
            }
        }

        return null;
    };

    /**
     * Server-side validation used to fail silently: nothing in this wizard
     * rendered `errors`, so a rejected submit left the user on step 5 with a
     * button that did nothing. Surface the messages and offer a way back to the
     * step that owns the field.
     */
    const STEP_FOR_FIELD: Record<string, number> = {
        situation: 2,
        is_eu: 2,
        entry_mode: 4,
        visa_expires_at: 4,
        current_residence_title: 4,
        residence_title_expires_at: 4,
        case_goal: 4,
        sponsor_current_title: 4,
        veedel: 3,
        arrival_planned: 3,
        arrival_date: 3,
        address_registration_status: 3,
        moved_in_at: 3,
        documented_german_level: 4,
        german_level: 4,
        has_deutschlandticket: 3,
        interests: 4,
    };

    const errorEntries = Object.entries(form.errors).filter(([, message]) =>
        Boolean(message),
    ) as Array<[string, string]>;
    const firstErrorStep = errorEntries
        .map(([field]) => STEP_FOR_FIELD[field] ?? TOTAL_STEPS)
        .sort((a, b) => a - b)[0];

    const buttonLabel = () => {
        switch (step) {
            case 1:
                return "Let's get started";
            case 4:
                return 'Open my first plan';
            default:
                return 'Continue';
        }
    };

    return (
        <>
            <Head title="Welcome to Expadu" />
            <FlashToast />
            <div className="flex min-h-svh flex-col bg-background">
                <OnboardingProgress
                    step={step}
                    total={TOTAL_STEPS}
                    onBack={back}
                />

                <div className="relative flex-1 overflow-hidden">
                    {step === 1 && <WelcomeStep />}
                    {step === 2 && (
                        <SituationStep
                            section="situation"
                            value={form.data.situation}
                            isEu={form.data.is_eu}
                            entryMode={form.data.entry_mode}
                            showEuQuestion={EU_QUESTION_CHOICES.includes(
                                form.data.situation,
                            )}
                            onChange={changeSituation}
                            onIsEuChange={changeIsEu}
                            onEntryModeChange={changeEntryMode}
                            visaExpiresAt={form.data.visa_expires_at}
                            onVisaExpiresAtChange={(v) =>
                                form.setData('visa_expires_at', v)
                            }
                            currentResidenceTitle={
                                form.data.current_residence_title
                            }
                            onCurrentResidenceTitleChange={(v) =>
                                form.setData({
                                    ...form.data,
                                    current_residence_title: v,
                                    residence_title_expires_at: '',
                                    case_goal: '',
                                })
                            }
                            residenceTitleExpiresAt={
                                form.data.residence_title_expires_at
                            }
                            onResidenceTitleExpiresAtChange={(v) =>
                                form.setData('residence_title_expires_at', v)
                            }
                            caseGoal={form.data.case_goal}
                            onCaseGoalChange={(v) =>
                                form.setData('case_goal', v)
                            }
                            sponsorCurrentTitle={
                                form.data.sponsor_current_title
                            }
                            onSponsorCurrentTitleChange={(v) =>
                                form.setData('sponsor_current_title', v)
                            }
                        />
                    )}
                    {step === 3 && (
                        <VeedelStep
                            veedels={veedels ?? {}}
                            veedel={form.data.veedel}
                            arrivalDate={form.data.arrival_date}
                            arrivalPlanned={form.data.arrival_planned}
                            onVeedelChange={(v) => form.setData('veedel', v)}
                            onArrivalDateChange={(v) =>
                                form.setData('arrival_date', v)
                            }
                            onArrivalPlannedChange={(planned) => {
                                // Switching to "still planning" has to retract
                                // the address answers, not just hide them: you
                                // cannot have moved into an address before
                                // arriving, and leaving them set would submit a
                                // move-in date for someone who is not here.
                                form.setData({
                                    ...form.data,
                                    arrival_planned: planned,
                                    arrival_date: '',
                                    ...(planned
                                        ? {
                                              address_registration_status: '',
                                              moved_in_at: '',
                                          }
                                        : {}),
                                });
                            }}
                            addressRegistrationStatus={
                                form.data.address_registration_status
                            }
                            onAddressRegistrationStatusChange={(v) =>
                                form.setData({
                                    ...form.data,
                                    address_registration_status: v,
                                    moved_in_at:
                                        v === 'registrable'
                                            ? form.data.moved_in_at
                                            : '',
                                })
                            }
                            movedInAt={form.data.moved_in_at}
                            onMovedInAtChange={(v) =>
                                form.setData('moved_in_at', v)
                            }
                        />
                    )}
                    {step === 4 && (
                        <OptionalStep
                            data={form.data}
                            onChange={(patch) =>
                                form.setData({ ...form.data, ...patch })
                            }
                        />
                    )}
                </div>

                <div className="sticky bottom-0 border-t border-border bg-background px-6 py-4">
                    {errorEntries.length > 0 && (
                        <div
                            role="alert"
                            className="mx-auto mb-3 max-w-[600px] rounded-xl border border-destructive/40 bg-destructive/10 px-4 py-3"
                        >
                            <p className="text-[13px] font-semibold text-destructive">
                                We could not save your answers
                            </p>
                            <ul className="mt-1 list-disc space-y-0.5 pl-4 text-xs text-destructive">
                                {errorEntries.map(([field, message]) => (
                                    <li key={field}>{message}</li>
                                ))}
                            </ul>
                            {firstErrorStep !== undefined &&
                                firstErrorStep !== step && (
                                    <button
                                        type="button"
                                        onClick={() => setStep(firstErrorStep)}
                                        className="mt-2 cursor-pointer text-xs font-bold text-destructive underline underline-offset-2"
                                    >
                                        Go back and fix this
                                    </button>
                                )}
                        </div>
                    )}

                    {missingReason() !== null && (
                        <p className="mx-auto mb-3 max-w-[600px] text-xs text-muted-foreground">
                            {missingReason()}
                        </p>
                    )}

                    <div className="mx-auto flex max-w-[600px] items-center gap-3">
                        {step === TOTAL_STEPS && (
                            <button
                                type="button"
                                onClick={skipAndFinish}
                                disabled={form.processing}
                                className="cursor-pointer text-[13px] font-semibold text-muted-foreground underline underline-offset-2 hover:text-foreground disabled:opacity-50"
                            >
                                Skip for now
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={step === TOTAL_STEPS ? submit : next}
                            disabled={!canProceed() || form.processing}
                            className="ml-auto w-full rounded-xl bg-primary px-6 py-3.5 text-[15px] font-semibold text-white transition-colors hover:bg-[var(--accent-hover)] disabled:opacity-50 sm:w-auto sm:min-w-[200px]"
                        >
                            {form.processing ? 'Saving...' : buttonLabel()}
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
}
