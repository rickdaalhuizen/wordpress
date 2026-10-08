<?php

declare(strict_types=1);

namespace Flux\Quote;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft', 'flux-quote'),
            self::Sent => __('Sent', 'flux-quote'),
            self::Accepted => __('Accepted', 'flux-quote'),
            self::Expired => __('Expired', 'flux-quote'),
        };
    }
}
