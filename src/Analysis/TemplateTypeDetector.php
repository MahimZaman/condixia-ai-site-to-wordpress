<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class TemplateTypeDetector
{
    public function detect(
        string $sourcePath,
        array $pageDetection,
        ?string $html = null
    ): ?array {
        $sourcePath = str_replace(
            '\\',
            '/',
            $sourcePath
        );

        if (
            ($pageDetection['classification'] ?? '') === 'page'
            && ! empty($pageDetection['candidate']['home_candidate'])
        ) {
            return $this->candidate(
                $sourcePath,
                'front_page',
                0.92,
                false,
                'root_index'
            );
        }

        $route = $this->routeName(
            $sourcePath
        );

        if ($route === 'single') {
            return $this->genericSingleCandidate($sourcePath, $html);
        }

        return match ($route) {
            'front-page' => $this->candidate(
                $sourcePath,
                'front_page',
                0.99,
                false,
                'explicit_filename'
            ),

            'home' => $this->candidate(
                $sourcePath,
                'front_page',
                0.78,
                true,
                'ambiguous_home_filename'
            ),

            'page',
            'default-page' => $this->candidate(
                $sourcePath,
                'default_page',
                0.99,
                false,
                'explicit_filename'
            ),

            'post',
            'single-post' => $this->candidate(
                $sourcePath,
                'single_post',
                0.99,
                false,
                'explicit_filename'
            ),

            'blog',
            'posts' => $this->candidate(
                $sourcePath,
                'post_archive',
                0.82,
                true,
                'blog_page_archive_suggestion'
            ),

            'archive' => $this->candidate(
                $sourcePath,
                'post_archive',
                0.78,
                true,
                'generic_archive_filename'
            ),

            'search' => $this->candidate(
                $sourcePath,
                'search',
                1.0,
                false,
                'explicit_filename'
            ),

            '404' => $this->candidate(
                $sourcePath,
                '404',
                1.0,
                false,
                'explicit_filename'
            ),

            'product',
            'single-product' => $this->candidate(
                $sourcePath,
                'single_product',
                1.0,
                false,
                'woocommerce_filename',
                null,
                true
            ),

            'shop' => $this->candidate(
                $sourcePath,
                'product_archive',
                0.88,
                true,
                'shop_page_archive_suggestion',
                null,
                true
            ),

            'products',
            'product-archive',
            'archive-product' => $this->candidate(
                $sourcePath,
                'product_archive',
                0.96,
                true,
                'woocommerce_filename_suggestion',
                null,
                true
            ),

            'category',
            'tag' => $this->candidate(
                $sourcePath,
                'taxonomy_archive',
                0.72,
                true,
                'generic_taxonomy_filename',
                $route === 'tag' ? 'post_tag' : 'category'
            ),

            'product-category',
            'product-tag' => $this->candidate(
                $sourcePath,
                'product_taxonomy_archive',
                0.98,
                false,
                'woocommerce_taxonomy_filename',
                null,
                true
            ),

            default => $this->patternCandidate(
                $sourcePath,
                $route
            ),
        };
    }

    private function genericSingleCandidate(
        string $sourcePath,
        ?string $html
    ): ?array {
        $classes = [];

        if (is_string($html)) {
            preg_match_all(
                '/\bclass\s*=\s*(["\'])(.*?)\1/is',
                $html,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $tokens = preg_split(
                    '/\s+/',
                    strtolower(trim((string) $match[2]))
                );

                if (! is_array($tokens)) {
                    continue;
                }

                foreach ($tokens as $token) {
                    if ($token !== '') {
                        $classes[$token] = true;
                    }
                }
            }
        }

        $productStructure = isset($classes['single-product'])
            || isset($classes['product-single'])
            || (
                isset($classes['product-detail'])
                && isset($classes['quantity'])
            );

        if ($productStructure) {
            return $this->candidate(
                $sourcePath,
                'single_product',
                0.90,
                true,
                'product_detail_structure',
                null,
                true
            );
        }

        $postStructure = isset($classes['single-post'])
            || isset($classes['post-single'])
            || isset($classes['post-detail'])
            || isset($classes['blog-detail'])
            || isset($classes['article-detail']);

        if ($postStructure) {
            return $this->candidate(
                $sourcePath,
                'single_post',
                0.85,
                true,
                'post_detail_structure'
            );
        }

        return $this->candidate(
            $sourcePath,
            'single_post',
            0.55,
            true,
            'generic_single_filename'
        );
    }

    private function patternCandidate(
        string $sourcePath,
        string $route
    ): ?array {
        if (
            preg_match(
                '/^single-(.+)$/',
                $route,
                $matches
            ) === 1
        ) {
            $postType = sanitize_key(
                $matches[1]
            );

            if ($postType !== '') {
                return $this->candidate(
                    $sourcePath,
                    'cpt_single',
                    0.95,
                    false,
                    'single_post_type_filename',
                    $postType
                );
            }
        }

        if (
            preg_match(
                '/^archive-(.+)$/',
                $route,
                $matches
            ) === 1
        ) {
            $postType = sanitize_key(
                $matches[1]
            );

            if ($postType !== '') {
                return $this->candidate(
                    $sourcePath,
                    'cpt_archive',
                    0.95,
                    false,
                    'archive_post_type_filename',
                    $postType
                );
            }
        }

        if (
            preg_match(
                '/^taxonomy-(.+)$/',
                $route,
                $matches
            ) === 1
        ) {
            $taxonomy = sanitize_key(
                $matches[1]
            );

            if ($taxonomy !== '') {
                return $this->candidate(
                    $sourcePath,
                    'taxonomy_archive',
                    0.90,
                    true,
                    'taxonomy_filename',
                    $taxonomy
                );
            }
        }

        return null;
    }

    private function candidate(
        string $sourcePath,
        string $role,
        float $confidence,
        bool $ambiguous,
        string $basis,
        ?string $target = null,
        bool $requiresWooCommerce = false
    ): array {
        $title = $this->title(
            $role,
            $target
        );

        $sourceKey = hash(
            'sha256',
            $sourcePath
                . "\0"
                . $role
                . "\0"
                . ($target ?? '')
        );

        return [
            'source' => $sourcePath,
            'role' => $role,
            'title' => $title,
            'slug' => sanitize_title(
                'template-' . $title
            ),
            'source_key' => $sourceKey,
            'target' => $target,
            'confidence' => $confidence,
            'ambiguous' => $ambiguous,
            'detection_basis' => $basis,
            'requires_woocommerce' => $requiresWooCommerce,
            'editable' => true,
        ];
    }

    private function title(
        string $role,
        ?string $target
    ): string {
        return match ($role) {
            'front_page' => __(
                'Front Page',
                'condixia-ai-site-to-wordpress'
            ),

            'default_page' => __(
                'Default Page',
                'condixia-ai-site-to-wordpress'
            ),

            'single_post' => __(
                'Single Post',
                'condixia-ai-site-to-wordpress'
            ),

            'post_archive' => __(
                'Post Archive',
                'condixia-ai-site-to-wordpress'
            ),

            'search' => __(
                'Search Results',
                'condixia-ai-site-to-wordpress'
            ),

            '404' => __(
                '404',
                'condixia-ai-site-to-wordpress'
            ),

            'single_product' => __(
                'Single Product',
                'condixia-ai-site-to-wordpress'
            ),

            'product_archive' => __(
                'Product Archive',
                'condixia-ai-site-to-wordpress'
            ),

            'product_taxonomy_archive' => __(
                'Product Taxonomy Archive',
                'condixia-ai-site-to-wordpress'
            ),

            'cpt_single' => sprintf(
                __(
                    'Single %s',
                    'condixia-ai-site-to-wordpress'
                ),
                $this->humanize(
                    $target
                )
            ),

            'cpt_archive' => sprintf(
                __(
                    '%s Archive',
                    'condixia-ai-site-to-wordpress'
                ),
                $this->humanize(
                    $target
                )
            ),

            'taxonomy_archive' => sprintf(
                __(
                    '%s Taxonomy Archive',
                    'condixia-ai-site-to-wordpress'
                ),
                $this->humanize(
                    $target
                )
            ),

            default => __(
                'Template',
                'condixia-ai-site-to-wordpress'
            ),
        };
    }

    private function humanize(?string $value): string
    {
        if (
            $value === null
            || $value === ''
        ) {
            return __(
                'Content',
                'condixia-ai-site-to-wordpress'
            );
        }

        return ucwords(
            str_replace(
                [
                    '-',
                    '_',
                ],
                ' ',
                $value
            )
        );
    }

    private function routeName(
        string $sourcePath
    ): string {
        $normalized = trim(
            $sourcePath,
            '/'
        );

        $basename = basename(
            $normalized
        );

        $stem = strtolower(
            pathinfo(
                $basename,
                PATHINFO_FILENAME
            )
        );

        if ($stem !== 'index') {
            return sanitize_title(
                $stem
            );
        }

        $directory = dirname(
            $normalized
        );

        if (
            $directory === '.'
            || $directory === ''
        ) {
            return 'index';
        }

        return sanitize_title(
            basename(
                $directory
            )
        );
    }
}
