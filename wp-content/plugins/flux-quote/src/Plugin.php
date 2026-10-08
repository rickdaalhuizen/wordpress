<?php

/**
 * Wires all Flux Quote hooks.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

use PHPMailer\PHPMailer\PHPMailer;

final class Plugin
{
    public function register(): void
    {
        add_action('plugins_loaded', [$this, 'boot']);
        add_action('phpmailer_init', [$this, 'configure_smtp']);
        add_filter('wp_mail_from', fn(string $from): string => $this->setting('MAIL_FROM') ?: $from);
        add_filter('wp_mail_from_name', fn(string $name): string => $this->setting('MAIL_FROM_NAME') ?: $name);
    }

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

    public function boot(): void
    {
        $adapter = apply_filters('flux_quote/adapter', null);
        if (!$adapter instanceof Adapter) {
            $adapter = new Adapter();
        }

        $repository = new Repository();
        $events = new EventLog();
        $events->register();
        $rate_limiter = new RateLimiter();
        $config_controller = new ConfigController($adapter, $repository, $rate_limiter);
        $delivery = new Delivery($adapter, $repository, new PdfClient(new PdfTemplate()), new Mailer(), $events);
        $quote_controller = new QuoteController($adapter, $repository, $delivery, $rate_limiter, $events);

        add_action('init', [$repository, 'register_post_types']);
        add_action('rest_api_init', [$config_controller, 'register_routes']);
        add_action('rest_api_init', [$quote_controller, 'register_routes']);
        add_action('rest_api_init', [new PreviewController($adapter, $repository), 'register_routes']);

        if (is_admin()) {
            add_action('admin_init', [$repository, 'maybe_clean_titles']);
            (new AdminUi($repository, $events))->register();
            (new DetailPage($repository, $delivery, $events))->register();
            (new SettingsPage($adapter, new Company(), new MailTemplate(), new PdfTemplate()))->register();
        }
    }

    private function setting(string $name): string
    {
        return defined($name) ? (string) constant($name) : '';
    }
}
