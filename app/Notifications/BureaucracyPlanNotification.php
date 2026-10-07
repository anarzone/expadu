<?php

namespace App\Notifications;

use App\Bureaucracy\Reminders\BureaucracyWebPushMessage;
use App\Bureaucracy\Reminders\PlanReminderDelivery;
use App\Bureaucracy\Reminders\PlanReminderReference;
use App\Models\User;
use App\Services\MuteService;
use App\Support\NotificationThrottle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class BureaucracyPlanNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public array $reference) {}

    public function shouldSend(mixed $notifiable, string $channel): bool
    {
        $user = $notifiable instanceof User ? User::query()->find($notifiable->id) : null;
        $row = $user === null ? null : app(PlanReminderReference::class)->resolve($this->reference, $user->id);
        $allowed = $row !== null && config('context_engine.push_via_bus') && $user->wantsNotification('checklist')
            && ! app(MuteService::class)->isMuted($user, 'bureaucracy_task', app(PlanReminderReference::class)->muteKey($user, $row))
            && NotificationThrottle::canPush($user, 'bureaucracy_task')
            && app(PlanReminderDelivery::class)->claimForSend($this->reference);
        if (! $allowed) {
            app(PlanReminderDelivery::class)->release($this->reference);
        }

        return $allowed;
    }

    public function via(mixed $notifiable): array
    {
        // The alert centre is persisted independently; avoid a second plaintext notification store.
        return [WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        // Lock-screen text remains discreet. Exact current timing is available after authentication.
        return (new BureaucracyWebPushMessage($this->reference))->title('Your paperwork needs attention')
            ->body('Open Bureaucracy to review your current next step.')
            ->action('Open Bureaucracy', 'view_bureaucracy')->options(['TTL' => 900])->data(['url' => '/bureaucracy']);
    }

    public function toArray(mixed $notifiable): array
    {
        return ['type' => 'bureaucracy_deadline', 'title' => 'Your paperwork needs attention',
            'body' => 'Open Bureaucracy to review your current next step.', 'url' => '/bureaucracy'];
    }
}
