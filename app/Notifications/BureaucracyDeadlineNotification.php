<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class BureaucracyDeadlineNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $taskTitle,
        public string $tier,
        public int $daysRemaining,
        public string $deadline,
        public ?int $taskId = null,
    ) {}

    /**
     * Deep-link straight to the task card (the page auto-expands + scrolls).
     */
    private function url(): string
    {
        return $this->taskId ? "/bureaucracy?focus={$this->taskId}" : '/bureaucracy';
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title())
            ->icon('/icons/buergeramt.png')
            ->body($this->body())
            ->action('Open checklist', 'view_bureaucracy')
            ->options(['TTL' => 3600])
            ->data(['url' => $this->url()]);
    }

    /**
     * @return array{type: string, title: string, body: string, tier: string, days_remaining: int, deadline: string, url: string}
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'bureaucracy_deadline',
            'title' => $this->title(),
            'body' => $this->body(),
            'tier' => $this->tier,
            'days_remaining' => $this->daysRemaining,
            'deadline' => $this->deadline,
            'url' => $this->url(),
        ];
    }

    private function title(): string
    {
        return match ($this->tier) {
            'overdue' => 'Overdue: '.$this->taskTitle,
            'critical' => 'Deadline approaching: '.$this->taskTitle,
            default => 'Reminder: '.$this->taskTitle,
        };
    }

    private function body(): string
    {
        if ($this->tier === 'overdue') {
            $days = abs($this->daysRemaining);

            return $days === 0
                ? 'The deadline is today. Open the checklist to see what to bring.'
                : "The deadline passed {$days} day(s) ago. It's usually fixable — open the checklist for next steps.";
        }

        return $this->daysRemaining === 0
            ? "Due today ({$this->deadline})."
            : "Due in {$this->daysRemaining} day(s), on {$this->deadline}.";
    }
}
