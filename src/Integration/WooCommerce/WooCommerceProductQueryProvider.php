<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Integration\WooCommerce;

use Condixia\AISiteToWordPress\Contracts\ProductQueryProviderInterface;

defined('ABSPATH') || exit;

final class WooCommerceProductQueryProvider implements ProductQueryProviderInterface
{
    private const ORDER_BY = [
        'date' => 'date',
        'modified' => 'modified',
        'title' => 'name',
        'id' => 'ID',
        'rand' => 'rand',
    ];

    public function available(): bool
    {
        return class_exists('WooCommerce')
            && function_exists('wc_get_products')
            && function_exists('wc_get_product');
    }

    public function query(
        array $config,
        array $context = []
    ): array|\WP_Error {
        if (! $this->available()) {
            return $this->error(
                __(
                    'WooCommerce is not available for this product query.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        if (
            ! empty($config['use_main_query'])
            && $this->mainQueryCompatible()
        ) {
            global $wp_query;

            if ($wp_query instanceof \WP_Query) {
                return $this->mainQueryResult(
                    $wp_query
                );
            }
        }

        $limit = max(
            1,
            min(
                100,
                absint(
                    $config['posts_per_page']
                        ?? 10
                )
            )
        );

        $order = strtoupper(
            (string) (
                $config['order']
                ?? 'DESC'
            )
        );

        if (
            ! in_array(
                $order,
                [
                    'ASC',
                    'DESC',
                ],
                true
            )
        ) {
            $order = 'DESC';
        }

        $orderByKey = sanitize_key(
            (string) (
                $config['orderby']
                ?? 'date'
            )
        );

        $orderBy = self::ORDER_BY[$orderByKey] ?? 'date';

        $paginate = ! empty($config['paginate']);

        $page = $paginate
            ? max(
                1,
                absint(
                    get_query_var('paged')
                        ?: get_query_var('page')
                )
            )
            : 1;

        $args = [
            'status' => 'publish',
            'visibility' => 'visible',
            'limit' => $limit,
            'page' => $page,
            'paginate' => $paginate,
            'orderby' => $orderBy,
            'order' => $order,
            'return' => 'objects',
        ];

        $taxonomy = sanitize_key(
            (string) (
                $config['taxonomy']
                ?? ''
            )
        );

        $terms = is_array(
            $config['terms']
                ?? null
        )
            ? array_values(
                array_filter(
                    array_unique(
                        array_map(
                            'sanitize_title',
                            $config['terms']
                        )
                    )
                )
            )
            : [];

        if (
            $taxonomy === 'product_cat'
            && $terms !== []
        ) {
            $args['category'] = $terms;
        } elseif (
            $taxonomy === 'product_tag'
            && $terms !== []
        ) {
            $args['tag'] = $terms;
        } elseif ($taxonomy !== '') {
            return $this->error(
                __(
                    'This WooCommerce product query supports product categories and product tags only.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $result = wc_get_products(
            $args
        );

        if ($paginate) {
            if (! is_object($result)) {
                return $this->error(
                    __(
                        'WooCommerce returned an invalid paginated product result.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            $products = is_array(
                $result->products
                    ?? null
            )
                ? $result->products
                : [];

            return [
                'posts' => $this->posts(
                    $products
                ),
                'uses_main_query' => false,
                'current_page' => $page,
                'max_num_pages' => max(
                    0,
                    (int) (
                        $result->max_num_pages
                        ?? 0
                    )
                ),
                'found_posts' => max(
                    0,
                    (int) (
                        $result->total
                        ?? 0
                    )
                ),
            ];
        }

        if (! is_array($result)) {
            return $this->error(
                __(
                    'WooCommerce returned an invalid product result.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $posts = $this->posts(
            $result
        );

        return [
            'posts' => $posts,
            'uses_main_query' => false,
            'current_page' => 1,
            'max_num_pages' => 0,
            'found_posts' => count($posts),
        ];
    }

    private function mainQueryCompatible(): bool
    {
        if (
            function_exists('is_shop')
            && is_shop()
        ) {
            return true;
        }

        if (
            function_exists(
                'is_product_category'
            )
            && is_product_category()
        ) {
            return true;
        }

        if (
            function_exists(
                'is_product_tag'
            )
            && is_product_tag()
        ) {
            return true;
        }

        return function_exists(
            'is_product_taxonomy'
        )
            && is_product_taxonomy();
    }

    private function mainQueryResult(
        \WP_Query $query
    ): array {
        $posts = array_values(
            array_filter(
                $query->posts,
                static fn(
                    mixed $post
                ): bool =>
                $post instanceof \WP_Post
                    && $post->post_type
                    === 'product'
            )
        );

        return [
            'posts' => $posts,
            'uses_main_query' => true,
            'current_page' => max(
                1,
                (int) (
                    $query->get('paged')
                    ?: get_query_var(
                        'paged'
                    )
                    ?: get_query_var(
                        'page'
                    )
                    ?: 1
                )
            ),
            'max_num_pages' => max(
                0,
                (int) $query->max_num_pages
            ),
            'found_posts' => max(
                0,
                (int) $query->found_posts
            ),
        ];
    }

    private function posts(
        array $products
    ): array {
        $posts = [];

        foreach ($products as $product) {
            if (
                ! $product
                    instanceof \WC_Product
            ) {
                continue;
            }

            $post = get_post(
                $product->get_id()
            );

            if ($post instanceof \WP_Post) {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    private function error(
        string $message
    ): \WP_Error {
        return new \WP_Error(
            'condixia_ai_stw_woocommerce_product_query',
            $message
        );
    }
}
