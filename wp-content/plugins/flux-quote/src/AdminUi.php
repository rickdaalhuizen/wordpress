<?php

/**
 * Read-only admin screens: detail meta boxes and list columns for configurations and quotations.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

use WP_Post;

final class AdminUi
{
    public function __construct(private Repository $repository)
    {
    }

    public function register(): void
    {
        add_action('add_meta_boxes_' . Repository::CONFIGURATION_TYPE, [$this, 'add_configuration_box']);
        add_action('add_meta_boxes_' . Repository::QUOTATION_TYPE, [$this, 'add_quotation_boxes']);

        add_filter('manage_' . Repository::CONFIGURATION_TYPE . '_posts_columns', [$this, 'configuration_columns']);
        add_action(
            'manage_' . Repository::CONFIGURATION_TYPE . '_posts_custom_column',
            [$this, 'render_configuration_column'],
            10,
            2
        );

        add_filter('manage_' . Repository::QUOTATION_TYPE . '_posts_columns', [$this, 'quotation_columns']);
        add_action(
            'manage_' . Repository::QUOTATION_TYPE . '_posts_custom_column',
            [$this, 'render_quotation_column'],
            10,
            2
        );
    }

    public function add_configuration_box(): void
    {
        add_meta_box(
            'flux_configuration_details',
            __('Configuration', 'flux-quote'),
            [$this, 'render_configuration_box']
        );
    }

    public function add_quotation_boxes(): void
    {
        add_meta_box('flux_quotation_contact', __('Contact', 'flux-quote'), [$this, 'render_contact_box']);
        add_meta_box('flux_quotation_pdf', __('PDF', 'flux-quote'), [$this, 'render_pdf_box'], null, 'side');
        add_meta_box('flux_quotation_data', __('Configuration & document', 'flux-quote'), [$this, 'render_data_box']);
    }

    public function render_configuration_box(WP_Post $post): void
    {
        $configuration = $this->repository->to_configuration($post);

        $this->render_rows([__('Public ID', 'flux-quote') => $configuration['cid']]);
        $this->render_json($configuration['data']);
    }

    public function render_contact_box(WP_Post $post): void
    {
        $contact = $this->repository->to_quotation($post)->contact;
        $email = (string) ($contact['email'] ?? '');

        $this->render_rows(
            [
                __('Name', 'flux-quote') => trim(($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? '')),
                __('Email', 'flux-quote') => $email,
                __('Phone', 'flux-quote') => $contact['phone'] ?? '',
                __('Address', 'flux-quote') => $contact['address'] ?? '',
                __('Remarks', 'flux-quote') => $contact['remarks'] ?? '',
            ],
            $email ? [__('Email', 'flux-quote') => 'mailto:' . $email] : []
        );
    }

    public function render_pdf_box(WP_Post $post): void
    {
        $quotation = $this->repository->to_quotation($post);

        $this->render_rows(
            [
                __('Status', 'flux-quote') => $this->status_label($quotation->pdf_status),
                __('File', 'flux-quote') => $quotation->pdf_url ? __('Open PDF', 'flux-quote') : '—',
                __('Public ID', 'flux-quote') => $quotation->public_id,
            ],
            $quotation->pdf_url ? [__('File', 'flux-quote') => $quotation->pdf_url] : []
        );
    }

    public function render_data_box(WP_Post $post): void
    {
        $quotation = $this->repository->to_quotation($post);

        echo '<h4>' . esc_html__('Configuration', 'flux-quote') . '</h4>';
        $this->render_json($quotation->configuration);
        echo '<h4>' . esc_html__('Document', 'flux-quote') . '</h4>';
        $this->render_json($quotation->document);
    }

    public function configuration_columns(array $columns): array
    {
        return $this->insert_before_date($columns, ['flux_cid' => __('Public ID', 'flux-quote')]);
    }

    public function render_configuration_column(string $column, int $post_id): void
    {
        if ('flux_cid' === $column) {
            echo '<code>' . esc_html($this->repository->to_configuration(get_post($post_id))['cid']) . '</code>';
        }
    }

    public function quotation_columns(array $columns): array
    {
        return $this->insert_before_date(
            $columns,
            [
                'flux_email' => __('Email', 'flux-quote'),
                'flux_phone' => __('Phone', 'flux-quote'),
                'flux_pdf' => __('PDF', 'flux-quote'),
            ]
        );
    }

    public function render_quotation_column(string $column, int $post_id): void
    {
        $quotation = $this->repository->to_quotation(get_post($post_id));

        switch ($column) {
            case 'flux_email':
                $email = (string) ($quotation->contact['email'] ?? '');
                printf('<a href="%s">%s</a>', esc_url('mailto:' . $email), esc_html($email));
                break;
            case 'flux_phone':
                echo esc_html($quotation->contact['phone'] ?? '');
                break;
            case 'flux_pdf':
                echo $quotation->pdf_url
                    ? sprintf(
                        '<a href="%s" target="_blank" rel="noopener">%s</a>',
                        esc_url($quotation->pdf_url),
                        esc_html__('Open PDF', 'flux-quote')
                    )
                    : esc_html($this->status_label($quotation->pdf_status));
                break;
        }
    }

    /**
     * Prints a label/value table; labels listed in $links get their value wrapped in a link to that URL.
     */
    private function render_rows(array $rows, array $links = []): void
    {
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ($rows as $label => $value) {
            $value = '' === (string) $value ? '—' : (string) $value;
            $cell = isset($links[$label])
                ? sprintf(
                    '<a href="%s" target="_blank" rel="noopener">%s</a>',
                    esc_url($links[$label]),
                    esc_html($value)
                )
                : nl2br(esc_html($value));

            // $cell is escaped above.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), $cell);
        }
        echo '</tbody></table>';
    }

    private function render_json(array $data): void
    {
        if (!$data) {
            echo '<p>—</p>';
            return;
        }

        printf(
            '<pre style="max-height:400px;overflow:auto;background:#f6f7f7;padding:12px;">%s</pre>',
            esc_html(wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        );
    }

    private function status_label(PdfStatus $status): string
    {
        return match ($status) {
            PdfStatus::Pending => __('Pending', 'flux-quote'),
            PdfStatus::Done => __('Generated', 'flux-quote'),
            PdfStatus::Failed => __('Failed', 'flux-quote'),
        };
    }

    private function insert_before_date(array $columns, array $added): array
    {
        $position = array_search('date', array_keys($columns), true);
        if (false === $position) {
            return $columns + $added;
        }

        return array_slice($columns, 0, $position, true) + $added + array_slice($columns, $position, null, true);
    }
}
