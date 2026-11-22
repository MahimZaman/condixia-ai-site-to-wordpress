<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Navigation;

use Condixia\AISiteToWordPress\Contracts\NavigationProviderInterface;

defined('ABSPATH') || exit;

final class BlockNavigationProvider implements NavigationProviderInterface
{
    public function key(): string
    {
        return 'block';
    }

    public function available(): bool
    {
        return post_type_exists(
            'wp_navigation'
        )
            && function_exists(
                'serialize_blocks'
            )
            && function_exists(
                'parse_blocks'
            );
    }

    public function exists(int $objectId): bool
    {
        return $objectId > 0
            && get_post_type(
                $objectId
            ) === 'wp_navigation';
    }

    public function create(
        string $name,
        array $items,
        array $context = []
    ): int|\WP_Error {
        $blocks = $this->blocks(
            $items
        );

        $content = serialize_blocks(
            $blocks
        );

        $postId = wp_insert_post(
            [
                'post_type'
                => 'wp_navigation',
                'post_status'
                => 'publish',
                'post_title'
                => $name,
                'post_name'
                => sanitize_title(
                    $name
                ),
                'post_content'
                => $content,
            ],
            true
        );

        if (is_wp_error($postId)) {
            return $postId;
        }

        $postId = (int) $postId;

        if (isset($context['site_id'])) {
            update_post_meta(
                $postId,
                '_condixia_ai_stw_site_id',
                (int) $context['site_id']
            );
        }

        if (isset($context['artifact_id'])) {
            update_post_meta(
                $postId,
                '_condixia_ai_stw_artifact_id',
                (int) $context['artifact_id']
            );
        }

        return $postId;
    }

    public function items(
        int $objectId
    ): array|\WP_Error {
        $post = get_post(
            $objectId
        );

        if (
            ! $post instanceof \WP_Post
            || $post->post_type
            !== 'wp_navigation'
        ) {
            return new \WP_Error(
                'condixia_ai_stw_block_navigation_missing',
                __(
                    'The WordPress Navigation entity could not be loaded.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        return $this->itemsFromBlocks(
            parse_blocks(
                $post->post_content
            )
        );
    }

    public function manageUrl(
        int $objectId
    ): string {
        return admin_url(
            'site-editor.php?p=/wp_navigation/'
                . $objectId
                . '&canvas=edit'
        );
    }

    private function blocks(
        array $items
    ): array {
        $blocks = [];

        foreach ($items as $item) {
            $children = is_array(
                $item['children']
                    ?? null
            )
                ? $this->blocks(
                    $item['children']
                )
                : [];

            $attrs = [
                'label' => (string) (
                    $item['label']
                    ?? ''
                ),
                'url' => (string) (
                    $item['url']
                    ?? ''
                ),
                'className' => sanitize_html_class(
                    'cdx-nav-key-'
                        . (string) (
                            $item['key']
                            ?? ''
                        )
                ),
                'opensInNewTab'
                => ! empty($item['opens_in_new_tab']),
            ];

            $rel = (string) (
                $item['rel']
                ?? ''
            );

            if ($rel !== '') {
                $attrs['rel'] = $rel;
            }

            if (
                ! empty($item['post_id'])
            ) {
                $attrs['id'] = (int) $item['post_id'];

                $attrs['kind'] = 'post-type';
                $attrs['type'] = 'page';
            } else {
                $attrs['kind'] = 'custom';
                $attrs['type'] = 'custom';
            }

            $blocks[] = [
                'blockName' => $children !== []
                    ? 'core/navigation-submenu'
                    : 'core/navigation-link',
                'attrs' => $attrs,
                'innerBlocks' => $children,
                'innerHTML' => '',
                'innerContent' => array_fill(
                    0,
                    count($children),
                    null
                ),
            ];
        }

        return $blocks;
    }

    private function itemsFromBlocks(
        array $blocks
    ): array {
        $items = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (
                ! in_array(
                    $block['blockName']
                        ?? null,
                    [
                        'core/navigation-link',
                        'core/navigation-submenu',
                    ],
                    true
                )
            ) {
                continue;
            }

            $attrs = is_array(
                $block['attrs'] ?? null
            )
                ? $block['attrs']
                : [];

            $className = (string) (
                $attrs['className']
                ?? ''
            );

            $key = '';

            if (
                preg_match(
                    '/(?:^|\s)cdx-nav-key-([a-f0-9]{24})(?:\s|$)/',
                    $className,
                    $matches
                ) === 1
            ) {
                $key = $matches[1];
            }

            $items[] = [
                'key' => $key,
                'label' => (string) (
                    $attrs['label']
                    ?? ''
                ),
                'url' => (string) (
                    $attrs['url']
                    ?? ''
                ),
                'post_id' => isset(
                    $attrs['id']
                )
                    ? (int) $attrs['id']
                    : 0,
                'opens_in_new_tab'
                => ! empty($attrs['opensInNewTab']),
                'rel' => (string) (
                    $attrs['rel']
                    ?? ''
                ),
                'children' => $this
                    ->itemsFromBlocks(
                        is_array(
                            $block['innerBlocks'] ?? null
                        )
                            ? $block['innerBlocks']
                            : []
                    ),
            ];
        }

        return $items;
    }
}
