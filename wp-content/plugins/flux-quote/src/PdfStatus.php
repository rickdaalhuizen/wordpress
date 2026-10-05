<?php

/**
 * Outcome of sending a quotation to the PDF service.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

enum PdfStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
}
