<?php

namespace App\Bureaucracy\Analyser;

use App\Bureaucracy\Facts\FactRegistry;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Explains a case's own confirmed answers back, from fixed templates over the
 * confirmed values; the model never writes these. Labels match the Paperwork UI's
 * (resources/js/components/paperwork/facts.ts) so a person reads the same words.
 */
final class CaseFactExplainer
{
    private const ResidenceTitles = [
        'national_d_visa' => 'National D visa', 'standard_work_permit' => 'Work permit', 'blue_card_pending' => 'Blue Card applied for',
        'blue_card' => 'EU Blue Card', 'family_reunification' => 'Family reunification', 'settlement_permit_9' => 'Settlement permit · §9',
        'settlement_permit_18c' => 'Settlement permit · §18c', 'settlement_permit_unknown' => 'Settlement permit · type unknown',
        'other' => 'Another title', 'none' => 'No residence title yet',
    ];

    private const YesNoUnsure = ['yes' => 'Yes', 'no' => 'No', 'unsure' => 'I’m not sure yet'];

    private const Fields = [
        'citizenship_group' => ['Citizenship group', ['eu' => 'EU', 'non_eu' => 'Non-EU']],
        'purpose' => ['Purpose of stay', ['employment' => 'Employment', 'study' => 'Study', 'freelance' => 'Freelance', 'family' => 'Family',
            'digital_nomad' => 'Digital nomad', 'other' => 'Something else']],
        'arrival_planned' => ['Your move', ['true' => 'Yes, still planning', 'false' => 'No, I have arrived']],
        'arrival_date' => ['Arrival in Germany', null],
        'entry_mode' => ['How you entered', ['d_visa' => 'With a national D visa', 'visa_free' => 'Without a visa', 'has_permit' => 'With a residence permit']],
        'current_residence_title' => ['Current visa or permit', self::ResidenceTitles],
        'visa_expires_at' => ['Entry visa expiry', null],
        'residence_title_expires_at' => ['Permit expiry', null],
        'residence_card_expires_at' => ['Residence card expiry', null],
        'case_goal' => ['What you want to do next', ['blue_card' => 'Get an EU Blue Card', 'family_reunification_permit' => 'Join my family',
            'renew_current_title' => 'Renew my current permit', 'settlement_permit' => 'Get a settlement permit', 'understand_options' => 'Understand my options']],
        'permit_track' => ['Work permit route', ['standard' => 'Standard work permit', 'blue_card' => 'EU Blue Card', 'chancenkarte' => 'Opportunity Card (Chancenkarte)']],
        'moved_in_at' => ['Move-in date', null],
        'housing_provider_confirmation' => ['Housing provider’s confirmation', ['available' => 'I have it', 'requested' => 'I asked for it', 'not_available' => 'I don’t have it']],
        'registration_status' => ['Address registration', ['registered' => 'Yes, registered', 'not_registered' => 'Not yet']],
        'health_coverage_confirmed' => ['Health insurance', ['true' => 'Confirmed with my insurer', 'false' => 'Not yet']],
        'tax_id_available' => ['Tax ID', ['true' => 'I have it', 'false' => 'Not yet']],
        'bank_account_help_needed' => ['Help with a bank account', ['true' => 'Yes, please', 'false' => 'No, thanks']],
        'german_level' => ['German level', ['none' => 'None yet', 'a1' => 'A1', 'a2' => 'A2', 'b1' => 'B1', 'b2' => 'B2', 'c1' => 'C1', 'c2' => 'C2']],
        'weekly_work_hours' => ['Weekly working hours', null],
        'blue_card_qualifying_months' => ['Months of Blue Card employment', null],
        'livelihood_secured' => ['Livelihood secured', self::YesNoUnsure],
        'housing_sufficient' => ['Enough living space', self::YesNoUnsure],
        'legal_social_knowledge_proved' => ['Knowledge of life in Germany', self::YesNoUnsure],
        'family_residence_permit_held_since' => ['Family permit held since', null],
        'marital_household_continues' => ['Living together as a married household', ['true' => 'Yes', 'false' => 'No']],
        'sponsor' => ['Family member you’re joining', ['german' => 'German citizen', 'eu_citizen' => 'EU citizen', 'non_eu' => 'Non-EU citizen']],
        'sponsor_current_title' => ['Your spouse’s residence status', self::ResidenceTitles],
        'sponsor_title_at_entry' => ['Your sponsor’s status when you joined', self::ResidenceTitles],
    ];

    public function __construct(private FactRegistry $registry) {}

    public function label(string $key): string
    {
        return self::Fields[$key][0] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public function valueLabel(string $key, mixed $value): string
    {
        $options = self::Fields[$key][1] ?? null;
        $raw = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        if (is_array($options) && isset($options[$raw])) {
            return $options[$raw];
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('j F Y');
        }

        return $raw;
    }

    /**
     * The confirmed answers the given condition keys read, with the person's value.
     *
     * @param  array<string, mixed>  $facts  ConfirmedFactView output (the person's own answers)
     * @param  list<string>  $keys
     * @return list<array{key: string, label: string, value: mixed, value_label: string}>
     */
    public function confirmed(array $facts, array $keys): array
    {
        $rows = [];
        foreach (array_values(array_unique($keys)) as $key) {
            if (($facts['states'][$key] ?? null) !== 'value' || ! array_key_exists($key, $facts['values'] ?? [])) {
                continue;
            }
            $value = $facts['values'][$key];
            $rows[] = ['key' => $key, 'label' => $this->label($key), 'value' => $value, 'value_label' => $this->valueLabel($key, $value)];
        }

        return $rows;
    }

    /**
     * What the processor may see of those answers. Choices and yes/no answers are sent
     * as their label; dates and counts only as "recorded", since no sentence may carry
     * a figure the card does not, and the model has no use for the value itself.
     *
     * @param  list<array{key: string, label: string, value: mixed, value_label: string}>  $confirmed
     * @return list<array{key: string, question: string, answer: string}>
     */
    public function forPrompt(array $confirmed): array
    {
        return array_map(function (array $row): array {
            try {
                $type = $this->registry->definition($row['key'])->type;
            } catch (DomainException) {
                $type = 'unknown';
            }

            return ['key' => $row['key'], 'question' => $row['label'],
                'answer' => in_array($type, ['enum', 'boolean'], true) ? $row['value_label'] : 'recorded (value not shared)'];
        }, $confirmed);
    }

    /**
     * Why a card is, or may be, part of this case, from the engine's own criteria.
     *
     * @param  array<string, mixed>|null  $facts  null when the reader may not see answers
     * @return array{applies_to_case: bool|null, facts: list<array<string, mixed>>, missing_facts: list<array{key: string, label: string}>, explanation: string}
     */
    public function forCard(CheckedCard $card, ?array $facts): array
    {
        $status = [];
        foreach ($card->criteria as $row) {
            $status[$row['fact_key']] = $status[$row['fact_key']] ?? $row['status'];
            if ($row['status'] === 'met') {
                $status[$row['fact_key']] = 'met';
            }
        }
        $rows = $facts === null ? [] : array_map(fn (array $row) => [...$row, 'condition' => $status[$row['key']] ?? 'not_a_condition'],
            $this->confirmed($facts, $card->conditionKeys));
        $missing = $facts === null ? [] : array_map(fn (string $key) => ['key' => $key, 'label' => $this->label($key)], $card->missingFacts);
        $listed = fn (array $items) => implode('; ', array_map(fn ($row) => $row['label'].': '.$row['value_label'], $items));
        $met = array_values(array_filter($rows, fn ($row) => $row['condition'] === 'met'));
        $unmet = array_values(array_filter($rows, fn ($row) => $row['condition'] === 'unmet'));

        $explanation = match (true) {
            $facts === null => match ($card->appliesToCase) {
                true => 'This card is part of this plan.',
                null => 'Whether this card applies depends on answers not confirmed yet.',
                false => 'This card is not part of this plan.',
            },
            $card->appliesToCase === true => $met === [] ? 'This card is part of your plan.' : 'This card is part of your plan because you confirmed: '.$listed($met).'.',
            $card->appliesToCase === null => 'Whether this card applies depends on answers you have not confirmed yet: '
                .implode('; ', array_column($missing, 'label')).'.'.($met === [] ? '' : ' So far you confirmed: '.$listed($met).'.'),
            default => $unmet === [] ? 'This card is not part of your plan.' : 'This card is not part of your plan, based on your confirmed answers: '.$listed($unmet).'.',
        };

        return ['applies_to_case' => $card->appliesToCase, 'facts' => $rows, 'missing_facts' => $missing, 'explanation' => $explanation];
    }
}
