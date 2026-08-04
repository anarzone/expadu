import { IconConfetti } from '@tabler/icons-react';
import { OnboardingIcon } from '@/components/onboarding/onboarding-icon';
import { PrivacyNote } from '@/components/onboarding/welcome-step';
import { resolveSituation } from '@/pages/onboarding';
import type { OnboardingData } from '@/pages/onboarding';

const situationLabels: Record<string, string> = {
    non_eu_employee: 'Employee (non-EU)',
    eu_employee: 'Employee (EU)',
    student: 'Student',
    freelancer: 'Freelancer',
    family_reunification: 'Joining family',
    digital_nomad: 'Remote worker',
    other: 'Other situation',
};

const entryModeLabels: Record<string, string> = {
    d_visa: 'National D visa',
    visa_free: 'Visa-free entry',
    has_permit: 'Already hold a permit',
};

const residenceTitleLabels: Record<string, string> = {
    national_d_visa: 'National D visa',
    standard_work_permit: 'Work residence permit',
    blue_card: 'EU Blue Card',
    blue_card_pending: 'EU Blue Card application pending',
    family_reunification: 'Family reunification permit',
    settlement_permit_18c: 'Settlement permit',
    other: 'Another title',
};

const goalLabels: Record<string, string> = {
    blue_card: 'Apply for an EU Blue Card',
    family_reunification_permit: 'Apply for family reunification',
    renew_current_title: 'Renew my current title',
    settlement_permit: 'Explore settlement',
    understand_options: "I'm not sure",
};

const addressRegistrationLabels: Record<string, string> = {
    registrable: 'I can register here',
    not_registrable: 'I cannot register here',
    unsure: "I'm not sure yet",
};

export function ConfirmationStep({ data }: { data: OnboardingData }) {
    const situation = resolveSituation(data.situation, data.is_eu);
    const answers = [
        ['Situation', situationLabels[situation]],
        ['Veedel', data.veedel],
        [
            'Address registration',
            addressRegistrationLabels[data.address_registration_status],
        ],
        data.address_registration_status === 'registrable' &&
        data.moved_in_at !== ''
            ? ['Move-in date', data.moved_in_at]
            : null,
        data.entry_mode !== ''
            ? ['Entry', entryModeLabels[data.entry_mode]]
            : null,
        data.entry_mode === 'd_visa' && data.visa_expires_at !== ''
            ? ['D-visa expiry', data.visa_expires_at]
            : null,
        data.entry_mode === 'has_permit' && data.current_residence_title !== ''
            ? [
                  'Current title',
                  residenceTitleLabels[data.current_residence_title],
              ]
            : null,
        data.entry_mode === 'has_permit' &&
        data.current_residence_title !== '' &&
        data.residence_title_expires_at !== ''
            ? ['Title expiry', data.residence_title_expires_at]
            : null,
        data.case_goal !== ''
            ? ['Your goal', goalLabels[data.case_goal]]
            : null,
        situation === 'family_reunification' &&
        data.sponsor_current_title !== ''
            ? [
                  'Sponsor’s title',
                  residenceTitleLabels[data.sponsor_current_title],
              ]
            : null,
        data.documented_german_level !== ''
            ? [
                  'Documented German level',
                  data.documented_german_level.toUpperCase(),
              ]
            : null,
    ].filter((answer): answer is [string, string] => answer !== null);

    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="pt-6 pb-2">
                <div className="mb-4">
                    <OnboardingIcon icon={IconConfetti} size="xl" />
                </div>
                <h2 className="mb-2.5 font-display text-[27px] leading-tight font-medium">
                    Check your answers
                </h2>
                <p className="text-[14.5px] leading-relaxed text-muted-foreground">
                    We’ll use these details to start your first plan after you
                    continue, with official sources to verify. You can update
                    them later.
                </p>
            </div>

            <dl className="mt-5 divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                {answers.map(([label, answer]) => (
                    <div
                        key={label}
                        className="flex items-start justify-between gap-4 px-4 py-3"
                    >
                        <dt className="text-xs font-medium text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="text-right text-sm font-semibold">
                            {answer}
                        </dd>
                    </div>
                ))}
            </dl>

            <PrivacyNote text="Your answers are stored in your Expadu profile to personalise your plan. They are never sold. Expadu is not legal advice." />
        </div>
    );
}
