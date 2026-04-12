<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\GermanLevel;
use App\Enums\Situation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                DateTimePicker::make('email_verified_at'),
                TextInput::make('password')
                    ->password()
                    ->required(),
                Textarea::make('two_factor_secret')
                    ->columnSpanFull(),
                Textarea::make('two_factor_recovery_codes')
                    ->columnSpanFull(),
                DateTimePicker::make('two_factor_confirmed_at'),
                TextInput::make('city'),
                Select::make('situation')
                    ->options(Situation::class),
                DatePicker::make('arrival_date'),
                Select::make('german_level')
                    ->options(GermanLevel::class),
                TextInput::make('speaks'),
                DateTimePicker::make('onboarded_at'),
                TextInput::make('avatar_path'),
            ]);
    }
}
