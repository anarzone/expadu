import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmationStep } from '@/components/onboarding/confirmation-step';
import { OnboardingProgress } from '@/components/onboarding/onboarding-progress';
import {
    EU_AMBIGUOUS_SITUATIONS,
    SituationStep,
} from '@/components/onboarding/situation-step';
import { VeedelStep } from '@/components/onboarding/veedel-step';
import { WelcomeStep } from '@/components/onboarding/welcome-step';
import { useTracker } from '@/hooks/use-tracker';

export type OnboardingData = {
    situation: string;
    is_eu: boolean | null;
    veedel: string;
    german_level: string;
    arrival_date: string;
};

const TOTAL_STEPS = 4;

export default function Onboarding() {
    const { track } = useTracker();
    const { veedels } = usePage<{ veedels: Record<string, string[]> }>().props;
    const [step, setStep] = useState(1);

    const form = useForm<OnboardingData>({
        situation: '',
        is_eu: null,
        veedel: '',
        german_level: '',
        arrival_date: '',
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
        form.post('/onboarding/complete');
    }

    const canProceed = () => {
        switch (step) {
            case 1:
                return true;
            case 2:
                if (form.data.situation === '') {
                    return false;
                }

                return EU_AMBIGUOUS_SITUATIONS.includes(form.data.situation)
                    ? form.data.is_eu !== null
                    : true;
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
                return 'Get started';
            case 4:
                return 'Open Expadu';
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
                            onChange={(v) => form.setData('situation', v)}
                            onIsEuChange={(v) => form.setData('is_eu', v)}
                        />
                    )}
                    {step === 3 && (
                        <VeedelStep
                            veedels={veedels ?? {}}
                            veedel={form.data.veedel}
                            arrivalDate={form.data.arrival_date}
                            germanLevel={form.data.german_level}
                            onVeedelChange={(v) => form.setData('veedel', v)}
                            onArrivalDateChange={(v) =>
                                form.setData('arrival_date', v)
                            }
                            onGermanLevelChange={(v) =>
                                form.setData('german_level', v)
                            }
                        />
                    )}
                    {step === 4 && <ConfirmationStep data={form.data} />}
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
