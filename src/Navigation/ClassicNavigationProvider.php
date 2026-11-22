<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Navigation;

use Condixia\AISiteToWordPress\Contracts\NavigationProviderInterface;

defined('ABSPATH') || exit;

final class ClassicNavigationProvider implements NavigationProviderInterface
{
    public function key(): string
    {
        return 'classic';
    }

    public function available(): bool
    {
        return function_exists(
            'wp_create_nav_menu'
        )
            && function_exists(
                'wp_update_nav_menu_item'
            );
    }

    public function exists(int $objectId): bool
    {
        if ($objectId < 1) {
            return false;
        }

        return wp_get_nav_menu_object(
            $objectId
        ) instanceof \WP_Term;
    }

    public function create(
        string $name,
        array $items,
        array $context = []
    ): int|\WP_Error {
        $siteId = absint(
            $context['site_id']
                ?? 0
        );

        $artifactId = absint(
            $context['artifact_id']
                ?? 0
        );

        $name = $this->availableName(
            sanitize_text_field($name),
            $siteId,
            $artifactId
        );

        if (is_wp_error($name)) {
            return $name;
        }

        $menuId = wp_create_nav_menu(
            wp_slash($name)
        );

        if (is_wp_error($menuId)) {
            return $menuId;
        }

        $menuId = (int) $menuId;

        if (isset($context['site_id'])) {
            add_term_meta(
                $menuId,
                '_condixia_ai_stw_site_id',
                (int) $context['site_id'],
                true
            );
        }

        if (isset($context['artifact_id'])) {
            add_term_meta(
                $menuId,
                '_condixia_ai_stw_artifact_id',
                (int) $context['artifact_id'],
                true
            );
        }

        $created = $this->createItems(
            $menuId,
            $items,
            0
        );

        if (is_wp_error($created)) {
            wp_delete_nav_menu(
                $menuId
            );

            return $created;
        }

        return $menuId;
    }

    public function items(
        int $objectId
    ): array|\WP_Error {
        $items = wp_get_nav_menu_items(
            $objectId,
            [
                'post_status' => 'publish',
            ]
        );

        if ($items === false) {
            return new \WP_Error(
                'condixia_ai_stw_classic_menu_missing',
                __(
                    'The WordPress navigation menu could not be loaded.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        usort(
            $items,
            static fn(
                \WP_Post $left,
                \WP_Post $right
            ): int =>
            (int) $left->menu_order
                <=>
                (int) $right->menu_order
        );

        $byParent = [];

        foreach ($items as $item) {
            $parent = (int) $item
                ->menu_item_parent;

            if (! isset($byParent[$parent])) {
                $byParent[$parent] = [];
            }

            $byParent[$parent][] = $item;
        }

        return $this->buildTree(
            $byParent,
            0
        );
    }

    public function manageUrl(
        int $objectId
    ): string {
        return admin_url(
            'nav-menus.php?action=edit&menu='
                . $objectId
        );
    }

    private function availableName(
        string $name,
        int $siteId,
        int $artifactId
    ): string|\WP_Error {
        if ($name === '') {
            return new \WP_Error(
                'condixia_ai_stw_classic_menu_name',
                __(
                    'The WordPress navigation menu needs a valid name.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $existing = wp_get_nav_menu_object(
            $name
        );

        if (! $existing instanceof \WP_Term) {
            return $name;
        }

        $ownedSiteId = (int) get_term_meta(
            $existing->term_id,
            '_condixia_ai_stw_site_id',
            true
        );

        $ownedArtifactId = (int) get_term_meta(
            $existing->term_id,
            '_condixia_ai_stw_artifact_id',
            true
        );

        if (
            $siteId > 0
            && $artifactId > 0
            && $ownedSiteId === $siteId
            && $ownedArtifactId === $artifactId
        ) {
            $deleted = wp_delete_nav_menu(
                $existing
            );

            if (
                $deleted === false
                || is_wp_error($deleted)
            ) {
                return is_wp_error($deleted)
                    ? $deleted
                    : new \WP_Error(
                        'condixia_ai_stw_classic_menu_cleanup',
                        __(
                            'Condixia could not replace its previous incomplete navigation menu.',
                            'condixia-ai-site-to-wordpress'
                        )
                    );
            }

            return $name;
        }

        $suffix = '';

        if (
            $siteId > 0
            && $artifactId > 0
        ) {
            $suffix = sprintf(
                ' — Condixia %d-%d',
                $siteId,
                $artifactId
            );
        } elseif ($siteId > 0) {
            $suffix = sprintf(
                ' — Condixia %d',
                $siteId
            );
        } else {
            $suffix = ' — Condixia';
        }

        $candidate = $name
            . $suffix;

        if (
            ! wp_get_nav_menu_object(
                $candidate
            ) instanceof \WP_Term
        ) {
            return $candidate;
        }

        for (
            $number = 2;
            $number <= 100;
            $number++
        ) {
            $candidate = $name
                . $suffix
                . ' '
                . $number;

            if (
                ! wp_get_nav_menu_object(
                    $candidate
                ) instanceof \WP_Term
            ) {
                return $candidate;
            }
        }

        return new \WP_Error(
            'condixia_ai_stw_classic_menu_name_conflict',
            __(
                'Condixia could not find an available WordPress navigation menu name.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }


    private function createItems(
        int $menuId,
        array $items,
        int $parentId
    ): true|\WP_Error {
        foreach ($items as $item) {
            $bindingKey = sanitize_html_class(
                'cdx-nav-key-'
                    . (string) (
                        $item['key']
                        ?? ''
                    )
            );

            $data = [
                'menu-item-title'
                => wp_slash(
                    (string) (
                        $item['label']
                        ?? ''
                    )
                ),
                'menu-item-url'
                => (string) (
                    $item['url']
                    ?? ''
                ),
                'menu-item-status'
                => 'publish',
                'menu-item-parent-id'
                => $parentId,
                'menu-item-classes'
                => $bindingKey,
                'menu-item-target'
                => ! empty($item['opens_in_new_tab'])
                    ? '_blank'
                    : '',
                'menu-item-xfn'
                => (string) (
                    $item['rel']
                    ?? ''
                ),
            ];

            if (
                ! empty($item['post_id'])
            ) {
                $data['menu-item-type']
                    = 'post_type';

                $data['menu-item-object']
                    = 'page';

                $data['menu-item-object-id']
                    = (int) $item['post_id'];
            } else {
                $data['menu-item-type']
                    = 'custom';

                $data['menu-item-object']
                    = 'custom';
            }

            $itemId = wp_update_nav_menu_item(
                $menuId,
                0,
                $data
            );

            if (is_wp_error($itemId)) {
                return $itemId;
            }

            $itemId = (int) $itemId;

            update_post_meta(
                $itemId,
                '_condixia_ai_stw_item_key',
                (string) (
                    $item['key']
                    ?? ''
                )
            );

            $children = is_array(
                $item['children']
                    ?? null
            )
                ? $item['children']
                : [];

            if ($children !== []) {
                $created = $this->createItems(
                    $menuId,
                    $children,
                    $itemId
                );

                if (is_wp_error($created)) {
                    return $created;
                }
            }
        }

        return true;
    }

    private function buildTree(
        array $byParent,
        int $parentId
    ): array {
        $result = [];

        foreach (
            $byParent[$parentId] ?? []
            as $item
        ) {
            $key = (string) get_post_meta(
                $item->ID,
                '_condixia_ai_stw_item_key',
                true
            );

            if ($key === '') {
                foreach (
                    (array) $item->classes
                    as $class
                ) {
                    if (
                        str_starts_with(
                            (string) $class,
                            'cdx-nav-key-'
                        )
                    ) {
                        $key = substr(
                            (string) $class,
                            strlen(
                                'cdx-nav-key-'
                            )
                        );

                        break;
                    }
                }
            }

            $result[] = [
                'key' => $key,
                'label' => (string) $item->title,
                'url' => (string) $item->url,
                'post_id' => (
                    $item->object === 'page'
                )
                    ? (int) $item->object_id
                    : 0,
                'opens_in_new_tab'
                => $item->target
                    === '_blank',
                'rel' => (string) $item->xfn,
                'children' => $this->buildTree(
                    $byParent,
                    (int) $item->ID
                ),
            ];
        }

        return $result;
    }
}
