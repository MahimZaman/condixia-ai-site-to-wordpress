<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Navigation;

use Condixia\AISiteToWordPress\Storage\NavigationBindingRepository;

defined('ABSPATH') || exit;

final class NavigationDocumentRenderer
{
    private NavigationBindingRepository $bindings;
    private NavigationBindingRenderer $renderer;

    public function __construct(
        ?NavigationBindingRepository $bindings = null,
        ?NavigationBindingRenderer $renderer = null
    ) {
        $this->bindings = $bindings
            ?? new NavigationBindingRepository();

        $this->renderer = $renderer
            ?? new NavigationBindingRenderer(
                $this->bindings
            );
    }

    public function render(
        int $siteId,
        string $sourcePath,
        string $html
    ): string|\WP_Error {
        $artifacts = $this->bindings->forSource(
            $siteId,
            $sourcePath
        );

        // Browser-only menus are handled by editor-runtime.js after scripts
        // render them. Do not DOMDocument-rewrite the source for those alone.
        $artifacts = array_values(array_filter(
            $artifacts,
            static function (array $artifact) use ($sourcePath): bool {
                foreach ($artifact['metadata']['members'] ?? [] as $member) {
                    if (
                        is_array($member)
                        && ($member['source'] ?? '') === $sourcePath
                        && ($member['mode'] ?? '') !== 'runtime'
                    ) {
                        return true;
                    }
                }
                return false;
            }
        ));

        if ($artifacts === []) {
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

        foreach ($artifacts as $artifact) {
            $member = $this->member(
                $artifact['metadata'] ?? [],
                $sourcePath
            );

            if ($member === null) {
                continue;
            }

            $path = (string) (
                $member['container_path']
                ?? ''
            );

            if ($path === '') {
                continue;
            }

            $elements = [];

            $root = $document->documentElement;

            if (! $root instanceof \DOMElement) {
                return $this->error();
            }

            $this->mapPaths(
                $root,
                '/'
                    . strtolower(
                        $root->tagName
                    )
                    . '[1]',
                $elements
            );

            if (
                ! isset($elements[$path])
                || ! $elements[$path]
                    instanceof \DOMElement
            ) {
                continue;
            }

            $rendered = $this->renderer
                ->render(
                    (int) $artifact['id'],
                    $sourcePath
                );

            if (is_wp_error($rendered)) {
                continue;
            }

            $replacement = $this
                ->replacement(
                    $rendered,
                    (string) (
                        $member['container_tag']
                        ?? ''
                    )
                );

            if ($replacement === null) {
                continue;
            }

            $target = $elements[$path];

            if (
                strtolower($target->tagName) !== strtolower($replacement->tagName)
                || ! $this->isNavigationOnlyContainer($target)
            ) {
                continue;
            }

            $parent = $target->parentNode;

            if ($parent === null) {
                continue;
            }

            $imported = $document->importNode(
                $replacement,
                true
            );

            $parent->replaceChild(
                $imported,
                $target
            );
        }

        if ($fullDocument) {
            $renderedDocument = $document
                ->saveHTML();

            return is_string($renderedDocument)
                ? $renderedDocument
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

    public function isNavigationOnlyContainer(
        \DOMElement $element
    ): bool {
        $tag = strtolower($element->tagName);
        $role = strtolower($element->getAttribute('role'));

        if (
            $tag !== 'nav'
            && $role !== 'navigation'
            && ! in_array($tag, ['div', 'ul', 'ol'], true)
        ) {
            return false;
        }

        if ($element->getElementsByTagName('nav')->length > 0) {
            return false;
        }

        foreach ($element->getElementsByTagName('*') as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            if (
                in_array(
                    strtolower($child->tagName),
                    [
                        'button',
                        'form',
                        'input',
                        'select',
                        'textarea',
                        'script',
                        'style',
                        'iframe',
                        'object',
                        'embed',
                        'h1',
                        'h2',
                        'h3',
                        'h4',
                        'h5',
                        'h6',
                    ],
                    true
                )
            ) {
                return false;
            }

            $childClasses = preg_split(
                '/\s+/',
                trim(strtolower($child->getAttribute('class')))
            );

            if (
                is_array($childClasses)
                && array_intersect(
                    $childClasses,
                    ['navbar-brand', 'site-logo', 'site-brand', 'search-form']
                ) !== []
            ) {
                return false;
            }
        }

        if ($tag === 'nav' || $role === 'navigation') {
            return true;
        }

        // A list inside <nav> can be a safe menu-only region,
        // even without specific WordPress/Bootstrap menu classes.
        if (in_array($tag, ['ul', 'ol'], true)) {
            for (
                $parent = $element->parentNode;
                $parent instanceof \DOMElement;
                $parent = $parent->parentNode
            ) {
                if (
                    strtolower($parent->tagName) === 'nav'
                    || strtolower(
                        $parent->getAttribute('role')
                    ) === 'navigation'
                ) {
                    return true;
                }

                if (in_array(
                    strtolower($parent->tagName),
                    ['header', 'body'],
                    true
                )) {
                    break;
                }
            }
        }

        $classes = preg_split(
            '/\s+/',
            trim(strtolower($element->getAttribute('class')))
        );

        return is_array($classes)
            && array_intersect(
                $classes,
                [
                    'navbar-nav',
                    'nav-menu',
                    'nav-list',
                    'menu',
                    'menu-list',
                    'menu-items',
                    'main-menu',
                ]
            ) !== [];
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

    private function replacement(
        string $html,
        string $tag
    ): ?\DOMElement {
        $document = $this->document($html);

        if (is_wp_error($document)) {
            return null;
        }

        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof \DOMElement) {
            return null;
        }

        $root = null;

        foreach ($body->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            if ($root !== null) {
                return null;
            }

            $root = $child;
        }

        if (
            ! $root instanceof \DOMElement
            || ($tag !== '' && strtolower($root->tagName) !== strtolower($tag))
        ) {
            return null;
        }

        return $root;
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

        return null;
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

            if (! isset($counts[$tag])) {
                $counts[$tag] = 0;
            }

            $counts[$tag]++;

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
            'condixia_ai_stw_navigation_document_render',
            __(
                'The imported navigation document could not be rendered.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
