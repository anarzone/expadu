// What the page calls each answer and its choices. The registry owns the questions and
// their wording; it has no short display labels, so these are UI copy only. Applicability,
// order of asking and what an answer changes stay with the backend.

import { formatDate } from './format';
import type { IconName } from './icons';
import type { Answer, AnswerState, FactState } from './types';

interface FieldCopy {
    label: string;
    icon: IconName;
    options?: Record<string, string>;
}

const residenceTitles = {
    national_d_visa: 'National D visa',
    standard_work_permit: 'Work permit',
    blue_card_pending: 'Blue Card applied for',
    blue_card: 'EU Blue Card',
    family_reunification: 'Family reunification',
    settlement_permit_9: 'Settlement permit · §9',
    settlement_permit_18c: 'Settlement permit · §18c',
    settlement_permit_unknown: 'Settlement permit · type unknown',
    other: 'Another title',
};

const yesNoUnsure = { yes: 'Yes', no: 'No', unsure: 'I’m not sure yet' };

export const fields: Record<string, FieldCopy> = {
    citizenship_group: {
        label: 'Citizenship group',
        icon: 'people',
        options: { eu: 'EU', non_eu: 'Non-EU' },
    },
    purpose: {
        label: 'Purpose of stay',
        icon: 'briefcase',
        options: {
            employment: 'Employment',
            study: 'Study',
            freelance: 'Freelance',
            family: 'Family',
            digital_nomad: 'Digital nomad',
            other: 'Something else',
        },
    },
    arrival_planned: {
        label: 'Your move',
        icon: 'pin',
        options: { true: 'Yes, still planning', false: 'No, I have arrived' },
    },
    arrival_date: { label: 'Arrival in Germany', icon: 'calendar' },
    entry_mode: {
        label: 'How you entered',
        icon: 'document',
        options: {
            d_visa: 'With a national D visa',
            visa_free: 'Without a visa',
            has_permit: 'With a residence permit',
        },
    },
    current_residence_title: {
        label: 'Current visa or permit',
        icon: 'document',
        options: residenceTitles,
    },
    visa_expires_at: { label: 'Entry visa expiry', icon: 'calendar' },
    residence_title_expires_at: { label: 'Permit expiry', icon: 'calendar' },
    residence_card_expires_at: {
        label: 'Residence card expiry',
        icon: 'calendar',
    },
    case_goal: {
        label: 'What you want to do next',
        icon: 'arrow',
        options: {
            blue_card: 'Get an EU Blue Card',
            family_reunification_permit: 'Join my family',
            renew_current_title: 'Renew my current permit',
            settlement_permit: 'Get a settlement permit',
            understand_options: 'Understand my options',
        },
    },
    permit_track: {
        label: 'Work permit route',
        icon: 'briefcase',
        options: {
            standard: 'Standard work permit',
            blue_card: 'EU Blue Card',
            chancenkarte: 'Opportunity Card (Chancenkarte)',
        },
    },
    moved_in_at: { label: 'Move-in date', icon: 'pin' },
    housing_provider_confirmation: {
        label: 'Housing provider’s confirmation',
        icon: 'document',
        options: {
            available: 'I have it',
            requested: 'I asked for it',
            not_available: 'I don’t have it',
        },
    },
    registration_status: {
        label: 'Address registration',
        icon: 'pin',
        options: { registered: 'Yes, registered', not_registered: 'Not yet' },
    },
    health_coverage_confirmed: {
        label: 'Health insurance',
        icon: 'check',
        options: { true: 'Confirmed with my insurer', false: 'Not yet' },
    },
    tax_id_available: {
        label: 'Tax ID',
        icon: 'document',
        options: { true: 'I have it', false: 'Not yet' },
    },
    bank_account_help_needed: {
        label: 'Help with a bank account',
        icon: 'document',
        options: { true: 'Yes, please', false: 'No, thanks' },
    },
    german_level: {
        label: 'German level',
        icon: 'people',
        options: {
            none: 'None yet',
            a1: 'A1',
            a2: 'A2',
            b1: 'B1',
            b2: 'B2',
            c1: 'C1',
            c2: 'C2',
        },
    },
    weekly_work_hours: { label: 'Weekly working hours', icon: 'clock' },
    blue_card_qualifying_months: {
        label: 'Months of Blue Card employment',
        icon: 'calendar',
    },
    livelihood_secured: {
        label: 'Livelihood secured',
        icon: 'check',
        options: yesNoUnsure,
    },
    housing_sufficient: {
        label: 'Enough living space',
        icon: 'pin',
        options: yesNoUnsure,
    },
    legal_social_knowledge_proved: {
        label: 'Knowledge of life in Germany',
        icon: 'document',
        options: yesNoUnsure,
    },
    family_residence_permit_held_since: {
        label: 'Family permit held since',
        icon: 'calendar',
    },
    marital_household_continues: {
        label: 'Living together as a married household',
        icon: 'people',
        options: { true: 'Yes', false: 'No' },
    },
    sponsor: {
        label: 'Family member you’re joining',
        icon: 'people',
        options: {
            german: 'German citizen',
            eu_citizen: 'EU citizen',
            non_eu: 'Non-EU citizen',
        },
    },
    sponsor_current_title: {
        label: 'Your spouse’s residence status',
        icon: 'people',
        options: residenceTitles,
    },
    sponsor_title_at_entry: {
        label: 'Your sponsor’s status when you joined',
        icon: 'people',
        options: residenceTitles,
    },
};

/** The order the summary lists answers in: who you are, your move, your papers, then the rest. */
export const order = Object.keys(fields);

export function fieldLabel(key: string): string {
    return fields[key]?.label ?? key.replace(/_/g, ' ');
}

export function fieldIcon(key: string): IconName {
    return fields[key]?.icon ?? 'document';
}

export function optionLabel(key: string, value: unknown): string {
    const own = fields[key]?.options?.[String(value)];

    if (own) {
        return own;
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return String(value).replace(/_/g, ' ');
}

const stateLabels: Record<Exclude<AnswerState, 'value'>, string> = {
    unknown: 'I’m not sure',
    declined: 'Prefer not to say',
    not_applicable: 'Doesn’t apply to me',
};

/** An answer as the person reads it back. */
export function answerLabel(
    key: string,
    type: string | undefined,
    answer: { state: AnswerState | FactState; value: unknown } | null,
): string {
    if (!answer) {
        return 'Not answered';
    }

    if (answer.state !== 'value') {
        return (
            stateLabels[answer.state as Exclude<AnswerState, 'value'>] ??
            'Not answered'
        );
    }

    if (answer.value === null || answer.value === undefined) {
        return 'Not answered';
    }

    if (type === 'date') {
        return formatDate(String(answer.value));
    }

    if (type === 'integer') {
        return String(answer.value);
    }

    return optionLabel(key, answer.value);
}

/** The choices a field offers, in the registry's order. Booleans offer yes, then no. */
export function choices(
    key: string,
    type: string,
    options: string[],
): { value: Answer['value']; label: string }[] {
    if (type === 'boolean') {
        return [true, false].map((value) => ({
            value,
            label: optionLabel(key, value),
        }));
    }

    return options.map((value) => ({ value, label: optionLabel(key, value) }));
}
