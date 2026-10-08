<?php

declare(strict_types=1);

namespace Flux\Quote;

final class Company
{
    public const OPTION = 'flux_company';

    public function fields(): array
    {
        return [
            'name' => __('Name', 'flux-quote'),
            'email' => __('Email', 'flux-quote'),
            'phone' => __('Phone', 'flux-quote'),
            'street' => __('Street', 'flux-quote'),
            'number' => __('Number', 'flux-quote'),
            'postalCode' => __('Postal code', 'flux-quote'),
            'city' => __('City', 'flux-quote'),
            'country' => __('Country', 'flux-quote'),
            'website' => __('Website', 'flux-quote'),
            'logo' => __('Logo', 'flux-quote'),
        ];
    }

    public function settings(): array
    {
        $saved = get_option(self::OPTION, []);
        $saved = array_filter(
            is_array($saved) ? $saved : [],
            static fn($value): bool => is_string($value) && '' !== $value
        );

        return array_intersect_key($saved, $this->fields()) + [
            'name' => wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES),
            'email' => (string) get_option('admin_email'),
            'website' => home_url(),
        ] + array_fill_keys(array_keys($this->fields()), '');
    }

    public function clean(array $input): array
    {
        $clean = [];
        foreach (array_keys($this->fields()) as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            $clean[$key] = match ($key) {
                'email' => sanitize_email($value),
                'website', 'logo' => esc_url_raw($value),
                default => sanitize_text_field($value),
            };
        }

        return array_filter($clean, static fn(string $value): bool => '' !== $value);
    }

    public function placeholders(): array
    {
        return [
            '{company_name}' => __('Company name', 'flux-quote'),
            '{company_email}' => __('Company email', 'flux-quote'),
            '{company_phone}' => __('Company phone', 'flux-quote'),
            '{company_address}' => __('Company address on one line', 'flux-quote'),
            '{company_website}' => __('Company website', 'flux-quote'),
            '{company_logo}' => __('Company logo URL', 'flux-quote'),
        ];
    }

    public function values(): array
    {
        $company = $this->settings();
        $street = trim($company['street'] . ' ' . $company['number']);
        $city = trim($company['postalCode'] . ' ' . $company['city']);

        return [
            '{company_name}' => $company['name'],
            '{company_email}' => $company['email'],
            '{company_phone}' => $company['phone'],
            '{company_address}' => implode(', ', array_filter([$street, $city, $company['country']])),
            '{company_website}' => $company['website'],
            '{company_logo}' => $company['logo'],
        ];
    }
}
