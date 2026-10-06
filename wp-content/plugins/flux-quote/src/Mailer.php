<?php

/**
 * Sends a quotation mail with its PDF attached.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

use WP_Error;

final class Mailer
{
    /**
     * Returns null when the mail was handed to the mail server, the failure reason otherwise.
     *
     * @param array{to: string|string[], subject: string, message: string, headers?: string[]} $mail
     */
    public function send(array $mail, Quotation $quotation): ?string
    {
        $attachment = $this->pdf_path($quotation->pdf_url);
        if (null === $attachment) {
            return $this->fail($quotation, 'PDF file not found');
        }

        // wp_mail() only returns false; the actual reason comes through this action.
        $error = null;
        $capture = static function (WP_Error $wp_error) use (&$error): void {
            $error = $wp_error->get_error_message();
        };

        add_action('wp_mail_failed', $capture);
        $sent = wp_mail($mail['to'], $mail['subject'], $mail['message'], $mail['headers'] ?? [], [$attachment]);
        remove_action('wp_mail_failed', $capture);

        return $sent ? null : $this->fail($quotation, $error ?? 'wp_mail returned false');
    }

    /**
     * Maps the stored PDF URL back to its file in uploads.
     * Scheme and host are ignored so an http/https mismatch still resolves.
     */
    private function pdf_path(?string $pdf_url): ?string
    {
        if (!$pdf_url) {
            return null;
        }

        $uploads = wp_get_upload_dir();
        $base = set_url_scheme($uploads['baseurl'], 'relative');
        $relative = set_url_scheme($pdf_url, 'relative');
        if (!str_starts_with($relative, $base . '/')) {
            return null;
        }

        $path = $uploads['basedir'] . substr($relative, strlen($base));

        return is_readable($path) ? $path : null;
    }

    private function fail(Quotation $quotation, string $reason): string
    {
        error_log(sprintf('[flux-quote] Mail for quotation %s failed, %s', $quotation->public_id, $reason));

        return $reason;
    }
}
