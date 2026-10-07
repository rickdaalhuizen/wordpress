<?php

/**
 * Generates a quotation's PDF when it is missing, then mails it to the contact.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

final class Delivery
{
    public function __construct(
        private Adapter $adapter,
        private Repository $repository,
        private PdfClient $pdf_client,
        private Mailer $mailer,
    ) {
    }

    public function deliver(Quotation $quotation): Quotation
    {
        if (PdfStatus::Done !== $quotation->pdf_status) {
            $pdf_url = $this->pdf_client->send($this->adapter->pdf_payload($quotation), $quotation);
            $quotation = $this->repository->save_pdf_result($quotation, $pdf_url);
        }

        // Without a PDF there is nothing to send; the admin can retry once the PDF service is back.
        if (PdfStatus::Done !== $quotation->pdf_status) {
            return $this->repository->save_mail_result($quotation, MailStatus::Skipped);
        }

        $mail = $this->adapter->quotation_mail($quotation);
        if (null === $mail) {
            return $this->repository->save_mail_result($quotation, MailStatus::Skipped);
        }

        $error = $this->mailer->send($mail, $quotation);

        return $this->repository->save_mail_result(
            $quotation,
            null === $error ? MailStatus::Sent : MailStatus::Failed,
            $error
        );
    }
}
