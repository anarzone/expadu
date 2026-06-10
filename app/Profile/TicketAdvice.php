<?php

namespace App\Profile;

/**
 * Which transit subscription this user should be on. Per-journey advice
 * (EinzelTicket price for one trip) is computed at take-me-there time
 * against this baseline.
 */
enum TicketAdvice: string
{
    case DeutschlandTicket = 'deutschland_ticket';
    case SemesterTicket = 'semester_ticket';
    case JobTicketAsk = 'job_ticket_ask';
    case EinzelTicket = 'einzel_ticket';

    public function label(): string
    {
        return match ($this) {
            self::DeutschlandTicket => 'Deutschlandticket',
            self::SemesterTicket => 'Semesterticket',
            self::JobTicketAsk => 'Deutschlandticket (ask your employer)',
            self::EinzelTicket => 'Single tickets',
        };
    }

    public function reason(): string
    {
        return match ($this) {
            self::DeutschlandTicket => 'Flat €58/month for all local transit in Germany — almost always worth it if you ride more than twice a week.',
            self::SemesterTicket => 'Your enrolment usually includes an NRW-wide Semesterticket — check before buying anything.',
            self::JobTicketAsk => 'Many employers subsidise the Deutschlandticket as a JobTicket — ask HR before paying the full €58.',
            self::EinzelTicket => 'If you ride rarely, single tickets beat a subscription.',
        };
    }
}
