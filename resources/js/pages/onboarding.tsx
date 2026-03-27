import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CityStep } from '@/components/onboarding/city-step';
import { ConfirmationStep } from '@/components/onboarding/confirmation-step';
import { LanguagesStep } from '@/components/onboarding/languages-step';
import { OnboardingProgress } from '@/components/onboarding/onboarding-progress';
import { SituationStep } from '@/components/onboarding/situation-step';
import { useTracker } from '@/hooks/use-tracker';
import { WelcomeStep } from '@/components/onboarding/welcome-step';

export type OnboardingData = {
    situation: string;
    city: string;
    german_level: string;
    speaks: string[];
    arrival_date: string;
};

const TOTAL_STEPS = 5;

export default function Onboarding() {
    const { track } = useTracker();
    const [step, setStep] = useState(1);

    const form = useForm<OnboardingData>({
        situation: '',
        city: '',
        german_level: '',
        speaks: [],
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

    function skip() {
        next();
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
                return form.data.situation !== '';
            case 3:
                return form.data.german_level !== '';
            case 4:
                return form.data.city !== '' && form.data.arrival_date !== '';
            case 5:
                return true;
            default:
                return false;
        }
    };

    const buttonLabel = () => {
        switch (step) {
            case 1:
                return 'Get started';
            case 5:
                return 'Open Expadu';
            default:
                return 'Continue';
        }
    };

    return (
        <>
            <Head title="Welcome to Expadu" />
            <div className="flex min-h-svh flex-col bg-background">
                <OnboardingProgress step={step} total={TOTAL_STEPS} onBack={back} />

                <div className="relative flex-1 overflow-hidden">
                    {step === 1 && <WelcomeStep />}
                    {step === 2 && <SituationStep value={form.data.situation} onChange={(v) => form.setData('situation', v)} />}
                    {step === 3 && (
                        <LanguagesStep
                            germanLevel={form.data.german_level}
                            speaks={form.data.speaks}
                            onGermanLevelChange={(v) => form.setData('german_level', v)}
                            onSpeaksChange={(v) => form.setData('speaks', v)}
                        />
                    )}
                    {step === 4 && (
                        <CityStep
                            city={form.data.city}
                            arrivalDate={form.data.arrival_date}
                            onCityChange={(v) => form.setData('city', v)}
                            onArrivalDateChange={(v) => form.setData('arrival_date', v)}
                        />
                    )}
                    {step === 5 && <ConfirmationStep data={form.data} />}
                </div>

                <div className="sticky bottom-0 border-t border-border bg-background px-6 py-4">
                    <div className="mx-auto flex max-w-[600px] items-center gap-3">
                        {step > 1 && step < 5 && (
                            <button
                                type="button"
                                onClick={skip}
                                className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                            >
                                Skip
                            </button>
                        )}
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
