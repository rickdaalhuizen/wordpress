<?php

declare(strict_types=1);

namespace Flux\Quote;

use WP_List_Table;

final class HistoryTable extends WP_List_Table
{
    private ?int $post_id;
    private bool $errors_only;
    private ?Event $event;

    private array $quotations = [];

    public function __construct(private Repository $repository, private EventLog $events)
    {
        parent::__construct(['singular' => 'event', 'plural' => 'events', 'ajax' => false]);

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $this->post_id = absint($_GET['quotation'] ?? 0) ?: null;
        $this->errors_only = 'errors' === sanitize_key($_GET['view'] ?? '');
        $this->event = Event::tryFrom(sanitize_key($_GET['event'] ?? ''));
        // phpcs:enable
    }

    public function get_columns(): array
    {
        return [
            'time' => __('Date', 'flux-quote'),
            'event' => __('Event', 'flux-quote'),
            'details' => __('Message', 'flux-quote'),
            'quotation' => __('Quotation', 'flux-quote'),
            'user' => __('Initiator', 'flux-quote'),
        ];
    }

    public function prepare_items(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = 'asc' === sanitize_key($_GET['order'] ?? '') ? 'ASC' : 'DESC';

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'event'];
        $this->items = $this->events->query(
            $this->post_id,
            $this->errors_only,
            $this->event,
            $order,
            $this->get_pagenum()
        );
        $this->set_pagination_args(
            [
                'total_items' => $this->events->count($this->post_id, $this->errors_only, $this->event),
                'per_page' => EventLog::PER_PAGE,
            ]
        );

        $post_ids = array_unique(array_map('intval', array_column($this->items, 'post_id')));
        _prime_post_caches($post_ids, false, true);
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if ($post) {
                $this->quotations[$post_id] = $this->repository->to_quotation($post);
            }
        }
    }

    public function no_items(): void
    {
        esc_html_e('No events found.', 'flux-quote');
    }

    public function single_row($item): void
    {
        $event = Event::tryFrom($item['event']);
        printf('<tr class="flux-quote-log--%s">', esc_attr($event?->tone() ?? 'info'));
        $this->single_row_columns($item);
        echo '</tr>';
    }

    protected function get_sortable_columns(): array
    {
        return ['time' => ['time', true]];
    }

    protected function get_views(): array
    {
        $base = AdminUi::history_url($this->post_id);
        $views = [
            'all' => [__('All', 'flux-quote'), $base, !$this->errors_only, $this->events->count($this->post_id, false)],
            'errors' => [
                __('Errors', 'flux-quote'),
                add_query_arg('view', 'errors', $base),
                $this->errors_only,
                $this->events->count($this->post_id, true),
            ],
        ];

        return array_map(
            static fn(array $view): string => sprintf(
                '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
                esc_url($view[1]),
                $view[2] ? ' class="current" aria-current="page"' : '',
                esc_html($view[0]),
                esc_html(number_format_i18n($view[3]))
            ),
            $views
        );
    }

    protected function extra_tablenav($which): void
    {
        if ('top' !== $which) {
            return;
        }

        printf(
            '<div class="alignleft actions"><label class="screen-reader-text" for="flux-quote-event-filter">%s</label>'
            . '<select name="event" id="flux-quote-event-filter"><option value="">%s</option>',
            esc_html__('Filter by event', 'flux-quote'),
            esc_html__('All events', 'flux-quote')
        );
        foreach (Event::cases() as $event) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($event->value),
                selected($event->value, $this->event?->value, false),
                esc_html($event->label())
            );
        }
        echo '</select>';
        submit_button(__('Filter', 'flux-quote'), '', 'filter_action', false);
        echo '</div>';
    }

    protected function column_time(array $item): string
    {
        $timestamp = (int) strtotime($item['created_at'] . ' UTC');

        return sprintf(
            '<time datetime="%s" title="%s UTC">%s</time><br><span class="description">%s</span>',
            esc_attr(gmdate('c', $timestamp)),
            esc_attr($item['created_at']),
            esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format') . ':s', $timestamp)),
            esc_html(sprintf(__('%s ago', 'flux-quote'), human_time_diff($timestamp)))
        );
    }

    protected function column_event(array $item): string
    {
        $event = Event::tryFrom($item['event']);

        return sprintf(
            '<span class="flux-quote-event flux-quote-event--%s">%s</span>',
            esc_attr($event?->tone() ?? 'info'),
            esc_html($event?->label() ?? $item['event'])
        );
    }

    protected function column_details(array $item): string
    {
        $context = [
            __('Event ID', 'flux-quote') => '#' . $item['id'],
            __('Event type', 'flux-quote') => $item['event'],
            __('Time (UTC)', 'flux-quote') => $item['created_at'],
        ] + $this->context($item);

        $rows = '';
        foreach ($context as $key => $value) {
            $rows .= sprintf(
                '<tr><th scope="row">%s</th><td><code>%s</code></td></tr>',
                esc_html($this->label((string) $key)),
                esc_html(is_scalar($value) ? (string) $value : (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES))
            );
        }

        return sprintf(
            '<p class="flux-quote-log__message">%s</p>'
            . '<details class="flux-quote-log__context"><summary>%s</summary>'
            . '<table class="widefat striped"><tbody>%s</tbody></table></details>',
            '' === $item['details'] ? '—' : esc_html($item['details']),
            esc_html__('Technical details', 'flux-quote'),
            $rows
        );
    }

    protected function column_quotation(array $item): string
    {
        $quotation = $this->quotations[(int) $item['post_id']] ?? null;
        if (!$quotation) {
            return sprintf(
                '%s<br><span class="description">#%d</span>',
                esc_html__('Deleted quotation', 'flux-quote'),
                (int) $item['post_id']
            );
        }

        return sprintf(
            '<a href="%s">%s</a><br><code>%s</code>',
            esc_url(DetailPage::url($quotation->post_id)),
            esc_html(AdminUi::customer_name($quotation)),
            esc_html($quotation->public_id)
        );
    }

    protected function column_user(array $item): string
    {
        $context = $this->context($item);
        $user_id = (int) $item['user_id'];
        $user = $user_id ? get_userdata($user_id) : false;

        if ($user) {
            $name = current_user_can('edit_user', $user_id)
                ? sprintf('<a href="%s">%s</a>', esc_url(get_edit_user_link($user_id)), esc_html($user->display_name))
                : esc_html($user->display_name);

            return sprintf(
                '<div class="flux-quote-log__user">%s<span>%s<br><span class="description">%s</span></span></div>',
                get_avatar($user_id, 32),
                $name,
                esc_html(implode(', ', array_map('translate_user_role', $this->role_names($user->roles))))
            );
        }

        if ($user_id) {
            $label = __('Deleted user', 'flux-quote');
        } elseif (Event::Requested->value === $item['event']) {
            $label = __('Customer', 'flux-quote');
        } else {
            $label = __('System', 'flux-quote');
        }

        return sprintf(
            '%s<br><span class="description">%s</span>',
            esc_html($label),
            esc_html($context['ip'] ?? $context['source'] ?? '')
        );
    }

    private function context(array $item): array
    {
        $context = json_decode((string) ($item['context'] ?? ''), true);

        return is_array($context) ? $context : [];
    }

    private function role_names(array $roles): array
    {
        $names = wp_roles()->get_names();

        return array_map(static fn(string $role): string => $names[$role] ?? $role, $roles);
    }

    private function label(string $key): string
    {
        $labels = [
            'source' => __('Source', 'flux-quote'),
            'ip' => __('IP address', 'flux-quote'),
            'user_agent' => __('User agent', 'flux-quote'),
            'referer' => __('Referer', 'flux-quote'),
            'quote_id' => __('Quote ID', 'flux-quote'),
            'endpoint' => __('Endpoint', 'flux-quote'),
            'template_id' => __('Template ID', 'flux-quote'),
            'app_id' => __('App ID', 'flux-quote'),
            'timeout_s' => __('Timeout (s)', 'flux-quote'),
            'duration_ms' => __('Duration (ms)', 'flux-quote'),
            'http_status' => __('HTTP status', 'flux-quote'),
            'content_type' => __('Content type', 'flux-quote'),
            'bytes' => __('Size (bytes)', 'flux-quote'),
            'error_code' => __('Error code', 'flux-quote'),
            'file' => __('File', 'flux-quote'),
            'url' => __('URL', 'flux-quote'),
            'to' => __('To', 'flux-quote'),
            'from' => __('From', 'flux-quote'),
            'subject' => __('Subject', 'flux-quote'),
            'attachment' => __('Attachment', 'flux-quote'),
            'attachment_bytes' => __('Attachment size (bytes)', 'flux-quote'),
            'transport' => __('Transport', 'flux-quote'),
            'smtp' => __('SMTP server', 'flux-quote'),
            'exception_code' => __('PHPMailer exception code', 'flux-quote'),
        ];

        return $labels[$key] ?? $key;
    }
}
