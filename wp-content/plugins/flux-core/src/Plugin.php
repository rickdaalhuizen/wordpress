<?php

/**
 * Wires all Flux Core hooks.
 *
 * @package Flux_Core
 */

declare(strict_types=1);

namespace Flux\Core;

use PHPMailer\PHPMailer\PHPMailer;

final class Plugin
{
    public function register(): void
    {
        add_action('phpmailer_init', [$this, 'configure_smtp']);
        add_filter('wp_mail_from', fn(string $from): string => $this->setting('MAIL_FROM') ?: $from);
        add_filter('wp_mail_from_name', fn(string $name): string => $this->setting('MAIL_FROM_NAME') ?: $name);
    }

    /**
     * Routes wp_mail() through SMTP when SMTP_HOST is set; otherwise WordPress keeps its default transport.
     */
    public function configure_smtp(PHPMailer $mailer): void
    {
        $host = $this->setting('SMTP_HOST');
        if ('' === $host) {
            return;
        }

        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = (int) ($this->setting('SMTP_PORT') ?: 587);
        $mailer->SMTPSecure = $this->setting('SMTP_SECURE');
        $mailer->Username = $this->setting('SMTP_USER');
        $mailer->Password = $this->setting('SMTP_PASSWORD');
        $mailer->SMTPAuth = '' !== $mailer->Username;
    }

    /**
     * Reads a wp-config.php constant, falling back to the environment (the Docker .env).
     */
    private function setting(string $name): string
    {
        return defined($name) ? (string) constant($name) : (string) getenv($name);
    }
}
