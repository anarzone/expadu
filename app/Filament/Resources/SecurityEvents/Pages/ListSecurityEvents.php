<?php

namespace App\Filament\Resources\SecurityEvents\Pages;

use App\Filament\Pages\SignupsSecurity;
use App\Filament\Resources\SecurityEvents\SecurityEventResource;
use App\Models\SecurityEvent;
use App\Security\SecurityLog;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListSecurityEvents extends ListRecords
{
    protected static string $resource = SecurityEventResource::class;

    protected static ?string $title = 'Security log';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected ?string $subheading = 'Kept 90 days. Click an IP to see everything it did.';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** Tab => the log types it shows. */
    public const Tabs = [
        'signups' => ['Sign-ups', [SecurityLog::Signup]],
        'logins' => ['Sign-ins', [SecurityLog::Login]],
        'failed' => ['Failed', [SecurityLog::LoginFailed, SecurityLog::TwoFactorFailed]],
        'blocked' => ['Turned away', [SecurityLog::Blocked]],
        'throttled' => ['Refused', [SecurityLog::Throttled]],
        'account' => ['Resets & verifications', [SecurityLog::ResetRequested, SecurityLog::PasswordReset, SecurityLog::EmailVerified]],
        'mail' => ['Mail held', [SecurityLog::MailHeld]],
    ];

    public function getTabs(): array
    {
        $counts = SecurityEvent::query()->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');

        $tabs = ['all' => Tab::make('All')->badge(number_format((int) $counts->sum()))];
        foreach (self::Tabs as $key => [$label, $types]) {
            $tabs[$key] = Tab::make($label)
                ->badge(number_format((int) collect($types)->sum(fn (string $t) => $counts[$t] ?? 0)))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('type', $types));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('board')
                ->label('Sign-ups & security')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->url(SignupsSecurity::getUrl()),
        ];
    }
}
