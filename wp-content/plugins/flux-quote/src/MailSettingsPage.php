<?php

/**
 * Admin page under Quotations to edit the subject and body of the quotation mail.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

final class MailSettingsPage
{
    private const PAGE = 'flux-quote-mail';
    private const GROUP = 'flux_quote_mail';

    public function __construct(private MailTemplate $template)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_page']);
        add_action('admin_init', [$this, 'register_setting']);
    }

    public function add_page(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . Repository::QUOTATION_TYPE,
            __('Mail settings', 'flux-quote'),
            __('Mail settings', 'flux-quote'),
            'manage_options',
            self::PAGE,
            [$this, 'render']
        );
    }

    public function register_setting(): void
    {
        register_setting(
            self::GROUP,
            MailTemplate::OPTION,
            ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize'], 'default' => []]
        );
    }

    /**
     * Keeps only what differs from the defaults, so untouched fields keep following translations.
     */
    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $defaults = $this->template->defaults();
        $values = [
            'subject' => sanitize_text_field((string) ($input['subject'] ?? '')),
            'body' => wp_kses_post(str_replace("\r\n", "\n", (string) ($input['body'] ?? ''))),
        ];

        return array_filter(
            $values,
            static fn(string $value, string $key): bool => !in_array(trim($value), ['', trim($defaults[$key])], true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    public function render(): void
    {
        $settings = $this->template->settings();
        $name = MailTemplate::OPTION;

        echo '<div class="wrap"><h1>' . esc_html(get_admin_page_title()) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);

        echo '<table class="form-table" role="presentation"><tbody>';

        printf(
            '<tr><th scope="row"><label for="flux-quote-mail-subject">%s</label></th>'
            . '<td><input type="text" class="large-text" id="flux-quote-mail-subject" name="%s" value="%s"></td></tr>',
            esc_html__('Subject', 'flux-quote'),
            esc_attr($name . '[subject]'),
            esc_attr($settings['subject'])
        );

        echo '<tr><th scope="row">' . esc_html__('Body', 'flux-quote') . '</th><td>';
        wp_editor(
            $settings['body'],
            'flux_quote_mail_body',
            ['textarea_name' => $name . '[body]', 'textarea_rows' => 12, 'media_buttons' => false]
        );
        printf('<p class="description">%s</p>', esc_html__('Empty a field to restore its default text.', 'flux-quote'));
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Placeholders', 'flux-quote') . '</th><td><ul>';
        foreach ($this->template->placeholders() as $placeholder => $description) {
            printf('<li><code>%s</code> %s</li>', esc_html($placeholder), esc_html($description));
        }
        echo '</ul></td></tr>';

        echo '</tbody></table>';
        submit_button();
        echo '</form></div>';
    }
}
