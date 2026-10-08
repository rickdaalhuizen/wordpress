<?php

/**
 * Renders a quotation with the external PDF service and stores the PDF in uploads.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

use WP_Error;

final class PdfClient
{
    private const DEFAULT_URL = 'https://pdf-generator.3dconfig.io/api/template-pdf';
    private const DEFAULT_TEMPLATE = 'invoice';

    private const TIMEOUT_SECONDS = 3;

    private array $diagnostics = [];

    public function __construct(private PdfTemplate $template)
    {
    }

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function send(array $data, Quotation $quotation): string|WP_Error
    {
        $pdf = $this->render($data);
        if (is_wp_error($pdf)) {
            return $this->fail($quotation, $pdf->get_error_message());
        }

        $upload = wp_upload_bits($quotation->private_id . '.pdf', null, $pdf);
        if ($upload['error']) {
            return $this->fail($quotation, 'could not store PDF: ' . $upload['error']);
        }
        $this->diagnostics['file'] = $upload['file'];

        return $upload['url'];
    }

    public function render(array $data): string|WP_Error
    {
        $body = [
            'appID' => $this->setting('FLUX_QUOTE_PDF_APP_ID', 'flux-quote'),
            'templateId' => $this->setting('FLUX_QUOTE_PDF_TEMPLATE', self::DEFAULT_TEMPLATE),
            'data' => $this->template->apply($data),
        ];
        $theme = $this->template->theme();
        if ('' !== $theme) {
            $body['theme'] = $theme;
        }

        $endpoint = $this->setting('FLUX_QUOTE_PDF_URL', self::DEFAULT_URL);
        $this->diagnostics = [
            'endpoint' => $endpoint,
            'template_id' => $body['templateId'],
            'app_id' => $body['appID'],
            'timeout_s' => self::TIMEOUT_SECONDS,
        ];
        $started = microtime(true);
        $response = wp_remote_post(
            $endpoint,
            [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($body),
            ]
        );

        $this->diagnostics['duration_ms'] = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($response)) {
            $this->diagnostics['error_code'] = $response->get_error_code();

            return new WP_Error('pdf_failed', 'unreachable: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $pdf = wp_remote_retrieve_body($response);
        $this->diagnostics += [
            'http_status' => $status,
            'content_type' => wp_remote_retrieve_header($response, 'content-type'),
            'bytes' => strlen($pdf),
        ];
        if ($status < 200 || $status >= 300) {
            return new WP_Error('pdf_failed', sprintf('HTTP %d: %s', $status, mb_substr($pdf, 0, 500)));
        }

        if (!str_starts_with($pdf, '%PDF')) {
            return new WP_Error('pdf_failed', 'response is not a PDF');
        }

        return $pdf;
    }

    private function fail(Quotation $quotation, string $reason): WP_Error
    {
        error_log(sprintf('[flux-quote] PDF for quotation %s failed, %s', $quotation->public_id, $reason));

        return new WP_Error('pdf_failed', $reason);
    }

    private function setting(string $constant, string $fallback): string
    {
        return defined($constant) ? (string) constant($constant) : $fallback;
    }
}
