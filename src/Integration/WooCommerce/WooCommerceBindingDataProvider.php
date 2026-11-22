<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Integration\WooCommerce;

use Condixia\AISiteToWordPress\Contracts\BindingDataProviderInterface;

defined('ABSPATH') || exit;

final class WooCommerceBindingDataProvider implements BindingDataProviderInterface
{
    private const SOURCES = [
        'product.name',
        'product.image',
        'product.permalink',
        'product.price',
        'product.regular_price',
        'product.sale_price',
        'product.rating',
        'product.stock',
        'product.categories',
        'product.short_description',
        'product.description',
        'product.sku',
        'product.add_to_cart_url',
        'product.add_to_cart_text',
    ];

    public function supports(
        string $source
    ): bool {
        return function_exists(
            'wc_get_product'
        )
            && in_array(
                $source,
                self::SOURCES,
                true
            );
    }

    public function resolve(
        string $source,
        array $config,
        array $context
    ): mixed {
        $product = $this->product(
            $context
        );

        if (
            ! $product
                instanceof \WC_Product
        ) {
            return null;
        }

        return match ($source) {
            'product.name'
            => $product->get_name(),

            'product.image'
            => $this->image(
                $product
            ),

            'product.permalink'
            => $product->get_permalink(),

            'product.price'
            => $product->get_price_html(),

            'product.regular_price'
            => $this->money(
                $product
                    ->get_regular_price()
            ),

            'product.sale_price'
            => $this->money(
                $product
                    ->get_sale_price()
            ),

            'product.rating'
            => $this->rating(
                $product
            ),

            'product.stock'
            => $this->stock(
                $product
            ),

            'product.categories'
            => $this->categories(
                $product
            ),

            'product.short_description'
            => $this->shortDescription(
                $product
            ),

            'product.description'
            => $this->description(
                $product
            ),

            'product.sku'
            => $product->get_sku(),

            'product.add_to_cart_url'
            => $product
                ->add_to_cart_url(),

            'product.add_to_cart_text'
            => $product
                ->add_to_cart_text(),

            default => null,
        };
    }

    private function product(
        array $context
    ): ?\WC_Product {
        $resolved = is_array(
            $context['resolved']
                ?? null
        )
            ? $context['resolved']
            : [];

        $productId = absint(
            $resolved['product_id']
                ?? $context['post_id']
                ?? 0
        );

        if ($productId < 1) {
            return null;
        }

        $product = wc_get_product(
            $productId
        );

        return $product
            instanceof \WC_Product
            ? $product
            : null;
    }

    private function image(
        \WC_Product $product
    ): ?array {
        $attachmentId = $product
            ->get_image_id();

        if ($attachmentId > 0) {
            $url = wp_get_attachment_image_url(
                $attachmentId,
                'full'
            );

            if (
                is_string($url)
                && $url !== ''
            ) {
                return [
                    'attachment_id'
                    => $attachmentId,
                    'url' => $url,
                    'alt' => (string)
                    get_post_meta(
                        $attachmentId,
                        '_wp_attachment_image_alt',
                        true
                    ),
                ];
            }
        }

        if (
            ! function_exists(
                'wc_placeholder_img_src'
            )
        ) {
            return null;
        }

        $url = wc_placeholder_img_src(
            'woocommerce_thumbnail'
        );

        if (
            ! is_string($url)
            || $url === ''
        ) {
            return null;
        }

        return [
            'attachment_id' => 0,
            'url' => $url,
            'alt' => $product->get_name(),
        ];
    }

    private function money(
        string $value
    ): string {
        if (
            $value === ''
            || ! function_exists(
                'wc_price'
            )
        ) {
            return '';
        }

        return wc_price(
            (float) $value
        );
    }

    private function rating(
        \WC_Product $product
    ): string {
        if (
            ! function_exists(
                'wc_get_rating_html'
            )
        ) {
            return '';
        }

        $count = $product
            ->get_rating_count();

        if ($count < 1) {
            return '';
        }

        return wc_get_rating_html(
            (float) $product
                ->get_average_rating(),
            $count
        );
    }

    private function stock(
        \WC_Product $product
    ): string {
        if (
            function_exists(
                'wc_get_stock_html'
            )
        ) {
            return wp_strip_all_tags(
                wc_get_stock_html(
                    $product
                )
            );
        }

        $availability = $product
            ->get_availability();

        return isset(
            $availability['availability']
        )
            ? wp_strip_all_tags(
                (string) $availability['availability']
            )
            : '';
    }

    private function shortDescription(
        \WC_Product $product
    ): string {
        $post = get_post(
            $product->get_id()
        );

        if (! $post instanceof \WP_Post) {
            return '';
        }

        return (string) apply_filters(
            'woocommerce_short_description',
            (string) $post->post_excerpt
        );
    }

    private function description(
        \WC_Product $product
    ): string {
        $post = get_post(
            $product->get_id()
        );

        if (! $post instanceof \WP_Post) {
            return '';
        }

        return (string) apply_filters(
            'the_content',
            (string) $post->post_content
        );
    }


    private function categories(
        \WC_Product $product
    ): string {
        if (
            ! function_exists(
                'wc_get_product_category_list'
            )
        ) {
            return '';
        }

        return wc_get_product_category_list(
            $product->get_id(),
            ', '
        );
    }
}
