<?php

declare(strict_types=1);

namespace Flux\Quote;

final class SettingsPage
{
    public const PAGE = 'flux-settings';

    private const COMPANY_GROUP = 'flux_company';
    private const MAIL_GROUP = 'flux_quote_mail';
    private const PDF_GROUP = 'flux_quote_pdf';
    private const SCRIPT = 'flux-quote-settings';

    private string $hook_suffix = '';

    public function __construct(
        private Adapter $adapter,
        private Company $company,
        private MailTemplate $mail,
        private PdfTemplate $pdf,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function add_page(): void
    {
        $this->hook_suffix = (string) add_submenu_page(
            AdminUi::MENU,
            __('Settings', 'flux-quote'),
            __('Settings', 'flux-quote'),
            'manage_options',
            self::PAGE,
            [$this, 'render']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            self::COMPANY_GROUP,
            Company::OPTION,
            ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_company'], 'default' => []]
        );
        register_setting(
            self::MAIL_GROUP,
            MailTemplate::OPTION,
            ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_mail'], 'default' => []]
        );
        register_setting(
            self::PDF_GROUP,
            PdfTemplate::OPTION,
            ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_pdf'], 'default' => []]
        );
    }

    public function sanitize_company(mixed $input): array
    {
        return $this->company->clean(is_array($input) ? $input : []);
    }

    public function sanitize_mail(mixed $input): array
    {
        $defaults = $this->mail->defaults();

        return array_filter(
            MailTemplate::clean(is_array($input) ? $input : []),
            static fn(string $value, string $key): bool => !in_array(trim($value), ['', trim($defaults[$key])], true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    public function sanitize_pdf(mixed $input): array
    {
        $decoded = $input;
        $errors = [];
        if (is_array($input) && isset($input['template'])) {
            $decoded = json_decode((string) $input['template'], true);
            if (null === $decoded) {
                $errors[] = sprintf(__('The template is not valid JSON: %s', 'flux-quote'), json_last_error_msg());
            } elseif (is_array($decoded)) {
                $decoded['theme'] = (string) ($input['theme'] ?? '');
            }
        }
        $errors = $errors ?: $this->pdf->errors($decoded);

        if ($errors) {
            foreach ($errors as $index => $error) {
                add_settings_error(PdfTemplate::OPTION, 'invalid_' . $index, $error);
            }
            $saved = get_option(PdfTemplate::OPTION, []);

            return is_array($saved) ? $saved : [];
        }

        return $this->pdf->clean($decoded);
    }

    public function enqueue_scripts(string $hook_suffix): void
    {
        if ($hook_suffix !== $this->hook_suffix) {
            return;
        }

        $tab = $this->current_tab();
        $deps = ['wp-api-fetch', 'wp-i18n', 'wp-a11y', 'clipboard'];
        $code_editor = false;
        $css_editor = false;
        if ('general' === $tab) {
            wp_enqueue_media();
        }
        if ('pdf' === $tab) {
            $code_editor = wp_enqueue_code_editor(['type' => 'application/json']);
            $css_editor = wp_enqueue_code_editor(['type' => 'text/css']);
            $deps[] = 'code-editor';
        }

        $plugin_dir = dirname(__DIR__);
        wp_enqueue_script(
            self::SCRIPT,
            plugins_url('assets/settings.js', $plugin_dir . '/flux-quote.php'),
            $deps,
            (string) filemtime($plugin_dir . '/assets/settings.js'),
            true
        );
        wp_set_script_translations(self::SCRIPT, 'flux-quote');
        wp_add_inline_script(
            self::SCRIPT,
            'var fluxQuoteSettings = ' . wp_json_encode(
                [
                    'tab' => $tab,
                    'mailPath' => '/' . $this->adapter->rest_namespace() . '/preview/mail',
                    'pdfPath' => '/' . $this->adapter->rest_namespace() . '/preview/pdf',
                    'schema' => array_diff_key(PdfTemplate::SCHEMA, ['theme' => true]),
                    'blockTypes' => PdfTemplate::BLOCK_TYPES,
                    'codeEditor' => $code_editor,
                    'cssEditor' => $css_editor,
                ]
            ) . ';',
            'before'
        );
    }

    public function render(): void
    {
        $current = $this->current_tab();
        $tabs = [
            'general' => __('General', 'flux-quote'),
            'mail' => __('Mail', 'flux-quote'),
            'pdf' => __('PDF', 'flux-quote'),
        ];

        echo '<div class="wrap flux-quote-settings">';
        printf('<h1>%s</h1>', esc_html(get_admin_page_title()));
        settings_errors();

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $tab => $label) {
            printf(
                '<a href="%s" class="nav-tab%s"%s>%s</a>',
                esc_url(add_query_arg(['page' => self::PAGE, 'tab' => $tab], admin_url('admin.php'))),
                $tab === $current ? ' nav-tab-active' : '',
                $tab === $current ? ' aria-current="page"' : '',
                esc_html($label)
            );
        }
        echo '</nav>';

        match ($current) {
            'mail' => $this->render_mail(),
            'pdf' => $this->render_pdf(),
            default => $this->render_general(),
        };
        echo '</div>';
    }

    private function render_general(): void
    {
        $settings = $this->company->settings();
        $types = ['email' => 'email', 'website' => 'url', 'logo' => 'url'];

        echo '<form method="post" action="options.php">';
        settings_fields(self::COMPANY_GROUP);
        printf(
            '<div class="flux-quote-settings__card"><h2>%s</h2><p class="description">%s</p>'
            . '<table class="form-table" role="presentation"><tbody>',
            esc_html__('Company details', 'flux-quote'),
            esc_html__('Used in the quotation mail and PDF through the {company_…} placeholders.', 'flux-quote')
        );
        foreach ($this->company->fields() as $key => $label) {
            printf(
                '<tr><th scope="row"><label for="flux-company-%1$s">%2$s</label></th><td>'
                . '<input type="%3$s" class="regular-text" id="flux-company-%1$s" name="%4$s" value="%5$s">',
                esc_attr($key),
                esc_html($label),
                esc_attr($types[$key] ?? 'text'),
                esc_attr(Company::OPTION . '[' . $key . ']'),
                esc_attr($settings[$key])
            );
            if ('logo' === $key) {
                printf(
                    ' <button type="button" class="button flux-quote-settings__media">%s</button>'
                    . '<p><img class="flux-quote-settings__logo" src="%s" alt=""%s></p>',
                    esc_html__('Choose image', 'flux-quote'),
                    esc_url($settings['logo']),
                    '' === $settings['logo'] ? ' hidden' : ''
                );
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        $this->render_save_bar();
        echo '</form>';
    }

    private function render_mail(): void
    {
        $settings = $this->mail->settings();
        $name = MailTemplate::OPTION;

        echo '<form method="post" action="options.php" class="flux-quote-settings__form">';
        settings_fields(self::MAIL_GROUP);
        $this->open_section(
            __('Quotation mail', 'flux-quote'),
            __(
                'Sent to the customer with the quotation PDF attached. Empty a field to restore its default text.',
                'flux-quote'
            ),
            __('Message', 'flux-quote'),
            ''
        );

        printf(
            '<div class="flux-quote-settings__field">'
            . '<label class="flux-quote-settings__label" for="flux-quote-mail-subject">%s</label>'
            . '<input type="text" class="flux-quote-settings__input" id="flux-quote-mail-subject" name="%s" value="%s">'
            . '</div>',
            esc_html__('Subject', 'flux-quote'),
            esc_attr($name . '[subject]'),
            esc_attr($settings['subject'])
        );
        printf(
            '<div class="flux-quote-settings__field flux-quote-settings__field--body">'
            . '<span class="flux-quote-settings__label">%s</span>',
            esc_html__('Body', 'flux-quote')
        );
        wp_editor(
            $settings['body'],
            'flux_quote_mail_body',
            ['textarea_name' => $name . '[body]', 'textarea_rows' => 12, 'media_buttons' => false]
        );
        echo '</div>';

        $this->render_placeholders($this->mail->placeholders());

        $this->render_preview(
            'mail',
            '',
            sprintf(
                '<div class="flux-quote-settings__inbox"><span>%s</span><strong class="flux-quote-settings__subject">'
                . '</strong></div><iframe sandbox="allow-same-origin" title="%s"></iframe>',
                esc_html__('Subject', 'flux-quote'),
                esc_attr__('Mail preview', 'flux-quote')
            )
        );
        $this->render_save_bar();
        echo '</form>';
    }

    private function render_pdf(): void
    {
        $json = static fn(array $value): string => (string) wp_json_encode(
            array_diff_key($value, ['theme' => true]),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $settings = $this->pdf->settings();

        echo '<form method="post" action="options.php" class="flux-quote-settings__form">';
        settings_fields(self::PDF_GROUP);
        $this->open_section('', '', __('Theme', 'flux-quote'), '');

        printf(
            '<textarea id="flux-quote-pdf-theme" name="%s" class="large-text code flux-quote-settings__theme" rows="8">'
            . '%s</textarea>',
            esc_attr(PdfTemplate::OPTION . '[theme]'),
            esc_textarea($settings['theme'])
        );

        printf(
            '<div class="flux-quote-settings__toolbar"><h3>%s</h3><span class="flux-quote-settings__actions">'
            . '<a href="https://pdf-generator.3dconfig.io/components" target="_blank" rel="noopener">%s'
            . '<span class="screen-reader-text"> %s</span>'
            . '<span aria-hidden="true" class="dashicons dashicons-external"></span></a>'
            . '<button type="button" class="button-link flux-quote-settings__reset">%s</button></span></div>',
            esc_html__('Template', 'flux-quote'),
            esc_html__('Components', 'flux-quote'),
            esc_html__('(opens in a new tab)', 'flux-quote'),
            esc_html__('Reset to default', 'flux-quote')
        );

        printf(
            '<textarea id="flux-quote-pdf-template" name="%s" class="large-text code" rows="18" data-defaults="%s">'
            . '%s</textarea>',
            esc_attr(PdfTemplate::OPTION . '[template]'),
            esc_attr($json($this->pdf->defaults())),
            esc_textarea($json($settings))
        );
        echo '<div class="notice notice-error inline flux-quote-settings__errors" role="alert" hidden><ul></ul></div>';
        $this->render_placeholders($this->company->placeholders());

        $this->render_preview(
            'pdf',
            sprintf(
                '<a href="#" target="_blank" rel="noopener" data-open hidden>%s'
                . '<span class="screen-reader-text"> %s</span>'
                . '<span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
                esc_html__('Open PDF', 'flux-quote'),
                esc_html__('(opens in a new tab)', 'flux-quote')
            ),
            sprintf('<iframe title="%s"></iframe>', esc_attr__('PDF preview', 'flux-quote'))
        );
        $this->render_save_bar();
        echo '</form>';
    }

    private function render_placeholders(array $placeholders): void
    {
        printf(
            '<details class="flux-quote-settings__placeholders" open><summary>%s</summary>'
            . '<p class="description">%s</p><dl>',
            esc_html__('Placeholders', 'flux-quote'),
            esc_html__('Click a placeholder to copy it.', 'flux-quote')
        );
        foreach ($placeholders as $placeholder => $description) {
            printf(
                '<dt><button type="button" class="flux-quote-chip" data-clipboard-text="%1$s">%1$s</button></dt>'
                . '<dd>%2$s</dd>',
                esc_attr($placeholder),
                esc_html($description)
            );
        }
        echo '</dl></details>';
    }

    private function open_section(string $title, string $description, string $editor_title, string $actions): void
    {
        printf(
            '<div class="flux-quote-settings__card">%s'
            . '<div class="flux-quote-settings__columns"><div class="flux-quote-settings__editor">'
            . '<div class="flux-quote-settings__toolbar"><h3>%s</h3>%s</div>',
            '' === $title
                ? ''
                : sprintf('<h2>%s</h2><p class="description">%s</p>', esc_html($title), esc_html($description)),
            esc_html($editor_title),
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
            $actions
        );
    }

    private function render_preview(string $type, string $toolbar, string $content): void
    {
        printf(
            '</div><div class="flux-quote-settings__preview" data-preview="%s">'
            . '<div class="flux-quote-settings__toolbar"><h3>%s</h3>%s</div>'
            . '<div class="notice notice-error inline" role="alert" hidden><p></p></div>'
            . '<div class="flux-quote-settings__frame">%s<div class="flux-quote-settings__loading">'
            . '<span class="spinner is-active"></span></div></div>'
            . '</div></div></div>',
            esc_attr($type),
            esc_html__('Preview', 'flux-quote'),
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
            $toolbar,
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
            $content
        );
    }

    private function render_save_bar(): void
    {
        echo '<div class="flux-quote-settings__bar">';
        submit_button(null, 'primary', 'submit', false);
        echo '</div>';
    }

    private function current_tab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? ''));

        return in_array($tab, ['mail', 'pdf'], true) ? $tab : 'general';
    }
}
