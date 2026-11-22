<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Templates;

defined('ABSPATH') || exit;

final class DisplayConditionMatcher
{
    public function specificity(
        string $type,
        string $key,
        array $context
    ): int {
        return match ($type) {
            'object' => (
                ! empty($context['is_singular'])
                && (int) $key
                === (int) (
                    $context['object_id']
                    ?? 0
                )
            )
                ? 600
                : -1,

            'taxonomy_term' => $this
                ->taxonomyTerm(
                    $key,
                    $context
                )
                ? 500
                : -1,

            'front_page' => ! empty($context['is_front_page'])
                ? 450
                : -1,

            'single_post' => ! empty($context['is_single_post'])
                ? 450
                : -1,

            'post_archive' => ! empty($context['is_post_archive'])
                ? 450
                : -1,

            'post_type_archive' => (
                ! empty($context['is_post_type_archive'])
                && sanitize_key($key)
                === (
                    $context['archive_post_type'] ?? ''
                )
            )
                ? 450
                : -1,

            'taxonomy_archive' => (
                ! empty($context['is_taxonomy_archive'])
                && sanitize_key($key)
                === (
                    $context['taxonomy']
                    ?? ''
                )
            )
                ? 450
                : -1,

            'search' => ! empty($context['is_search'])
                ? 450
                : -1,

            '404' => ! empty($context['is_404'])
                ? 450
                : -1,

            'shop' => ! empty($context['is_shop'])
                ? 450
                : -1,

            'single_product' => ! empty($context['is_single_product'])
                ? 450
                : -1,

            'product_taxonomy' => ! empty($context['is_product_taxonomy'])
                ? 450
                : -1,

            'post_type' => (
                ! empty($context['is_singular'])
                && sanitize_key($key)
                === (
                    $context['post_type']
                    ?? ''
                )
            )
                ? 300
                : -1,

            'woocommerce' => ! empty($context['is_woocommerce'])
                ? 250
                : -1,

            'all_pages' => ! empty($context['is_page'])
                ? 200
                : -1,

            'all_posts' => ! empty($context['is_single_post'])
                ? 200
                : -1,

            'entire_site' => 100,

            default => -1,
        };
    }

    private function taxonomyTerm(
        string $key,
        array $context
    ): bool {
        $parts = explode(
            ':',
            $key,
            2
        );

        if (count($parts) !== 2) {
            return false;
        }

        return sanitize_key($parts[0])
            === (
                $context['taxonomy']
                ?? ''
            )
            && absint($parts[1])
            === (int) (
                $context['term_id']
                ?? 0
            );
    }
}
