<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\GermanLevel;
use App\Enums\Situation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('password')
                            ->password()
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->maxLength(255),
                        Toggle::make('is_admin')
                            ->label('Admin access')
                            ->helperText('Can access the admin panel'),
                    ]),
                Section::make('Profile')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('city')
                            ->maxLength(255),
                        Select::make('situation')
                            ->options(Situation::class),
                        DatePicker::make('arrival_date'),
                        Select::make('german_level')
                            ->options(GermanLevel::class),
                    ]),
            ]);
    }
}
