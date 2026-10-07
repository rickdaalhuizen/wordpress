<?php

/**
 * REST endpoints for saving and loading configurations.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ConfigController
{
    private const MAX_PER_IP_PER_HOUR = 30;

    public function __construct(
        private Adapter $adapter,
        private Repository $repository,
        private RateLimiter $rate_limiter,
    ) {
    }

    public function register_routes(): void
    {
        register_rest_route($this->adapter->rest_namespace(), '/configurations', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'save'],
            'permission_callback' => '__return_true',
            'args' => [
                'configuration' => ['type' => 'object', 'required' => true, 'minProperties' => 1],
                'post_title' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($this->adapter->rest_namespace(), '/configurations/(?P<cid>[a-f0-9]{12})', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function save(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $rejected = $this->rate_limiter->hit(
            'configuration_ip',
            $this->rate_limiter->client_ip(),
            self::MAX_PER_IP_PER_HOUR,
            HOUR_IN_SECONDS
        ) ?? $this->adapter->before_save($request);
        if ($rejected) {
            return $rejected;
        }

        $public_id = $this->repository->save_configuration($request['post_title'] ?: null, $request['configuration']);
        if ($public_id instanceof WP_Error) {
            return $public_id;
        }

        return new WP_REST_Response(['configuration' => ['cid' => $public_id]], 201);
    }

    public function get(WP_REST_Request $request): array|WP_Error
    {
        $configuration = $this->repository->find_configuration((string) $request['cid']);
        if (!$configuration) {
            return new WP_Error('not_found', __('Not found.', 'flux-quote'), ['status' => 404]);
        }

        return ['configuration' => $configuration];
    }
}
