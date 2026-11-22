<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class TemplateRoles
{
    public static function labels(): array
    {
        return [
            'front_page' => __(
                'Front Page',
                'condixia-ai-site-to-wordpress'
            ),
            'default_page' => __(
                'Default Page',
                'condixia-ai-site-to-wordpress'
            ),
            'single_post' => __(
                'Single Post',
                'condixia-ai-site-to-wordpress'
            ),
            'post_archive' => __(
                'Post Archive',
                'condixia-ai-site-to-wordpress'
            ),
            'cpt_single' => __(
                'Custom Post Type — Single',
                'condixia-ai-site-to-wordpress'
            ),
            'cpt_archive' => __(
                'Custom Post Type — Archive',
                'condixia-ai-site-to-wordpress'
            ),
            'taxonomy_archive' => __(
                'Taxonomy Archive',
                'condixia-ai-site-to-wordpress'
            ),
            'search' => __(
                'Search Results',
                'condixia-ai-site-to-wordpress'
            ),
            '404' => __(
                '404',
                'condixia-ai-site-to-wordpress'
            ),
            'product_archive' => __(
                'WooCommerce Product Archive',
                'condixia-ai-site-to-wordpress'
            ),
            'product_taxonomy_archive' => __(
                'WooCommerce Product Taxonomy Archive',
                'condixia-ai-site-to-wordpress'
            ),
            'single_product' => __(
                'WooCommerce Single Product',
                'condixia-ai-site-to-wordpress'
            ),
        ];
    }

    public static function valid(string $role): bool
    {
        return isset(
            self::labels()[$role]
        );
    }

    public static function requiresTarget(
        string $role
    ): bool {
        return in_array(
            $role,
            [
                'cpt_single',
                'cpt_archive',
                'taxonomy_archive',
            ],
            true
        );
    }

    public static function requiresWooCommerce(
        string $role
    ): bool {
        return in_array(
            $role,
            [
                'product_archive',
                'product_taxonomy_archive',
                'single_product',
            ],
            true
        );
    }

    public static function uniqueKey(
        string $role,
        string $target
    ): string {
        if (
            self::requiresTarget($role)
            && $target !== ''
        ) {
            return $role
                . ':'
                . $target;
        }

        return $role;
    }
}
