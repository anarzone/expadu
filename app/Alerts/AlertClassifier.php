<?php

namespace App\Alerts;

use App\Enums\AlertType;

/**
 * The single source of truth for the v4 Alerts taxonomy. Given an alert
 * `subtype` (and, for the border, the ContextEngine severity), it derives the
 * dimensions the AlertsScreen design needs: the urgency lane, the category
 * filter bucket, the severity tone, a source caption, and the call-to-action
 * label. Pure + deterministic — mirrors RailCopy, no LLM, no DB.
 *
 * The mappings are transcribed from AlertsScreen.dc.html's worked examples
 * (Anmeldung overdue = bureau/action/danger, Line 7 = transit/action/warn,
 * Heat advisory = city/posted/warn, Steuer-ID arrived = bureau/good/success).
 */
class AlertClassifier
{
    /** Category filter bucket per subtype (Transit · Bureaucracy · Events · City). */
    public static function category(?string $subtype): string
    {
        return match ($subtype) {
            'transit_disruption', 'transit_delay' => 'transit',
            'bureaucracy_deadline', 'buergeramt_slot' => 'bureau',
            'event_reminder' => 'events',
            'weather', 'rhine', 'market_closure' => 'city',
            default => 'city',
        };
    }

    /**
     * Urgency lane. A positive/"success" alert is good news; the actionable
     * subtypes need the user; everything else is keeping-you-posted.
     */
    public static function lane(?string $subtype, string $severity): string
    {
        if ($severity === 'success') {
            return 'good';
        }

        return match ($subtype) {
            'bureaucracy_deadline', 'buergeramt_slot', 'transit_disruption', 'transit_delay' => 'action',
            default => 'posted',
        };
    }

    /** ContextEngine severity → card tone (drives the left border + icon tint). */
    public static function severity(string $scoredSeverity): string
    {
        return match ($scoredSeverity) {
            'critical' => 'danger',
            'major', 'moderate' => 'warn',
            'success' => 'success',
            default => 'info',
        };
    }

    /** The mono source caption under each card. */
    public static function source(?string $subtype): string
    {
        return match ($subtype) {
            'transit_disruption', 'transit_delay' => 'KVB live',
            'bureaucracy_deadline' => 'City of Cologne',
            'buergeramt_slot' => 'Termin tracker',
            'weather' => 'City notices',
            'rhine' => 'Pegel Köln',
            'market_closure' => 'City notices',
            'event_reminder' => 'Your reminders',
            default => 'Expadu',
        };
    }

    /** The action-button label, or null when the alert is purely informational. */
    public static function actionLabel(?string $subtype): ?string
    {
        return match ($subtype) {
            'transit_disruption', 'transit_delay' => 'See alternatives',
            'bureaucracy_deadline' => 'Open checklist',
            'buergeramt_slot' => 'Book a slot',
            default => null,
        };
    }

    /** Coarse system/social/reminder bucket, kept for back-compat counters. */
    public static function alertType(?string $subtype): AlertType
    {
        return match ($subtype) {
            'bureaucracy_deadline', 'event_reminder', 'market_closure' => AlertType::Reminder,
            default => AlertType::System,
        };
    }

    /** ScoredAction `type` → the alert `subtype` string we persist. */
    public static function subtypeForActionType(string $actionType): string
    {
        return match ($actionType) {
            'transit_disruption' => 'transit_disruption',
            'transit_delay' => 'transit_delay',
            'weather_alert' => 'weather',
            'rhine_level' => 'rhine',
            'bureaucracy_task' => 'bureaucracy_deadline',
            'market_closure' => 'market_closure',
            default => 'generic',
        };
    }
}
