import {
    IconBabyCarriage,
    IconBallFootball,
    IconBooks,
    IconBuildingBank,
    IconCheck,
    IconCoffee,
    IconDeviceLaptop,
    IconDog,
    IconGlassFull,
    IconMusic,
    IconPalette,
    IconSwimming,
    IconTree,
} from '@tabler/icons-react';
import type { TablerIcon } from '@tabler/icons-react';
import { OnboardingIcon } from '@/components/onboarding/onboarding-icon';

// Keys + labels mirror the backend App\Profile\Interest enum.
const INTERESTS: { value: string; label: string; icon: TablerIcon }[] = [
    { value: 'parks', label: 'Parks & green', icon: IconTree },
    { value: 'swimming', label: 'Swimming & lakes', icon: IconSwimming },
    { value: 'sports', label: 'Sports & courts', icon: IconBallFootball },
    { value: 'museums', label: 'Museums & galleries', icon: IconPalette },
    { value: 'sights', label: 'Sights & landmarks', icon: IconBuildingBank },
    { value: 'family', label: 'Family & kids', icon: IconBabyCarriage },
    { value: 'cafes', label: 'Cafés & bakeries', icon: IconCoffee },
    { value: 'nightlife', label: 'Bars & nightlife', icon: IconGlassFull },
    { value: 'live_music', label: 'Live music', icon: IconMusic },
    { value: 'coworking', label: 'Coworking', icon: IconDeviceLaptop },
    { value: 'libraries', label: 'Libraries', icon: IconBooks },
    { value: 'dogs', label: 'Dog-friendly', icon: IconDog },
];

// Mirrors App\Profile\Interest::MAX_SELECT.
const MAX = 7;

export function InterestsStep({
    interests,
    onToggle,
}: {
    interests: string[];
    onToggle: (value: string) => void;
}) {
    const count = interests.length;
    const atMax = count >= MAX;

    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="py-2 pb-6">
                <h2 className="mb-2 font-display text-[26px] font-medium">
                    What are you into?
                </h2>
                <p className="text-sm text-muted-foreground">
                    Optional — pick up to {MAX} to tailor your home feed and day
                    plans. You can change them anytime.
                </p>
                <p className="mt-2 text-[13px] font-semibold text-muted-foreground">
                    {count}/{MAX} chosen
                </p>
            </div>

            <div className="grid grid-cols-2 gap-2.5">
                {INTERESTS.map((interest) => {
                    const on = interests.includes(interest.value);
                    const disabled = !on && atMax;

                    return (
                        <button
                            key={interest.value}
                            type="button"
                            aria-pressed={on}
                            disabled={disabled}
                            onClick={() => onToggle(interest.value)}
                            className={`flex items-center gap-2.5 rounded-[12px] border-[1.5px] px-4 py-3.5 text-left text-[14px] font-semibold transition-all ${
                                on
                                    ? 'border-primary bg-accent-soft text-primary'
                                    : disabled
                                      ? 'border-border bg-card opacity-40'
                                      : 'border-border bg-card hover:border-primary/30'
                            }`}
                        >
                            <OnboardingIcon icon={interest.icon} size="md" />
                            {interest.label}
                            {on && (
                                <OnboardingIcon
                                    icon={IconCheck}
                                    size="sm"
                                    className="ml-auto text-primary"
                                />
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
