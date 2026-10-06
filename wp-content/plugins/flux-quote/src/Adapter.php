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

    /**
     * The mail sent to the contact with the PDF attached, or null to send none.
     * Define FLUX_QUOTE_MAIL_BCC to also receive a copy of every quotation.
     *
     * @return array{to: string|string[], subject: string, message: string, headers?: string[]}|null
     */
    public function quotation_mail(Quotation $quotation): ?array
    {
        $to = (string) ($quotation->contact['email'] ?? '');
        if (!is_email($to)) {
            return null;
        }

        $site = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);

        return [
            'to' => $to,
            'subject' => sprintf(__('[%s] Your quotation', 'flux-quote'), $site),
            'message' => sprintf(
                __(
                    "Hello %1\$s,\n\nThank you for your request. Please find your quotation attached.\n\nBest regards,\n%2\$s",
                    'flux-quote'
                ),
                $quotation->contact['firstName'] ?? '',
                $site
            ),
            'headers' => defined('FLUX_QUOTE_MAIL_BCC') ? ['Bcc: ' . FLUX_QUOTE_MAIL_BCC] : [],
        ];
    }

    public function before_save(WP_REST_Request $request): ?WP_Error
    {
        return null;
    }
}
