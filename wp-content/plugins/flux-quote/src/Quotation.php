<?php

/**
 * A saved quotation as returned by the repository.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

final readonly class Quotation
{
    public function __construct(
        public string $public_id,
        public string $private_id,
        public string $title,
        public array $configuration,
        public array $contact,
        public array $document,
        public ?string $pdf_url,
        public PdfStatus $pdf_status,
        public MailStatus $mail_status,
        public ?string $mail_error,
        public string $created_at,
    ) {
    }
}
