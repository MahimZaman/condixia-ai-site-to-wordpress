<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Editor;

use Condixia\AISiteToWordPress\Storage\EditorStateRepository;

defined('ABSPATH') || exit;

final class EditorOverrideRenderer
{
    private EditorStateRepository $state;
    private array $loadedOverrides = [];

    public function __construct(
        ?EditorStateRepository $state = null
    ) {
        $this->state = $state
            ?? new EditorStateRepository();
    }

    public function hasOverrides(int $siteId, int $artifactId): bool
    {
        return $this->loadOverrides($siteId, $artifactId) !== [];
    }

    private function loadOverrides(int $siteId, int $artifactId): array
    {
        if ($siteId < 1 || $artifactId < 1) {
            return [];
        }

        $key = $siteId . ':' . $artifactId;

        if (! array_key_exists($key, $this->loadedOverrides)) {
            $this->loadedOverrides[$key] = $this->state->overrides(
                $siteId,
                $artifactId
            );
        }

        return $this->loadedOverrides[$key];
    }

    public function render(
        int $siteId,
        int $artifactId,
        string $html
    ): string|\WP_Error {
        if (
            $siteId < 1
            || $artifactId < 1
        ) {
            return $html;
        }

        $overrides = $this->loadOverrides($siteId, $artifactId);

        if ($overrides === []) {
            return $html;
        }

        if (
            ! class_exists(
                \DOMDocument::class
            )
        ) {
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

        $elements = [];

        foreach (
            $document
                ->getElementsByTagName('*')
            as $element
        ) {
            if (
                ! $element
                    instanceof \DOMElement
            ) {
                continue;
            }

            $id = $element
                ->getAttribute(
                    'data-cdx-id'
                );

            if ($id !== '') {
                $elements[$id]
                    = $element;
            }
        }

        $orders = [];
        $hasVisibility = false;

        foreach (
            $overrides
            as $override
        ) {
            $elementId = (string) (
                $override['element_id']
                ?? ''
            );

            if (
                $elementId === ''
                || ! isset(
                    $elements[$elementId]
                )
            ) {
                continue;
            }

            $key = (string) ($override['property_key'] ?? '');
            $value = (string) ($override['value'] ?? '');
            if ($key === 'childorder') {
                $orders[] = [$elements[$elementId], $value];
                continue;
            }
            if ($key === 'visibility') {
                $hasVisibility = $this->applyVisibility($elements[$elementId], $value) || $hasVisibility;
                continue;
            }

            $this->apply(
                $elements[$elementId],
                (string) (
                    $override['property_key']
                    ?? ''
                ),
                (string) (
                    $override['value']
                    ?? ''
                )
            );
        }

        foreach ($orders as [$parent, $order]) {
            $this->applyChildOrder($parent, $order);
        }
        if ($hasVisibility) $this->injectVisibilityCss($document, $fullDocument);

        return $this->serialize(
            $document,
            $fullDocument
        );
    }

    private function apply(
        \DOMElement $element,
        string $property,
        string $value
    ): void {
        switch ($property) {
            case 'text':
                // Legacy text overrides must never flatten nested inline markup.
                if ($element->getElementsByTagName('*')->length === 0) {
                    $this->replaceText($element, $value);
                }
                break;

            case 'richtext':
                $this->applyRichText($element, $value);
                break;

            case 'linkurl':
                $element->setAttribute(
                    'href',
                    $value
                );
                break;

            case 'linktarget':
                $this->linkTarget(
                    $element,
                    $value
                );
                break;

            case 'imagesrc':
                $this->imageSource(
                    $element,
                    $value
                );
                break;

            case 'imagealt':
                $element->setAttribute(
                    'alt',
                    $value
                );
                break;

            case 'backgroundimage':
                $cleanUrl = str_replace(
                    [
                        '"',
                        "'",
                        '\\',
                        "\r",
                        "\n",
                    ],
                    '',
                    $value
                );

                $this->style(
                    $element,
                    'background-image',
                    'url("' . $cleanUrl . '")'
                );
                break;

            case 'color':
                $this->style(
                    $element,
                    'color',
                    $value,
                    true
                );
                break;

            case 'backgroundcolor':
                $solid = str_starts_with($value, 'solid:');
                $this->style(
                    $element,
                    'background-color',
                    $solid ? substr($value, 6) : $value,
                    true
                );
                if ($solid) {
                    // A gradient/image can obscure a changed solid background.
                    $this->style($element, 'background-image', 'none', true);
                    $element->setAttribute('data-cdx-solid-background', '1');
                }
                break;

            case 'fontsize':
                $this->style(
                    $element,
                    'font-size',
                    $value
                );
                break;

            case 'fontweight':
                $this->style(
                    $element,
                    'font-weight',
                    $value
                );
                break;

            case 'textalign':
                $this->style(
                    $element,
                    'text-align',
                    $value
                );
                break;

            case 'padding':
                $this->style(
                    $element,
                    'padding',
                    $value
                );
                break;

            case 'margin':
                $this->style(
                    $element,
                    'margin',
                    $value
                );
                break;

            case 'border':
                $this->style(
                    $element,
                    'border',
                    $value
                );
                break;

            case 'borderradius':
                $this->style(
                    $element,
                    'border-radius',
                    $value
                );
                break;
        }
    }

    /** Apply only to the original ordered text nodes, never change inline markup. */

    /** Only source-identifiable section siblings are eligible for structural movement. */
    private function compatibleChildren(\DOMElement $parent): array
    {
        if (! in_array(strtolower($parent->tagName), ['main','section','article','div'], true)
            || $parent->getAttribute('data-cdx-id') === ''
            || $parent->hasAttribute('data-cdx-grid-managed')
            || preg_match('/(?:^|[\s_-])(grid|products|cards|list|slider|carousel|swiper)(?:$|[\s_-])/i',
                $parent->getAttribute('class')) === 1
            || $parent->getElementsByTagName('main')->length > 0) return [];
        for ($ancestor = $parent; $ancestor instanceof \DOMElement; $ancestor = $ancestor->parentNode) {
            if (in_array(strtolower($ancestor->tagName), ['header','footer','nav','form','table','ul','ol'], true)) return [];
        }
        $children = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMText) {
                if (trim($node->textContent) !== '') return [];
                continue;
            }
            if (! $node instanceof \DOMElement) return [];
            if (! in_array(strtolower($node->tagName), ['section','article','aside','div'], true)
                || preg_match('/^cdx-stw-[a-f0-9]{24}$/', $node->getAttribute('data-cdx-id')) !== 1
                || $node->getElementsByTagName('main')->length > 0) return [];
            $children[] = $node;
        }
        return count($children) >= 2 && count($children) <= 80 ? $children : [];
    }

    private function applyChildOrder(\DOMElement $parent, string $value): void
    {
        $decoded = json_decode($value, true, 8);
        if (! is_array($decoded) || ! isset($decoded['original'], $decoded['order'])
            || ! is_array($decoded['original']) || ! is_array($decoded['order'])) return;
        $children = $this->compatibleChildren($parent);
        $original = array_map(static fn($node) => $node->getAttribute('data-cdx-id'), $children);
        if ($original === [] || $original !== $decoded['original']
            || count(array_unique($original)) !== count($original)
            || count($decoded['order']) !== count($original)
            || count(array_unique($decoded['order'])) !== count($original)
            || array_diff($original, $decoded['order'])) return;
        $map = array_combine($original, $children);
        foreach ($decoded['order'] as $id) {
            if (! isset($map[$id])) return;
        }
        foreach ($decoded['order'] as $id) {
            $parent->appendChild($map[$id]);
        }
        // Used only by the editor to recover the exact imported position after reload.
        $parent->setAttribute('data-cdx-order-original', implode(',', $original));
    }

    private function applyVisibility(\DOMElement $element, string $value): bool
    {
        if (preg_match('/^[01]{3}$/', $value) !== 1
            || ! in_array(strtolower($element->tagName), ['section','article','aside','div'], true)
            || $element->getElementsByTagName('main')->length > 0) return false;
        for ($ancestor = $element; $ancestor instanceof \DOMElement; $ancestor = $ancestor->parentNode) {
            if (in_array(strtolower($ancestor->tagName), ['header','footer','nav','form','table','ul','ol'], true)) return false;
        }
        $element->setAttribute('data-cdx-visibility', $value);
        return true;
    }

    private function injectVisibilityCss(\DOMDocument $document, bool $fullDocument): void
    {
        $css = '@media(min-width:1025px){[data-cdx-visibility^="0"]{display:none!important}}'
            . '@media(min-width:768px) and (max-width:1024px){'
            . '[data-cdx-visibility="000"],[data-cdx-visibility="001"],'
            . '[data-cdx-visibility="100"],[data-cdx-visibility="101"]{display:none!important}}'
            . '@media(max-width:767px){[data-cdx-visibility$="0"]{display:none!important}}';
        $style = $document->createElement('style');
        $style->setAttribute('data-cdx-visibility-rules', '1');
        $style->appendChild($document->createTextNode($css));
        $target = $fullDocument
            ? $document->getElementsByTagName('head')->item(0)
            : $document->getElementsByTagName('body')->item(0);
        if (! $target instanceof \DOMElement) return;
        if ($fullDocument) $target->appendChild($style);
        else $target->insertBefore($style, $target->firstChild);
    }

    private function applyRichText(\DOMElement $element, string $value): void
    {
        if (! in_array(strtoupper($element->tagName), [
            'A', 'BUTTON', 'EM', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
            'LABEL', 'LI', 'P', 'SMALL', 'SPAN', 'STRONG', 'TD', 'TH',
        ], true)) return;

        $segments = json_decode($value, true, 16);
        if (! is_array($segments) || ! array_is_list($segments)
            || count($segments) > 100 || $segments === []) return;

        $allowed = [
            'span', 'strong', 'em', 'b', 'i', 'mark', 'small', 'a', 'br',
            'code', 'sub', 'sup', 'u', 's', 'del', 'ins', 'abbr', 'time', 'wbr',
        ];
        $nodes = [];
        $valid = true;
        $visit = static function (\DOMNode $node, string $path, int $depth)
            use (&$visit, &$nodes, &$valid, $allowed): void {
            if (! $valid || $depth > 12) { $valid = false; return; }
            if ($node instanceof \DOMText) {
                $nodes[] = ['p' => $path, 'node' => $node];
                if (count($nodes) > 100) $valid = false;
                return;
            }
            if (! $node instanceof \DOMElement
                || ! in_array(strtolower($node->tagName), $allowed, true)
                || $node->hasAttribute('contenteditable')) {
                $valid = false;
                return;
            }
            foreach ($node->childNodes as $index => $child) {
                $visit($child, $path . '.' . $index, $depth + 1);
            }
        };

        foreach ($element->childNodes as $index => $child) {
            $visit($child, (string) $index, 1);
        }

        if (! $valid || count($nodes) !== count($segments)) return;
        foreach ($segments as $index => $part) {
            if (! is_array($part) || ! isset($part['p'], $part['t'])
                || ! is_string($part['p']) || ! is_string($part['t'])
                || $part['p'] !== $nodes[$index]['p'] || $part['t'] === '') return;
        }
        // Commit only after checking every path: no partial or destructive edits.
        foreach ($segments as $index => $part) {
            $nodes[$index]['node']->nodeValue = $part['t'];
        }
    }

    private function replaceText(
        \DOMElement $element,
        string $value
    ): void {
        $nodes = [];

        foreach (
            $element->childNodes
            as $child
        ) {
            $this->textNodes(
                $child,
                $nodes
            );
        }

        if ($nodes === []) {
            $element->appendChild(
                $element
                    ->ownerDocument
                    ->createTextNode(
                        $value
                    )
            );

            return;
        }

        $nodes[0]->nodeValue = $value;

        for (
            $index = 1;
            $index < count($nodes);
            $index++
        ) {
            $nodes[$index]
                ->nodeValue = '';
        }
    }

    private function textNodes(
        \DOMNode $node,
        array &$nodes
    ): void {
        if ($node instanceof \DOMText) {
            if (
                trim(
                    (string)
                    $node->nodeValue
                ) !== ''
            ) {
                $nodes[] = $node;
            }

            return;
        }

        foreach (
            $node->childNodes
            as $child
        ) {
            $this->textNodes(
                $child,
                $nodes
            );
        }
    }

    private function linkTarget(
        \DOMElement $element,
        string $value
    ): void {
        if ($value === '_blank') {
            $element->setAttribute(
                'target',
                '_blank'
            );

            $element->setAttribute(
                'rel',
                'noopener noreferrer'
            );

            return;
        }

        $element->removeAttribute(
            'target'
        );

        if (
            $element->getAttribute(
                'rel'
            ) === 'noopener noreferrer'
        ) {
            $element->removeAttribute(
                'rel'
            );
        }
    }

    private function imageSource(
        \DOMElement $element,
        string $value
    ): void {
        $element->setAttribute(
            'src',
            $value
        );

        $element->removeAttribute(
            'srcset'
        );

        $element->removeAttribute(
            'sizes'
        );
    }

    private function style(
        \DOMElement $element,
        string $property,
        string $value,
        bool $important = false
    ): void {
        if ($value === '') {
            return;
        }

        $style = trim(
            $element->getAttribute(
                'style'
            )
        );

        if (
            $style !== ''
            && ! str_ends_with(
                $style,
                ';'
            )
        ) {
            $style .= ';';
        }

        $style .= $property
            . ':'
            . $value
            . ($important ? ' !important' : '')
            . ';';

        $element->setAttribute(
            'style',
            $style
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
            $output = $document
                ->saveHTML();

            return is_string($output)
                ? $output
                : $this->error();
        }

        $body = $document
            ->getElementsByTagName(
                'body'
            )
            ->item(0);

        if (
            ! $body
                instanceof \DOMElement
        ) {
            return $this->error();
        }

        $output = '';

        foreach (
            $body->childNodes
            as $child
        ) {
            $fragment = $document
                ->saveHTML($child);

            if (is_string($fragment)) {
                $output .= $fragment;
            }
        }

        return $output;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_editor_override_render',
            __(
                'The saved Condixia editor overrides could not be rendered.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
