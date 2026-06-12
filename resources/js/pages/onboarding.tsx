import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmationStep } from '@/components/onboarding/confirmation-step';
import { OnboardingProgress } from '@/components/onboarding/onboarding-progress';
import { SituationStep } from '@/components/onboarding/situation-step';
import { VeedelStep } from '@/components/onboarding/veedel-step';
import { WelcomeStep } from '@/components/onboarding/welcome-step';
import { useTracker } from '@/hooks/use-tracker';

// The form stores the friendly CHOICE ('job' | 'student' | …); the real
// Situation enum value is derived from choice + is_eu at submit time.
export type OnboardingData = {
    situation: string;
    is_eu: boolean | null;
    entry_mode: string;
    visa_expires_at: string;
    veedel: string;
    housing_status: string;
    german_level: string;
    arrival_date: string;
};

export type TaskPreview = {
    title: string;
    meta: string | null;
    deadline_days: number | null;
};

export type TaskPreviews = Record<
    string,
    { eu: TaskPreview[]; non_eu: TaskPreview[] }
>;

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

const TOTAL_STEPS = 4;

export default function Onboarding() {
    const { track } = useTracker();
    const { veedels, taskPreviews } = usePage<{
        veedels: Record<string, string[]>;
        taskPreviews: TaskPreviews;
    }>().props;
    const [step, setStep] = useState(1);

    // The arrival selects display the current month/year by default — the
    // form must hold that same value, or Continue stays disabled with no
    // visible reason for anyone who arrived this month.
    const now = new Date();
    const defaultArrival = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`;

    const form = useForm<OnboardingData>({
        situation: '',
        is_eu: null,
        entry_mode: '',
        visa_expires_at: '',
        veedel: '',
        housing_status: 'long_term',
        german_level: '',
        arrival_date: defaultArrival,
    });

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

                // Non-EU answer unlocks the entry-mode question — it sets
                // the real permit deadline, so it must be answered.
                return form.data.is_eu === false
                    ? form.data.entry_mode !== ''
                    : true;
            }
            case 3:
                return form.data.veedel !== '' && form.data.arrival_date !== '';
            case 4:
                return true;
            default:
                return false;
        }
    };

    const buttonLabel = () => {
        switch (step) {
            case 1:
                return "Let's get started";
            case 4:
                return 'Open my plan';
            default:
                return 'Continue';
        }
    };

    return (
        <>
            <Head title="Welcome to Expadu" />
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
                            onChange={(v) => form.setData('situation', v)}
                            onIsEuChange={(v) => form.setData('is_eu', v)}
                            onEntryModeChange={(v) =>
                                form.setData('entry_mode', v)
                            }
                            visaExpiresAt={form.data.visa_expires_at}
                            onVisaExpiresAtChange={(v) =>
                                form.setData('visa_expires_at', v)
                            }
                        />
                    )}
                    {step === 3 && (
                        <VeedelStep
                            veedels={veedels ?? {}}
                            veedel={form.data.veedel}
                            arrivalDate={form.data.arrival_date}
                            germanLevel={form.data.german_level}
                            housingStatus={form.data.housing_status}
                            onVeedelChange={(v) => form.setData('veedel', v)}
                            onArrivalDateChange={(v) =>
                                form.setData('arrival_date', v)
                            }
                            onGermanLevelChange={(v) =>
                                form.setData('german_level', v)
                            }
                            onHousingStatusChange={(v) =>
                                form.setData('housing_status', v)
                            }
                        />
                    )}
                    {step === 4 && (
                        <ConfirmationStep
                            data={form.data}
                            previews={taskPreviews ?? {}}
                        />
                    )}
                </div>

                <div className="sticky bottom-0 border-t border-border bg-background px-6 py-4">
                    <div className="mx-auto flex max-w-[600px] items-center gap-3">
                        <button
                            type="button"
                            onClick={step === 4 ? submit : next}
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
