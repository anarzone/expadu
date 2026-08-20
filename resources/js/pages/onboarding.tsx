import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { FlashToast } from '@/components/flash-toast';
import { ConfirmationStep } from '@/components/onboarding/confirmation-step';
import { InterestsStep } from '@/components/onboarding/interests-step';
import { OnboardingProgress } from '@/components/onboarding/onboarding-progress';
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

const TOTAL_STEPS = 5;

export default function Onboarding() {
    const { track } = useTracker();
    const { veedels } = usePage<{
        veedels: Record<string, string[]>;
    }>().props;
    const [step, setStep] = useState(1);

    const form = useForm<OnboardingData>({
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
    });

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

    function submit() {
        track('onboarding_complete');
        form.transform((data) => ({
            ...data,
            situation: resolveSituation(data.situation, data.is_eu),
        }));
        form.post('/onboarding/complete');
    }

    const canProceed = () => {
        switch (step) {
            case 1:
                return true;
            case 2: {
                if (form.data.situation === '') {
                    return false;
                }

                if (
                    EU_QUESTION_CHOICES.includes(form.data.situation) &&
                    form.data.is_eu === null
                ) {
                    return false;
                }

                // A non-EU answer unlocks entry details, which are needed for
                // the first-plan guidance.
                return form.data.is_eu === false
                    ? form.data.entry_mode !== ''
                    : true;
            }
            case 3:
                return (
                    form.data.veedel !== '' &&
                    form.data.address_registration_status !== '' &&
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
            case 5:
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

            return 'Tell us how you entered Germany.';
        }

        if (step === 3) {
            if (form.data.veedel === '') {
                return 'Pick your neighbourhood.';
            }

            if (form.data.address_registration_status === '') {
                return 'Answer whether you can register at this address.';
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
        entry_mode: 2,
        visa_expires_at: 2,
        current_residence_title: 2,
        residence_title_expires_at: 2,
        case_goal: 2,
        sponsor_current_title: 2,
        veedel: 3,
        arrival_planned: 3,
        arrival_date: 3,
        address_registration_status: 3,
        moved_in_at: 3,
        documented_german_level: 3,
        german_level: 3,
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
            case 5:
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
                            hasDeutschlandticket={
                                form.data.has_deutschlandticket
                            }
                            onVeedelChange={(v) => form.setData('veedel', v)}
                            onArrivalDateChange={(v) =>
                                form.setData('arrival_date', v)
                            }
                            onArrivalPlannedChange={(planned) => {
                                form.setData('arrival_planned', planned);
                                form.setData('arrival_date', '');
                            }}
                            documentedGermanLevel={
                                form.data.documented_german_level
                            }
                            onDocumentedGermanLevelChange={(v) =>
                                form.setData('documented_german_level', v)
                            }
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
                            onDticketChange={(v) =>
                                form.setData('has_deutschlandticket', v)
                            }
                        />
                    )}
                    {step === 4 && (
                        <InterestsStep
                            interests={form.data.interests}
                            onToggle={(v) =>
                                form.setData(
                                    'interests',
                                    form.data.interests.includes(v)
                                        ? form.data.interests.filter(
                                              (x) => x !== v,
                                          )
                                        : [...form.data.interests, v],
                                )
                            }
                        />
                    )}
                    {step === 5 && <ConfirmationStep data={form.data} />}
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
                        <button
                            type="button"
                            onClick={step === 5 ? submit : next}
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
