<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class PageDetector
{
    private DomParser $parser;

    public function __construct(
        ?DomParser $parser = null
    ) {
        $this->parser = $parser
            ?? new DomParser();
    }

    public function detect(
        string $sourcePath,
        string $html
    ): array|\WP_Error {
        $sourcePath = str_replace(
            '\\',
            '/',
            $sourcePath
        );

        $templateRole = $this->templateLikeRole(
            $sourcePath
        );

        if ($templateRole !== null && $this->isTemplateOnlyFile($sourcePath)) {
            return [
                'classification' => 'deferred',
                'deferred' => [
                    'source' => $sourcePath,
                    'suggested_role' => $templateRole,
                    'reason' => 'template_like_filename',
                ],
            ];
        }

        $parsed = $this->parser->parse(
            $html
        );

        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $document = $parsed['document'];

        $title = $this->documentTitle(
            $document
        );

        if ($title === '') {
            $title = $this->firstHeading(
                $document
            );
        }

        if ($title === '') {
            $title = $this->fallbackTitle(
                $sourcePath
            );
        }

        $slug = $this->proposedSlug(
            $sourcePath
        );

        $homeCandidate = $this->isRootIndex(
            $sourcePath
        );

        return [
            'classification' => 'page',
            'candidate' => [
                'source' => $sourcePath,
                'title' => $title,
                'slug' => $slug,
                'home_candidate' => $homeCandidate,
                'confidence' => $homeCandidate
                    ? 1.0
                    : 0.9,
                'reason' => $homeCandidate
                    ? 'root_index'
                    : 'normal_html_document',
            ],
        ];
    }

    private function isTemplateOnlyFile(string $sourcePath): bool
    {
        $stem = strtolower(pathinfo(basename($sourcePath), PATHINFO_FILENAME));

        if ($stem === 'index') {
            return false;
        }

        if (in_array($stem, [
            'front-page',
            'default-page',
            'single-post',
            'single-product',
            'archive-product',
            'product-archive',
            'product-category',
            'product-tag',
        ], true)) {
            return true;
        }

        return preg_match('/^(?:single|archive|taxonomy)-[a-z0-9_-]+$/', $stem) === 1;
    }

    private function templateLikeRole(
        string $sourcePath
    ): ?string {
        $routeName = $this->routeName(
            $sourcePath
        );

        $exactRoles = [
            '404' => '404',
            'search' => 'search',
            'post' => 'single_post',
            'single-post' => 'single_post',
            'blog' => 'post_archive',
            'posts' => 'post_archive',
            'archive' => 'archive',
            'category' => 'taxonomy_archive',
            'tag' => 'taxonomy_archive',
            'author' => 'author_archive',
            'date' => 'date_archive',
            'product' => 'single_product',
            'single-product' => 'single_product',
            'shop' => 'product_archive',
            'products' => 'product_archive',
            'product-archive' => 'product_archive',
            'archive-product' => 'product_archive',
            'product-category' => 'product_taxonomy_archive',
            'product-tag' => 'product_taxonomy_archive',
            'page' => 'default_page',
            'default-page' => 'default_page',
            'front-page' => 'front_page',
            'home' => 'ambiguous_home',
        ];

        if (isset($exactRoles[$routeName])) {
            return $exactRoles[$routeName];
        }

        if (
            str_starts_with(
                $routeName,
                'single-'
            )
        ) {
            return 'single_content';
        }

        if (
            str_starts_with(
                $routeName,
                'archive-'
            )
        ) {
            return 'archive_content';
        }

        if (
            str_starts_with(
                $routeName,
                'taxonomy-'
            )
        ) {
            return 'taxonomy_archive';
        }

        return null;
    }

    private function routeName(
        string $sourcePath
    ): string {
        $normalized = trim(
            str_replace(
                '\\',
                '/',
                $sourcePath
            ),
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
            basename($directory)
        );
    }

    private function documentTitle(
        \DOMDocument $document
    ): string {
        $node = $document
            ->getElementsByTagName('title')
            ->item(0);

        if (! $node instanceof \DOMNode) {
            return '';
        }

        return $this->normalizeTitle(
            $node->textContent
        );
    }

    private function firstHeading(
        \DOMDocument $document
    ): string {
        $node = $document
            ->getElementsByTagName('h1')
            ->item(0);

        if (! $node instanceof \DOMNode) {
            return '';
        }

        return $this->normalizeTitle(
            $node->textContent
        );
    }

    private function normalizeTitle(
        string $title
    ): string {
        $title = wp_strip_all_tags(
            $title
        );

        $title = preg_replace(
            '/\s+/u',
            ' ',
            $title
        );

        if (! is_string($title)) {
            return '';
        }

        $title = trim(
            $title
        );

        return $this->limit(
            $title,
            191
        );
    }

    private function fallbackTitle(
        string $sourcePath
    ): string {
        if ($this->isRootIndex($sourcePath)) {
            return __(
                'Home',
                'condixia-ai-site-to-wordpress'
            );
        }

        $normalized = trim(
            str_replace(
                '\\',
                '/',
                $sourcePath
            ),
            '/'
        );

        $basename = basename(
            $normalized
        );

        $stem = pathinfo(
            $basename,
            PATHINFO_FILENAME
        );

        if (
            strtolower($stem) === 'index'
            && dirname($normalized) !== '.'
        ) {
            $stem = basename(
                dirname($normalized)
            );
        }

        $title = str_replace(
            [
                '-',
                '_',
            ],
            ' ',
            $stem
        );

        $title = ucwords(
            trim($title)
        );

        if ($title === '') {
            $title = __(
                'Untitled Page',
                'condixia-ai-site-to-wordpress'
            );
        }

        return $this->limit(
            $title,
            191
        );
    }

    private function proposedSlug(
        string $sourcePath
    ): string {
        if ($this->isRootIndex($sourcePath)) {
            return 'home';
        }

        $normalized = trim(
            str_replace(
                '\\',
                '/',
                $sourcePath
            ),
            '/'
        );

        $basename = basename(
            $normalized
        );

        $stem = pathinfo(
            $basename,
            PATHINFO_FILENAME
        );

        if (
            strtolower($stem) === 'index'
            && dirname($normalized) !== '.'
        ) {
            $stem = basename(
                dirname($normalized)
            );
        }

        $slug = sanitize_title(
            $stem
        );

        if ($slug !== '') {
            return $this->limit(
                $slug,
                191
            );
        }

        return 'page-'
            . substr(
                hash(
                    'sha256',
                    $sourcePath
                ),
                0,
                10
            );
    }

    private function isRootIndex(
        string $sourcePath
    ): bool {
        $normalized = trim(
            str_replace(
                '\\',
                '/',
                $sourcePath
            ),
            '/'
        );

        return in_array(
            strtolower($normalized),
            [
                'index.html',
                'index.htm',
            ],
            true
        );
    }

    private function limit(
        string $value,
        int $length
    ): string {
        if (function_exists('mb_substr')) {
            return mb_substr(
                $value,
                0,
                $length,
                'UTF-8'
            );
        }

        return substr(
            $value,
            0,
            $length
        );
    }
}
