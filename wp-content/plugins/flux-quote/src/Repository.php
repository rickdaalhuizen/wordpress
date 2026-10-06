<?php

/**
 * Stores configurations and quotations as private posts with their data in post meta.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

use WP_Error;
use WP_Post;

final class Repository
{
    public const CONFIGURATION_TYPE = 'flux_configuration';
    public const QUOTATION_TYPE = 'flux_quotation';

    private const PUBLIC_ID = '_flux_public_id';
    private const PRIVATE_ID = '_flux_private_id';
    private const CONFIGURATION = '_flux_configuration';
    private const CONTACT = '_flux_contact';
    private const DOCUMENT = '_flux_document';
    private const PDF_URL = '_flux_pdf_url';
    private const PDF_STATUS = '_flux_pdf_status';

    private const MAX_ID_ATTEMPTS = 3;

    public function register_post_types(): void
    {
        $this->register_post_type(
            self::CONFIGURATION_TYPE,
            __('Configurations', 'flux-quote'),
            __('Configuration', 'flux-quote'),
        );
        $this->register_post_type(self::QUOTATION_TYPE, __('Quotations', 'flux-quote'), __('Quotation', 'flux-quote'));
    }

    public function save_configuration(?string $title, array $data): string|WP_Error
    {
        $title ??= __('Configuration', 'flux-quote');

        $post_id = $this->insert(self::CONFIGURATION_TYPE, $title, [self::CONFIGURATION => $data]);

        return is_wp_error($post_id) ? $post_id : $this->meta($post_id, self::PUBLIC_ID);
    }

    public function find_configuration(string $public_id): ?array
    {
        $post = $this->find_post(self::CONFIGURATION_TYPE, $public_id, 'publish');

        return $post ? $this->to_configuration($post) : null;
    }

    public function save_quotation(
        ?string $title,
        array $configuration,
        array $contact,
        array $document,
    ): Quotation|WP_Error {
        $title ??= trim(
            sprintf(
                __('Quotation %1$s %2$s', 'flux-quote'),
                $contact['firstName'] ?? '',
                $contact['lastName'] ?? ''
            )
        );

        $post_id = $this->insert(
            self::QUOTATION_TYPE,
            $title,
            [
                self::CONFIGURATION => $configuration,
                self::CONTACT => $contact,
                self::DOCUMENT => $document,
                self::PDF_STATUS => PdfStatus::Pending->value,
            ]
        );

        return is_wp_error($post_id) ? $post_id : $this->to_quotation(get_post($post_id));
    }

    public function find_quotation(string $public_id, string $private_id): ?Quotation
    {
        $post = $this->find_post(self::QUOTATION_TYPE, $public_id, 'publish');

        // hash_equals avoids leaking the private ID through response timing.
        if (!$post || !hash_equals($this->meta($post->ID, self::PRIVATE_ID), $private_id)) {
            return null;
        }

        return $this->to_quotation($post);
    }

    public function save_pdf_result(Quotation $quotation, ?string $pdf_url): Quotation
    {
        $post = $this->find_post(self::QUOTATION_TYPE, $quotation->public_id, 'publish');
        if (!$post) {
            return $quotation;
        }

        $status = null === $pdf_url ? PdfStatus::Failed : PdfStatus::Done;

        update_post_meta($post->ID, self::PDF_STATUS, $status->value);
        if (null !== $pdf_url) {
            update_post_meta($post->ID, self::PDF_URL, $pdf_url);
        }

        return $this->to_quotation($post);
    }

    private function register_post_type(string $name, string $plural, string $singular): void
    {
        $args = [
            'labels' => [
                'name' => $plural,
                'singular_name' => $singular,
            ],
            'public' => false,
            'show_ui' => true,
            'supports' => ['title'],
            'rewrite' => false,
        ];

        register_post_type($name, apply_filters('flux_quote/post_type_args', $args, $name));
    }

    private function insert(string $post_type, string $title, array $meta): int|WP_Error
    {
        $public_id = $this->unique_public_id($post_type);

        if (is_wp_error($public_id)) {
            return $public_id;
        }

        $meta[self::PUBLIC_ID] = $public_id;
        $meta[self::PRIVATE_ID] = wp_generate_uuid4();

        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                $meta[$key] = wp_json_encode($value);
            }
        }

        // wp_insert_post unslashes its input, so slash it to keep backslashes inside the JSON intact.
        $post_id = wp_insert_post(
            wp_slash(
                [
                    'post_type' => $post_type,
                    'post_title' => $title,
                    'post_status' => 'publish',
                    'meta_input' => $meta,
                ]
            ),
            true
        );

        if (is_wp_error($post_id)) {
            error_log(sprintf('[flux-quote] Insert of %s failed: %s', $post_type, $post_id->get_error_message()));

            return new WP_Error(
                'save_failed',
                __('Could not save. Please try again.', 'flux-quote'),
                ['status' => 500],
            );
        }

        return $post_id;
    }

    private function unique_public_id(string $post_type): string|WP_Error
    {
        for ($attempt = 1; $attempt <= self::MAX_ID_ATTEMPTS; $attempt++) {
            $public_id = bin2hex(random_bytes(6));

            // Drafts and trashed posts count too, so their IDs never get reused.
            if (!$this->find_post($post_type, $public_id, 'any')) {
                return $public_id;
            }
        }

        error_log(
            sprintf('[flux-quote] No unique public ID for %s after %d attempts.', $post_type, self::MAX_ID_ATTEMPTS)
        );

        return new WP_Error(
            'save_failed',
            __('Could not save. Please try again.', 'flux-quote'),
            ['status' => 500],
        );
    }

    private function find_post(string $post_type, string $public_id, string $post_status): ?WP_Post
    {
        $posts = get_posts(
            [
                'post_type' => $post_type,
                'post_status' => $post_status,
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key' => self::PUBLIC_ID,
                        'value' => $public_id,
                    ],
                ],
            ]
        );

        return $posts[0] ?? null;
    }

    public function to_configuration(WP_Post $post): array
    {
        return [
            'cid' => $this->meta($post->ID, self::PUBLIC_ID),
            'title' => $post->post_title,
            'data' => $this->json_meta($post->ID, self::CONFIGURATION),
            'created_at' => $post->post_date,
        ];
    }

    public function to_quotation(WP_Post $post): Quotation
    {
        $pdf_url = $this->meta($post->ID, self::PDF_URL);

        return new Quotation(
            public_id: $this->meta($post->ID, self::PUBLIC_ID),
            private_id: $this->meta($post->ID, self::PRIVATE_ID),
            title: $post->post_title,
            configuration: $this->json_meta($post->ID, self::CONFIGURATION),
            contact: $this->json_meta($post->ID, self::CONTACT),
            document: $this->json_meta($post->ID, self::DOCUMENT),
            pdf_url: '' === $pdf_url ? null : $pdf_url,
            pdf_status: PdfStatus::tryFrom($this->meta($post->ID, self::PDF_STATUS)) ?? PdfStatus::Pending,
            created_at: $post->post_date,
        );
    }

    private function meta(int $post_id, string $key): string
    {
        return (string) get_post_meta($post_id, $key, true);
    }

    private function json_meta(int $post_id, string $key): array
    {
        $value = json_decode($this->meta($post_id, $key), true);

        return is_array($value) ? $value : [];
    }
}
