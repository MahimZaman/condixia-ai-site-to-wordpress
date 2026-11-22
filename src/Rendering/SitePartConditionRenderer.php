<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Rendering;

use Condixia\AISiteToWordPress\Bindings\DynamicBindingDocumentRenderer;
use Condixia\AISiteToWordPress\Editor\EditorDocumentBridge;
use Condixia\AISiteToWordPress\Editor\EditorOverrideRenderer;
use Condixia\AISiteToWordPress\Navigation\NavigationDocumentRenderer;
use Condixia\AISiteToWordPress\Routing\PageRouteMapRepository;
use Condixia\AISiteToWordPress\Storage\AssetRepository;
use Condixia\AISiteToWordPress\Storage\SitePartRepository;
use Condixia\AISiteToWordPress\Storage\SourceFileRepository;
use Condixia\AISiteToWordPress\Templates\DisplayConditionContext;
use Condixia\AISiteToWordPress\Templates\DisplayConditionResolver;

defined('ABSPATH') || exit;

final class SitePartConditionRenderer
{
    private DisplayConditionResolver $conditions;
    private SitePartRepository $siteParts;
    private SourceFileRepository $sources;
    private DynamicBindingDocumentRenderer $bindings;
    private NavigationDocumentRenderer $navigation;
    private AssetRepository $assets;
    private PageRouteMapRepository $routes;
    private HtmlUrlRewriter $urls;
    private EditorDocumentBridge $editor;
    private EditorOverrideRenderer $overrides;

    public function __construct(
        ?DisplayConditionResolver $conditions = null,
        ?SitePartRepository $siteParts = null,
        ?SourceFileRepository $sources = null,
        ?DynamicBindingDocumentRenderer $bindings = null,
        ?NavigationDocumentRenderer $navigation = null,
        ?AssetRepository $assets = null,
        ?PageRouteMapRepository $routes = null,
        ?HtmlUrlRewriter $urls = null,
        ?EditorDocumentBridge $editor = null,
        ?EditorOverrideRenderer $overrides = null
    ) {
        $this->conditions = $conditions
            ?? new DisplayConditionResolver();

        $this->siteParts = $siteParts
            ?? new SitePartRepository();

        $this->sources = $sources
            ?? new SourceFileRepository();

        $this->bindings = $bindings
            ?? new DynamicBindingDocumentRenderer();

        $this->navigation = $navigation
            ?? new NavigationDocumentRenderer();

        $this->assets = $assets
            ?? new AssetRepository();

        $this->routes = $routes
            ?? new PageRouteMapRepository();

        $this->urls = $urls
            ?? new HtmlUrlRewriter();

        $this->editor = $editor
            ?? new EditorDocumentBridge();

        $this->overrides = $overrides
            ?? new EditorOverrideRenderer();
    }

    public function render(
        int $siteId,
        string $sourcePath,
        string $html
    ): string|\WP_Error {
        $context = DisplayConditionContext::current();

        $assetContext = $this->assets
            ->contextForSite(
                $siteId
            );

        if (is_wp_error($assetContext)) {
            return $assetContext;
        }

        $routeMap = $this->routes
            ->mapForSite(
                $siteId
            );

        foreach (
            [
                'header',
                'footer',
            ]
            as $role
        ) {
            $selected = $this
                ->conditions
                ->resolveSitePart(
                    $siteId,
                    $role,
                    $context
                );

            if (is_wp_error($selected)) {
                return $selected;
            }

            if ($selected === null) {
                continue;
            }

            $target = $this->siteParts
                ->memberForSource(
                    $siteId,
                    $role,
                    $sourcePath,
                    (int) $selected['artifact_id']
                );

            if ($target === null) {
                continue;
            }

            $partHtml = $this->sources
                ->readHtml(
                    (int) $selected['site_id'],
                    (int) $selected['manifest_id'],
                    (string) $selected['source_path']
                );

            if (is_wp_error($partHtml)) {
                return $partHtml;
            }

            $partArtifactId = (int) $selected['artifact_id'];

            $hasOverrides = $this->overrides->hasOverrides(
                $siteId,
                $partArtifactId
            );

            if ($hasOverrides) {
                $partHtml = $this->editor->prepareSource(
                    (string) $selected['source_path'],
                    $partHtml
                );

                if (is_wp_error($partHtml)) {
                    return $partHtml;
                }
            }

            $partHtml = $this->bindings
                ->render(
                    $siteId,
                    (int) $selected['artifact_id'],
                    (string) $selected['source_path'],
                    $partHtml,
                    $selected
                );

            if (is_wp_error($partHtml)) {
                return $partHtml;
            }

            $partHtml = $this->navigation
                ->render(
                    $siteId,
                    (string) $selected['source_path'],
                    $partHtml
                );

            if (is_wp_error($partHtml)) {
                return $partHtml;
            }

            $rewritten = $this->urls->rewrite(
                (string) $selected['source_path'],
                $partHtml,
                $assetContext['asset_map'],
                $assetContext['file_index'],
                $routeMap
            );

            if (is_wp_error($rewritten)) {
                return $rewritten;
            }

            $partOutput = (string) $rewritten['html'];

            if ($hasOverrides) {
                $partOutput = $this->editor->normalizeIdentities(
                    $partOutput
                );

                if (is_wp_error($partOutput)) {
                    return $partOutput;
                }

                $partOutput = $this->overrides->render(
                    $siteId,
                    $partArtifactId,
                    $partOutput
                );

                if (is_wp_error($partOutput)) {
                    return $partOutput;
                }
            }

            $replacementPath = (string) (
                $selected['metadata']['representative_path']
                ?? ''
            );

            $targetPath = (string) (
                $target['member']['path']
                ?? ''
            );

            if (
                $replacementPath === ''
                || $targetPath === ''
            ) {
                continue;
            }

            $html = $this->replace(
                $html,
                $targetPath,
                $partOutput,
                $replacementPath
            );

            if (is_wp_error($html)) {
                return $html;
            }
        }

        return $html;
    }

    private function replace(
        string $currentHtml,
        string $targetPath,
        string $replacementHtml,
        string $replacementPath
    ): string|\WP_Error {
        $fullDocument = preg_match(
            '/(?:<!doctype\b|<html\b)/i',
            $currentHtml
        ) === 1;

        $current = $this->document(
            $currentHtml
        );

        $replacement = $this->document(
            $replacementHtml
        );

        if (
            is_wp_error($current)
            || is_wp_error($replacement)
        ) {
            return $this->error();
        }

        $currentElements = [];
        $replacementElements = [];

        if (
            ! $current->documentElement
                instanceof \DOMElement
            || ! $replacement->documentElement
                instanceof \DOMElement
        ) {
            return $this->error();
        }

        $this->mapPaths(
            $current->documentElement,
            '/'
                . strtolower(
                    $current->documentElement
                        ->tagName
                )
                . '[1]',
            $currentElements
        );

        $this->mapPaths(
            $replacement->documentElement,
            '/'
                . strtolower(
                    $replacement
                        ->documentElement
                        ->tagName
                )
                . '[1]',
            $replacementElements
        );

        if (
            ! isset(
                $currentElements[$targetPath],
                $replacementElements[$replacementPath]
            )
        ) {
            return $currentHtml;
        }

        $target = $currentElements[$targetPath];

        $source = $replacementElements[$replacementPath];

        if (
            in_array(
                strtolower($target->tagName),
                ['html', 'head', 'body', 'main'],
                true
            )
            || $target->getElementsByTagName('main')->length > 0
            || $source->getElementsByTagName('main')->length > 0
        ) {
            return $currentHtml;
        }

        $parent = $target->parentNode;

        if ($parent === null) {
            return $currentHtml;
        }

        $parent->replaceChild(
            $current->importNode(
                $source,
                true
            ),
            $target
        );

        if ($fullDocument) {
            $result = $current->saveHTML();

            return is_string($result)
                ? $result
                : $this->error();
        }

        $body = $current
            ->getElementsByTagName('body')
            ->item(0);

        if (! $body instanceof \DOMElement) {
            return $this->error();
        }

        $result = '';

        foreach ($body->childNodes as $child) {
            $fragment = $current->saveHTML(
                $child
            );

            if (is_string($fragment)) {
                $result .= $fragment;
            }
        }

        return $result;
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
                $node->nodeType === XML_PI_NODE
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
            'condixia_ai_stw_site_part_render',
            __(
                'The assigned site part could not be rendered.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
