<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Navigation;

use Condixia\AISiteToWordPress\Storage\NavigationBindingRepository;

defined('ABSPATH') || exit;

final class NavigationBindingRenderer
{
    private NavigationBindingRepository $bindings;
    private NavigationProviderResolver $providers;

    public function __construct(
        ?NavigationBindingRepository $bindings = null,
        ?NavigationProviderResolver $providers = null
    ) {
        $this->bindings = $bindings
            ?? new NavigationBindingRepository();

        $this->providers = $providers
            ?? new NavigationProviderResolver();
    }

    public function render(
        int $artifactId,
        string $sourcePath = ''
    ): string|\WP_Error {
        $artifact = $this->bindings
            ->artifact(
                $artifactId
            );

        if ($artifact === null) {
            return $this->error(
                __(
                    'The navigation artifact could not be loaded.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $binding = $this->bindings
            ->forArtifact(
                $artifactId
            );

        if ($binding === null) {
            return $this->error(
                __(
                    'The WordPress navigation binding could not be loaded.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $provider = $this->providers->byKey(
            (string) $binding['data_source']
        );

        if (
            $provider === null
            || ! $provider->exists(
                (int) $binding['binding_target']
            )
        ) {
            return $this->error(
                __(
                    'The WordPress navigation source no longer exists.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $nativeItems = $provider->items(
            (int) $binding['binding_target']
        );

        if (is_wp_error($nativeItems)) {
            return $nativeItems;
        }

        $metadata = $artifact['metadata'];

        $member = $this->member(
            $metadata,
            $sourcePath
        );

        if ($member === null) {
            return $this->error(
                __(
                    'The imported navigation markup could not be located.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $templateHtml = (string) (
            $member['template_html']
            ?? ''
        );

        $templateItems = is_array(
            $member['items'] ?? null
        )
            ? $member['items']
            : [];

        $mode = (string) (
            $member['mode']
            ?? $metadata['mode']
            ?? ''
        );

        if (
            $templateHtml === ''
            || $templateItems === []
        ) {
            return $this->error(
                __(
                    'The imported navigation template is incomplete.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        if ($nativeItems === []) {
            return $templateHtml;
        }

        return match ($mode) {
            'list' => $this->renderList(
                $templateHtml,
                $templateItems,
                $nativeItems,
                (string) (
                    $member['container_tag']
                    ?? $metadata['container_tag']
                    ?? 'nav'
                )
            ),
            'links' => $this->renderLinks(
                $templateHtml,
                $templateItems,
                $nativeItems,
                (string) (
                    $member['container_tag']
                    ?? $metadata['container_tag']
                    ?? 'nav'
                )
            ),
            default => $this->error(
                __(
                    'The navigation markup mode is unsupported.',
                    'condixia-ai-site-to-wordpress'
                )
            ),
        };
    }

    private function renderList(
        string $templateHtml,
        array $templateItems,
        array $nativeItems,
        string $containerTag
    ): string|\WP_Error {
        $document = $this->document(
            $templateHtml
        );

        if (is_wp_error($document)) {
            return $document;
        }

        $container = $this->container(
            $document,
            $containerTag
        );

        if ($container === null) {
            return $this->error(
                __(
                    'The imported navigation container could not be found.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $list = $this->listRoot(
            $container
        );

        if ($list === null) {
            return $this->error(
                __(
                    'The imported navigation list could not be found.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $templates = [];
        $templateData = [];
        $submenuTemplate = null;

        $this->mapListTemplates(
            $list,
            $templateItems,
            $templates,
            $templateData,
            $submenuTemplate
        );

        $fallback = $this->firstDirectListItem(
            $list
        );

        if ($fallback === null) {
            return $this->error(
                __(
                    'The imported navigation has no reusable item markup.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $this->removeDirectListItems(
            $list
        );

        foreach ($nativeItems as $nativeItem) {
            $rendered = $this->renderListItem(
                $document,
                $nativeItem,
                $templates,
                $templateData,
                $fallback,
                $submenuTemplate
            );

            if ($rendered !== null) {
                $list->appendChild(
                    $rendered
                );
            }
        }

        $html = $document->saveHTML(
            $container
        );

        return is_string($html)
            ? $html
            : $this->error(
                __(
                    'The navigation markup could not be rendered.',
                    'condixia-ai-site-to-wordpress'
                )
            );
    }

    private function renderLinks(
        string $templateHtml,
        array $templateItems,
        array $nativeItems,
        string $containerTag
    ): string|\WP_Error {
        $document = $this->document(
            $templateHtml
        );

        if (is_wp_error($document)) {
            return $document;
        }

        $container = $this->container(
            $document,
            $containerTag
        );

        if ($container === null) {
            return $this->error(
                __(
                    'The imported navigation container could not be found.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $anchors = $this->directAnchors(
            $container
        );

        if ($anchors === []) {
            return $this->error(
                __(
                    'The imported navigation links could not be found.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $templates = [];
        $data = [];

        foreach (
            $templateItems
            as $index => $item
        ) {
            if (
                ! isset($anchors[$index])
                || ! is_array($item)
            ) {
                continue;
            }

            $key = (string) (
                $item['key']
                ?? ''
            );

            if ($key === '') {
                continue;
            }

            $templates[$key] = $anchors[$index];

            $data[$key] = $item;
        }

        $fallback = $anchors[0];

        foreach ($anchors as $anchor) {
            $container->removeChild(
                $anchor
            );
        }

        foreach ($nativeItems as $nativeItem) {
            $key = (string) (
                $nativeItem['key']
                ?? ''
            );

            $template = $templates[$key]
                ?? $fallback;

            $anchor = $template->cloneNode(
                true
            );

            if (! $anchor instanceof \DOMElement) {
                continue;
            }

            $sourceData = $data[$key]
                ?? [];

            $this->updateAnchor(
                $anchor,
                $nativeItem,
                (string) (
                    $sourceData['source_label'] ?? ''
                )
            );

            $container->appendChild(
                $anchor
            );
        }

        $html = $document->saveHTML(
            $container
        );

        return is_string($html)
            ? $html
            : $this->error(
                __(
                    'The navigation markup could not be rendered.',
                    'condixia-ai-site-to-wordpress'
                )
            );
    }

    private function mapListTemplates(
        \DOMElement $list,
        array $items,
        array &$templates,
        array &$templateData,
        ?\DOMElement &$submenuTemplate
    ): void {
        $listItems = $this->directListItems(
            $list
        );

        foreach ($items as $index => $item) {
            if (
                ! isset($listItems[$index])
                || ! is_array($item)
            ) {
                continue;
            }

            $key = (string) (
                $item['key']
                ?? ''
            );

            $listItem = $listItems[$index];

            if ($key !== '') {
                $templates[$key] = $listItem;
                $templateData[$key] = $item;
            }

            $nested = $this->nestedList(
                $listItem
            );

            if ($nested !== null) {
                if ($submenuTemplate === null) {
                    $submenuTemplate = $nested;
                }

                $this->mapListTemplates(
                    $nested,
                    is_array(
                        $item['children']
                            ?? null
                    )
                        ? $item['children']
                        : [],
                    $templates,
                    $templateData,
                    $submenuTemplate
                );
            }
        }
    }

    private function renderListItem(
        \DOMDocument $document,
        array $nativeItem,
        array $templates,
        array $templateData,
        \DOMElement $fallback,
        ?\DOMElement $submenuTemplate
    ): ?\DOMElement {
        $key = (string) (
            $nativeItem['key']
            ?? ''
        );

        $source = $templates[$key]
            ?? $fallback;

        $clone = $source->cloneNode(
            true
        );

        if (! $clone instanceof \DOMElement) {
            return null;
        }

        $sourceData = $templateData[$key]
            ?? [];

        $anchor = $this->primaryAnchor(
            $clone
        );

        if ($anchor !== null) {
            $this->updateAnchor(
                $anchor,
                $nativeItem,
                (string) (
                    $sourceData['source_label'] ?? ''
                )
            );
        }

        $existingSubmenu = $this->nestedList(
            $clone
        );

        if ($existingSubmenu !== null) {
            $existingSubmenu
                ->parentNode
                ?->removeChild(
                    $existingSubmenu
                );
        }

        $children = is_array(
            $nativeItem['children']
                ?? null
        )
            ? $nativeItem['children']
            : [];

        if ($children === []) {
            return $clone;
        }

        if ($existingSubmenu !== null) {
            $submenu = $existingSubmenu;
        } elseif ($submenuTemplate !== null) {
            $submenu = $submenuTemplate
                ->cloneNode(true);
        } else {
            $submenu = $document
                ->createElement('ul');
        }

        if (! $submenu instanceof \DOMElement) {
            return $clone;
        }

        $this->removeDirectListItems(
            $submenu
        );

        foreach ($children as $child) {
            $renderedChild = $this
                ->renderListItem(
                    $document,
                    $child,
                    $templates,
                    $templateData,
                    $fallback,
                    $submenuTemplate
                );

            if ($renderedChild !== null) {
                $submenu->appendChild(
                    $renderedChild
                );
            }
        }

        $clone->appendChild(
            $submenu
        );

        return $clone;
    }

    private function updateAnchor(
        \DOMElement $anchor,
        array $item,
        string $sourceLabel
    ): void {
        $anchor->setAttribute(
            'href',
            (string) (
                $item['url']
                ?? ''
            )
        );

        if (
            ! empty($item['opens_in_new_tab'])
        ) {
            $anchor->setAttribute(
                'target',
                '_blank'
            );
        } else {
            $anchor->removeAttribute(
                'target'
            );
        }

        $rel = trim(
            (string) (
                $item['rel']
                ?? ''
            )
        );

        if ($rel !== '') {
            $anchor->setAttribute(
                'rel',
                $rel
            );
        } else {
            $anchor->removeAttribute(
                'rel'
            );
        }

        $this->replaceLabel(
            $anchor,
            (string) (
                $item['label']
                ?? ''
            ),
            $sourceLabel
        );
    }

    private function replaceLabel(
        \DOMElement $anchor,
        string $label,
        string $sourceLabel
    ): void {
        if ($label === '') {
            return;
        }

        $textNodes = [];

        $this->collectTextNodes(
            $anchor,
            $textNodes
        );

        if ($textNodes === []) {
            $anchor->appendChild(
                $anchor->ownerDocument
                    ->createTextNode(
                        $label
                    )
            );

            return;
        }

        foreach ($textNodes as $node) {
            $normalized = $this
                ->normalizeText(
                    $node->nodeValue
                        ?? ''
                );

            if (
                $sourceLabel !== ''
                && $normalized
                === $this->normalizeText(
                    $sourceLabel
                )
            ) {
                $node->nodeValue = $label;

                return;
            }
        }

        if (count($textNodes) === 1) {
            $textNodes[0]->nodeValue
                = $label;

            return;
        }

        foreach ($textNodes as $node) {
            if (
                $this->normalizeText(
                    $node->nodeValue
                        ?? ''
                ) !== ''
            ) {
                $node->nodeValue = $label;

                return;
            }
        }
    }

    private function collectTextNodes(
        \DOMNode $node,
        array &$nodes
    ): void {
        foreach ($node->childNodes as $child) {
            if (
                $child instanceof \DOMText
                && $this->normalizeText(
                    $child->nodeValue
                        ?? ''
                ) !== ''
            ) {
                $nodes[] = $child;

                continue;
            }

            if ($child instanceof \DOMElement) {
                $this->collectTextNodes(
                    $child,
                    $nodes
                );
            }
        }
    }

    private function normalizeText(
        string $value
    ): string {
        $value = preg_replace(
            '/\s+/u',
            ' ',
            $value
        );

        return is_string($value)
            ? trim($value)
            : '';
    }

    private function document(
        string $html
    ): \DOMDocument|\WP_Error {
        if (! class_exists(\DOMDocument::class)) {
            return $this->error(
                __(
                    'The PHP DOM extension is required to render navigation bindings.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

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
            return $this->error(
                __(
                    'The imported navigation markup could not be parsed.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        return $document;
    }

    private function container(
        \DOMDocument $document,
        string $tag
    ): ?\DOMElement {
        $node = $document
            ->getElementsByTagName(
                strtolower($tag)
            )
            ->item(0);

        return $node instanceof \DOMElement
            ? $node
            : null;
    }

    private function member(
        array $metadata,
        string $sourcePath
    ): ?array {
        $members = is_array(
            $metadata['members']
                ?? null
        )
            ? $metadata['members']
            : [];

        if ($sourcePath !== '') {
            foreach ($members as $member) {
                if (
                    is_array($member)
                    && (
                        $member['source']
                        ?? ''
                    ) === $sourcePath
                ) {
                    return $member;
                }
            }
        }

        foreach ($members as $member) {
            if (is_array($member)) {
                return $member;
            }
        }

        return null;
    }

    private function listRoot(
        \DOMElement $container
    ): ?\DOMElement {
        if (
            in_array(
                strtolower(
                    $container->tagName
                ),
                [
                    'ul',
                    'ol',
                ],
                true
            )
        ) {
            return $container;
        }

        foreach (
            ['ul', 'ol']
            as $tag
        ) {
            $node = $container
                ->getElementsByTagName(
                    $tag
                )
                ->item(0);

            if ($node instanceof \DOMElement) {
                return $node;
            }
        }

        return null;
    }

    private function firstDirectListItem(
        \DOMElement $list
    ): ?\DOMElement {
        foreach ($list->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && strtolower(
                    $child->tagName
                ) === 'li'
            ) {
                return $child;
            }
        }

        return null;
    }

    private function directListItems(
        \DOMElement $list
    ): array {
        $items = [];

        foreach ($list->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && strtolower(
                    $child->tagName
                ) === 'li'
            ) {
                $items[] = $child;
            }
        }

        return $items;
    }

    private function removeDirectListItems(
        \DOMElement $list
    ): void {
        foreach (
            $this->directListItems($list)
            as $item
        ) {
            $list->removeChild(
                $item
            );
        }
    }

    private function directAnchors(
        \DOMElement $container
    ): array {
        $anchors = [];

        foreach (
            $container->childNodes
            as $child
        ) {
            if (
                $child instanceof \DOMElement
                && strtolower(
                    $child->tagName
                ) === 'a'
            ) {
                $anchors[] = $child;
            }
        }

        return $anchors;
    }

    private function primaryAnchor(
        \DOMElement $item
    ): ?\DOMElement {
        foreach ($item->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            if (
                strtolower(
                    $child->tagName
                ) === 'a'
            ) {
                return $child;
            }

            $anchor = $this
                ->firstAnchorOutsideList(
                    $child
                );

            if ($anchor !== null) {
                return $anchor;
            }
        }

        return null;
    }

    private function firstAnchorOutsideList(
        \DOMElement $element
    ): ?\DOMElement {
        $tag = strtolower(
            $element->tagName
        );

        if ($tag === 'a') {
            return $element;
        }

        if (
            in_array(
                $tag,
                [
                    'ul',
                    'ol',
                ],
                true
            )
        ) {
            return null;
        }

        foreach (
            $element->childNodes
            as $child
        ) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $anchor = $this
                ->firstAnchorOutsideList(
                    $child
                );

            if ($anchor !== null) {
                return $anchor;
            }
        }

        return null;
    }

    private function nestedList(
        \DOMElement $item
    ): ?\DOMElement {
        foreach (
            $item->childNodes
            as $child
        ) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower(
                $child->tagName
            );

            if (
                in_array(
                    $tag,
                    [
                        'ul',
                        'ol',
                    ],
                    true
                )
            ) {
                return $child;
            }

            $nested = $this
                ->nestedListRecursive(
                    $child
                );

            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    private function nestedListRecursive(
        \DOMElement $element
    ): ?\DOMElement {
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

            if ($tag === 'li') {
                continue;
            }

            if (
                in_array(
                    $tag,
                    [
                        'ul',
                        'ol',
                    ],
                    true
                )
            ) {
                return $child;
            }

            $nested = $this
                ->nestedListRecursive(
                    $child
                );

            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    private function error(
        string $message
    ): \WP_Error {
        return new \WP_Error(
            'condixia_ai_stw_navigation_render',
            $message
        );
    }
}
