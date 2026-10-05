<?php

/**
 * Renders a quotation with the external PDF service and stores the PDF in uploads.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

final class PdfClient
{
    private const DEFAULT_URL = 'https://pdf-generator.3dconfig.io/api/template-pdf';
    private const DEFAULT_TEMPLATE = 'invoice';

    private const TIMEOUT_SECONDS = 3;

    public function send(array $data, Quotation $quotation): ?string
    {
        $response = wp_remote_post(
            $this->setting('FLUX_QUOTE_PDF_URL', self::DEFAULT_URL),
            [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode(
                    [
                        'appID' => $this->setting('FLUX_QUOTE_PDF_APP_ID', 'flux-quote'),
                        'templateId' => $this->setting('FLUX_QUOTE_PDF_TEMPLATE', self::DEFAULT_TEMPLATE),
                        'data' => $data,
                    ]
                ),
            ]
        );

        if (is_wp_error($response)) {
            return $this->fail($quotation, 'unreachable: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return $this->fail($quotation, sprintf('HTTP %d: %s', $status, wp_remote_retrieve_body($response)));
        }

        $pdf = wp_remote_retrieve_body($response);
        if (!str_starts_with($pdf, '%PDF')) {
            return $this->fail($quotation, 'response is not a PDF');
        }

        $upload = wp_upload_bits($quotation->private_id . '.pdf', null, $pdf);
        if ($upload['error']) {
            return $this->fail($quotation, 'could not store PDF: ' . $upload['error']);
        }

        return $upload['url'];
    }

    private function fail(Quotation $quotation, string $reason): null
    {
        error_log(sprintf('[flux-quote] PDF for quotation %s failed, %s', $quotation->public_id, $reason));

        return null;
    }

    private function setting(string $constant, string $fallback): string
    {
        return defined($constant) ? (string) constant($constant) : $fallback;
    }
}
