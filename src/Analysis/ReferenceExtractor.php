<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class ReferenceExtractor
{
    public function extract(
        string $type,
        string $content
    ): array {
        return match ($type) {
            'html' => $this->extractHtml($content),
            'css' => $this->extractCss($content, 'css'),
            'js' => $this->extractJavaScript($content),
            default => [],
        };
    }

    private function extractHtml(string $content): array
    {
        if (class_exists(\DOMDocument::class)) {
            $references = $this->extractHtmlWithDom(
                $content
            );

            if ($references !== null) {
                return $references;
            }
        }

        return $this->extractHtmlFallback(
            $content
        );
    }

    private function extractHtmlWithDom(
        string $content
    ): ?array {
        $previousState = libxml_use_internal_errors(
            true
        );

        $document = new \DOMDocument();

        $loaded = $document->loadHTML(
            $content,
            LIBXML_NONET
                | LIBXML_NOERROR
                | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors(
            $previousState
        );

        if (! $loaded) {
            return null;
        }

        $references = [];
        $attributes = [
            'src',
            'href',
            'poster',
            'action',
            'data-src',
            'data-href',
        ];

        foreach ($document->getElementsByTagName('*') as $element) {
            $tag = strtolower(
                $element->tagName
            );

            foreach ($attributes as $attribute) {
                if (! $element->hasAttribute($attribute)) {
                    continue;
                }

                $this->add(
                    $references,
                    $element->getAttribute($attribute),
                    'html:' . $tag . ':' . $attribute
                );
            }

            foreach (['srcset', 'data-srcset'] as $attribute) {
                if (! $element->hasAttribute($attribute)) {
                    continue;
                }

                foreach (
                    $this->parseSrcset(
                        $element->getAttribute($attribute)
                    )
                    as $reference
                ) {
                    $this->add(
                        $references,
                        $reference,
                        'html:' . $tag . ':' . $attribute
                    );
                }
            }

            if ($element->hasAttribute('style')) {
                $references = array_merge(
                    $references,
                    $this->extractCss(
                        $element->getAttribute('style'),
                        'html:' . $tag . ':style'
                    )
                );
            }
        }

        foreach (
            $document->getElementsByTagName('style')
            as $style
        ) {
            $references = array_merge(
                $references,
                $this->extractCss(
                    $style->textContent,
                    'html:style'
                )
            );
        }

        return $this->unique(
            $references
        );
    }

    private function extractHtmlFallback(
        string $content
    ): array {
        $references = [];

        preg_match_all(
            '/\b(src|href|poster|action|data-src|data-href)\s*=\s*(["\'])(.*?)\2/is',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $this->add(
                $references,
                $match[3],
                'html:' . strtolower($match[1])
            );
        }

        preg_match_all(
            '/\b(src|href|poster|action|data-src|data-href)\s*=\s*([^\s>"\']+)/i',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $this->add(
                $references,
                $match[2],
                'html:' . strtolower($match[1])
            );
        }

        preg_match_all(
            '/\b(srcset|data-srcset)\s*=\s*(["\'])(.*?)\2/is',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            foreach (
                $this->parseSrcset($match[3])
                as $reference
            ) {
                $this->add(
                    $references,
                    $reference,
                    'html:' . strtolower($match[1])
                );
            }
        }

        preg_match_all(
            '/\bstyle\s*=\s*(["\'])(.*?)\1/is',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $references = array_merge(
                $references,
                $this->extractCss(
                    $match[2],
                    'html:style'
                )
            );
        }

        preg_match_all(
            '/<style\b[^>]*>(.*?)<\/style>/is',
            $content,
            $matches
        );

        foreach ($matches[1] ?? [] as $css) {
            $references = array_merge(
                $references,
                $this->extractCss(
                    $css,
                    'html:style'
                )
            );
        }

        return $this->unique(
            $references
        );
    }

    private function extractCss(
        string $content,
        string $prefix
    ): array {
        $references = [];

        preg_match_all(
            '/url\(\s*(?:(["\'])(.*?)\1|([^)\s][^)]*?))\s*\)/is',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $reference = isset($match[2])
                && $match[2] !== ''
                ? $match[2]
                : ($match[3] ?? '');

            $this->add(
                $references,
                $reference,
                $prefix . ':url'
            );
        }

        preg_match_all(
            '/@import\s+(?:url\(\s*)?(?:(["\'])(.*?)\1|([^\s;)]+))/i',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $reference = isset($match[2])
                && $match[2] !== ''
                ? $match[2]
                : ($match[3] ?? '');

            $this->add(
                $references,
                $reference,
                $prefix . ':import'
            );
        }

        return $this->unique(
            $references
        );
    }

    private function extractJavaScript(
        string $content
    ): array {
        $references = [];

        preg_match_all(
            '/\b(?:import|export)\s+(?:[^;]*?\s+from\s+)?(["\'])([^"\']+)\1/m',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $this->add(
                $references,
                $match[2],
                'js:module'
            );
        }

        preg_match_all(
            '/\bimport\s*\(\s*(["\'])([^"\']+)\1\s*\)/m',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $this->add(
                $references,
                $match[2],
                'js:dynamic-import'
            );
        }

        preg_match_all(
            '/new\s+URL\s*\(\s*(["\'])([^"\']+)\1\s*,\s*import\.meta\.url\s*\)/m',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $this->add(
                $references,
                $match[2],
                'js:url'
            );
        }

        return $this->unique(
            $references
        );
    }

    private function parseSrcset(
        string $srcset
    ): array {
        if (
            str_starts_with(
                strtolower(trim($srcset)),
                'data:'
            )
        ) {
            return [];
        }

        $references = [];

        foreach (explode(',', $srcset) as $candidate) {
            $candidate = trim(
                $candidate
            );

            if ($candidate === '') {
                continue;
            }

            $parts = preg_split(
                '/\s+/',
                $candidate
            );

            if (
                is_array($parts)
                && isset($parts[0])
            ) {
                $references[] = $parts[0];
            }
        }

        return $references;
    }

    private function add(
        array &$references,
        string $reference,
        string $context
    ): void {
        $reference = trim(
            html_entity_decode(
                $reference,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

        if ($reference === '') {
            return;
        }

        $references[] = [
            'reference' => $reference,
            'context' => $context,
        ];
    }

    private function unique(array $references): array
    {
        $unique = [];

        foreach ($references as $reference) {
            $key = $reference['context']
                . "\0"
                . $reference['reference'];

            $unique[$key] = $reference;
        }

        ksort(
            $unique,
            SORT_STRING
        );

        return array_values(
            $unique
        );
    }
}
