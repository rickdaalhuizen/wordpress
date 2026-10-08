<?php

declare(strict_types=1);

namespace Flux\Quote;

use WP_Post;

final class DetailPage
{
    private const PAGE = 'flux-quote-view';
    private const UPDATE_ACTION = 'flux_quote_update';
    private const SEND_EMAIL = 'send_email';
    private const HISTORY_ITEMS = 5;

    private string $hook_suffix = '';

    public function __construct(
        private Repository $repository,
        private Delivery $delivery,
        private EventLog $events,
    ) {
    }

    public static function url(int $post_id): string
    {
        return add_query_arg(['page' => self::PAGE, 'id' => $post_id], admin_url('admin.php'));
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_page']);
        add_action('admin_head', [$this, 'hide_menu_item']);
        add_filter('submenu_file', [$this, 'highlight_menu']);
        add_action('admin_post_' . self::UPDATE_ACTION, [$this, 'handle_update']);
        add_action('admin_notices', [$this, 'render_notice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function add_page(): void
    {
        $this->hook_suffix = (string) add_submenu_page(
            AdminUi::MENU,
            __('Details', 'flux-quote'),
            __('Details', 'flux-quote'),
            get_post_type_object(Repository::QUOTATION_TYPE)->cap->edit_posts,
            self::PAGE,
            [$this, 'render']
        );
    }

    public function enqueue_scripts(string $hook_suffix): void
    {
        if ($hook_suffix !== $this->hook_suffix) {
            return;
        }

        wp_enqueue_script('clipboard');
        wp_enqueue_script('wp-a11y');
        $copied = wp_json_encode(__('Copied to clipboard.', 'flux-quote'));
        $copy_script = <<<JS
            document.addEventListener('DOMContentLoaded', function () {
                new ClipboardJS('.flux-quote-copy').on('success', function (event) {
                    var button = event.trigger;
                    var label = button.textContent;
                    event.clearSelection();
                    button.focus();
                    button.textContent = button.dataset.copied;
                    wp.a11y.speak({$copied});
                    setTimeout(function () {
                        button.textContent = label;
                    }, 2000);
                });
            });
            JS;
        wp_add_inline_script('clipboard', $copy_script);

        $settings = wp_enqueue_code_editor(
            [
                'type' => 'application/json',
                'codemirror' => [
                    'readOnly' => true,
                    'lint' => false,
                    'lineNumbers' => true,
                    'foldGutter' => true,
                    'gutters' => ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
                ],
            ]
        );
        if (false === $settings) {
            return;
        }

        $script = <<<'JS'
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.flux-quote-technical').forEach(function (details) {
                    var start = function () {
                        if (!details.open || details.dataset.editor) {
                            return;
                        }
                        details.dataset.editor = '1';
                        details.querySelectorAll('pre.flux-quote-json').forEach(function (pre) {
                            var options = Object.assign({}, settings.codemirror, { value: pre.textContent });
                            wp.CodeMirror(function (editor) {
                                pre.replaceWith(editor);
                            }, options);
                        });
                    };
                    details.addEventListener('toggle', start);
                    start();
                });
            });
            JS;

        wp_add_inline_script(
            'code-editor',
            '(function (settings) {' . $script . '})(' . wp_json_encode($settings) . ');'
        );
    }

    public function hide_menu_item(): void
    {
        remove_submenu_page(AdminUi::MENU, self::PAGE);
    }

    public function highlight_menu(?string $submenu_file): ?string
    {
        global $plugin_page;

        if (self::PAGE !== $plugin_page) {
            return $submenu_file;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $post_type = get_post_type(absint($_GET['id'] ?? 0));

        return $post_type ? 'edit.php?post_type=' . $post_type : $submenu_file;
    }

    public function render(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $post = get_post(absint($_GET['id'] ?? 0));
        if ($post && Repository::CONFIGURATION_TYPE === $post->post_type) {
            $this->render_configuration($this->allowed_post($post->ID, Repository::CONFIGURATION_TYPE));
            return;
        }

        $post = $this->allowed_post($post?->ID ?? 0, Repository::QUOTATION_TYPE);
        $quotation = $this->repository->to_quotation($post);

        echo '<div class="wrap flux-quote-detail">';
        printf('<h1 class="wp-heading-inline">%s</h1>', esc_html(AdminUi::customer_name($quotation)));
        echo '<hr class="wp-header-end">';

        echo '<div id="poststuff"><div id="post-body" class="metabox-holder columns-2">';
        echo '<div id="post-body-content">';
        $this->render_sheet($post, $quotation);
        $this->render_technical_data(
            __('Technical data', 'flux-quote'),
            [
                __('Configuration', 'flux-quote') => $quotation->configuration,
                __('Document', 'flux-quote') => $quotation->document,
            ]
        );
        echo '</div><div id="postbox-container-1" class="postbox-container flux-quote-no-print">';
        $this->render_quote_box($post->ID, $quotation);
        $this->render_history_box($post->ID);
        echo '</div></div></div></div>';
    }

    public function handle_update(): void
    {
        $post_id = absint($_POST['quotation'] ?? 0);
        check_admin_referer(self::UPDATE_ACTION . '_' . $post_id);
        $quotation = $this->repository->to_quotation($this->allowed_post($post_id, Repository::QUOTATION_TYPE));

        $status = QuoteStatus::tryFrom(sanitize_key(wp_unslash($_POST['status'] ?? '')));
        if ($status && $status !== $quotation->status) {
            $this->repository->save_status($post_id, $status);
            $this->events->record(
                $post_id,
                Event::StatusChanged,
                $quotation->status->label() . ' → ' . $status->label(),
                ['from' => $quotation->status->value, 'to' => $status->value]
            );
            $quotation = $this->repository->to_quotation(get_post($post_id));
        }

        $result = ['flux_updated' => 1];
        if (self::SEND_EMAIL === sanitize_key(wp_unslash($_POST['quote_action'] ?? ''))) {
            $result['flux_mail'] = $this->delivery->deliver($quotation)->mail_status->value;
        }

        wp_safe_redirect(add_query_arg($result, self::url($post_id)));
        exit;
    }

    public function render_notice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['flux_updated'])) {
            return;
        }
        $mail = MailStatus::tryFrom(sanitize_key($_GET['flux_mail'] ?? ''));
        // phpcs:enable

        [$type, $message] = match (true) {
            null === $mail => ['success', __('Quote updated.', 'flux-quote')],
            MailStatus::Sent === $mail => ['success', __('Quote updated. Mail sent to the client.', 'flux-quote')],
            MailStatus::Failed === $mail => [
                'error',
                __('Quote updated, but the mail could not be sent. See the history for the reason.', 'flux-quote'),
            ],
            default => [
                'warning',
                __('Quote updated, but no mail was sent. See the history for the reason.', 'flux-quote'),
            ],
        };

        printf(
            '<div class="notice notice-%s is-dismissible" role="status"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    private function allowed_post(int $post_id, string $post_type): WP_Post
    {
        $post = get_post($post_id);
        if (!$post || $post_type !== $post->post_type || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('You are not allowed to view this item.', 'flux-quote'), 403);
        }

        return $post;
    }

    private function render_configuration(WP_Post $post): void
    {
        $configuration = $this->repository->to_configuration($post);
        $product = AdminUi::product_label($configuration['data']);

        echo '<div class="wrap flux-quote-detail">';
        printf('<h1 class="wp-heading-inline">%s</h1>', esc_html($product ?: $post->post_title));
        echo '<hr class="wp-header-end">';

        echo '<div id="poststuff"><div id="post-body" class="metabox-holder columns-2">';
        echo '<div id="post-body-content">';
        $this->render_technical_data(
            __('Configuration data', 'flux-quote'),
            [__('Configuration', 'flux-quote') => $configuration['data']],
            true
        );

        echo '</div><div id="postbox-container-1" class="postbox-container flux-quote-no-print">';
        echo '<div id="submitdiv" class="postbox"><div class="postbox-header"><h2 class="hndle">'
            . esc_html__('Configuration', 'flux-quote') . '</h2></div><div class="inside"><div class="submitbox">';
        echo '<div id="misc-publishing-actions">';
        printf(
            '<div class="misc-pub-section">%s <strong><time datetime="%s">%s</time></strong></div>',
            esc_html__('Date:', 'flux-quote'),
            esc_attr((string) get_post_time('c', true, $post)),
            esc_html((string) get_the_date('', $post))
        );
        printf(
            '<div class="misc-pub-section">%s <code>%s</code> '
            . '<button type="button" class="button-link flux-quote-copy" data-clipboard-text="%s" data-copied="%s" '
            . 'aria-label="%s">%s</button></div>',
            esc_html__('Share code:', 'flux-quote'),
            esc_html($configuration['cid']),
            esc_attr($configuration['cid']),
            esc_attr__('Copied!', 'flux-quote'),
            esc_attr__('Copy share code', 'flux-quote'),
            esc_html__('Copy', 'flux-quote')
        );
        echo '</div><div id="major-publishing-actions"><div id="delete-action">';
        $this->render_trash_link($post->ID);
        echo '</div><div class="clear"></div></div></div></div></div>';
        echo '</div></div></div></div>';
    }

    private function render_trash_link(int $post_id): void
    {
        $trash_url = get_delete_post_link($post_id);
        if ($trash_url) {
            printf(
                '<a class="submitdelete deletion" href="%s">%s</a>',
                esc_url($trash_url),
                esc_html__('Move to Trash', 'flux-quote')
            );
        }
    }

    private function render_sheet(WP_Post $post, Quotation $quotation): void
    {
        $document = $quotation->document;
        $contact = $quotation->contact;

        echo '<article class="flux-quote-sheet">';

        echo '<header class="flux-quote-sheet__header"><div>';
        printf('<h2>%s</h2>', esc_html($document['header']['headerInfo'] ?? __('Quotation', 'flux-quote')));
        $this->render_fields(
            [
                __('Number', 'flux-quote') => esc_html($quotation->public_id),
                __('Date', 'flux-quote') => sprintf(
                    '<time datetime="%s">%s</time>',
                    esc_attr((string) get_post_time('c', true, $post)),
                    esc_html((string) get_the_date('', $post))
                ),
                __('PDF', 'flux-quote') => $quotation->pdf_url
                    ? sprintf(
                        '<a href="%s" target="_blank" rel="noopener">%s</a>',
                        esc_url($quotation->pdf_url),
                        esc_html__('Download PDF', 'flux-quote')
                    )
                    : esc_html__('Not generated yet', 'flux-quote'),
            ]
        );
        $email = (string) ($contact['email'] ?? '');
        $lines = [
            esc_html(AdminUi::customer_name($quotation)),
            esc_html(implode(', ', $this->address_lines($document['header']['address'] ?? $contact['address'] ?? ''))),
            $email ? sprintf('<a href="%s">%s</a>', esc_url('mailto:' . $email), esc_html($email)) : '',
            esc_html((string) ($contact['phone'] ?? '')),
        ];
        printf('</div><div><h2>%s</h2><p>', esc_html__('Customer', 'flux-quote'));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each line is escaped above.
        echo implode('<br>', array_filter($lines));
        echo '</p></div></header>';

        foreach ($document['blocks'] ?? [] as $block) {
            if ('table' === ($block['type'] ?? '')) {
                $this->render_table($block['data'] ?? []);
            }
        }

        if (!empty($contact['remarks'])) {
            printf(
                '<section class="flux-quote-sheet__section"><h3>%s</h3><p>%s</p></section>',
                esc_html__('Remarks', 'flux-quote'),
                nl2br(esc_html($contact['remarks']))
            );
        }

        $footer = array_filter($document['footer'] ?? [], 'is_string');
        if ($footer) {
            printf('<footer class="flux-quote-sheet__footer">%s</footer>', esc_html(implode(' · ', $footer)));
        }

        echo '</article>';
    }

    private function render_table(array $data): void
    {
        echo '<section class="flux-quote-sheet__section">';
        if (!empty($data['title'])) {
            printf('<h3>%s</h3>', esc_html($data['title']));
        }
        if (!empty($data['description'])) {
            printf('<p class="description">%s</p>', esc_html($data['description']));
        }

        echo '<table class="widefat striped"><thead><tr>';
        foreach ($data['header'] ?? [] as $heading) {
            printf('<th scope="col">%s</th>', esc_html((string) $heading));
        }
        echo '</tr></thead><tbody>';
        foreach ($data['items'] ?? [] as $row) {
            echo '<tr>';
            foreach ((array) $row as $cell) {
                printf('<td>%s</td>', esc_html(is_scalar($cell) ? (string) $cell : ''));
            }
            echo '</tr>';
        }
        echo '</tbody></table></section>';
    }

    private function render_technical_data(string $title, array $blocks, bool $open = false): void
    {
        printf(
            '<details class="postbox flux-quote-technical flux-quote-no-print%s"%s>',
            $open ? ' flux-quote-technical--main' : '',
            $open ? ' open' : ''
        );
        printf(
            '<summary class="postbox-header"><h2 class="hndle">%s</h2></summary><div class="inside">',
            esc_html($title)
        );
        foreach ($blocks as $heading => $data) {
            if (count($blocks) > 1) {
                printf('<h3>%s</h3>', esc_html($heading));
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in json().
            echo AdminUi::json($data);
        }
        echo '</div></details>';
    }

    private function render_quote_box(int $post_id, Quotation $quotation): void
    {
        printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
        printf('<input type="hidden" name="action" value="%s">', esc_attr(self::UPDATE_ACTION));
        printf('<input type="hidden" name="quotation" value="%s">', esc_attr((string) $post_id));
        wp_nonce_field(self::UPDATE_ACTION . '_' . $post_id);

        echo '<div id="submitdiv" class="postbox"><div class="postbox-header"><h2 class="hndle">'
            . esc_html__('Quote', 'flux-quote') . '</h2></div><div class="inside"><div class="submitbox">';

        echo '<div class="flux-quote-box-fields">';
        printf(
            '<p><label for="flux-quote-status">%s</label><select id="flux-quote-status" name="status">',
            esc_html__('Status', 'flux-quote')
        );
        foreach (QuoteStatus::cases() as $status) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($status->value),
                selected($status->value, $quotation->status->value, false),
                esc_html($status->label())
            );
        }
        echo '</select></p>';

        printf(
            '<p><label for="flux-quote-action">%s</label><select id="flux-quote-action" name="quote_action">'
            . '<option value="">%s</option><option value="%s">%s</option></select></p>',
            esc_html__('Action', 'flux-quote'),
            esc_html__('Choose an action…', 'flux-quote'),
            esc_attr(self::SEND_EMAIL),
            MailStatus::Sent === $quotation->mail_status
                ? esc_html__('Resend email to client', 'flux-quote')
                : esc_html__('Send email to client', 'flux-quote')
        );
        echo '</div>';

        echo '<div id="major-publishing-actions"><div id="delete-action">';
        $this->render_trash_link($post_id);
        printf(
            '</div><div id="publishing-action"><button type="submit" class="button button-primary button-large">'
            . '%s</button></div><div class="clear"></div></div>',
            esc_html__('Update', 'flux-quote')
        );

        echo '</div></div></div></form>';
    }

    private function render_history_box(int $post_id): void
    {
        $rows = $this->events->query($post_id, false, null, 'DESC', 1, self::HISTORY_ITEMS);

        echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">'
            . esc_html__('History', 'flux-quote') . '</h2></div><div class="inside">';

        if (!$rows) {
            echo '<p>' . esc_html__('No events yet.', 'flux-quote') . '</p>';
        } else {
            echo '<ul class="flux-quote-history">';
            foreach ($rows as $row) {
                $event = Event::tryFrom($row['event']);
                $timestamp = (int) strtotime($row['created_at'] . ' UTC');
                printf(
                    '<li><span class="flux-quote-event flux-quote-event--%s">%s</span>'
                    . '<time datetime="%s">%s</time></li>',
                    esc_attr($event?->tone() ?? 'info'),
                    esc_html($event?->label() ?? $row['event']),
                    esc_attr(gmdate('c', $timestamp)),
                    esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp))
                );
            }
            echo '</ul>';
        }

        printf(
            '<p><a href="%s">%s</a></p></div></div>',
            esc_url(AdminUi::history_url($post_id)),
            esc_html__('View all', 'flux-quote')
        );
    }

    private function address_lines(mixed $address): array
    {
        if (!is_array($address)) {
            return array_filter(array_map('trim', explode("\n", (string) $address)));
        }

        $part = static fn(string $key): string => is_scalar($address[$key] ?? null) ? (string) $address[$key] : '';

        return array_filter(
            [
                trim($part('street') . ' ' . $part('number')),
                trim($part('postalCode') . ' ' . $part('city')),
                $part('country'),
            ]
        );
    }

    private function render_fields(array $rows): void
    {
        echo '<dl class="flux-quote-fields">';
        foreach ($rows as $label => $value) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            printf('<dt>%s</dt><dd>%s</dd>', esc_html($label), '' === $value ? '—' : $value);
        }
        echo '</dl>';
    }
}
