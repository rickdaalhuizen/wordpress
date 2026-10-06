<?php

/**
 * Outcome of mailing a quotation to its contact.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

enum MailStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
