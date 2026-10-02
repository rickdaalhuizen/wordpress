<?php
/**
 * Wires all Flux Quote hooks.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

final class Plugin {

	public function register(): void {
		add_action( 'init', array( new PostType(), 'register' ) );
		add_action( 'rest_api_init', array( new RestController(), 'register_routes' ) );
	}
}
