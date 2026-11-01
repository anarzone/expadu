<?php

namespace App\Bureaucracy\Verification;

use App\Models\BureaucracyEscalation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only route from the paperwork system to a person. Everything is recorded;
 * only high-severity items alert, once per opening, through the error reporter.
 * Subjects and details never contain personal data.
 */
class Escalations
{
    public const High = 'high';

    public const Normal = 'normal';

    public function raise(string $kind, string $subject, string $severity, string $summary, array $details = []): BureaucracyEscalation
    {
        $alert = false;
        $row = DB::transaction(function () use ($kind, $subject, $severity, $summary, $details, &$alert): BureaucracyEscalation {
            $now = now()->utc();
            $row = BureaucracyEscalation::query()->where('kind', $kind)->where('subject', $subject)->lockForUpdate()->first();
            if ($row === null) {
                $row = BureaucracyEscalation::query()->create(['kind' => $kind, 'subject' => $subject, 'severity' => $severity,
                    'summary' => $summary, 'details' => $details, 'occurrences' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now]);
                $alert = $severity === self::High;
            } else {
                $reopened = $row->resolved_at !== null;
                $raised = $severity === self::High && $row->severity !== self::High;
                $row->update(['severity' => $severity === self::High ? self::High : $row->severity, 'summary' => $summary, 'details' => $details,
                    'occurrences' => $row->occurrences + 1, 'last_seen_at' => $now, 'resolved_at' => null,
                    'first_seen_at' => $reopened ? $now : $row->first_seen_at]);
                $alert = $severity === self::High && ($reopened || $raised || $row->alerted_at === null);
            }
            if ($alert) {
                $row->update(['alerted_at' => $now]);
            }

            return $row;
        });
        if ($alert) {
            DB::afterCommit(function () use ($row): void {
                Log::critical('bureaucracy.escalation', ['kind' => $row->kind, 'subject' => $row->subject, 'summary' => $row->summary]);
                report(new EscalationRaised($row->kind, $row->subject, $row->summary));
            });
        }

        return $row;
    }

    public function resolve(string $kind, string $subject): void
    {
        BureaucracyEscalation::query()->where('kind', $kind)->where('subject', $subject)->whereNull('resolved_at')
            ->update(['resolved_at' => now()->utc(), 'updated_at' => now()->utc()]);
    }
}
