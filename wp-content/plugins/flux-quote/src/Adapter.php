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
     * Subject and body are edited in Flux → Settings; the HTML layout can be overridden by the theme.
     * Define FLUX_QUOTE_MAIL_BCC to also receive a copy of every quotation.
     *
     * @return array{
     *     to: string|string[],
     *     subject: string,
     *     message: string,
     *     headers?: string[],
     *     attachment_name?: string
     * }|null
     */
    public function quotation_mail(Quotation $quotation): ?array
    {
        $to = (string) ($quotation->contact['email'] ?? '');
        if (!is_email($to)) {
            return null;
        }

        $template = new MailTemplate();
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (defined('FLUX_QUOTE_MAIL_BCC')) {
            $headers[] = 'Bcc: ' . FLUX_QUOTE_MAIL_BCC;
        }

        return [
            'to' => $to,
            'subject' => $template->subject($quotation),
            'message' => $template->message($quotation),
            'headers' => $headers,
            'attachment_name' => sanitize_file_name(
                sprintf(
                    'Quote-%s-%s.pdf',
                    $quotation->contact['lastName'] ?? '',
                    mysql2date('Y-m-d', $quotation->created_at)
                )
            ),
        ];
    }

    public function before_save(WP_REST_Request $request): ?WP_Error
    {
        return null;
    }
}
