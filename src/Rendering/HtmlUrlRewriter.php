<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Rendering;

defined('ABSPATH') || exit;

final class HtmlUrlRewriter
{
    private AssetResolver $resolver;
    private CssUrlRewriter $cssRewriter;

    public function __construct(
        ?AssetResolver $resolver = null,
        ?CssUrlRewriter $cssRewriter = null
    ) {
        $this->resolver = $resolver
            ?? new AssetResolver();

        $this->cssRewriter = $cssRewriter
            ?? new CssUrlRewriter(
                $this->resolver
            );
    }

    public function rewrite(
        string $sourcePath,
        string $html,
        array $assetMap,
        array $fileIndex,
        array $routeMap = []
    ): array|\WP_Error {
        if (! class_exists(\WP_HTML_Tag_Processor::class)) {
            return new \WP_Error(
                'condixia_ai_stw_html_processor_missing',
                __(
                    'The WordPress HTML processor required for URL rewriting is unavailable.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $processor = new \WP_HTML_Tag_Processor(
            $html
        );

        $internalLinks = [];
        $assetRewrites = 0;
        $routeRewrites = 0;

        while ($processor->next_token()) {
            $tag = $processor->get_tag();

            if ($tag === null) {
                continue;
            }

            if ($tag === 'STYLE') {
                $css = $processor->get_modifiable_text();

                if ($css !== '') {
                    $rewrittenCss = $this->cssRewriter->rewrite(
                        $sourcePath,
                        $css,
                        $assetMap,
                        $fileIndex
                    );

                    if ($rewrittenCss !== $css) {
                        $processor->set_modifiable_text(
                            $rewrittenCss
                        );

                        $assetRewrites++;
                    }
                }
            }

            $style = $processor->get_attribute(
                'style'
            );

            if (is_string($style)) {
                $rewrittenStyle = $this->cssRewriter->rewrite(
                    $sourcePath,
                    $style,
                    $assetMap,
                    $fileIndex
                );

                if ($rewrittenStyle !== $style) {
                    $processor->set_attribute(
                        'style',
                        $rewrittenStyle
                    );

                    $assetRewrites++;
                }
            }

            foreach (
                $this->assetAttributes($tag)
                as $attribute
            ) {
                $assetRewrites += $this->rewriteAssetAttribute(
                    $processor,
                    $attribute,
                    $sourcePath,
                    $assetMap,
                    $fileIndex
                );
            }

            if (
                $tag === 'IMG'
                || $tag === 'SOURCE'
            ) {
                foreach (
                    ['srcset', 'data-srcset']
                    as $attribute
                ) {
                    $value = $processor->get_attribute(
                        $attribute
                    );

                    if (! is_string($value)) {
                        continue;
                    }

                    $rewritten = $this->rewriteSrcset(
                        $sourcePath,
                        $value,
                        $assetMap,
                        $fileIndex
                    );

                    if ($rewritten !== $value) {
                        $processor->set_attribute(
                            $attribute,
                            $rewritten
                        );

                        $assetRewrites++;
                    }
                }
            }

            if (
                $tag === 'A'
                || $tag === 'AREA'
            ) {
                $href = $processor->get_attribute(
                    'href'
                );

                if (is_string($href)) {
                    $assetUrl = $this->resolver->assetUrl(
                        $sourcePath,
                        $href,
                        $assetMap,
                        $fileIndex
                    );

                    if ($assetUrl !== null) {
                        $processor->set_attribute(
                            'href',
                            $assetUrl
                        );

                        $assetRewrites++;
                    } else {
                        $pageUrl = $this->resolver->pageUrl(
                            $sourcePath,
                            $href,
                            $fileIndex,
                            $routeMap
                        );

                        if ($pageUrl !== null) {
                            $processor->set_attribute(
                                'href',
                                $pageUrl
                            );

                            $routeRewrites++;
                        } else {
                            $this->collectInternalLink(
                                $internalLinks,
                                $sourcePath,
                                $href,
                                'href',
                                $fileIndex
                            );
                        }
                    }
                }
            }

            if ($tag === 'FORM') {
                $action = $processor->get_attribute(
                    'action'
                );

                if (is_string($action)) {
                    $pageUrl = $this->resolver->pageUrl(
                        $sourcePath,
                        $action,
                        $fileIndex,
                        $routeMap
                    );

                    if ($pageUrl !== null) {
                        $processor->set_attribute(
                            'action',
                            $pageUrl
                        );

                        $routeRewrites++;
                    } else {
                        $this->collectInternalLink(
                            $internalLinks,
                            $sourcePath,
                            $action,
                            'action',
                            $fileIndex
                        );
                    }
                }
            }

            if ($tag === 'IFRAME') {
                $src = $processor->get_attribute(
                    'src'
                );

                if (is_string($src)) {
                    $pageUrl = $this->resolver->pageUrl(
                        $sourcePath,
                        $src,
                        $fileIndex,
                        $routeMap
                    );

                    if ($pageUrl !== null) {
                        $processor->set_attribute(
                            'src',
                            $pageUrl
                        );

                        $routeRewrites++;
                    } else {
                        $this->collectInternalLink(
                            $internalLinks,
                            $sourcePath,
                            $src,
                            'src',
                            $fileIndex
                        );
                    }
                }
            }
        }

        return [
            'html' => $processor->get_updated_html(),
            'asset_rewrites' => $assetRewrites,
            'route_rewrites' => $routeRewrites,
            'internal_links' => $this->uniqueInternalLinks(
                $internalLinks
            ),
        ];
    }

    private function assetAttributes(
        string $tag
    ): array {
        return match ($tag) {
            'IMG' => [
                'src',
                'data-src',
            ],
            'SOURCE' => [
                'src',
                'data-src',
            ],
            'SCRIPT' => [
                'src',
            ],
            'LINK' => [
                'href',
            ],
            'VIDEO' => [
                'src',
                'poster',
            ],
            'AUDIO' => [
                'src',
            ],
            'TRACK' => [
                'src',
            ],
            'INPUT' => [
                'src',
            ],
            'EMBED' => [
                'src',
            ],
            'OBJECT' => [
                'data',
            ],
            'IMAGE',
            'USE' => [
                'href',
                'xlink:href',
            ],
            default => [],
        };
    }

    private function rewriteAssetAttribute(
        \WP_HTML_Tag_Processor $processor,
        string $attribute,
        string $sourcePath,
        array $assetMap,
        array $fileIndex
    ): int {
        $value = $processor->get_attribute(
            $attribute
        );

        if (! is_string($value)) {
            return 0;
        }

        $url = $this->resolver->assetUrl(
            $sourcePath,
            $value,
            $assetMap,
            $fileIndex
        );

        if ($url === null) {
            return 0;
        }

        $processor->set_attribute(
            $attribute,
            $url
        );

        return 1;
    }

    private function rewriteSrcset(
        string $sourcePath,
        string $srcset,
        array $assetMap,
        array $fileIndex
    ): string {
        if (
            str_starts_with(
                strtolower(trim($srcset)),
                'data:'
            )
        ) {
            return $srcset;
        }

        $candidates = explode(
            ',',
            $srcset
        );

        $rewritten = [];

        foreach ($candidates as $candidate) {
            $candidate = trim(
                $candidate
            );

            if ($candidate === '') {
                continue;
            }

            $parts = preg_split(
                '/\s+/',
                $candidate,
                2
            );

            if (
                ! is_array($parts)
                || ! isset($parts[0])
            ) {
                $rewritten[] = $candidate;

                continue;
            }

            $url = $this->resolver->assetUrl(
                $sourcePath,
                $parts[0],
                $assetMap,
                $fileIndex
            );

            if ($url === null) {
                $rewritten[] = $candidate;

                continue;
            }

            $rewritten[] = $url
                . (
                    isset($parts[1])
                    && $parts[1] !== ''
                    ? ' ' . $parts[1]
                    : ''
                );
        }

        return implode(
            ', ',
            $rewritten
        );
    }

    private function collectInternalLink(
        array &$links,
        string $sourcePath,
        string $reference,
        string $attribute,
        array $fileIndex
    ): void {
        $target = $this->resolver->internalHtmlTarget(
            $sourcePath,
            $reference,
            $fileIndex
        );

        if ($target === null) {
            return;
        }

        $links[] = [
            'source' => $sourcePath,
            'target' => $target,
            'attribute' => $attribute,
            'reference' => $reference,
        ];
    }

    private function uniqueInternalLinks(
        array $links
    ): array {
        $unique = [];

        foreach ($links as $link) {
            $key = wp_json_encode(
                $link,
                JSON_UNESCAPED_SLASHES
            );

            if (! is_string($key)) {
                continue;
            }

            $unique[$key] = $link;
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
