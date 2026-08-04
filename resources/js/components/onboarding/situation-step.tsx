// Friendly first-person choices. 'job' resolves to eu_employee /
// non_eu_employee via the EU follow-up (see resolveSituation in onboarding.tsx).
import {
    IconBriefcase,
    IconCheck,
    IconDeviceLaptop,
    IconFlag,
    IconHeartHandshake,
    IconIdBadge,
    IconPlaneArrival,
    IconSchool,
    IconSparkles,
    IconWorld,
} from '@tabler/icons-react';
import { OnboardingIcon } from '@/components/onboarding/onboarding-icon';

const choices = [
    {
        value: 'job',
        icon: IconBriefcase,
        title: 'I have a job here',
        subtitle: 'Employed by a company in Germany',
    },
    {
        value: 'student',
        icon: IconSchool,
        title: "I'm studying",
        subtitle: 'University, Studienkolleg or study preparation',
    },
    {
        value: 'freelancer',
        icon: IconDeviceLaptop,
        title: 'I work for myself',
        subtitle: 'Freelance or my own business',
    },
    {
        value: 'family_reunification',
        icon: IconHeartHandshake,
        title: "I'm joining family",
        subtitle: 'My partner or family lives here',
    },
    {
        value: 'digital_nomad',
        icon: IconWorld,
        title: 'I work remotely',
        subtitle: 'Digital nomad, employer abroad',
    },
    {
        value: 'other',
        icon: IconSparkles,
        title: 'Something else',
        subtitle: "We'll start with the essentials everyone needs",
    },
];

const entryModes = [
    {
        value: 'd_visa',
        icon: IconIdBadge,
        label: 'With a national D visa',
        subtitle: 'Share its expiry to help identify what to verify first',
    },
    {
        value: 'visa_free',
        icon: IconPlaneArrival,
        label: 'Visa-free (90-day window)',
        subtitle: 'We’ll tailor first-permit guidance to your entry window',
    },
    {
        value: 'has_permit',
        icon: IconCheck,
        label: 'I already hold a German residence permit',
        subtitle: "We'll adjust the first-permit guidance",
    },
];

export function SituationStep({
    value,
    isEu,
    entryMode,
    showEuQuestion,
    onChange,
    onIsEuChange,
    onEntryModeChange,
    visaExpiresAt,
    onVisaExpiresAtChange,
    currentResidenceTitle,
    onCurrentResidenceTitleChange,
    residenceTitleExpiresAt,
    onResidenceTitleExpiresAtChange,
    caseGoal,
    onCaseGoalChange,
    sponsorCurrentTitle,
    onSponsorCurrentTitleChange,
}: {
    value: string;
    isEu: boolean | null;
    entryMode: string;
    showEuQuestion: boolean;
    onChange: (value: string) => void;
    onIsEuChange: (value: boolean) => void;
    onEntryModeChange: (value: string) => void;
    visaExpiresAt: string;
    onVisaExpiresAtChange: (value: string) => void;
    currentResidenceTitle: string;
    onCurrentResidenceTitleChange: (value: string) => void;
    residenceTitleExpiresAt: string;
    onResidenceTitleExpiresAtChange: (value: string) => void;
    caseGoal: string;
    onCaseGoalChange: (value: string) => void;
    sponsorCurrentTitle: string;
    onSponsorCurrentTitleChange: (value: string) => void;
}) {
    const residenceFactsApply =
        value === 'family_reunification' || isEu === false;
    const sharedGoals = [
        { value: 'renew_current_title', label: 'Renew my current title' },
        { value: 'settlement_permit', label: 'Explore settlement' },
        { value: 'understand_options', label: "I'm not sure" },
    ];
    const caseGoals =
        value === 'family_reunification'
            ? currentResidenceTitle === 'family_reunification'
                ? sharedGoals
                : [
                      {
                          value: 'family_reunification_permit',
                          label: 'Apply for family reunification',
                      },
                      ...sharedGoals.filter(
                          (goal) => goal.value !== 'settlement_permit',
                      ),
                  ]
            : currentResidenceTitle === 'blue_card'
              ? sharedGoals
              : [
                    {
                        value: 'blue_card',
                        label: 'Apply for an EU Blue Card',
                    },
                    ...sharedGoals,
                ];

    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="py-2 pb-6">
                <h2 className="mb-2 font-display text-[26px] font-medium">
                    What brings you to Cologne?
                </h2>
                <p className="text-sm text-muted-foreground">
                    This helps us start a focused plan. Verify requirements with
                    official sources before you act.
                </p>
            </div>

            <div className="flex flex-col gap-2.5">
                {choices.map((s) => (
                    <button
                        key={s.value}
                        type="button"
                        onClick={() => onChange(s.value)}
                        aria-pressed={value === s.value}
                        className={`flex min-h-11 items-center gap-3.5 rounded-xl border-[1.5px] p-3.5 text-left transition-all ${
                            value === s.value
                                ? 'border-primary bg-accent-soft'
                                : 'border-border bg-card hover:border-primary/30'
                        }`}
                    >
                        <span className="flex w-8 items-center justify-center">
                            <OnboardingIcon icon={s.icon} size="lg" />
                        </span>
                        <div className="flex-1">
                            <div className="text-sm font-semibold">
                                {s.title}
                            </div>
                            <div className="mt-0.5 text-xs text-muted-foreground">
                                {s.subtitle}
                            </div>
                        </div>
                        <OnboardingIcon
                            icon={IconCheck}
                            size="sm"
                            className={`text-primary transition-opacity ${
                                value === s.value ? 'opacity-100' : 'opacity-0'
                            }`}
                        />
                    </button>
                ))}
            </div>

            {showEuQuestion && (
                <div className="mt-4 animate-in rounded-xl border border-dashed border-border bg-card p-4 fade-in slide-in-from-bottom-2">
                    <div className="mb-1 text-sm font-semibold">
                        Are you an EU / EEA / Swiss citizen?
                    </div>
                    <p className="mb-3 text-xs text-primary">
                        Why we ask: citizenship affects whether residence-
                        permit guidance might apply.
                    </p>
                    <div className="flex gap-2.5">
                        {[
                            { value: true, icon: IconFlag, label: 'Yes' },
                            { value: false, icon: IconWorld, label: 'No' },
                        ].map((opt) => (
                            <button
                                key={String(opt.value)}
                                type="button"
                                onClick={() => onIsEuChange(opt.value)}
                                aria-pressed={isEu === opt.value}
                                className={`flex min-h-11 flex-1 items-center justify-center gap-2 rounded-[10px] border-[1.5px] px-3.5 py-2.5 text-sm font-semibold transition-all ${
                                    isEu === opt.value
                                        ? 'border-primary bg-accent-soft text-primary'
                                        : 'border-border bg-card hover:border-primary/30'
                                }`}
                            >
                                <OnboardingIcon icon={opt.icon} size="sm" />
                                {opt.label}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {residenceFactsApply && (
                <div className="mt-4 animate-in rounded-xl border border-dashed border-border bg-card p-4 fade-in slide-in-from-bottom-2">
                    <div className="mb-1 text-sm font-semibold">
                        How did you enter Germany?
                    </div>
                    <p className="mb-3 text-xs text-primary">
                        Why we ask: entry details affect the guidance we show.
                        Verify relevant time limits with official sources.
                    </p>
                    <div className="flex flex-col gap-2">
                        {entryModes.map((opt) => (
                            <button
                                key={opt.value}
                                type="button"
                                onClick={() => onEntryModeChange(opt.value)}
                                aria-pressed={entryMode === opt.value}
                                className={`min-h-11 rounded-[10px] border-[1.5px] px-3.5 py-3 text-left transition-all ${
                                    entryMode === opt.value
                                        ? 'border-primary bg-accent-soft'
                                        : 'border-border bg-card hover:border-primary/30'
                                }`}
                            >
                                <span className="flex items-center gap-2 text-sm font-semibold">
                                    <OnboardingIcon icon={opt.icon} size="sm" />
                                    {opt.label}
                                </span>
                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                    {opt.subtitle}
                                </span>
                            </button>
                        ))}
                    </div>
                    {entryMode === 'd_visa' && (
                        <div className="mt-3">
                            <label className="flex flex-wrap items-center gap-2 text-[13px] font-semibold">
                                When does your visa expire?
                                <input
                                    type="date"
                                    value={visaExpiresAt}
                                    onChange={(e) =>
                                        onVisaExpiresAtChange(e.target.value)
                                    }
                                    className="min-h-11 rounded-[10px] border-[1.5px] border-border bg-card px-3 py-2 text-sm font-normal outline-none focus:border-primary"
                                />
                            </label>
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                Optional — it helps us flag an important date
                                for you to verify.
                            </p>
                        </div>
                    )}

                    {entryMode === 'has_permit' && (
                        <div className="mt-4 border-t border-border pt-4">
                            <div className="mb-2 text-sm font-semibold">
                                Which German visa or residence title do you
                                currently hold?
                            </div>
                            <p className="mb-3 text-xs text-muted-foreground">
                                Optional. Choose “I’m not sure” if you do not
                                know the exact title.
                            </p>
                            <div className="grid grid-cols-2 gap-2">
                                {[
                                    {
                                        value: 'national_d_visa',
                                        label: 'National D visa',
                                    },
                                    {
                                        value: 'standard_work_permit',
                                        label: 'Work residence permit',
                                    },
                                    {
                                        value: 'blue_card',
                                        label: 'EU Blue Card',
                                    },
                                    {
                                        value: 'family_reunification',
                                        label: 'Family reunification permit',
                                    },
                                    {
                                        value: 'settlement_permit_18c',
                                        label: 'Settlement permit',
                                    },
                                    { value: 'other', label: 'Another title' },
                                    { value: '', label: "I'm not sure" },
                                ].map((option) => (
                                    <button
                                        key={option.label}
                                        type="button"
                                        onClick={() =>
                                            onCurrentResidenceTitleChange(
                                                option.value,
                                            )
                                        }
                                        aria-pressed={
                                            currentResidenceTitle ===
                                            option.value
                                        }
                                        className={`min-h-11 rounded-[10px] border-[1.5px] px-3 py-2 text-left text-xs font-semibold transition-all ${
                                            currentResidenceTitle ===
                                            option.value
                                                ? 'border-primary bg-accent-soft text-primary'
                                                : 'border-border bg-card hover:border-primary/30'
                                        }`}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                            {currentResidenceTitle !== '' && (
                                <label className="mt-3 flex flex-wrap items-center gap-2 text-[13px] font-semibold">
                                    When does this title expire?
                                    <input
                                        type="date"
                                        aria-label="When does this title expire?"
                                        value={residenceTitleExpiresAt}
                                        onChange={(event) =>
                                            onResidenceTitleExpiresAtChange(
                                                event.target.value,
                                            )
                                        }
                                        className="min-h-11 rounded-[10px] border-[1.5px] border-border bg-card px-3 py-2 text-sm font-normal outline-none focus:border-primary"
                                    />
                                </label>
                            )}
                        </div>
                    )}

                    {value === 'family_reunification' && entryMode !== '' && (
                        <div className="mt-4 border-t border-border pt-4">
                            <div className="mb-2 text-sm font-semibold">
                                Which title does your sponsor currently hold?
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {[
                                    {
                                        value: 'national_d_visa',
                                        label: 'National D visa',
                                    },
                                    {
                                        value: 'standard_work_permit',
                                        label: 'Work residence permit',
                                    },
                                    {
                                        value: 'blue_card',
                                        label: 'My sponsor has a Blue Card',
                                    },
                                    {
                                        value: 'blue_card_pending',
                                        label: 'Their Blue Card is pending',
                                    },
                                    {
                                        value: 'settlement_permit_18c',
                                        label: 'Settlement permit',
                                    },
                                    { value: 'other', label: 'Another title' },
                                    { value: '', label: "I'm not sure" },
                                ].map((option) => (
                                    <button
                                        key={option.label}
                                        type="button"
                                        onClick={() =>
                                            onSponsorCurrentTitleChange(
                                                option.value,
                                            )
                                        }
                                        aria-pressed={
                                            sponsorCurrentTitle === option.value
                                        }
                                        className={`min-h-11 rounded-full border-[1.5px] px-3 py-1.5 text-xs font-semibold transition-all ${
                                            sponsorCurrentTitle === option.value
                                                ? 'border-primary bg-accent-soft text-primary'
                                                : 'border-border bg-card hover:border-primary/30'
                                        }`}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {entryMode !== '' && (
                        <div className="mt-4 border-t border-border pt-4">
                            <div className="mb-2 text-sm font-semibold">
                                What would you like help with?
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {caseGoals.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() =>
                                            onCaseGoalChange(option.value)
                                        }
                                        aria-pressed={caseGoal === option.value}
                                        className={`min-h-11 rounded-full border-[1.5px] px-3 py-1.5 text-xs font-semibold transition-all ${
                                            caseGoal === option.value
                                                ? 'border-primary bg-accent-soft text-primary'
                                                : 'border-border bg-card hover:border-primary/30'
                                        }`}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
