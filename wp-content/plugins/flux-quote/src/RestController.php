<?php
/**
 * REST endpoints under vamm-rack-api/v1 for saving and loading
 * configurations and quotations.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

use WP_Error;
use WP_Post;
use WP_REST_Request;

final class RestController {

	private const NAMESPACE = 'vamm-rack-api/v1';

	private const CONTACT_FIELDS = array( 'firstName', 'lastName', 'phone', 'email' );

	public function register_routes(): void {
		$routes = array(
			'/ping'                                        => array( 'GET', 'ping' ),
			'/configurations'                              => array( 'POST', 'save_configuration' ),
			'/configurations/(?P<id>\d+)'                  => array( 'GET', 'get_configuration' ),
			'/quotations'                                  => array( 'POST', 'save_quotation' ),
			'/quotations/(?P<cid>\d+)/(?P<id>[a-f0-9\-]+)' => array( 'GET', 'get_quotation' ),
		);

		foreach ( $routes as $route => [ $method, $callback ] ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => $method,
					'callback'            => array( $this, $callback ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	public function ping(): array {
		return array( 'status' => 'ok' );
	}

	public function save_configuration( WP_REST_Request $request ): array|WP_Error {
		$data = $request->get_json_params();

		if ( empty( $data['configuration'] ) || ! is_array( $data['configuration'] ) ) {
			return new WP_Error( 'invalid_config', 'Configuration invalide', array( 'status' => 400 ) );
		}

		$post_id = $this->insert( $data['post_title'] ?? 'Configuration sans nom', $data['configuration'] );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array( 'configuration' => array( 'cid' => $post_id ) );
	}

	public function get_configuration( WP_REST_Request $request ): array|WP_Error {
		$post = $this->find( (int) $request['id'] );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Configuration introuvable', array( 'status' => 404 ) );
		}

		return array(
			'configuration' => array(
				'cid'        => $post->ID,
				'title'      => $post->post_title,
				'data'       => json_decode( (string) get_post_meta( $post->ID, 'config_data', true ), true ),
				'created_at' => $post->post_date,
			),
		);
	}

	public function save_quotation( WP_REST_Request $request ): array|WP_Error {
		$data = $request->get_json_params();

		if ( empty( $data['configuration'] ) || ! is_array( $data['configuration'] ) ) {
			return new WP_Error( 'invalid_config', 'Configuration invalide', array( 'status' => 400 ) );
		}

		if ( empty( $data['contact'] ) || ! is_array( $data['contact'] ) ) {
			return new WP_Error( 'invalid_contact', 'Contact info is required', array( 'status' => 400 ) );
		}

		$contact = $data['contact'];

		foreach ( self::CONTACT_FIELDS as $field ) {
			if ( empty( $contact[ $field ] ) ) {
				return new WP_Error( 'missing_field', "Missing required field: $field", array( 'status' => 400 ) );
			}
		}

		$post_id = $this->insert( $data['post_title'] ?? 'Quotation sans nom', $data['configuration'] );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$quotation_id = wp_generate_uuid4();

		update_post_meta(
			$post_id,
			'quotation_contact',
			wp_json_encode(
				array(
					'firstName' => sanitize_text_field( $contact['firstName'] ),
					'lastName'  => sanitize_text_field( $contact['lastName'] ),
					'phone'     => sanitize_text_field( $contact['phone'] ),
					'email'     => sanitize_email( $contact['email'] ),
				)
			)
		);
		update_post_meta( $post_id, 'quotation_id', $quotation_id );

		return array(
			'quotation' => array(
				'cid' => $post_id,
				'id'  => $quotation_id,
			),
		);
	}

	public function get_quotation( WP_REST_Request $request ): array|WP_Error {
		$post = $this->find( (int) $request['cid'] );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Quotation introuvable', array( 'status' => 404 ) );
		}

		$id = sanitize_text_field( $request['id'] );

		// hash_equals avoids leaking the token through response timing.
		if ( ! hash_equals( (string) get_post_meta( $post->ID, 'quotation_id', true ), $id ) ) {
			return new WP_Error( 'forbidden', 'Token de quotation invalide', array( 'status' => 403 ) );
		}

		return array(
			'quotation' => array(
				'cid'        => $post->ID,
				'id'         => $id,
				'title'      => $post->post_title,
				'data'       => json_decode( (string) get_post_meta( $post->ID, 'config_data', true ), true ),
				'contact'    => json_decode( (string) get_post_meta( $post->ID, 'quotation_contact', true ), true ),
				'created_at' => $post->post_date,
			),
		);
	}

	private function insert( string $title, array $configuration ): int|WP_Error {
		$post_id = wp_insert_post(
			array(
				'post_type'   => PostType::NAME,
				'post_title'  => sanitize_text_field( $title ),
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'save_failed', 'Échec de la sauvegarde', array( 'status' => 500 ) );
		}

		update_post_meta( $post_id, 'config_data', wp_json_encode( $configuration ) );

		return $post_id;
	}

	private function find( int $id ): ?WP_Post {
		$post = get_post( $id );

		return PostType::NAME === $post?->post_type ? $post : null;
	}
}
