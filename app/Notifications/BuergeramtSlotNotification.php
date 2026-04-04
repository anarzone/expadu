<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class BuergeramtSlotNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, array{name: string, address: string, status: string, next_slot: ?string, slots_today: int}>  $availableSlots
     */
    public function __construct(
        public array $availableSlots,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        $officeNames = collect($this->availableSlots)
            ->pluck('name')
            ->implode(', ');

        $slotCount = collect($this->availableSlots)->sum('slots_today');

        return (new WebPushMessage)
            ->title('Buergeramt Appointment Available!')
            ->icon('/icons/buergeramt.png')
            ->body("{$slotCount} slot(s) available at: {$officeNames}")
            ->action('Book now', 'view_bureaucracy')
            ->options(['TTL' => 1800])
            ->data(['url' => '/bureaucracy']);
    }

    /**
     * @return array{type: string, available_slots: array<string, mixed>, slot_count: int, url: string}
     */
    public function toArray(mixed $notifiable): array
    {
        $slotCount = collect($this->availableSlots)->sum('slots_today');
        $officeNames = collect($this->availableSlots)->pluck('name')->filter()->implode(', ');

        return [
            'type' => 'buergeramt_slot',
            'title' => 'Bürgeramt appointment available!',
            'body' => "{$slotCount} slot(s) at {$officeNames}. Book before they're taken.",
            'available_slots' => $this->availableSlots,
            'slot_count' => $slotCount,
            'url' => '/bureaucracy',
        ];
    }
}
