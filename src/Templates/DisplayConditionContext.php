<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Templates;

defined('ABSPATH') || exit;

final class DisplayConditionContext
{
    public static function current(): array
    {
        $queried = get_queried_object();

        $objectId = get_queried_object_id();

        $postType = '';

        if (
            $objectId > 0
            && is_singular()
        ) {
            $resolvedPostType = get_post_type(
                $objectId
            );

            if (is_string($resolvedPostType)) {
                $postType = sanitize_key(
                    $resolvedPostType
                );
            }
        }

        $archivePostType = '';

        if (
            is_post_type_archive()
            && $queried instanceof \WP_Post_Type
        ) {
            $archivePostType = sanitize_key(
                $queried->name
            );
        }

        $taxonomy = '';
        $termId = 0;

        if ($queried instanceof \WP_Term) {
            $taxonomy = sanitize_key(
                $queried->taxonomy
            );

            $termId = (int) $queried->term_id;
        }

        $isWooCommerce = false;

        foreach (
            [
                'is_woocommerce',
                'is_cart',
                'is_checkout',
                'is_account_page',
            ]
            as $function
        ) {
            if (
                function_exists($function)
                && $function()
            ) {
                $isWooCommerce = true;

                break;
            }
        }

        return [
            'object_id' => $objectId,
            'post_type' => $postType,
            'archive_post_type' => $archivePostType,
            'taxonomy' => $taxonomy,
            'term_id' => $termId,
            'is_singular' => is_singular(),
            'is_page' => is_page(),
            'is_single_post' => is_singular(
                'post'
            ),
            'is_front_page' => is_front_page(),
            'is_post_archive' => (
                is_home()
                || is_category()
                || is_tag()
                || is_author()
                || is_date()
            ),
            'is_post_type_archive' => is_post_type_archive(),
            'is_taxonomy_archive' => is_tax(),
            'is_search' => is_search(),
            'is_404' => is_404(),
            'is_woocommerce' => $isWooCommerce,
            'is_shop' => (
                function_exists('is_shop')
                && is_shop()
            ),
            'is_single_product' => (
                function_exists('is_product')
                && is_product()
            ),
            'is_product_taxonomy' => (
                function_exists(
                    'is_product_taxonomy'
                )
                && is_product_taxonomy()
            ),
        ];
    }
}
