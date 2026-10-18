import { InterestsStep } from '@/components/onboarding/interests-step';
import { SituationStep } from '@/components/onboarding/situation-step';
import type { OnboardingData } from '@/pages/onboarding';

const germanLevels = [
    { value: 'none', label: 'None' },
    { value: 'a1', label: 'A1' },
    { value: 'a2', label: 'A2' },
    { value: 'b1', label: 'B1' },
    { value: 'b2', label: 'B2' },
    { value: 'c1', label: 'C1' },
    { value: 'c2', label: 'C2' },
];

/**
 * The one screen where nothing is required.
 *
 * Residence details used to sit on the branch question's screen, which pushed
 * it to eight questions and 2.5 viewports. They belong here instead: none of
 * them blocks a feature, and every one of them can be answered later — the
 * registered facts through PendingAnswers on the Bureaucracy page, the rest
 * through the task card that needs them.
 *
 * They share a screen with interests so there is exactly one thing to skip,
 * rather than a skip control on every question.
 */
export function OptionalStep({
    data,
    onChange,
}: {
    data: OnboardingData;
    onChange: (patch: Partial<OnboardingData>) => void;
}) {
    return (
        <div className="pb-4">
            <SituationStep
                section="residence"
                value={data.situation}
                isEu={data.is_eu}
                showEuQuestion={false}
                onChange={() => {}}
                onIsEuChange={() => {}}
                entryMode={data.entry_mode}
                onEntryModeChange={(v) =>
                    onChange({
                        entry_mode: v,
                        current_residence_title:
                            v === 'd_visa' ? 'national_d_visa' : '',
                    })
                }
                visaExpiresAt={data.visa_expires_at}
                onVisaExpiresAtChange={(v) => onChange({ visa_expires_at: v })}
                currentResidenceTitle={data.current_residence_title}
                onCurrentResidenceTitleChange={(v) =>
                    onChange({
                        current_residence_title: v,
                        residence_title_expires_at: '',
                        case_goal: '',
                    })
                }
                residenceTitleExpiresAt={data.residence_title_expires_at}
                onResidenceTitleExpiresAtChange={(v) =>
                    onChange({ residence_title_expires_at: v })
                }
                caseGoal={data.case_goal}
                onCaseGoalChange={(v) => onChange({ case_goal: v })}
                sponsorCurrentTitle={data.sponsor_current_title}
                onSponsorCurrentTitleChange={(v) =>
                    onChange({ sponsor_current_title: v })
                }
            />

            <div className="mx-auto flex max-w-[600px] flex-col gap-5 px-6 pt-2">
                <div>
                    <div className="mb-2 text-[13px] font-semibold">
                        Do you have a documented German level?{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional — only choose a level you can document)
                        </span>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {germanLevels.map((l) => (
                            <button
                                key={l.value}
                                type="button"
                                onClick={() =>
                                    onChange({
                                        documented_german_level:
                                            data.documented_german_level ===
                                            l.value
                                                ? ''
                                                : l.value,
                                    })
                                }
                                aria-pressed={
                                    data.documented_german_level === l.value
                                }
                                className={`min-h-11 rounded-full border-[1.5px] px-3.5 py-1.5 font-mono text-[13px] transition-all ${
                                    data.documented_german_level === l.value
                                        ? 'border-primary bg-accent-soft font-semibold text-primary'
                                        : 'border-border bg-card hover:border-primary/30'
                                }`}
                            >
                                {l.label}
                            </button>
                        ))}
                    </div>
                </div>

                <div>
                    <div className="mb-2 text-[13px] font-semibold">
                        Do you have a Deutschlandticket?{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </div>
                    <div className="flex gap-2">
                        {[
                            { value: true, label: 'Yes, I have one' },
                            { value: false, label: 'Not yet' },
                        ].map((opt) => (
                            <button
                                key={String(opt.value)}
                                type="button"
                                onClick={() =>
                                    onChange({
                                        has_deutschlandticket: opt.value,
                                    })
                                }
                                aria-pressed={
                                    data.has_deutschlandticket === opt.value
                                }
                                className={`min-h-11 flex-1 rounded-[10px] border-[1.5px] px-3 py-2.5 text-[13px] font-semibold transition-all ${
                                    data.has_deutschlandticket === opt.value
                                        ? 'border-primary bg-accent-soft text-primary'
                                        : 'border-border bg-card hover:border-primary/30'
                                }`}
                            >
                                {opt.label}
                            </button>
                        ))}
                    </div>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        If you do, we'll show trips as covered instead of
                        quoting a single fare.
                    </p>
                </div>
            </div>

            <InterestsStep
                interests={data.interests}
                onToggle={(v) =>
                    onChange({
                        interests: data.interests.includes(v)
                            ? data.interests.filter((x) => x !== v)
                            : [...data.interests, v],
                    })
                }
            />
        </div>
    );
}
