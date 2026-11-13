<?php

namespace App\Filament\Clusters\People\Pages;

use App\Admin\PeopleActions;
use App\Admin\ReviewQueue;
use App\Filament\Clusters\People\PeopleCluster;
use App\Models\User;
use App\Models\WaitlistSignup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\On;

/**
 * The review queue (the owner's B, as People's first tab): what the checks
 * flagged, one card each, with Keep, Suspend and Delete or Remove on it.
 * Keep and Suspend can be undone from their notice; deleting asks first.
 */
class NeedsALook extends Page
{
    protected static ?string $cluster = PeopleCluster::class;

    protected static ?string $title = 'Needs a look';

    protected static ?string $slug = 'needs-a-look';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.people.needs-a-look';

    public static function getNavigationBadge(): ?string
    {
        $count = app(ReviewQueue::class)->count();

        return $count > 0 ? (string) $count : null;
    }

    public function getSubheading(): string
    {
        return 'Accounts and waitlist entries the checks flagged. Keep what’s real; the rest goes. Everyone else is under Users and Waitlist.';
    }

    protected function getViewData(): array
    {
        return ['items' => app(ReviewQueue::class)->items()];
    }

    public function keepAction(): Action
    {
        return Action::make('keep')->label('Keep')->icon(Heroicon::OutlinedCheck)->color('gray')->size('sm')->outlined()
            ->action(function (array $arguments, PeopleActions $people): void {
                $people->keep($this->subject((string) $arguments['key']));
                $this->undoable('Kept. It won’t show here again.', 'undoKeep', (string) $arguments['key']);
            });
    }

    public function suspendAction(): Action
    {
        return Action::make('suspend')->label('Suspend')->icon(Heroicon::OutlinedNoSymbol)->color('gray')->size('sm')->outlined()
            ->action(function (array $arguments, PeopleActions $people): void {
                $user = $this->subject((string) $arguments['key']);
                if ($user instanceof User && $people->suspend($user)) {
                    $this->undoable("{$user->name} is suspended and signed out everywhere.", 'undoSuspend', (string) $arguments['key']);
                }
            });
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(fn (array $arguments): string => str_starts_with((string) ($arguments['key'] ?? ''), 'waitlist:') ? 'Remove' : 'Delete')
            ->icon(Heroicon::OutlinedTrash)->color('danger')->size('sm')->outlined()
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => str_starts_with((string) ($arguments['key'] ?? ''), 'waitlist:') ? 'Remove from the waitlist?' : 'Delete this account?')
            ->modalDescription(fn (array $arguments): string => str_starts_with((string) ($arguments['key'] ?? ''), 'waitlist:')
                ? 'The entry is deleted. This can’t be undone.'
                : 'The account and everything in it are deleted. This can’t be undone.')
            ->modalSubmitActionLabel(fn (array $arguments): string => str_starts_with((string) ($arguments['key'] ?? ''), 'waitlist:') ? 'Remove' : 'Delete for good')
            ->action(function (array $arguments, PeopleActions $people): void {
                $subject = $this->subject((string) $arguments['key']);
                if ($subject instanceof WaitlistSignup) {
                    $people->removeFromWaitlist($subject);
                    Notification::make()->title('Removed from the waitlist.')->success()->send();
                } elseif ($subject instanceof User && $people->deleteAccount($subject)) {
                    Notification::make()->title('Account deleted.')->success()->send();
                }
            });
    }

    #[On('undoKeep')]
    public function undoKeep(string $key, PeopleActions $people): void
    {
        $people->unkeep($this->subject($key));
    }

    #[On('undoSuspend')]
    public function undoSuspend(string $key, PeopleActions $people): void
    {
        $user = $this->subject($key);
        if ($user instanceof User) {
            $people->unsuspend($user);
        }
    }

    private function undoable(string $title, string $event, string $key): void
    {
        Notification::make()->title($title)->success()
            ->actions([Action::make('undo')->label('Undo')->link()->dispatch($event, ['key' => $key])->close()])
            ->send();
    }

    private function subject(string $key): User|WaitlistSignup
    {
        [$kind, $id] = explode(':', $key, 2) + [null, null];

        return $kind === 'waitlist'
            ? WaitlistSignup::query()->findOrFail((int) $id)
            : User::query()->findOrFail((int) $id);
    }
}
