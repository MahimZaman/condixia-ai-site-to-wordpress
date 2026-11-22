<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Routing;

use Condixia\AISiteToWordPress\Navigation\NavigationCreationService;
use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class PageCreationService
{
    private TableNames $tables;
    private PageRouteMapRepository $routes;
    private NavigationCreationService $navigation;

    public function __construct(
        ?TableNames $tables = null,
        ?PageRouteMapRepository $routes = null,
        ?NavigationCreationService $navigation = null
    ) {
        $this->tables = $tables
            ?? new TableNames();

        $this->routes = $routes
            ?? new PageRouteMapRepository(
                $this->tables
            );

        $this->navigation = $navigation
            ?? new NavigationCreationService(
                $this->tables,
                $this->routes
            );
    }

    public function create(
        int $siteId
    ): array|\WP_Error {
        global $wpdb;

        if (
            ! current_user_can('edit_pages')
            || ! current_user_can('publish_pages')
        ) {
            return $this->error(
                __(
                    'Your WordPress account cannot create and publish Pages.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $site = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->sites() . '
                WHERE id = %d
                LIMIT 1',
                $siteId
            ),
            ARRAY_A
        );

        if (
            ! is_array($site)
            || ! in_array(
                $site['status'],
                [
                    'confirmed',
                    'pages_created',
                ],
                true
            )
        ) {
            return $this->error(
                __(
                    'The site structure must be confirmed before WordPress Pages can be created.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->artifacts() . '
                WHERE site_id = %d
                    AND manifest_id = %d
                    AND kind = %s
                    AND status IN (%s, %s)
                ORDER BY source_path ASC',
                $siteId,
                (int) $site['current_manifest_id'],
                'page',
                'confirmed',
                'created'
            ),
            ARRAY_A
        );

        if (
            ! is_array($rows)
            || $rows === []
        ) {
            return $this->error(
                __(
                    'There are no confirmed page candidates to create.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $pages = array_map(
            [$this, 'normalizeRow'],
            $rows
        );

        usort(
            $pages,
            static fn(
                array $left,
                array $right
            ): int =>
            substr_count(
                $left['source_path'],
                '/'
            )
                <=>
                substr_count(
                    $right['source_path'],
                    '/'
                )
        );

        $bySource = [];

        foreach ($pages as $page) {
            $bySource[$page['source_path']] = $page;
        }

        $createdIds = [];
        $resolvedIds = [];
        $results = [];

        foreach ($pages as $page) {
            $artifactId = (int) $page['id'];

            if (
                $page['wp_object_id'] > 0
                && get_post_type(
                    $page['wp_object_id']
                ) === 'page'
            ) {
                $resolvedIds[$artifactId] = $page['wp_object_id'];

                continue;
            }

            $parentArtifact = $this->findParent(
                $page['source_path'],
                $bySource
            );

            $parentId = 0;
            $parentArtifactId = 0;

            if ($parentArtifact !== null) {
                $parentArtifactId = (int) $parentArtifact['id'];

                $parentId = $resolvedIds[$parentArtifactId] ?? (int) $parentArtifact['wp_object_id'];
            }

            $postId = wp_insert_post(
                [
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'post_title' => $page['title'],
                    'post_name' => $page['slug'],
                    'post_content' => '',
                    'post_parent' => $parentId,
                    'post_author' => get_current_user_id(),
                    'comment_status' => 'closed',
                    'ping_status' => 'closed',
                    'meta_input' => [
                        '_condixia_ai_stw_site_id'
                        => $siteId,
                        '_condixia_ai_stw_artifact_id'
                        => $artifactId,
                        '_condixia_ai_stw_source_path'
                        => $page['source_path'],
                    ],
                ],
                true
            );

            if (is_wp_error($postId)) {
                $this->cleanupCreated(
                    $createdIds
                );

                return $postId;
            }

            $postId = (int) $postId;

            $createdIds[] = $postId;

            $resolvedIds[$artifactId] = $postId;

            $results[$artifactId] = [
                'post_id' => $postId,
                'parent_artifact_id'
                => $parentArtifactId,
                'parent_post_id' => $parentId,
            ];
        }

        $homeArtifactId = 0;

        foreach ($pages as $page) {
            if (
                ! empty($page['metadata']['confirmed_home'])
            ) {
                $homeArtifactId = (int) $page['id'];

                break;
            }
        }

        $homePageId = $homeArtifactId > 0
            ? (
                $resolvedIds[$homeArtifactId]
                ?? 0
            )
            : 0;

        if ($homePageId > 0) {
            if (! current_user_can('manage_options')) {
                $this->cleanupCreated(
                    $createdIds
                );

                return $this->error(
                    __(
                        'Setting the imported homepage requires permission to manage WordPress options.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            update_option(
                'show_on_front',
                'page'
            );

            update_option(
                'page_on_front',
                $homePageId
            );
        }

        foreach ($pages as $page) {
            $artifactId = (int) $page['id'];

            $postId = $resolvedIds[$artifactId] ?? 0;

            if ($postId < 1) {
                continue;
            }

            $post = get_post(
                $postId
            );

            if (! $post instanceof \WP_Post) {
                $this->cleanupCreated(
                    $createdIds
                );

                return $this->error(
                    __(
                        'A newly created WordPress Page could not be loaded.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            $permalink = get_permalink(
                $postId
            );

            if (! is_string($permalink)) {
                $permalink = '';
            }

            $parentArtifact = $this->findParent(
                $page['source_path'],
                $bySource
            );

            $parentArtifactId = $parentArtifact !== null
                ? (int) $parentArtifact['id']
                : 0;

            $parentPostId = $parentArtifactId > 0
                ? (
                    $resolvedIds[$parentArtifactId]
                    ?? 0
                )
                : 0;

            $metadata = $page['metadata'];

            $metadata['requested_slug']
                = $page['slug'];

            $metadata['actual_slug']
                = $post->post_name;

            $metadata['slug_collision']
                = $page['slug']
                !== $post->post_name;

            $metadata['permalink']
                = $permalink;

            $metadata['parent_artifact_id']
                = $parentArtifactId;

            $metadata['parent_wp_object_id']
                = $parentPostId;

            $metadata['route_created']
                = true;

            $updated = $wpdb->update(
                $this->tables->artifacts(),
                [
                    'wp_object_id' => $postId,
                    'status' => 'created',
                    'metadata_json' => $this->json(
                        $metadata
                    ),
                    'updated_at' => gmdate(
                        'Y-m-d H:i:s'
                    ),
                ],
                [
                    'id' => $artifactId,
                    'site_id' => $siteId,
                ],
                [
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                ],
                [
                    '%d',
                    '%d',
                ]
            );

            if ($updated === false) {
                $this->cleanupCreated(
                    $createdIds
                );

                return $this->error(
                    __(
                        'Condixia could not save the WordPress page routing metadata.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }
        }

        $navigationResult = $this
            ->navigation
            ->createForSite(
                $siteId
            );

        if (is_wp_error($navigationResult)) {
            return $navigationResult;
        }


        $updated = $wpdb->update(
            $this->tables->sites(),
            [
                'status' => 'pages_created',
                'home_page_id' => $homePageId,
                'updated_at' => gmdate(
                    'Y-m-d H:i:s'
                ),
            ],
            [
                'id' => $siteId,
            ],
            [
                '%s',
                '%d',
                '%s',
            ],
            [
                '%d',
            ]
        );

        if ($updated === false) {
            $this->cleanupCreated(
                $createdIds
            );

            return $this->error(
                __(
                    'Condixia could not finalize WordPress page routing.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        return [
            'site_id' => $siteId,
            'created_count' => count(
                $createdIds
            ),
            'page_count' => count(
                $resolvedIds
            ),
            'home_page_id' => $homePageId,
            'route_map' => $this->routes
                ->mapForSite(
                    $siteId
                ),
            'navigation' => $navigationResult,
        ];
    }

    private function findParent(
        string $sourcePath,
        array $bySource
    ): ?array {
        $sourcePath = trim(
            str_replace(
                '\\',
                '/',
                $sourcePath
            ),
            '/'
        );

        $directory = dirname(
            $sourcePath
        );

        $stem = strtolower(
            pathinfo(
                basename($sourcePath),
                PATHINFO_FILENAME
            )
        );

        if ($stem === 'index') {
            $directory = dirname(
                $directory
            );
        }

        while (
            $directory !== '.'
            && $directory !== ''
            && $directory !== '/'
        ) {
            $candidates = [
                $directory . '/index.html',
                $directory . '/index.htm',
                $directory . '.html',
                $directory . '.htm',
            ];

            foreach ($candidates as $candidate) {
                if (
                    $candidate !== $sourcePath
                    && isset(
                        $bySource[$candidate]
                    )
                ) {
                    return $bySource[$candidate];
                }
            }

            $next = dirname(
                $directory
            );

            if ($next === $directory) {
                break;
            }

            $directory = $next;
        }

        return null;
    }

    private function normalizeRow(
        array $row
    ): array {
        $metadata = json_decode(
            (string) (
                $row['metadata_json']
                ?? ''
            ),
            true
        );

        $row['id'] = (int) $row['id'];
        $row['wp_object_id'] = (int) $row['wp_object_id'];

        $row['metadata'] = is_array(
            $metadata
        )
            ? $metadata
            : [];

        return $row;
    }

    private function cleanupCreated(
        array $postIds
    ): void {
        foreach (
            array_reverse($postIds)
            as $postId
        ) {
            wp_delete_post(
                (int) $postId,
                true
            );
        }
    }

    private function json(array $value): string
    {
        $json = wp_json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        return is_string($json)
            ? $json
            : '{}';
    }

    private function error(
        string $message
    ): \WP_Error {
        return new \WP_Error(
            'condixia_ai_stw_page_creation',
            $message
        );
    }
}
