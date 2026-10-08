<?php

/**
 * Admin list screens for configurations, quotations and their history.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

use WP_Post;
use WP_Query;

final class AdminUi
{
    public const MENU = 'flux';

    private const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 117 84.6">'
        . '<path fill="black" d="M105.6 38.8H0V0h117zM59.6 84.6H0V47.8h71z"/></svg>';
    private const HISTORY_PAGE = 'flux-quote-history';

    public function __construct(private Repository $repository, private EventLog $events)
    {
    }

    public static function history_url(?int $post_id = null): string
    {
        return add_query_arg(
            array_filter(['page' => self::HISTORY_PAGE, 'quotation' => $post_id]),
            admin_url('admin.php')
        );
    }

    public static function json(array $data): string
    {
        if (!$data) {
            return '<p>—</p>';
        }

        return sprintf(
            '<pre class="flux-quote-json">%s</pre>',
            esc_html(wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        );
    }

    public function register(): void
    {
        $quotation_list = 'edit-' . Repository::QUOTATION_TYPE;
        $configuration_list = 'edit-' . Repository::CONFIGURATION_TYPE;

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
        add_filter('views_' . $quotation_list, [$this, 'status_views']);

        foreach ([$quotation_list, $configuration_list] as $list) {
            add_filter('manage_' . $list . '_sortable_columns', [$this, 'sortable_columns']);
            add_filter('bulk_actions-' . $list, [$this, 'bulk_actions']);
        }
        add_filter('views_' . $configuration_list, [$this, 'without_published_view']);
        add_filter('list_table_primary_column', [$this, 'primary_column'], 10, 2);
        add_filter('post_row_actions', [$this, 'row_actions'], 10, 2);
        add_action('pre_get_posts', [$this, 'filter_list']);

        add_filter('get_edit_post_link', [$this, 'edit_link'], 10, 3);
        add_action('load-post.php', [$this, 'redirect_edit_screen']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_menu', [$this, 'sort_menu'], PHP_INT_MAX);
    }

    public function add_menu(): void
    {
        add_menu_page(
            'Flux',
            'Flux',
            'edit_posts',
            self::MENU,
            '',
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI, not obfuscation.
            'data:image/svg+xml;base64,' . base64_encode(self::ICON),
            26
        );
        add_submenu_page(
            self::MENU,
            __('History', 'flux-quote'),
            __('History', 'flux-quote'),
            get_post_type_object(Repository::QUOTATION_TYPE)->cap->edit_posts,
            self::HISTORY_PAGE,
            [$this, 'render_history']
        );
    }

    public function sort_menu(): void
    {
        global $submenu;

        if (empty($submenu[self::MENU])) {
            return;
        }

        $items = array_filter($submenu[self::MENU], static fn(array $item): bool => self::MENU !== $item[2]);
        usort(
            $items,
            static fn(array $a, array $b): int => (SettingsPage::PAGE === $a[2]) <=> (SettingsPage::PAGE === $b[2])
        );
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $submenu[self::MENU] = $items;
    }

    public function render_history(): void
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

        $table = new HistoryTable($this->repository, $this->events);
        $table->prepare_items();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $post = get_post(absint($_GET['quotation'] ?? 0));
        $quotation = $post && Repository::QUOTATION_TYPE === $post->post_type
            ? $this->repository->to_quotation($post)
            : null;

        echo '<div class="wrap">';
        if ($quotation) {
            printf(
                '<h1 class="wp-heading-inline">%s</h1>',
                sprintf(
                    esc_html__('History of “%s”', 'flux-quote'),
                    sprintf(
                        '<a href="%s">%s</a>',
                        esc_url(DetailPage::url($quotation->post_id)),
                        esc_html(self::customer_name($quotation))
                    )
                )
            );
        } else {
            printf('<h1 class="wp-heading-inline">%s</h1>', esc_html__('History', 'flux-quote'));
        }
        echo '<hr class="wp-header-end">';
        $table->views();
        echo '<form method="get">';
        $query = ['post_type' => Repository::QUOTATION_TYPE, 'page' => self::HISTORY_PAGE];
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $query += array_filter(
            ['quotation' => absint($_GET['quotation'] ?? 0), 'view' => sanitize_key($_GET['view'] ?? '')]
        );
        // phpcs:enable
        foreach ($query as $name => $value) {
            printf('<input type="hidden" name="%s" value="%s">', esc_attr($name), esc_attr((string) $value));
        }
        $table->display();
        echo '</form></div>';
    }

    public function enqueue_styles(string $hook_suffix): void
    {
        $flux_page = str_starts_with($hook_suffix, self::MENU . '_page_');
        if (!$flux_page && !self::is_ours(get_current_screen()?->post_type)) {
            return;
        }

        $plugin_dir = dirname(__DIR__);
        wp_enqueue_style(
            'flux-quote-admin',
            plugins_url('assets/admin.css', $plugin_dir . '/flux-quote.php'),
            [],
            (string) filemtime($plugin_dir . '/assets/admin.css')
        );
    }

    public function edit_link(?string $link, int $post_id, string $context): ?string
    {
        if (!self::is_ours(get_post_type($post_id))) {
            return $link;
        }

        $url = DetailPage::url($post_id);

        return 'display' === $context ? esc_url($url) : $url;
    }

    public function redirect_edit_screen(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $action = sanitize_key($_GET['action'] ?? '');
        $post_id = absint($_GET['post'] ?? 0);
        // phpcs:enable

        if ('edit' === $action && self::is_ours(get_post_type($post_id))) {
            wp_safe_redirect(DetailPage::url($post_id));
            exit;
        }
    }

    public function configuration_columns(array $columns): array
    {
        return [
            'cb' => $columns['cb'] ?? '',
            'flux_configuration' => __('Configuration', 'flux-quote'),
            'flux_product' => __('Product', 'flux-quote'),
            'flux_date' => __('Date', 'flux-quote'),
        ];
    }

    public function render_configuration_column(string $column, int $post_id): void
    {
        $post = get_post($post_id);

        switch ($column) {
            case 'flux_configuration':
                printf(
                    '<strong><a class="row-title" href="%s">%s</a></strong>',
                    esc_url(DetailPage::url($post_id)),
                    esc_html($post->post_title)
                );
                break;
            case 'flux_product':
                echo esc_html(self::product_label($this->repository->to_configuration($post)['data']) ?: '—');
                break;
            case 'flux_date':
                $this->render_date($post);
                break;
        }
    }

    public function quotation_columns(array $columns): array
    {
        return [
            'cb' => $columns['cb'] ?? '',
            'flux_customer' => __('Customer', 'flux-quote'),
            'flux_product' => __('Product', 'flux-quote'),
            'flux_quote_status' => __('Status', 'flux-quote'),
            'flux_email' => __('Email', 'flux-quote'),
            'flux_phone' => __('Phone', 'flux-quote'),
            'flux_date' => __('Date', 'flux-quote'),
        ];
    }

    public function sortable_columns(array $columns): array
    {
        return ['flux_date' => ['date', true]] + $columns;
    }

    public function primary_column(string $column, string $screen_id): string
    {
        return match ($screen_id) {
            'edit-' . Repository::QUOTATION_TYPE => 'flux_customer',
            'edit-' . Repository::CONFIGURATION_TYPE => 'flux_configuration',
            default => $column,
        };
    }

    public function render_quotation_column(string $column, int $post_id): void
    {
        $post = get_post($post_id);
        $quotation = $this->repository->to_quotation($post);

        switch ($column) {
            case 'flux_customer':
                printf(
                    '<strong><a class="row-title" href="%s">%s</a></strong>',
                    esc_url(DetailPage::url($post_id)),
                    esc_html(self::customer_name($quotation))
                );
                break;
            case 'flux_product':
                echo esc_html(implode(', ', $quotation->products()) ?: '—');
                break;
            case 'flux_quote_status':
                printf(
                    '<span class="flux-quote-status flux-quote-status--%s">%s</span>',
                    esc_attr($quotation->status->value),
                    esc_html($quotation->status->label())
                );
                break;
            case 'flux_email':
                $email = (string) ($quotation->contact['email'] ?? '');
                printf('<a href="%s">%s</a>', esc_url('mailto:' . $email), esc_html($email));
                break;
            case 'flux_phone':
                echo esc_html($quotation->contact['phone'] ?? '');
                break;
            case 'flux_date':
                $this->render_date($post);
                break;
        }
    }

    /**
     * Adds a tab per status after "All". "Published" is dropped: every quotation is published, so it equals "All".
     */
    public function status_views(array $views): array
    {
        $current = $this->requested_status();
        $base = admin_url('edit.php?post_type=' . Repository::QUOTATION_TYPE);

        if ($current && isset($views['all'])) {
            $views['all'] = str_replace([' class="current"', ' aria-current="page"'], '', $views['all']);
        }

        $added = [];
        foreach (QuoteStatus::cases() as $status) {
            $added['flux_' . $status->value] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
                esc_url(add_query_arg('quote_status', $status->value, $base)),
                $status === $current ? ' class="current" aria-current="page"' : '',
                esc_html($status->label()),
                esc_html(number_format_i18n($this->repository->count_quotations($status)))
            );
        }

        $views = $this->without_published_view($views);
        $all = isset($views['all']) ? ['all' => $views['all']] : [];

        return $all + $added + $views;
    }

    public function without_published_view(array $views): array
    {
        unset($views['publish']);

        return $views;
    }

    public function bulk_actions(array $actions): array
    {
        unset($actions['edit']);

        return $actions;
    }

    public function row_actions(array $actions, WP_Post $post): array
    {
        if (!self::is_ours($post->post_type)) {
            return $actions;
        }

        unset($actions['edit'], $actions['inline hide-if-no-js']);
        $added = [
            'flux_view' => sprintf(
                '<a href="%s">%s</a>',
                esc_url(DetailPage::url($post->ID)),
                esc_html__('View', 'flux-quote')
            ),
        ];
        if (Repository::CONFIGURATION_TYPE === $post->post_type) {
            return $added + $actions;
        }

        $quotation = $this->repository->to_quotation($post);
        if ($quotation->pdf_url) {
            $added['flux_pdf'] = sprintf(
                '<a href="%s" target="_blank" rel="noopener">%s</a>',
                esc_url($quotation->pdf_url),
                esc_html__('Download PDF', 'flux-quote')
            );
        }
        $added['flux_history'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::history_url($post->ID)),
            esc_html__('History', 'flux-quote')
        );

        return $added + $actions;
    }

    public function filter_list(WP_Query $query): void
    {
        if (!$query->is_main_query() || !self::is_ours($query->get('post_type'))) {
            return;
        }

        $search = trim((string) $query->get('s'));
        if (preg_match('/^[a-f0-9]{12}$/', $search)) {
            $query->set('s', '');
            $query->set('meta_query', $this->repository->public_id_meta_query($search));
            return;
        }

        $status = Repository::QUOTATION_TYPE === $query->get('post_type') ? $this->requested_status() : null;
        if ($status) {
            $query->set('meta_query', $this->repository->status_meta_query($status));
        }
    }

    public static function customer_name(Quotation $quotation): string
    {
        $name = trim(($quotation->contact['firstName'] ?? '') . ' ' . ($quotation->contact['lastName'] ?? ''));

        return '' === $name ? $quotation->title : $name;
    }

    public static function product_label(array $configuration): string
    {
        $product_id = $configuration['productId'] ?? '';

        return is_string($product_id) ? ucfirst(str_replace(['_', '-'], ' ', $product_id)) : '';
    }

    private static function is_ours(mixed $post_type): bool
    {
        return in_array($post_type, [Repository::QUOTATION_TYPE, Repository::CONFIGURATION_TYPE], true);
    }

    private function render_date(WP_Post $post): void
    {
        printf(
            '<time datetime="%s">%s</time>',
            esc_attr((string) get_post_time('c', true, $post)),
            esc_html((string) get_the_date('', $post))
        );
    }

    private function requested_status(): ?QuoteStatus
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return QuoteStatus::tryFrom(sanitize_key(wp_unslash($_GET['quote_status'] ?? '')));
    }
}
