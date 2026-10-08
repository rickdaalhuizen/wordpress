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

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending', 'flux-quote'),
            self::Sent => __('Sent', 'flux-quote'),
            self::Failed => __('Failed', 'flux-quote'),
            self::Skipped => __('Not sent', 'flux-quote'),
        };
    }
}
