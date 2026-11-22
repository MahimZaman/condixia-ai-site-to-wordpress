<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class DomNormalizer
{
    private const MAX_ELEMENTS = 50000;
    private const MAX_DEPTH = 256;

    private const STATE_CLASSES = [
        'active',
        'current',
        'current-menu-item',
        'current-menu-parent',
        'current-menu-ancestor',
        'current_page_item',
        'current_page_parent',
        'current_page_ancestor',
        'selected',
        'checked',
        'disabled',
        'enabled',
        'open',
        'closed',
        'expanded',
        'collapsed',
        'focus',
        'focused',
        'hover',
        'hovered',
        'visible',
        'hidden',
        'show',
        'shown',
        'hide',
        'loading',
        'loaded',
        'error',
        'success',
    ];

    private string $sourcePath = '';
    private array $elements = [];
    private int $elementCount = 0;

    public function normalize(
        string $sourcePath,
        \DOMDocument $document
    ): array|\WP_Error {
        $root = $document->documentElement;

        if (! $root instanceof \DOMElement) {
            return $this->error(
                'condixia_ai_stw_dom_root_missing',
                __('The HTML document has no usable root element.', 'condixia-ai-site-to-wordpress')
            );
        }

        $this->sourcePath = str_replace(
            '\\',
            '/',
            $sourcePath
        );

        $this->elements = [];
        $this->elementCount = 0;

        $rootPath = '/'
            . strtolower($root->tagName)
            . '[1]';

        $result = $this->walk(
            $root,
            $rootPath,
            null,
            0,
            1
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'source_path' => $this->sourcePath,
            'root_ref' => $result['ref'],
            'document_fingerprint' => $result['subtree_fingerprint'],
            'element_count' => $this->elementCount,
            'elements' => $this->elements,
        ];
    }

    private function walk(
        \DOMElement $element,
        string $path,
        ?string $parentRef,
        int $depth,
        int $siblingIndex
    ): array|\WP_Error {
        if ($depth > self::MAX_DEPTH) {
            return $this->error(
                'condixia_ai_stw_dom_depth_limit',
                __('An imported HTML document exceeds the safe DOM nesting limit.', 'condixia-ai-site-to-wordpress')
            );
        }

        $this->elementCount++;

        if ($this->elementCount > self::MAX_ELEMENTS) {
            return $this->error(
                'condixia_ai_stw_dom_element_limit',
                __('An imported HTML document contains too many elements to analyze safely.', 'condixia-ai-site-to-wordpress')
            );
        }

        $tag = strtolower(
            $element->tagName
        );

        $ref = $this->sourceRef(
            $path
        );

        $attributes = $this->normalizedAttributes(
            $element
        );

        $signatureData = [
            'tag' => $tag,
            'attributes' => $attributes,
        ];

        $signatureJson = wp_json_encode(
            $signatureData,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        if (! is_string($signatureJson)) {
            return $this->error(
                'condixia_ai_stw_dom_signature_failed',
                __('An HTML structural signature could not be generated.', 'condixia-ai-site-to-wordpress')
            );
        }

        $nodeFingerprint = hash(
            'sha256',
            $signatureJson
        );

        $recordIndex = count(
            $this->elements
        );

        $this->elements[] = [
            'ref' => $ref,
            'parent_ref' => $parentRef,
            'path' => $path,
            'tag' => $tag,
            'depth' => $depth,
            'sibling_index' => $siblingIndex,
            'attributes' => $attributes,
            'fingerprint' => $nodeFingerprint,
            'subtree_fingerprint' => '',
        ];

        $childFingerprints = [];
        $childTagCounts = [];

        foreach ($element->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $childTag = strtolower(
                $child->tagName
            );

            if (! isset($childTagCounts[$childTag])) {
                $childTagCounts[$childTag] = 0;
            }

            $childTagCounts[$childTag]++;

            $childPath = $path
                . '/'
                . $childTag
                . '['
                . $childTagCounts[$childTag]
                . ']';

            $childResult = $this->walk(
                $child,
                $childPath,
                $ref,
                $depth + 1,
                $childTagCounts[$childTag]
            );

            if (is_wp_error($childResult)) {
                return $childResult;
            }

            $childFingerprints[] = $childResult['subtree_fingerprint'];
        }

        $subtreeFingerprint = hash(
            'sha256',
            $nodeFingerprint
                . "\n"
                . implode(
                    "\n",
                    $childFingerprints
                )
        );

        $this->elements[$recordIndex]['subtree_fingerprint'] = $subtreeFingerprint;

        return [
            'ref' => $ref,
            'subtree_fingerprint' => $subtreeFingerprint,
        ];
    }

    private function normalizedAttributes(
        \DOMElement $element
    ): array {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            if (! $attribute instanceof \DOMAttr) {
                continue;
            }

            $name = strtolower(
                $attribute->name
            );

            if ($name === 'class') {
                $classes = $this->normalizeClasses(
                    $attribute->value
                );

                if ($classes !== []) {
                    $attributes['class'] = $classes;
                }

                continue;
            }

            if ($name === 'id') {
                $identifier = strtolower(trim($attribute->value));

                if ($identifier !== '') {
                    $attributes['id'] = $identifier;
                }

                continue;
            }

            if (
                in_array(
                    $name,
                    [
                        'id',
                        'href',
                        'src',
                        'srcset',
                        'poster',
                        'action',
                        'value',
                        'placeholder',
                        'title',
                        'alt',
                        'style',
                    ],
                    true
                )
            ) {
                if (
                    in_array(
                        $name,
                        [
                            'href',
                            'src',
                            'srcset',
                            'poster',
                            'action',
                        ],
                        true
                    )
                ) {
                    $attributes[$name] = true;
                }

                continue;
            }

            if (
                in_array(
                    $name,
                    [
                        'role',
                        'type',
                        'name',
                        'itemprop',
                        'property',
                    ],
                    true
                )
            ) {
                $value = strtolower(
                    trim($attribute->value)
                );

                if ($value !== '') {
                    $attributes[$name] = $value;
                }

                continue;
            }

            if (
                str_starts_with($name, 'data-')
                || str_starts_with($name, 'aria-')
            ) {
                $attributes[$name] = true;

                continue;
            }

            $attributes[$name] = true;
        }

        ksort(
            $attributes,
            SORT_STRING
        );

        return $attributes;
    }

    private function normalizeClasses(
        string $value
    ): array {
        $classes = preg_split(
            '/\s+/',
            trim($value)
        );

        if (! is_array($classes)) {
            return [];
        }

        $normalized = [];

        foreach ($classes as $class) {
            $class = trim(
                strtolower($class)
            );

            if (
                $class === ''
                || in_array(
                    $class,
                    self::STATE_CLASSES,
                    true
                )
            ) {
                continue;
            }

            $normalized[$class] = true;
        }

        $classes = array_keys(
            $normalized
        );

        sort(
            $classes,
            SORT_STRING
        );

        return $classes;
    }

    private function sourceRef(string $path): string
    {
        return 'cdx-stw-'
            . substr(
                hash(
                    'sha256',
                    $this->sourcePath
                        . "\0"
                        . $path
                ),
                0,
                24
            );
    }

    private function error(
        string $code,
        string $message
    ): \WP_Error {
        return new \WP_Error(
            $code,
            $message
        );
    }
}
