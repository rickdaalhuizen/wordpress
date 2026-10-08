<?php

/**
 * Builds the quotation mail from the subject and body edited in the admin, wrapped in an HTML layout
 * that themes can override.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

final class MailTemplate
{
    public const OPTION = 'flux_quote_mail';

    private const THEME_TEMPLATE = 'flux-quote/emails/quotation.php';

    public function __construct(private ?array $overrides = null, private Company $company = new Company())
    {
    }

    /**
     * Placeholders available in the subject and body, with what they are replaced by.
     */
    public function placeholders(): array
    {
        return [
            '{first_name}' => __('Contact first name', 'flux-quote'),
            '{last_name}' => __('Contact last name', 'flux-quote'),
            '{email}' => __('Contact email', 'flux-quote'),
            '{quote_id}' => __('Quotation public ID', 'flux-quote'),
            '{date}' => __('Quotation date', 'flux-quote'),
            '{site_name}' => __('Site name', 'flux-quote'),
        ] + $this->company->placeholders();
    }

    public function defaults(): array
    {
        return [
            'subject' => __('[{site_name}] Your quotation', 'flux-quote'),
            'body' => __(
                "Hello {first_name},\n\nThank you for your request. Please find your quotation attached.\n\nBest regards,\n{site_name}",
                'flux-quote'
            ),
        ];
    }

    /**
     * The saved subject and body; a field left empty falls back to its translated default.
     */
    public function settings(): array
    {
        $saved = $this->overrides ?? get_option(self::OPTION, []);
        $saved = array_filter(
            is_array($saved) ? $saved : [],
            static fn($value): bool => is_string($value) && '' !== trim($value)
        );

        return array_merge($this->defaults(), array_intersect_key($saved, $this->defaults()));
    }

    public static function clean(array $input): array
    {
        return [
            'subject' => sanitize_text_field((string) ($input['subject'] ?? '')),
            'body' => wp_kses_post(str_replace("\r\n", "\n", (string) ($input['body'] ?? ''))),
        ];
    }

    public function subject(Quotation $quotation): string
    {
        // A line break in the subject could inject mail headers.
        return str_replace(["\r", "\n"], ' ', strtr($this->settings()['subject'], $this->values($quotation)));
    }

    public function message(Quotation $quotation): string
    {
        // Contact values come from the public form, so they are escaped before landing in the HTML.
        $content = wpautop(strtr($this->settings()['body'], array_map('esc_html', $this->values($quotation))));

        ob_start();
        load_template(
            locate_template(self::THEME_TEMPLATE) ?: dirname(__DIR__) . '/templates/emails/quotation.php',
            false,
            [
                'content' => $content,
                'subject' => $this->subject($quotation),
                'site_name' => $this->site_name(),
                'quotation' => $quotation,
            ]
        );

        return (string) ob_get_clean();
    }

    private function values(Quotation $quotation): array
    {
        return [
            '{first_name}' => (string) ($quotation->contact['firstName'] ?? ''),
            '{last_name}' => (string) ($quotation->contact['lastName'] ?? ''),
            '{email}' => (string) ($quotation->contact['email'] ?? ''),
            '{quote_id}' => $quotation->public_id,
            '{date}' => mysql2date((string) get_option('date_format'), $quotation->created_at),
            '{site_name}' => $this->site_name(),
        ] + $this->company->values();
    }

    private function site_name(): string
    {
        return wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
    }
}
