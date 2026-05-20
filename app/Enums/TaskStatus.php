<?php

namespace App\Enums;

/**
 * User-facing progress of an individual bureaucracy task in the framing-B
 * workflow. The lifecycle is intentionally short — four discrete steps that
 * map to every situation (Anmeldung, Steuer-ID, Visa, Tax declaration, etc.):
 *
 *   not_started  → user hasn't touched it yet
 *   in_progress  → started — collecting docs, booking, drafting, etc.
 *   submitted    → handed off — waiting on a Bürgeramt/Finanzamt/insurer decision
 *   done         → completed (or this recurrence's instance closed)
 *
 * "Not applicable" lives on a separate is_applicable boolean column, not in
 * this enum, so the user can opt out without losing the status of past work.
 */
enum TaskStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Done = 'done';

    /**
     * Human-readable label for the dashboard chip.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::Submitted => 'Submitted',
            self::Done => 'Done',
        };
    }

    /**
     * Tailwind colour scheme key. Used by the React side via the prop payload.
     */
    public function tone(): string
    {
        return match ($this) {
            self::NotStarted => 'neutral',
            self::InProgress => 'info',
            self::Submitted => 'warn',
            self::Done => 'success',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Done;
    }
}
