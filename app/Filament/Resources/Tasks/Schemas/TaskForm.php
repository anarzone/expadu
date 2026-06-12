<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\DeadlineType;
use App\Enums\Urgency;
use App\Services\BuergeramtService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Admin hotfix surface for the bureaucracy catalogue. The YAML files stay
 * the source of truth (bureaucracy:import-tasks overwrites by key) — this
 * form exists so wrong facts can be fixed NOW without a deploy.
 */
class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        $branches = [
            'core', 'eu_employee',
            'non_eu_employee', 'non_eu_employee_blue_card', 'non_eu_employee_chancenkarte',
            'student',
            'freelancer', 'freelancer_gewerbe',
            'family_reunification', 'family_reunification_of_german', 'family_reunification_of_eu_citizen',
        ];

        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                TextInput::make('key')
                    ->helperText('Stable catalogue slug — re-imports match on this. Leave empty only for ad-hoc tasks.'),
                Select::make('type')
                    ->options(['task' => 'Task (actionable)', 'info' => 'Info card (good to know)'])
                    ->default('task')
                    ->required(),
                Select::make('situation')
                    ->multiple()
                    ->options(array_combine($branches, $branches))
                    ->required()
                    ->helperText('Branches this task appears in (incl. refined sub-paths).'),
                Select::make('eu_filter')
                    ->options(['eu_only' => 'EU citizens only', 'non_eu_only' => 'Non-EU citizens only'])
                    ->placeholder('Everyone')
                    ->nullable(),
                Textarea::make('description')
                    ->rows(5)
                    ->columnSpanFull(),
                Select::make('phase')
                    ->options([
                        'arrival' => 'Arrival',
                        'first_30_days' => 'First 30 days',
                        'settling' => 'Settling in',
                        'ongoing' => 'Ongoing',
                    ]),
                Select::make('urgency')
                    ->options(Urgency::class)
                    ->default('medium')
                    ->required(),
                Select::make('deadline_type')
                    ->options(DeadlineType::class)
                    ->default('none')
                    ->required(),
                TextInput::make('deadline_days')
                    ->numeric()
                    ->helperText('Days since arrival (with deadline type "days since arrival").'),
                TextInput::make('recurrence_months')
                    ->numeric()
                    ->helperText('Recurring tasks (e.g. 12 = yearly tax return). Empty = one-off.'),
                TagsInput::make('depends_on')
                    ->helperText('Task keys that must be done first — the card shows as blocked until then.')
                    ->placeholder('e.g. core.anmeldung'),
                Select::make('booking_service_key')
                    ->options(
                        collect(BuergeramtService::SERVICES)
                            ->map(fn (array $s) => $s['name_en'] ?? $s['name'])
                            ->all(),
                    )
                    ->placeholder('No booking deep-link')
                    ->nullable(),
                TagsInput::make('links')
                    ->helperText('Official source URLs — shown as link buttons on the card.')
                    ->columnSpanFull(),
                Repeater::make('documents_required')
                    ->schema([
                        TextInput::make('label')->required(),
                        Textarea::make('note')
                            ->rows(2)
                            ->helperText('Optional context shown under the document.'),
                        Select::make('tone')
                            ->options(['warn' => 'Red trap chip'])
                            ->placeholder('Normal note')
                            ->nullable(),
                    ])
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    // Legacy entries are plain strings — normalise to objects.
                    ->formatStateUsing(fn (?array $state) => collect($state ?? [])
                        ->map(fn ($doc) => is_string($doc)
                            ? ['label' => $doc, 'note' => null, 'tone' => null]
                            : $doc)
                        ->all())
                    ->columnSpanFull(),
                Repeater::make('how_to_steps')
                    ->schema([
                        TextInput::make('title')->required(),
                        Textarea::make('body')->rows(2)->required(),
                        TextInput::make('link')->url(),
                    ])
                    ->columnSpanFull(),
                DatePicker::make('verified_at')
                    ->helperText('ONLY set after personally checking the official source. Cleared automatically after 3 outdated reports.'),
                Toggle::make('is_published')
                    ->default(true)
                    ->helperText('Unpublished tasks are hidden from users — wrong info is worse than missing info.'),
            ]);
    }
}
