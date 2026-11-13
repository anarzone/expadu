<?php

namespace App\Filament\Resources\Users\Pages;

use App\Admin\PeopleActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // As a person deleting their own account would (DeleteAccount), not a bare row delete.
            Action::make('deleteAccount')
                ->label('Delete account')
                ->color('danger')
                ->visible(fn (User $record): bool => ! $record->is_admin)
                ->requiresConfirmation()
                ->modalHeading('Delete this account?')
                ->modalDescription('Everything in it goes: profile, Paperwork, Composer, places, trips. This can’t be undone.')
                ->modalSubmitActionLabel('Delete for good')
                ->action(function (User $record, PeopleActions $people): void {
                    $people->deleteAccount($record);
                    Notification::make()->title('Account deleted.')->success()->send();
                    $this->redirect(UserResource::getUrl());
                }),
        ];
    }
}
