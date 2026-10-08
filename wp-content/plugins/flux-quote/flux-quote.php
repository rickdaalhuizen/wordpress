<?php

/**
 * Flux Quote entry point: boots the plugin.
 *
 * @package Flux_Quote
 *
 * @wordpress-plugin
 * Plugin Name:       Flux Quote
 * Description:       Flux Quote Plugin.
 * Version:           1.0.0
 * Text Domain:       flux-quote
 * Requires PHP:      8.4
 * Requires at least: 6.5
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

(new Flux\Quote\Plugin())->register();
