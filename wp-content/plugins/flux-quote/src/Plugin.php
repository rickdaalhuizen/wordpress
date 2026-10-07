<?php

/**
 * Wires all Flux Quote hooks.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

final class Plugin
{
    public function register(): void
    {
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function boot(): void
    {
        $adapter = apply_filters('flux_quote/adapter', null);
        if (!$adapter instanceof Adapter) {
            $adapter = new Adapter();
        }

        $repository = new Repository();
        $rate_limiter = new RateLimiter();
        $config_controller = new ConfigController($adapter, $repository, $rate_limiter);
        $delivery = new Delivery($adapter, $repository, new PdfClient(), new Mailer());
        $quote_controller = new QuoteController($adapter, $repository, $delivery, $rate_limiter);

        add_action('init', [$repository, 'register_post_types']);
        add_action('rest_api_init', [$config_controller, 'register_routes']);
        add_action('rest_api_init', [$quote_controller, 'register_routes']);

        if (is_admin()) {
            (new AdminUi($repository, $delivery))->register();
            (new MailSettingsPage(new MailTemplate()))->register();
        }
    }
}
