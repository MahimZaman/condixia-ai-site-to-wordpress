<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Integration\WooCommerce;

defined('ABSPATH') || exit;

final class WooCommerceFieldMapper
{
    public function suggestions(
        array $element,
        string $rootPath,
        array $byRef
    ): array {
        if (
            ! $this->productContext(
                $element,
                $byRef
            )
        ) {
            return [];
        }

        $tag = strtolower(
            (string) (
                $element['tag']
                ?? ''
            )
        );

        $attributes = is_array(
            $element['attributes']
                ?? null
        )
            ? $element['attributes']
            : [];

        $semantic = $this->semantic(
            $attributes
        );

        $suggestions = [];

        if (
            in_array(
                $tag,
                [
                    'h1',
                    'h2',
                    'h3',
                    'h4',
                    'h5',
                    'h6',
                ],
                true
            )
            && $this->containsAny(
                $semantic,
                [
                    'title',
                    'name',
                    'product-title',
                    'product-name',
                    'woocommerce-loop-product__title',
                ]
            )
        ) {
            $suggestions[] = $this
                ->suggestion(
                    $element,
                    $rootPath,
                    'product.name',
                    'text',
                    '',
                    0.97,
                    'woocommerce_product_name'
                );
        }

        if (
            $tag === 'img'
            && $this->containsAny(
                $semantic,
                [
                    'image',
                    'thumbnail',
                    'thumb',
                    'photo',
                    'woocommerce_thumbnail',
                ]
            )
        ) {
            $suggestions[] = $this
                ->suggestion(
                    $element,
                    $rootPath,
                    'product.image',
                    'media',
                    'src',
                    0.96,
                    'woocommerce_product_image'
                );
        }

        if (
            $this->containsAny(
                $semantic,
                [
                    'regular-price',
                    'regular_price',
                    'price-regular',
                ]
            )
        ) {
            $suggestions[] = $this
                ->suggestion(
                    $element,
                    $rootPath,
                    'product.regular_price',
                    'html',
                    '',
                    0.97,
                    'woocommerce_regular_price'
                );
        } elseif (
            $this->containsAny(
                $semantic,
                [
                    'sale-price',
                    'sale_price',
                    'price-sale',
                ]
            )
        ) {
            $suggestions[] = $this
                ->suggestion(
                    $element,
                    $rootPath,
                    'product.sale_price',
                    'html',
                    '',
                    0.97,
                    'woocommerce_sale_price'
                );
        } elseif (
            $this->containsAny(
                $semantic,
                [
                    'price',
                    'amount',
                    'product-price',
                ]
            )
        ) {
            $suggestions[] = $this
                ->suggestion(
                    $element,
                    $rootPath,
                    'product.price',
                    'html',
                    '',
                    0.96,
                    'woocommerce_current_price'
                );
        }

        if (
            $this->containsAny(
                $semantic,
                [
                    'rating',
                    'star-rating',
                    'stars',
                    'review-rating',
                ]
            )
        ) {
            $suggestions[] = $this
                ->suggestion(
                    $element,
                    $rootPath,
                    'product.rating',
                    'html',
                    '',
                    0.95,
                    'woocommerce_rating'
                );
        }

        if (
            $this->containsAny(
                $semantic,
                [
                    'stock',
                    'availability',
                    'inventory',
                ]
            )
        ) {
            $suggestions[] = $this
                ->suggestion(
                    $element,
                    $rootPath,
                    'product.stock',
                    'text',
                    '',
                    0.94,
                    'woocommerce_stock'
                );
        }

        if (
            $this->containsAny(
                $semantic,
                [
                    'product-category',
                    'product-categories',
                    'product_cat',
                ]
            )
        ) {
            $suggestions[] = $this
                ->suggestion(
                    $element,
                    $rootPath,
                    'product.categories',
                    'html',
                    '',
                    0.94,
                    'woocommerce_categories'
                );
        }

        $addToCart = $this->containsAny(
            $semantic,
            [
                'add-to-cart',
                'add_to_cart',
                'ajax_add_to_cart',
            ]
        );

        if (
            $tag === 'a'
            && array_key_exists(
                'href',
                $attributes
            )
        ) {
            if ($addToCart) {
                $suggestions[] = $this
                    ->suggestion(
                        $element,
                        $rootPath,
                        'product.add_to_cart_url',
                        'url',
                        'href',
                        0.99,
                        'woocommerce_add_to_cart_url'
                    );
            } elseif (
                $this->containsAny(
                    $semantic,
                    [
                        'product-link',
                        'product-permalink',
                        'woocommerce-loop-product__link',
                    ]
                )
            ) {
                $suggestions[] = $this
                    ->suggestion(
                        $element,
                        $rootPath,
                        'product.permalink',
                        'url',
                        'href',
                        0.97,
                        'woocommerce_product_permalink'
                    );
            }
        }

        if (
            in_array(
                $tag,
                [
                    'a',
                    'button',
                ],
                true
            )
            && $addToCart
        ) {
            $suggestions[] = $this
                ->suggestion(
                    $element,
                    $rootPath,
                    'product.add_to_cart_text',
                    'text',
                    '',
                    0.99,
                    'woocommerce_add_to_cart_text'
                );
        }

        if (
            $this->containsAny(
                $semantic,
                [
                    'short-description',
                    'short_description',
                    'product-short-description',
                    'woocommerce-product-details__short-description',
                ]
            )
        ) {
            $suggestions[] = $this
                ->suggestion(
                    $element,
                    $rootPath,
                    'product.short_description',
                    'html',
                    '',
                    0.97,
                    'woocommerce_short_description'
                );
        } elseif (
            $this->containsAny(
                $semantic,
                [
                    'product-description',
                    'product_description',
                    'woocommerce-tabs-panel--description',
                ]
            )
        ) {
            $suggestions[] = $this
                ->suggestion(
                    $element,
                    $rootPath,
                    'product.description',
                    'html',
                    '',
                    0.95,
                    'woocommerce_description'
                );
        }

        if (
            $this->containsAny(
                $semantic,
                [
                    'sku',
                    'product-sku',
                    'product_sku',
                ]
            )
        ) {
            $suggestions[] = $this
                ->suggestion(
                    $element,
                    $rootPath,
                    'product.sku',
                    'text',
                    '',
                    0.96,
                    'woocommerce_sku'
                );
        }

        if (
            in_array(
                $tag,
                [
                    'div',
                    'section',
                    'figure',
                ],
                true
            )
            && $this->containsAny(
                $semantic,
                [
                    'woocommerce-product-gallery',
                    'product-gallery',
                    'product_gallery',
                    'product-images',
                    'product_images',
                ]
            )
        ) {
            $gallery = $this->suggestion(
                $element,
                $rootPath,
                'product.gallery',
                'functional',
                '',
                0.99,
                'woocommerce_product_gallery'
            );

            $gallery['config'] = [
                'replace_mode' => 'inner',
            ];

            $suggestions[] = $gallery;
        }

        if (
            in_array(
                $tag,
                [
                    'form',
                    'div',
                    'section',
                ],
                true
            )
            && $this->containsAny(
                $semantic,
                [
                    'variations_form',
                    'variations-form',
                    'add-to-cart',
                    'add_to_cart',
                    'cart-form',
                    'product-cart',
                ]
            )
        ) {
            $cart = $this->suggestion(
                $element,
                $rootPath,
                'product.add_to_cart_form',
                'functional',
                '',
                0.99,
                'woocommerce_single_add_to_cart'
            );

            $cart['config'] = [
                'replace_mode' => $tag === 'form'
                    ? 'outer'
                    : 'inner',
            ];

            $suggestions[] = $cart;
        }

        if (
            in_array(
                $tag,
                [
                    'div',
                    'section',
                    'p',
                ],
                true
            )
            && $this->containsAny(
                $semantic,
                [
                    'product_meta',
                    'product-meta',
                    'woocommerce-product-meta',
                ]
            )
        ) {
            $meta = $this->suggestion(
                $element,
                $rootPath,
                'product.meta',
                'functional',
                '',
                0.98,
                'woocommerce_product_meta'
            );

            $meta['config'] = [
                'replace_mode' => 'inner',
            ];

            $suggestions[] = $meta;
        }

        if (
            in_array(
                $tag,
                [
                    'div',
                    'section',
                ],
                true
            )
            && $this->containsAny(
                $semantic,
                [
                    'woocommerce-tabs',
                    'product-tabs',
                    'product_tabs',
                ]
            )
        ) {
            $tabs = $this->suggestion(
                $element,
                $rootPath,
                'product.tabs',
                'functional',
                '',
                0.99,
                'woocommerce_product_tabs'
            );

            $tabs['config'] = [
                'replace_mode' => 'inner',
            ];

            $suggestions[] = $tabs;
        }

        if (
            in_array(
                $tag,
                [
                    'div',
                    'section',
                ],
                true
            )
            && $this->containsAny(
                $semantic,
                [
                    'related-products',
                    'related_products',
                    'woocommerce-related-products',
                ]
            )
        ) {
            $related = $this->suggestion(
                $element,
                $rootPath,
                'product.related_products',
                'functional',
                '',
                0.98,
                'woocommerce_related_products'
            );

            $related['config'] = [
                'replace_mode' => 'inner',
            ];

            $suggestions[] = $related;
        }

        return $suggestions;
    }

    public function catalog(): array
    {
        return [
            [
                'source' => 'product.name',
                'binding_types' => [
                    'text',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.image',
                'binding_types' => [
                    'media',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.permalink',
                'binding_types' => [
                    'url',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.price',
                'binding_types' => [
                    'html',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.regular_price',
                'binding_types' => [
                    'html',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.sale_price',
                'binding_types' => [
                    'html',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.rating',
                'binding_types' => [
                    'html',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.stock',
                'binding_types' => [
                    'text',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.categories',
                'binding_types' => [
                    'html',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.add_to_cart_url',
                'binding_types' => [
                    'url',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.add_to_cart_text',
                'binding_types' => [
                    'text',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.short_description',
                'binding_types' => [
                    'html',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.description',
                'binding_types' => [
                    'html',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.sku',
                'binding_types' => [
                    'text',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.gallery',
                'binding_types' => [
                    'functional',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.add_to_cart_form',
                'binding_types' => [
                    'functional',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.meta',
                'binding_types' => [
                    'functional',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.tabs',
                'binding_types' => [
                    'functional',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'product.related_products',
                'binding_types' => [
                    'functional',
                ],
                'config_required' => [],
            ],
        ];
    }

    private function productContext(
        array $element,
        array $byRef
    ): bool {
        $current = $element;

        while (true) {
            $attributes = is_array(
                $current['attributes']
                    ?? null
            )
                ? $current['attributes']
                : [];

            $semantic = $this->semantic(
                $attributes
            );

            $itemType = strtolower(
                (string) (
                    $attributes['itemtype']
                    ?? ''
                )
            );

            if (
                str_contains(
                    $itemType,
                    'schema.org/product'
                )
                || $this->containsAny(
                    $semantic,
                    [
                        'product',
                        'woocommerce',
                        'wc-product',
                    ]
                )
            ) {
                return true;
            }

            $parentRef = isset(
                $current['parent_ref']
            ) && is_string(
                $current['parent_ref']
            )
                ? $current['parent_ref']
                : '';

            if (
                $parentRef === ''
                || ! isset(
                    $byRef[$parentRef]
                )
                || ! is_array(
                    $byRef[$parentRef]
                )
            ) {
                return false;
            }

            $current = $byRef[$parentRef];
        }
    }

    private function semantic(
        array $attributes
    ): string {
        $classes = is_array(
            $attributes['class']
                ?? null
        )
            ? $attributes['class']
            : [];

        return strtolower(
            implode(
                ' ',
                $classes
            )
                . ' '
                . (string) (
                    $attributes['id']
                    ?? ''
                )
                . ' '
                . (string) (
                    $attributes['itemprop']
                    ?? ''
                )
                . ' '
                . (string) (
                    $attributes['property']
                    ?? ''
                )
                . ' '
                . (string) (
                    $attributes['name']
                    ?? ''
                )
        );
    }

    private function suggestion(
        array $element,
        string $rootPath,
        string $source,
        string $bindingType,
        string $bindingTarget,
        float $confidence,
        string $reason
    ): array {
        $path = (string) (
            $element['path']
            ?? ''
        );

        return [
            'source_ref' => (string) (
                $element['ref']
                ?? ''
            ),
            'source_path' => $path,
            'relative_path' =>
            $path === $rootPath
                ? '.'
                : ltrim(
                    substr(
                        $path,
                        strlen($rootPath)
                    ),
                    '/'
                ),
            'tag' => (string) (
                $element['tag']
                ?? ''
            ),
            'data_source' => $source,
            'binding_type' => $bindingType,
            'binding_target'
            => $bindingTarget,
            'config' => [],
            'confidence' => round(
                $confidence,
                3
            ),
            'ambiguous' =>
            $confidence < 0.80,
            'reason' => $reason,
            'editable' => true,
        ];
    }

    private function containsAny(
        string $value,
        array $needles
    ): bool {
        foreach ($needles as $needle) {
            if (
                str_contains(
                    $value,
                    strtolower($needle)
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
