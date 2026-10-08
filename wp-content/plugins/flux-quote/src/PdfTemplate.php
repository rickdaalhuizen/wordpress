<?php

declare(strict_types=1);

namespace Flux\Quote;

final class PdfTemplate
{
    public const OPTION = 'flux_quote_pdf';

    public const SCHEMA = [
        'theme' => true,
        'header' => [
            'headerInfo' => true,
            'name' => true,
            'email' => true,
            'phone' => true,
            'address' => ['street' => true, 'number' => true, 'postalCode' => true, 'city' => true, 'country' => true],
            'logo' => true,
        ],
        'footer' => ['zoneA' => true, 'zoneB' => true],
        'blocks' => self::BLOCKS,
    ];

    public const BLOCKS = 'blocks';

    public const QUOTATION_BLOCK = 'quotation';

    public const BLOCK_TYPES = [
        self::QUOTATION_BLOCK,
        'header',
        'table',
        'list',
        'item-cards',
        'images',
        'highlight-text',
        'highlight-blocks',
        'html',
        'page-break',
    ];

    public function __construct(private ?array $overrides = null, private Company $company = new Company())
    {
    }

    public function defaults(): array
    {
        return [
            'theme' => '',
            'header' => [
                'headerInfo' => '',
                'name' => '',
                'email' => '',
                'phone' => '',
                'address' => ['street' => '', 'number' => '', 'postalCode' => '', 'city' => '', 'country' => ''],
                'logo' => '{company_logo}',
            ],
            'footer' => [
                'zoneA' => '{company_address} | {company_email} | {company_phone}',
                'zoneB' => '{company_website}',
            ],
            'blocks' => [['type' => self::QUOTATION_BLOCK]],
        ];
    }

    public function settings(): array
    {
        $saved = $this->overrides ?? get_option(self::OPTION, []);

        return $this->merge(is_array($saved) ? $saved : []);
    }

    public function theme(): string
    {
        return $this->settings()['theme'];
    }

    public function apply(array $document): array
    {
        $settings = $this->settings();
        $values = $this->company->values();
        array_walk_recursive($settings, static function (mixed &$value) use ($values): void {
            $value = is_string($value) ? strtr($value, $values) : $value;
        });
        $settings['header']['logo'] = esc_url_raw($settings['header']['logo']);

        foreach (['header', 'footer'] as $part) {
            $own = is_array($document[$part] ?? null) ? $document[$part] : [];
            $fields = $this->filled($settings[$part]);
            if ($fields) {
                $document[$part] = array_replace_recursive($own, $fields);
            }
        }

        $own_blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        $blocks = $settings['blocks'];
        if (!in_array(self::QUOTATION_BLOCK, array_column($blocks, 'type'), true)) {
            array_unshift($blocks, ['type' => self::QUOTATION_BLOCK]);
        }
        $document['blocks'] = array_merge(
            ...array_map(
                static fn(array $block): array => self::QUOTATION_BLOCK === $block['type'] ? $own_blocks : [$block],
                $blocks
            )
        );

        return $document;
    }

    public function errors(mixed $input): array
    {
        return $this->check($input, self::SCHEMA, '');
    }

    public function clean(array $input): array
    {
        $settings = $this->merge($input);
        $header = [];
        foreach ($settings['header'] as $key => $value) {
            $header[$key] = match ($key) {
                'logo' => sanitize_text_field($value),
                'address' => array_map('sanitize_text_field', $value),
                default => sanitize_text_field($value),
            };
        }

        return [
            'theme' => trim(wp_strip_all_tags($settings['theme'])),
            'header' => $header,
            'footer' => array_map('sanitize_text_field', $settings['footer']),
            'blocks' => $settings['blocks'],
        ];
    }

    private function merge(array $input): array
    {
        $settings = array_replace_recursive($this->defaults(), $input);
        $blocks = $input['blocks'] ?? null;
        $settings['blocks'] = is_array($blocks) && array_is_list($blocks) ? $blocks : $this->defaults()['blocks'];

        return $settings;
    }

    private function filled(array $fields): array
    {
        $fields = array_map(
            fn(mixed $value): mixed => is_array($value) ? $this->filled($value) : trim((string) $value),
            $fields
        );

        return array_filter($fields, static fn(mixed $value): bool => '' !== $value && [] !== $value);
    }

    private function check(mixed $value, array|string|bool $schema, string $path): array
    {
        if (true === $schema) {
            return is_string($value) ? [] : [sprintf(__('%s must be text.', 'flux-quote'), $path)];
        }
        if (self::BLOCKS === $schema) {
            return $this->check_blocks($value, $path);
        }
        if (!$this->is_object($value)) {
            return [$this->not_an_object($path)];
        }

        $errors = [];
        foreach ($value as $key => $item) {
            $item_path = '' === $path ? (string) $key : $path . '.' . $key;
            $errors = array_merge(
                $errors,
                array_key_exists($key, $schema)
                    ? $this->check($item, $schema[$key], $item_path)
                    : [sprintf(__('%s is not a known field.', 'flux-quote'), $item_path)]
            );
        }

        return $errors;
    }

    private function check_blocks(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [sprintf(__('%s must be a list.', 'flux-quote'), $path)];
        }

        $errors = [];
        foreach ($value as $index => $block) {
            $block_path = sprintf('%s[%d]', $path, $index);
            if (!$this->is_object($block)) {
                $errors[] = $this->not_an_object($block_path);
                continue;
            }

            if (!in_array($block['type'] ?? null, self::BLOCK_TYPES, true)) {
                $errors[] = sprintf(
                    __('%1$s.type must be one of: %2$s.', 'flux-quote'),
                    $block_path,
                    implode(', ', self::BLOCK_TYPES)
                );
            }
            foreach (array_keys($block) as $key) {
                if (!in_array($key, ['type', 'data', 'props'], true)) {
                    $errors[] = sprintf(__('%s is not a known field.', 'flux-quote'), $block_path . '.' . $key);
                } elseif ('type' !== $key && !$this->is_object($block[$key])) {
                    $errors[] = $this->not_an_object($block_path . '.' . $key);
                }
            }
        }

        return $errors;
    }

    private function is_object(mixed $value): bool
    {
        return is_array($value) && ([] === $value || !array_is_list($value));
    }

    private function not_an_object(string $path): string
    {
        return sprintf(
            __('%s must be an object.', 'flux-quote'),
            '' === $path ? __('The template', 'flux-quote') : $path
        );
    }
}
