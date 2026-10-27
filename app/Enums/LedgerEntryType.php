<?php

namespace App\Enums;

/**
 * Append-only ledger entry kinds, recorded from the organiser's point of view.
 * Convention: amount_minor is signed. Positive values increase what Tickora
 * owes the organiser; negative values decrease it. An organiser's balance in
 * a currency is the sum of its entries in that currency.
 */
enum LedgerEntryType: string
{
    /** Gross ticket revenue for a paid order (positive). */
    case TicketSale = 'ticket_sale';

    /** Platform commission on that sale (negative). */
    case PlatformFee = 'platform_fee';

    /** Money returned to the customer (negative). */
    case Refund = 'refund';

    /** Commission returned to the organiser on a refund, where policy allows (positive). */
    case PlatformFeeReversal = 'platform_fee_reversal';

    /** Chargeback lost to the customer's bank (negative). */
    case Chargeback = 'chargeback';

    /** Money transferred to the organiser (negative). */
    case Payout = 'payout';

    /** Audited manual correction (either sign). */
    case Adjustment = 'adjustment';
}
