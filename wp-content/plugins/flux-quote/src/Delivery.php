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
        private EventLog $events,
    ) {
    }

    public function deliver(Quotation $quotation): Quotation
    {
        if (PdfStatus::Done !== $quotation->pdf_status) {
            $pdf_url = $this->pdf_client->send($this->adapter->pdf_payload($quotation), $quotation);

            $context = $this->pdf_client->diagnostics();
            if (is_wp_error($pdf_url)) {
                $this->events->record($quotation->post_id, Event::PdfFailed, $pdf_url->get_error_message(), $context);
                $pdf_url = null;
            } else {
                $this->events->record($quotation->post_id, Event::PdfGenerated, '', ['url' => $pdf_url] + $context);
            }

            $quotation = $this->repository->save_pdf_result($quotation, $pdf_url);
        }

        // Without a PDF there is nothing to send; the admin can retry once the PDF service is back.
        if (PdfStatus::Done !== $quotation->pdf_status) {
            return $this->skip($quotation, __('There is no PDF to attach.', 'flux-quote'));
        }

        $mail = $this->adapter->quotation_mail($quotation);
        if (null === $mail) {
            return $this->skip($quotation, __('The contact has no valid email address.', 'flux-quote'));
        }

        $recipient = implode(', ', (array) $mail['to']);
        $error = $this->mailer->send($mail, $quotation);
        $context = ['to' => $recipient] + $this->mailer->diagnostics();

        if (null !== $error) {
            $this->events->record($quotation->post_id, Event::MailFailed, $recipient . ': ' . $error, $context);

            return $this->repository->save_mail_result($quotation, MailStatus::Failed, $error);
        }

        $this->events->record($quotation->post_id, Event::MailSent, $recipient, $context);
        if (QuoteStatus::Draft === $quotation->status) {
            $this->repository->save_status($quotation->post_id, QuoteStatus::Sent);
            $this->events->record(
                $quotation->post_id,
                Event::StatusChanged,
                QuoteStatus::Draft->label() . ' → ' . QuoteStatus::Sent->label(),
                ['from' => QuoteStatus::Draft->value, 'to' => QuoteStatus::Sent->value]
            );
        }

        return $this->repository->save_mail_result($quotation, MailStatus::Sent);
    }

    private function skip(Quotation $quotation, string $reason): Quotation
    {
        $this->events->record($quotation->post_id, Event::MailSkipped, $reason);

        return $this->repository->save_mail_result($quotation, MailStatus::Skipped);
    }
}
