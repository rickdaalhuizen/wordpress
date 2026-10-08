<?php

declare(strict_types=1);

namespace Flux\Quote;

enum Event: string
{
    case Requested = 'requested';
    case PdfGenerated = 'pdf_generated';
    case PdfFailed = 'pdf_failed';
    case MailSent = 'mail_sent';
    case MailFailed = 'mail_failed';
    case MailSkipped = 'mail_skipped';
    case StatusChanged = 'status_changed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => __('Quote requested', 'flux-quote'),
            self::PdfGenerated => __('PDF generated', 'flux-quote'),
            self::PdfFailed => __('PDF failed', 'flux-quote'),
            self::MailSent => __('Mail sent', 'flux-quote'),
            self::MailFailed => __('Mail failed', 'flux-quote'),
            self::MailSkipped => __('Mail not sent', 'flux-quote'),
            self::StatusChanged => __('Status changed', 'flux-quote'),
        };
    }

    public function is_error(): bool
    {
        return in_array($this, [self::PdfFailed, self::MailFailed, self::MailSkipped], true);
    }

    public function tone(): string
    {
        return match (true) {
            $this->is_error() => 'error',
            in_array($this, [self::PdfGenerated, self::MailSent], true) => 'success',
            default => 'info',
        };
    }
}
