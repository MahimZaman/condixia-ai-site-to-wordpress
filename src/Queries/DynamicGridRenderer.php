<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Queries;

use Condixia\AISiteToWordPress\Bindings\DynamicBindingDocumentRenderer;
use Condixia\AISiteToWordPress\Contracts\ProductQueryProviderInterface;
use Condixia\AISiteToWordPress\Integration\WooCommerce\WooCommerceProductQueryProvider;
use Condixia\AISiteToWordPress\Storage\DynamicGridCandidateRepository;

defined('ABSPATH') || exit;

final class DynamicGridRenderer
{
    private DynamicGridCandidateRepository $grids;
    private WordPressPostQueryProvider $provider;
    private ?ProductQueryProviderInterface $productProvider;
    private DynamicBindingDocumentRenderer $bindings;

    public function __construct(
        ?DynamicGridCandidateRepository $grids = null,
        ?WordPressPostQueryProvider $provider = null,
        ?ProductQueryProviderInterface $productProvider = null,
        ?DynamicBindingDocumentRenderer $bindings = null
    ) {
        $this->grids = $grids
            ?? new DynamicGridCandidateRepository();

        $this->provider = $provider
            ?? new WordPressPostQueryProvider();

        $this->productProvider = $productProvider;

        $this->bindings = $bindings
            ?? new DynamicBindingDocumentRenderer();
    }

    public function render(
        int $siteId,
        string $sourcePath,
        string $html,
        array $resolved = []
    ): string|\WP_Error {
        $grids = $this->grids
            ->confirmedForSource(
                $siteId,
                $sourcePath
            );

        if ($grids === []) {
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

        $this->mapPaths(
            $root,
            '/'
                . strtolower($root->tagName)
                . '[1]',
            $elements
        );

        $templateHtml = $html;

        foreach ($grids as $grid) {
            $metadata = $grid['metadata'];

            $containerPath = (string) (
                $metadata['container_path']
                ?? ''
            );

            $samplePath = (string) (
                $metadata['sample_item_path']
                ?? ''
            );

            if (
                $containerPath === ''
                || $samplePath === ''
                || ! isset(
                    $elements[$containerPath],
                    $elements[$samplePath]
                )
                || ! $elements[$containerPath]
                    instanceof \DOMElement
                || ! $elements[$samplePath]
                    instanceof \DOMElement
            ) {
                continue;
            }

            $queryConfig = is_array(
                $metadata['query']
                    ?? null
            )
                ? $metadata['query']
                : [];

            $query = $this->query(
                $queryConfig,
                $resolved
            );

            if ($query === null) {
                continue;
            }

            if (is_wp_error($query)) {
                return $query;
            }

            $container = $elements[$containerPath];

            $originalItems = [];

            foreach (
                $metadata['items']
                    ?? []
                as $item
            ) {
                if (! is_array($item)) {
                    continue;
                }

                $itemPath = (string) (
                    $item['path']
                    ?? ''
                );

                if (
                    $itemPath !== ''
                    && isset(
                        $elements[$itemPath]
                    )
                    && $elements[$itemPath]
                    instanceof \DOMElement
                    && $elements[$itemPath]
                    ->parentNode
                    === $container
                ) {
                    $originalItems[] = $elements[$itemPath];
                }
            }

            if ($originalItems === []) {
                continue;
            }

            $anchor = $originalItems[0];
            $insertedCount = 0;

            foreach (
                $query['posts']
                as $post
            ) {
                if (! $post instanceof \WP_Post) {
                    continue;
                }

                $bound = $this->bindings->render(
                    $siteId,
                    (int) $grid['id'],
                    $sourcePath,
                    $templateHtml,
                    array_merge(
                        $resolved,
                        [
                            'post_id' => $post->ID,
                            'product_id' => sanitize_key(
                                (string) (
                                    $queryConfig['post_type']
                                    ?? ''
                                )
                            ) === 'product'
                                ? $post->ID
                                : 0,
                        ]
                    )
                );

                if (is_wp_error($bound)) {
                    return $bound;
                }

                $boundDocument = $this->document(
                    $bound
                );

                if (is_wp_error($boundDocument)) {
                    return $boundDocument;
                }

                $boundRoot = $boundDocument
                    ->documentElement;

                if (! $boundRoot instanceof \DOMElement) {
                    return $this->error();
                }

                $boundElements = [];

                $this->mapPaths(
                    $boundRoot,
                    '/'
                        . strtolower(
                            $boundRoot->tagName
                        )
                        . '[1]',
                    $boundElements
                );

                if (
                    ! isset(
                        $boundElements[$samplePath]
                    )
                    || ! $boundElements[$samplePath]
                        instanceof \DOMElement
                ) {
                    continue;
                }

                // Generated cards are query-managed; do not expose duplicate
                // source editor IDs as independently editable static elements.
                $card = $document->importNode(
                    $boundElements[$samplePath],
                    true
                );
                if ($card instanceof \DOMElement) {
                    $this->stripGeneratedEditorIdentities($card);
                }
                $container->insertBefore($card, $anchor);

                $insertedCount++;
            }

            if ($insertedCount === 0) {
                // Do not show imported sample cards for an empty query.
                // Keep them as a fallback if records existed but rendering failed.
                if ($query['posts'] === []) {
                    foreach ($originalItems as $item) {
                        if ($item->parentNode === $container) {
                            $container->removeChild($item);
                        }
                    }
                }

                continue;
            }

            foreach ($originalItems as $item) {
                if ($item->parentNode === $container) {
                    $container->removeChild(
                        $item
                    );
                }
            }
        }

        return $this->serialize(
            $document,
            $fullDocument
        );
    }

    private function stripGeneratedEditorIdentities(\DOMElement $card): void
    {
        $card->removeAttribute('data-cdx-id');
        foreach ($card->getElementsByTagName('*') as $element) {
            if ($element instanceof \DOMElement) {
                $element->removeAttribute('data-cdx-id');
            }
        }
    }

    private function query(
        array $config,
        array $context
    ): array|\WP_Error|null {
        $postType = sanitize_key(
            (string) (
                $config['post_type']
                ?? 'post'
            )
        );

        if ($postType !== 'product') {
            return $this->provider->query(
                $config,
                $context
            );
        }

        if (
            ! class_exists('WooCommerce')
            || ! function_exists(
                'wc_get_products'
            )
            || ! function_exists(
                'wc_get_product'
            )
        ) {
            return null;
        }

        if ($this->productProvider === null) {
            $this->productProvider
                = new WooCommerceProductQueryProvider();
        }

        if (
            ! $this->productProvider
                ->available()
        ) {
            return null;
        }

        return $this->productProvider
            ->query(
                $config,
                $context
            );
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

    private function mapPaths(
        \DOMElement $element,
        string $path,
        array &$elements
    ): void {
        $elements[$path] = $element;

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

            $this->mapPaths(
                $child,
                $path
                    . '/'
                    . $tag
                    . '['
                    . $counts[$tag]
                    . ']',
                $elements
            );
        }
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_dynamic_grid_render',
            __(
                'The dynamic post grid could not be rendered.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
