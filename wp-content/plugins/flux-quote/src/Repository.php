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
use WP_Query;

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
    private const MAIL_STATUS = '_flux_mail_status';
    private const MAIL_ERROR = '_flux_mail_error';
    private const STATUS = '_flux_status';

    private const MAX_ID_ATTEMPTS = 3;
    private const TITLES_CLEANED = 'flux_quote_titles_cleaned';

    public function register_post_types(): void
    {
        $this->register_post_type(
            self::QUOTATION_TYPE,
            [
                'labels' => [
                    'name' => __('Quotations', 'flux-quote'),
                    'singular_name' => __('Quotation', 'flux-quote'),
                    'all_items' => __('Quotations', 'flux-quote'),
                    'search_items' => __('Search quotations', 'flux-quote'),
                    'not_found' => __('No quotations yet. They appear when a customer requests one.', 'flux-quote'),
                    'not_found_in_trash' => __('No quotations in the trash.', 'flux-quote'),
                ],
                'show_in_menu' => AdminUi::MENU,
            ]
        );
        $this->register_post_type(
            self::CONFIGURATION_TYPE,
            [
                'labels' => [
                    'name' => __('Configurations', 'flux-quote'),
                    'singular_name' => __('Configuration', 'flux-quote'),
                    'search_items' => __('Search configurations', 'flux-quote'),
                    'not_found' => __('No configurations found.', 'flux-quote'),
                    'not_found_in_trash' => __('No configurations in the trash.', 'flux-quote'),
                ],
                'show_in_menu' => AdminUi::MENU,
            ]
        );
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
                self::MAIL_STATUS => MailStatus::Pending->value,
                self::STATUS => QuoteStatus::Draft->value,
            ]
        );

        return is_wp_error($post_id) ? $post_id : $this->to_quotation(get_post($post_id));
    }

    public function maybe_clean_titles(): void
    {
        if (get_option(self::TITLES_CLEANED)) {
            return;
        }

        $posts = get_posts(
            [
                'post_type' => [self::CONFIGURATION_TYPE, self::QUOTATION_TYPE],
                'post_status' => 'any',
                'posts_per_page' => -1,
            ]
        );
        foreach ($posts as $post) {
            $title = $this->clean_title($post->post_title);
            if ($title !== $post->post_title) {
                wp_update_post(wp_slash(['ID' => $post->ID, 'post_title' => $title]));
            }
        }

        update_option(self::TITLES_CLEANED, 1, false);
    }

    public function public_id_meta_query(string $public_id): array
    {
        return [['key' => self::PUBLIC_ID, 'value' => $public_id]];
    }

    public function save_status(int $post_id, QuoteStatus $status): void
    {
        update_post_meta($post_id, self::STATUS, $status->value);
    }

    public function status_meta_query(QuoteStatus $status): array
    {
        $query = [['key' => self::STATUS, 'value' => $status->value]];

        if (QuoteStatus::Draft === $status) {
            $query = ['relation' => 'OR', $query[0], ['key' => self::STATUS, 'compare' => 'NOT EXISTS']];
        }

        return $query;
    }

    public function count_quotations(QuoteStatus $status): int
    {
        $query = new WP_Query(
            [
                'post_type' => self::QUOTATION_TYPE,
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_query' => $this->status_meta_query($status),
            ]
        );

        return $query->found_posts;
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

    public function latest_quotation(): ?Quotation
    {
        $posts = get_posts(['post_type' => self::QUOTATION_TYPE, 'post_status' => 'any', 'posts_per_page' => 1]);

        return $posts ? $this->to_quotation($posts[0]) : null;
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

    public function save_mail_result(Quotation $quotation, MailStatus $status, ?string $error = null): Quotation
    {
        $post = $this->find_post(self::QUOTATION_TYPE, $quotation->public_id, 'publish');
        if (!$post) {
            return $quotation;
        }

        update_post_meta($post->ID, self::MAIL_STATUS, $status->value);
        if (null === $error) {
            delete_post_meta($post->ID, self::MAIL_ERROR);
        } else {
            update_post_meta($post->ID, self::MAIL_ERROR, wp_slash($error));
        }

        return $this->to_quotation($post);
    }

    private function register_post_type(string $name, array $args): void
    {
        $args += [
            'public' => false,
            'show_ui' => true,
            'supports' => ['title'],
            'rewrite' => false,
            'capabilities' => ['create_posts' => 'do_not_allow'],
            'map_meta_cap' => true,
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
                    'post_title' => $this->clean_title($title),
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

    private function clean_title(string $title): string
    {
        return (string) preg_replace('#\s+\d{1,2}/\d{1,2}/\d{4}(\s+\d{1,2}:\d{2}(:\d{2})?)?$#', '', trim($title));
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
        $mail_error = $this->meta($post->ID, self::MAIL_ERROR);

        return new Quotation(
            post_id: $post->ID,
            public_id: $this->meta($post->ID, self::PUBLIC_ID),
            private_id: $this->meta($post->ID, self::PRIVATE_ID),
            title: $post->post_title,
            configuration: $this->json_meta($post->ID, self::CONFIGURATION),
            contact: $this->json_meta($post->ID, self::CONTACT),
            document: $this->json_meta($post->ID, self::DOCUMENT),
            pdf_url: '' === $pdf_url ? null : $pdf_url,
            pdf_status: PdfStatus::tryFrom($this->meta($post->ID, self::PDF_STATUS)) ?? PdfStatus::Pending,
            mail_status: MailStatus::tryFrom($this->meta($post->ID, self::MAIL_STATUS)) ?? MailStatus::Pending,
            mail_error: '' === $mail_error ? null : $mail_error,
            status: QuoteStatus::tryFrom($this->meta($post->ID, self::STATUS)) ?? QuoteStatus::Draft,
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
