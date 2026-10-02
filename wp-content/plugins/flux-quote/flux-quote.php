<?php
/**
 * Flux Quote entry point: verifies dependencies, then boots the plugin.
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
 * Requires Plugins:  flux-core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// is_plugin_active() is only loaded in wp-admin; REST and frontend requests need it too.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_plugin_active( 'flux-core/flux-core.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Flux Quote requires Flux Core to be active.', 'flux-quote' )
				. '</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

( new Flux\Quote\Plugin() )->register();
