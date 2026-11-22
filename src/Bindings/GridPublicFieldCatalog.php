<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Bindings;

defined('ABSPATH') || exit;

/**
 * Public, single-value post fields eligible for grid-card mapping.
 */
final class GridPublicFieldCatalog
{
    private const KEY_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D';

    private const ACF_TEXT = [
        'text',
        'textarea',
        'number',
        'email',
        'select',
        'radio',
        'true_false',
        'date_picker',
        'date_time_picker',
        'time_picker',
    ];

    public function forType(string $postType): array
    {
        $type = get_post_type_object($postType);

        if (
            ! $type instanceof \WP_Post_Type
            || ! $type->public
            || $postType === 'attachment'
        ) {
            return [];
        }

        $fields = [];

        $registered = function_exists('get_registered_meta_keys')
            ? get_registered_meta_keys('post', $postType)
            : [];

        foreach (is_array($registered) ? $registered : [] as $name => $definition) {
            if (
                ! $this->safeName((string) $name)
                || ! is_array($definition)
                || empty($definition['show_in_rest'])
                || empty($definition['single'])
            ) {
                continue;
            }

            $auth = $definition['auth_callback'] ?? null;

            if ($auth !== null && $auth !== '__return_true') {
                continue;
            }

            if (! in_array(
                (string) ($definition['type'] ?? ''),
                ['string', 'integer', 'number', 'boolean'],
                true
            )) {
                continue;
            }

            $rest = $definition['show_in_rest'];

            $format = is_array($rest)
                ? (string) ($rest['schema']['format'] ?? '')
                : '';

            $kind = $format === 'uri' ? 'url' : 'text';
            $id = 'meta:' . $name;

            $fields[$id] = [
                'id' => $id,
                'label' => $name . ' (registered field)',
                'kind' => $kind,
                'field_type' => (string) $definition['type'],
                'source' => 'grid.public_meta',
                'config' => [
                    'key' => (string) $name,
                    'post_type' => $postType,
                ],
            ];
        }

        if (
            function_exists('acf_get_field_groups')
            && function_exists('acf_get_fields')
            && function_exists('get_field')
        ) {
            $groups = acf_get_field_groups(['post_type' => $postType]);

            foreach (is_array($groups) ? $groups : [] as $group) {
                if (
                    ! is_array($group)
                    || empty($group['show_in_rest'])
                    || ! $this->acfGroupMatches($group, $postType)
                ) {
                    continue;
                }

                $entries = acf_get_fields($group);

                foreach (is_array($entries) ? $entries : [] as $field) {
                    if (! is_array($field)) {
                        continue;
                    }

                    $name = (string) ($field['name'] ?? '');
                    $fieldType = (string) ($field['type'] ?? '');

                    if (
                        ! $this->safeName($name)
                        || (
                            ! in_array($fieldType, self::ACF_TEXT, true)
                            && ! in_array($fieldType, ['url', 'image'], true)
                        )
                    ) {
                        continue;
                    }

                    $id = 'acf:' . $name;

                    $fields[$id] = [
                        'id' => $id,
                        'label' => sanitize_text_field(
                            (string) ($field['label'] ?? $name)
                        ) . ' (ACF)',
                        'kind' => $fieldType === 'image'
                            ? 'media'
                            : ($fieldType === 'url' ? 'url' : 'text'),
                        'field_type' => $fieldType,
                        'source' => 'grid.public_acf',
                        'config' => [
                            'key' => $name,
                            'post_type' => $postType,
                        ],
                    ];
                }
            }
        }

        return array_slice($fields, 0, 150, true);
    }

    public function resolve(
        string $source,
        array $config,
        array $context
    ): mixed {
        if (! in_array($source, ['grid.public_meta', 'grid.public_acf'], true)) {
            return null;
        }

        $postId = absint($context['post_id'] ?? 0);
        $key = is_string($config['key'] ?? null) ? $config['key'] : '';
        $postType = is_string($config['post_type'] ?? null)
            ? sanitize_key($config['post_type'])
            : '';

        if (
            $postId < 1
            || ! $this->safeName($key)
            || $postType === ''
            || get_post_type($postId) !== $postType
            || get_post_status($postId) !== 'publish'
        ) {
            return null;
        }

        $fieldId = (
            $source === 'grid.public_acf' ? 'acf:' : 'meta:'
        ) . $key;

        $fields = $this->forType($postType);

        if (
            ! isset($fields[$fieldId])
            || $fields[$fieldId]['source'] !== $source
        ) {
            return null;
        }

        $value = $source === 'grid.public_acf'
            ? get_field($key, $postId, true)
            : get_post_meta($postId, $key, true);

        // False is valid for Boolean fields. Other ACF field types
        // can return false to indicate a missing value.
        if (
            $value === null
            || $value === ''
            || ($value === false && ! in_array(
                (string) ($fields[$fieldId]['field_type'] ?? ''),
                ['boolean', 'true_false'],
                true
            ))
        ) {
            return null;
        }

        $kind = $fields[$fieldId]['kind'];

        if ($kind === 'media') {
            if (is_array($value)) {
                $value = isset($value['url'])
                    ? $value
                    : ($value['ID'] ?? null);
            }

            if (is_numeric($value)) {
                $attachmentId = absint($value);
                $url = wp_get_attachment_image_url($attachmentId, 'full');

                return $url ? [
                    'url' => $url,
                    'alt' => sanitize_text_field(
                        (string) get_post_meta(
                            $attachmentId,
                            '_wp_attachment_image_alt',
                            true
                        )
                    ),
                ] : null;
            }

            if (is_array($value) && ! empty($value['url'])) {
                $url = esc_url_raw((string) $value['url']);

                return $url !== '' ? [
                    'url' => $url,
                    'alt' => sanitize_text_field(
                        (string) ($value['alt'] ?? '')
                    ),
                ] : null;
            }

            if (is_string($value)) {
                $url = esc_url_raw($value);

                return $url !== '' ? ['url' => $url] : null;
            }

            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        if ($kind === 'url') {
            $url = esc_url_raw((string) $value);

            return preg_match('~^https?://~i', $url) === 1
                ? $url
                : null;
        }

        return sanitize_text_field(
            is_bool($value) ? ($value ? '1' : '0') : (string) $value
        );
    }

    private function safeName(string $name): bool
    {
        return preg_match(self::KEY_PATTERN, $name) === 1
            && ! is_protected_meta($name, 'post');
    }

    private function acfGroupMatches(
        array $group,
        string $postType
    ): bool {
        foreach ($group['location'] ?? [] as $andRules) {
            if (
                ! is_array($andRules)
                || count($andRules) !== 1
            ) {
                continue;
            }

            $rule = reset($andRules);

            if (
                is_array($rule)
                && ($rule['param'] ?? '') === 'post_type'
                && ($rule['operator'] ?? '') === '=='
                && ($rule['value'] ?? '') === $postType
            ) {
                return true;
            }
        }

        return false;
    }
}
