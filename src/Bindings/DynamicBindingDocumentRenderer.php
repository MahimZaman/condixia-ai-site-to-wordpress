<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Bindings;

use Condixia\AISiteToWordPress\Storage\DynamicBindingRepository;

defined('ABSPATH') || exit;

final class DynamicBindingDocumentRenderer
{
    private DynamicBindingRepository $bindings;
    private BindingValueResolver $values;

    public function __construct(
        ?DynamicBindingRepository $bindings = null,
        ?BindingValueResolver $values = null
    ) {
        $this->bindings = $bindings
            ?? new DynamicBindingRepository();

        $this->values = $values
            ?? new BindingValueResolver();
    }

    public function render(
        int $siteId,
        int $artifactId,
        string $sourcePath,
        string $html,
        array $resolved = []
    ): string|\WP_Error {
        if (
            $siteId < 1
            || $artifactId < 1
            || $sourcePath === ''
        ) {
            return $html;
        }

        $bindings = $this->bindings
            ->forArtifact(
                $artifactId
            );

        if ($bindings === []) {
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

        $context = [
            'site_id' => $siteId,
            'artifact_id' => $artifactId,
            'source_path' => $sourcePath,
            'post_id' => isset($resolved['post_id'])
                ? absint($resolved['post_id'])
                : get_queried_object_id(),
            'resolved' => $resolved,
        ];

        foreach ($bindings as $binding) {
            $elementId = (string) (
                $binding['element_id']
                ?? ''
            );

            if (
                ! isset($elements[$elementId])
                || ! $elements[$elementId]
                    instanceof \DOMElement
            ) {
                continue;
            }

            $value = $this->values->resolve(
                (string) $binding['data_source'],
                $binding['config'],
                $context
            );

            if ($value === null) {
                continue;
            }

            $this->apply(
                $elements[$elementId],
                (string) $binding['binding_type'],
                (string) $binding['binding_target'],
                $value,
                $binding['config']
            );
        }

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

    private function apply(
        \DOMElement $element,
        string $type,
        string $target,
        mixed $value,
        array $config
    ): void {
        if ($type === 'html') {
            $html = $this->scalar(
                $value
            );

            if ($html !== null) {
                $this->replaceHtml(
                    $element,
                    $html
                );
            }

            return;
        }

        if (
            $type === 'text'
            || $type === 'taxonomy'
        ) {
            $text = $this->scalar(
                $value
            );

            if ($text !== null) {
                $this->replaceText(
                    $element,
                    $text
                );
            }

            return;
        }

        if ($type === 'media') {
            $this->media(
                $element,
                $target,
                $value,
                $config
            );

            return;
        }

        $text = $this->scalar(
            $value
        );

        if ($text === null) {
            return;
        }

        if ($type === 'url') {
            $attribute = $target !== ''
                ? strtolower($target)
                : 'href';

            $url = $this->safeUrl(
                $text
            );

            if (
                $url !== null
                && $this->allowedAttribute(
                    $attribute
                )
            ) {
                $element->setAttribute(
                    $attribute,
                    $url
                );
            }

            return;
        }

        if (
            $type === 'attribute'
            && $this->allowedAttribute(
                $target
            )
        ) {
            $element->setAttribute(
                strtolower($target),
                $text
            );
        }
    }

    private function media(
        \DOMElement $element,
        string $target,
        mixed $value,
        array $config
    ): void {
        $attribute = $target !== ''
            ? strtolower($target)
            : 'src';

        if (! $this->allowedAttribute($attribute)) {
            return;
        }

        $url = '';

        if (is_array($value)) {
            $url = isset($value['url'])
                ? (string) $value['url']
                : '';
        } else {
            $resolved = $this->scalar($value);

            $url = $resolved ?? '';
        }

        $url = $this->safeUrl(
            $url
        ) ?? '';

        if ($url === '') {
            return;
        }

        $element->setAttribute(
            $attribute,
            $url
        );

        // An imported responsive srcset can override a newly mapped src.
        if (
            strtolower($element->tagName) === 'img'
            && $attribute === 'src'
        ) {
            $element->removeAttribute('srcset');
            $element->removeAttribute('sizes');
        }

        if (
            is_array($value)
            && (
                $config['sync_alt']
                ?? true
            )
            && array_key_exists(
                'alt',
                $value
            )
        ) {
            $element->setAttribute(
                'alt',
                (string) $value['alt']
            );
        }
    }

    private function replaceHtml(
        \DOMElement $element,
        string $value
    ): void {
        $safe = wp_kses_post(
            $value
        );

        $fragmentDocument = $this->document(
            '<div data-cdx-stw-fragment="1">'
                . $safe
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
                    'data-cdx-stw-fragment'
                ) === '1'
            ) {
                $wrapper = $candidate;

                break;
            }
        }

        if (! $wrapper instanceof \DOMElement) {
            return;
        }

        while ($element->firstChild !== null) {
            $element->removeChild(
                $element->firstChild
            );
        }

        $children = [];

        foreach ($wrapper->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $element->appendChild(
                $element->ownerDocument
                    ->importNode(
                        $child,
                        true
                    )
            );
        }
    }


    private function replaceText(
        \DOMElement $element,
        string $value
    ): void {
        $nodes = [];

        $this->textNodes(
            $element,
            $nodes
        );

        if ($nodes === []) {
            $element->appendChild(
                $element->ownerDocument
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
            $nodes[$index]->nodeValue = '';
        }
    }

    private function textNodes(
        \DOMNode $node,
        array &$nodes
    ): void {
        foreach ($node->childNodes as $child) {
            if (
                $child instanceof \DOMText
                && trim(
                    $child->nodeValue
                        ?? ''
                ) !== ''
            ) {
                $nodes[] = $child;

                continue;
            }

            if ($child instanceof \DOMElement) {
                $this->textNodes(
                    $child,
                    $nodes
                );
            }
        }
    }

    private function scalar(
        mixed $value
    ): ?string {
        if (
            is_string($value)
            || is_int($value)
            || is_float($value)
        ) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value
                ? '1'
                : '0';
        }

        return null;
    }

    private function allowedAttribute(
        string $attribute
    ): bool {
        $attribute = strtolower(
            trim($attribute)
        );

        if (
            str_starts_with(
                $attribute,
                'data-'
            )
            || str_starts_with(
                $attribute,
                'aria-'
            )
        ) {
            return true;
        }

        return in_array(
            $attribute,
            [
                'href',
                'src',
                'srcset',
                'poster',
                'alt',
                'title',
                'value',
                'datetime',
                'content',
                'action',
                'name',
                'method',
            ],
            true
        );
    }

    private function safeUrl(
        string $value
    ): ?string {
        $value = trim(
            $value
        );

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '#')) {
            return $value;
        }

        $url = esc_url_raw(
            $value,
            [
                'http',
                'https',
                'mailto',
                'tel',
            ]
        );

        return $url !== ''
            ? $url
            : null;
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

            if (! isset($counts[$tag])) {
                $counts[$tag] = 0;
            }

            $counts[$tag]++;

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

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_dynamic_binding_render',
            __(
                'The dynamic binding document could not be rendered.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
