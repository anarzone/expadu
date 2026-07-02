<?php

namespace App\ContextEngine\Listeners;

use App\Alerts\AlertClassifier;
use App\ContextEngine\ContextNotificationFactory;
use App\ContextEngine\ScoredAction;
use App\Events\Context\ScoredActionInserted;
use App\Models\Alert;

/**
 * Materialises the in-app Alert from the `alert_page` delivery channel —
 * independently of whether the action also pushed.
 *
 * This is the fix for the alert center being a side-effect of push: an action
 * that's alert_page-eligible but had its push stripped (muted / throttled /
 * preference off) or never pushed at all (Rhine, minor delays) now still lands
 * in the center, tagged with its lane / category / severity for the v4 screen.
 * The ContextEngine notification classes are skipped by CreateAlertFromNotification
 * so this is the single creator for them — no duplicates.
 */
class RecordContextAlert
{
    public function __construct(private ContextNotificationFactory $notifications) {}

    public function handle(ScoredActionInserted $event): void
    {
        $action = $event->action;
        $user = $event->user;

        if (! in_array(ScoredAction::CHANNEL_ALERT_PAGE, $action->deliverChannels, true)) {
            return;
        }

        // Reuse the push notification's formatting so the card text and the
        // push text never drift; null = type with no notification / empty payload.
        $notification = $this->notifications->build($action);
        if ($notification === null) {
            return;
        }

        $data = $notification->toArray($user);
        $title = (string) ($data['title'] ?? '');
        if ($title === '') {
            return;
        }

        $subtype = AlertClassifier::subtypeForActionType($action->type);
        $severity = AlertClassifier::severity($action->severity);

        // Coalesce recurrences of the same subject onto ONE live card instead of
        // a new row every poll: weather/rhine/market collapse to one evolving
        // card per kind, transit/bureaucracy keep their per-line/per-task grain.
        // Alert::record refreshes the text + severity, bumps it to the top, and
        // only re-marks it unread when the situation has escalated.
        Alert::record(
            $user->id,
            AlertClassifier::groupKey($action->type, $action->actionKey),
            [
                'type' => AlertClassifier::alertType($subtype),
                'subtype' => $subtype,
                'severity' => $severity,
                'category' => AlertClassifier::category($subtype),
                'lane' => AlertClassifier::lane($subtype, $severity),
                'title' => $title,
                'body' => (string) ($data['body'] ?? $data['summary'] ?? ''),
                'deep_link' => $data['url'] ?? null,
            ],
        );
    }
}
