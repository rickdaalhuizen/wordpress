<?php

/**
 * Lets a project change how Flux Quote behaves, without editing this plugin.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

use WP_Error;
use WP_REST_Request;

class Adapter
{
    public function rest_namespace(): string
    {
        return 'flux-quote/v1';
    }

    public function pdf_payload(Quotation $quotation): array
    {
        return $quotation->document;
    }

    public function before_save(WP_REST_Request $request): ?WP_Error
    {
        return null;
    }
}
