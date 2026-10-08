<?php

declare(strict_types=1);

namespace Flux\Quote;

// phpcs:disable WordPress.DB.DirectDatabaseQuery

final class EventLog
{
    public const PER_PAGE = 50;

    private const DB_VERSION = '2';
    private const VERSION_OPTION = 'flux_quote_events_db_version';

    public function register(): void
    {
        $this->maybe_install();
        add_action('deleted_post', [$this, 'delete_for_post']);
    }

    public function record(int $post_id, Event $event, string $details = '', array $context = []): void
    {
        global $wpdb;

        $wpdb->insert(
            $this->table(),
            [
                'post_id' => $post_id,
                'event' => $event->value,
                'details' => mb_substr($details, 0, 1000),
                'context' => (string) wp_json_encode(array_filter($context + ['source' => $this->source()])),
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s']
        );
    }

    public function query(
        ?int $post_id,
        bool $errors_only,
        ?Event $event,
        string $order,
        int $page,
        int $per_page = self::PER_PAGE,
    ): array {
        global $wpdb;

        [$where, $args] = $this->where($post_id, $errors_only, $event);
        $order = 'ASC' === $order ? 'ASC' : 'DESC';
        $args[] = $per_page;
        $args[] = max(0, $page - 1) * $per_page;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE {$where} ORDER BY created_at {$order}, id {$order} LIMIT %d OFFSET %d",
                $args
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
    }

    public function count(?int $post_id, bool $errors_only, ?Event $event = null): int
    {
        global $wpdb;

        [$where, $args] = $this->where($post_id, $errors_only, $event);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", $args));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

        return (int) $count;
    }

    public function delete_for_post(int $post_id): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['post_id' => $post_id], ['%d']);
    }

    private function maybe_install(): void
    {
        global $wpdb;

        if (self::DB_VERSION === get_option(self::VERSION_OPTION)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(
            "CREATE TABLE {$this->table()} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL,
                event varchar(32) NOT NULL,
                details text NOT NULL,
                context longtext NULL,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY post_id (post_id),
                KEY created_at (created_at)
            ) {$wpdb->get_charset_collate()};"
        );
        update_option(self::VERSION_OPTION, self::DB_VERSION);
    }

    private function where(?int $post_id, bool $errors_only, ?Event $event): array
    {
        $where = ['1 = 1'];
        $args = [$this->table()];

        if ($post_id) {
            $where[] = 'post_id = %d';
            $args[] = $post_id;
        }

        if ($errors_only) {
            $errors = array_values(array_filter(Event::cases(), static fn(Event $event): bool => $event->is_error()));
            $where[] = 'event IN (' . implode(', ', array_fill(0, count($errors), '%s')) . ')';
            array_push($args, ...array_map(static fn(Event $event): string => $event->value, $errors));
        }

        if ($event) {
            $where[] = 'event = %s';
            $args[] = $event->value;
        }

        return [implode(' AND ', $where), $args];
    }

    private function source(): string
    {
        return match (true) {
            defined('WP_CLI') && WP_CLI => 'wp-cli',
            wp_doing_cron() => 'cron',
            defined('REST_REQUEST') && REST_REQUEST => 'rest',
            wp_doing_ajax() => 'ajax',
            is_admin() => 'admin',
            default => 'frontend',
        };
    }

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'flux_quote_events';
    }
}
