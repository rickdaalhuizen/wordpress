<?php
/**
 * Registers the post type that stores configurations and quotations.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

final class PostType {

	public const NAME = 'rack_config';

	public function register(): void {
		register_post_type(
			self::NAME,
			array(
				'public'   => false,
				'show_ui'  => true,
				'supports' => array( 'title', 'author' ),
			)
		);
	}
}
