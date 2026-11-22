<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Integration\WooCommerce;

defined('ABSPATH') || exit;

final class WooCommerceSingleProductRenderer
{
    private const FUNCTIONAL_SOURCES = [
        'product.gallery',
        'product.add_to_cart_form',
        'product.meta',
        'product.tabs',
        'product.related_products',
    ];

    public function render(
        string $sourcePath,
        string $html,
        array $resolved
    ): string|\WP_Error {
        if (
            ($resolved['role'] ?? '') !== 'single_product'
            || ! class_exists('WooCommerce')
            || ! function_exists('wc_get_product')
        ) {
            return $html;
        }

        $productId = get_queried_object_id();

        if (
            $productId < 1
            || get_post_type($productId) !== 'product'
        ) {
            return $html;
        }

        $product = wc_get_product(
            $productId
        );

        if (! $product instanceof \WC_Product) {
            return $html;
        }

        $metadata = is_array(
            $resolved['metadata'] ?? null
        )
            ? $resolved['metadata']
            : [];

        $selected = is_array(
            $metadata['selected_mappings']
                ?? null
        )
            ? $metadata['selected_mappings']
            : [];

        $mappings = array_values(
            array_filter(
                $selected,
                static function (
                    mixed $mapping
                ): bool {
                    return is_array($mapping)
                        && ($mapping['binding_type'] ?? '') === 'functional'
                        && in_array(
                            (string) (
                                $mapping['data_source']
                                ?? ''
                            ),
                            self::FUNCTIONAL_SOURCES,
                            true
                        );
                }
            )
        );

        if ($mappings === []) {
            return $html;
        }

        if (! class_exists(\DOMDocument::class)) {
            return $this->error();
        }

        $fullDocument = preg_match(
            '/(?:<!doctype\b|<html\b)/i',
            $html
        ) === 1;

        $document = $this->document(
            $html
        );

        if (is_wp_error($document)) {
            return $document;
        }

        $root = $document->documentElement;

        if (! $root instanceof \DOMElement) {
            return $this->error();
        }

        $elements = [];

        $this->mapElements(
            $root,
            '/'
                . strtolower($root->tagName)
                . '[1]',
            $sourcePath,
            $elements
        );

        $previousProduct = $GLOBALS['product']
            ?? null;

        $previousPost = $GLOBALS['post']
            ?? null;

        $productPost = get_post(
            $productId
        );

        $GLOBALS['product'] = $product;

        if ($productPost instanceof \WP_Post) {
            $GLOBALS['post'] = $productPost;
        }

        try {
            foreach ($mappings as $mapping) {
                $elementId = (string) (
                    $mapping['element_id']
                    ?? ''
                );

                if (
                    $elementId === ''
                    || ! isset($elements[$elementId])
                    || ! $elements[$elementId]
                        instanceof \DOMElement
                ) {
                    continue;
                }

                $fragment = $this->fragment(
                    (string) (
                        $mapping['data_source']
                        ?? ''
                    ),
                    $product
                );

                if ($fragment === '') {
                    continue;
                }

                $config = is_array(
                    $mapping['config'] ?? null
                )
                    ? $mapping['config']
                    : [];

                $this->replace(
                    $document,
                    $elements[$elementId],
                    $fragment,
                    ($config['replace_mode'] ?? '') === 'outer'
                );
            }
        } finally {
            if ($previousProduct === null) {
                unset($GLOBALS['product']);
            } else {
                $GLOBALS['product'] = $previousProduct;
            }

            if ($previousPost === null) {
                unset($GLOBALS['post']);
            } else {
                $GLOBALS['post'] = $previousPost;
            }
        }

        return $this->serialize(
            $document,
            $fullDocument
        );
    }

    private function fragment(
        string $source,
        \WC_Product $product
    ): string {
        return match ($source) {
            'product.gallery'
            => $this->gallery(
                $product
            ),

            'product.add_to_cart_form'
            => $this->addToCart(
                $product
            ),

            'product.meta'
            => $this->capture(
                'woocommerce_template_single_meta'
            ),

            'product.tabs'
            => $this->tabs(),

            'product.related_products'
            => $this->capture(
                'woocommerce_output_related_products'
            ),

            default => '',
        };
    }

    private function gallery(
        \WC_Product $product
    ): string {
        WooCommerceStandaloneAssets::requireScript(
            'wc-single-product'
        );

        if (function_exists('wc_get_product_gallery_html')) {
            $html = wc_get_product_gallery_html(
                $product
            );

            return is_string($html)
                ? $html
                : '';
        }

        return $this->capture(
            'woocommerce_show_product_images'
        );
    }

    private function addToCart(
        \WC_Product $product
    ): string {
        if ($product->is_type('variable')) {
            WooCommerceStandaloneAssets::requireScript(
                'wc-add-to-cart-variation'
            );
        }

        return $this->capture(
            'woocommerce_template_single_add_to_cart'
        );
    }

    private function tabs(): string
    {
        WooCommerceStandaloneAssets::requireScript(
            'wc-single-product'
        );

        return $this->capture(
            'woocommerce_output_product_data_tabs'
        );
    }

    private function capture(
        string $function
    ): string {
        if (! function_exists($function)) {
            return '';
        }

        ob_start();

        $function();

        $html = ob_get_clean();

        return is_string($html)
            ? $html
            : '';
    }

    private function replace(
        \DOMDocument $document,
        \DOMElement $target,
        string $html,
        bool $outer
    ): void {
        $fragmentDocument = $this->document(
            '<div data-cdx-stw-woo-fragment="1">'
                . $html
                . '</div>'
        );

        if (is_wp_error($fragmentDocument)) {
            return;
        }

        $wrapper = null;

        foreach (
            $fragmentDocument
                ->getElementsByTagName('div')
            as $candidate
        ) {
            if (
                $candidate instanceof \DOMElement
                && $candidate->getAttribute(
                    'data-cdx-stw-woo-fragment'
                ) === '1'
            ) {
                $wrapper = $candidate;

                break;
            }
        }

        if (! $wrapper instanceof \DOMElement) {
            return;
        }

        $children = [];

        foreach ($wrapper->childNodes as $child) {
            $children[] = $child;
        }

        if ($outer) {
            $parent = $target->parentNode;

            if ($parent === null) {
                return;
            }

            foreach ($children as $child) {
                $parent->insertBefore(
                    $document->importNode(
                        $child,
                        true
                    ),
                    $target
                );
            }

            $parent->removeChild(
                $target
            );

            return;
        }

        while ($target->firstChild !== null) {
            $target->removeChild(
                $target->firstChild
            );
        }

        foreach ($children as $child) {
            $target->appendChild(
                $document->importNode(
                    $child,
                    true
                )
            );
        }
    }

    private function mapElements(
        \DOMElement $element,
        string $path,
        string $sourcePath,
        array &$elements
    ): void {
        $ref = 'cdx-stw-'
            . substr(
                hash(
                    'sha256',
                    str_replace(
                        '\\',
                        '/',
                        $sourcePath
                    )
                        . "\0"
                        . $path
                ),
                0,
                24
            );

        $elements[$ref] = $element;

        $counts = [];

        foreach (
            $element->childNodes
            as $child
        ) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower(
                $child->tagName
            );

            $counts[$tag] = (
                $counts[$tag]
                ?? 0
            ) + 1;

            $this->mapElements(
                $child,
                $path
                    . '/'
                    . $tag
                    . '['
                    . $counts[$tag]
                    . ']',
                $sourcePath,
                $elements
            );
        }
    }

    private function document(
        string $html
    ): \DOMDocument|\WP_Error {
        $previous = libxml_use_internal_errors(
            true
        );

        libxml_clear_errors();

        $document = new \DOMDocument(
            '1.0',
            'UTF-8'
        );

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'
                . $html,
            LIBXML_NONET
                | LIBXML_COMPACT
                | LIBXML_NOERROR
                | LIBXML_NOWARNING
        );

        libxml_clear_errors();

        libxml_use_internal_errors(
            $previous
        );

        if (! $loaded) {
            return $this->error();
        }

        $node = $document->firstChild;

        while ($node !== null) {
            $next = $node->nextSibling;

            if (
                $node->nodeType
                === XML_PI_NODE
                && strtolower(
                    $node->nodeName
                ) === 'xml'
            ) {
                $document->removeChild(
                    $node
                );
            }

            $node = $next;
        }

        return $document;
    }

    private function serialize(
        \DOMDocument $document,
        bool $fullDocument
    ): string|\WP_Error {
        if ($fullDocument) {
            $output = $document->saveHTML();

            return is_string($output)
                ? $output
                : $this->error();
        }

        $body = $document
            ->getElementsByTagName('body')
            ->item(0);

        if (! $body instanceof \DOMElement) {
            return $this->error();
        }

        $output = '';

        foreach ($body->childNodes as $child) {
            $fragment = $document->saveHTML(
                $child
            );

            if (is_string($fragment)) {
                $output .= $fragment;
            }
        }

        return $output;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_woocommerce_single_product_render',
            __(
                'The WooCommerce single-product region could not be rendered.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
