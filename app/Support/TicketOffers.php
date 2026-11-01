<?php

namespace App\Support;

/**
 * koeln.de lists ticket products beside events (owner, 1 Nov 2026): "VIP Ticket – …",
 * "Ticket + T-Shirt Bundle – …", gift tickets and vouchers, an exhibition's time-slot
 * tickets. They are offers for something listed elsewhere, never an event of their own.
 * Its "Hot Tickets – " label is marketing in front of a real event's name.
 */
final class TicketOffers
{
    private const PRODUCT = '/^(VIP[\s-]*Tickets?\b|Tickets?\s*\+)|Geschenk-?(ticket|gutschein)|Gutschein|Zeitfenstertickets?/iu';

    private const LABEL = '/^Hot\s+Tickets?\s*[–—-]\s*/iu';

    public static function isProduct(string $title): bool
    {
        return preg_match(self::PRODUCT, trim($title)) === 1;
    }

    /** The event's own name, without koeln.de's "Hot Tickets – " label. */
    public static function title(string $title): string
    {
        return trim((string) preg_replace(self::LABEL, '', trim($title)));
    }
}
