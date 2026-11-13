<?php

namespace App\Filament\Resources\SecurityEvents;

use App\Filament\Resources\SecurityEvents\Pages\ListSecurityEvents;
use App\Filament\Resources\SecurityEvents\Tables\SecurityEventsTable;
use App\Models\SecurityEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The security log (the owner's pick B, as the log view of Sign-ups &
 * security): read-only, 90 days, every IP one click from what it did.
 */
class SecurityEventResource extends Resource
{
    protected static ?string $model = SecurityEvent::class;

    protected static ?string $slug = 'security-log';

    protected static ?string $modelLabel = 'security log entry';

    protected static ?string $pluralModelLabel = 'Security log';

    protected static ?string $navigationLabel = 'Security log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationParentItem = 'Sign-ups & security';

    protected static ?int $navigationSort = 91;

    public static function table(Table $table): Table
    {
        return SecurityEventsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListSecurityEvents::route('/')];
    }
}
