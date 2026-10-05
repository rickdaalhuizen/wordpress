<?php

/**
 * REST endpoints for saving and loading quotations.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class QuoteController
{
    private const TEXT = ['type' => 'string', 'required' => true, 'minLength' => 1];

    public function __construct(
        private Adapter $adapter,
        private Repository $repository,
        private PdfClient $pdf_client,
    ) {
    }

    public function register_routes(): void
    {
        register_rest_route($this->adapter->rest_namespace(), '/quotations', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'save'],
            'permission_callback' => '__return_true',
            'args' => [
                'configuration' => ['type' => 'object', 'required' => true, 'minProperties' => 1],
                'document' => ['type' => 'object', 'required' => true, 'minProperties' => 1],
                'post_title' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'contact' => [
                    'type' => 'object',
                    'required' => true,
                    'properties' => [
                        'firstName' => self::TEXT,
                        'lastName' => self::TEXT,
                        'email' => ['type' => 'string', 'required' => true, 'format' => 'email'],
                        'phone' => self::TEXT,
                        'address' => self::TEXT,
                        'remarks' => ['type' => 'string'],
                    ],
                ],
            ],
        ]);

        $route = '/quotations/(?P<cid>[a-f0-9]{12})/(?P<id>[a-f0-9-]{36})';
        register_rest_route($this->adapter->rest_namespace(), $route, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function save(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $rejected = $this->adapter->before_save($request);
        if ($rejected) {
            return $rejected;
        }

        $saved = $this->repository->save_quotation(
            $request['post_title'] ?: null,
            $request['configuration'],
            $request['contact'],
            $request['document'],
        );
        if ($saved instanceof WP_Error) {
            return $saved;
        }

        $pdf_url = $this->pdf_client->send($this->adapter->pdf_payload($saved), $saved);
        $saved = $this->repository->save_pdf_result($saved, $pdf_url);

        return new WP_REST_Response(
            ['quotation' => ['cid' => $saved->public_id, 'id' => $saved->private_id, 'pdf_url' => $saved->pdf_url]],
            201
        );
    }

    public function get(WP_REST_Request $request): array|WP_Error
    {
        $quotation = $this->repository->find_quotation((string) $request['cid'], (string) $request['id']);
        if (!$quotation) {
            return new WP_Error('not_found', __('Not found.', 'flux-quote'), ['status' => 404]);
        }

        return [
            'quotation' => [
                'cid' => $quotation->public_id,
                'id' => $quotation->private_id,
                'title' => $quotation->title,
                'configuration' => $quotation->configuration,
                'contact' => $quotation->contact,
                'pdf_url' => $quotation->pdf_url,
                'created_at' => $quotation->created_at,
            ],
        ];
    }
}
