<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\SiteManagement;

use Condixia\AISiteToWordPress\Media\ImportedMediaCleanup;
use Condixia\AISiteToWordPress\Storage\SourceStore;
use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class SiteDeletionService
{
    private TableNames $tables;
    private ImportedMediaCleanup $mediaCleanup;
    private SourceStore $sourceStore;

    public function __construct(
        ?TableNames $tables = null,
        ?ImportedMediaCleanup $mediaCleanup = null,
        ?SourceStore $sourceStore = null
    ) {
        $this->tables = $tables
            ?? new TableNames();

        $this->mediaCleanup = $mediaCleanup
            ?? new ImportedMediaCleanup(
                $this->tables
            );

        $this->sourceStore = $sourceStore
            ?? new SourceStore();
    }

    public function delete(
        int $siteId
    ): array|\WP_Error {
        global $wpdb;

        if ($siteId < 1) {
            return $this->error();
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

        if (! is_array($site)) {
            return $this->error(
                __(
                    'The Condixia site no longer exists.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $pagesDeleted = $this->deletePages(
            $siteId
        );

        if (is_wp_error($pagesDeleted)) {
            return $pagesDeleted;
        }

        $navigationDeleted = $this
            ->deleteNavigation(
                $siteId
            );

        if (is_wp_error($navigationDeleted)) {
            return $navigationDeleted;
        }

        $homePageId = (int) (
            $site['home_page_id']
            ?? 0
        );

        if (
            $homePageId > 0
            && (int) get_option(
                'page_on_front'
            ) === $homePageId
        ) {
            update_option(
                'page_on_front',
                0
            );

            if (
                get_option('show_on_front')
                === 'page'
            ) {
                update_option(
                    'show_on_front',
                    'posts'
                );
            }
        }

        $this->mediaCleanup->cleanupSite(
            $siteId
        );

        $deleted = $this->deleteRows(
            $siteId
        );

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        $uuid = (string) (
            $site['uuid']
            ?? ''
        );

        if ($uuid !== '') {
            $this->sourceStore->deleteSite(
                $uuid
            );
        }

        return [
            'site_id' => $siteId,
            'pages_deleted' => $pagesDeleted,
            'navigation_deleted'
            => $navigationDeleted,
        ];
    }

    private function deletePages(
        int $siteId
    ): int|\WP_Error {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, wp_object_id
                FROM ' . $this->tables->artifacts() . '
                WHERE site_id = %d
                    AND kind = %s
                    AND wp_object_id > 0
                ORDER BY id ASC',
                $siteId,
                'page'
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return $this->error();
        }

        $deleted = 0;

        foreach ($rows as $row) {
            $artifactId = (int) (
                $row['id']
                ?? 0
            );

            $postId = (int) (
                $row['wp_object_id']
                ?? 0
            );

            if ($postId < 1) {
                continue;
            }

            $post = get_post(
                $postId
            );

            if (! $post instanceof \WP_Post) {
                continue;
            }

            if (
                $post->post_type !== 'page'
                || (int) get_post_meta(
                    $postId,
                    '_condixia_ai_stw_site_id',
                    true
                ) !== $siteId
                || (int) get_post_meta(
                    $postId,
                    '_condixia_ai_stw_artifact_id',
                    true
                ) !== $artifactId
            ) {
                return $this->error(
                    __(
                        'Condixia stopped site deletion because ownership of a generated WordPress Page could not be verified.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            if (
                ! wp_delete_post(
                    $postId,
                    true
                )
            ) {
                return $this->error(
                    __(
                        'A Condixia-owned WordPress Page could not be deleted.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            $deleted++;
        }

        return $deleted;
    }

    private function deleteNavigation(
        int $siteId
    ): int|\WP_Error {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT
                    artifact_id,
                    binding_target
                FROM ' . $this->tables->bindings() . '
                WHERE site_id = %d
                    AND binding_type = %s
                    AND enabled = 1
                ORDER BY id ASC',
                $siteId,
                'navigation'
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return $this->error();
        }

        $deleted = 0;
        $handled = [];

        foreach ($rows as $row) {
            $artifactId = absint(
                $row['artifact_id']
                    ?? 0
            );

            $objectId = absint(
                $row['binding_target']
                    ?? 0
            );

            if (
                $objectId < 1
                || isset($handled[$objectId])
            ) {
                continue;
            }

            $handled[$objectId] = true;

            $menu = wp_get_nav_menu_object(
                $objectId
            );

            if ($menu instanceof \WP_Term) {
                if (
                    (int) get_term_meta(
                        $menu->term_id,
                        '_condixia_ai_stw_site_id',
                        true
                    ) !== $siteId
                    || (
                        $artifactId > 0
                        && (int) get_term_meta(
                            $menu->term_id,
                            '_condixia_ai_stw_artifact_id',
                            true
                        ) !== $artifactId
                    )
                ) {
                    return $this->error(
                        __(
                            'Condixia stopped site deletion because ownership of a WordPress navigation menu could not be verified.',
                            'condixia-ai-site-to-wordpress'
                        )
                    );
                }

                $result = wp_delete_nav_menu(
                    $menu
                );

                if (
                    $result === false
                    || is_wp_error($result)
                ) {
                    return is_wp_error($result)
                        ? $result
                        : $this->error();
                }

                $deleted++;

                continue;
            }

            $post = get_post(
                $objectId
            );

            if (! $post instanceof \WP_Post) {
                continue;
            }

            if (
                $post->post_type
                !== 'wp_navigation'
                || (int) get_post_meta(
                    $objectId,
                    '_condixia_ai_stw_site_id',
                    true
                ) !== $siteId
                || (
                    $artifactId > 0
                    && (int) get_post_meta(
                        $objectId,
                        '_condixia_ai_stw_artifact_id',
                        true
                    ) !== $artifactId
                )
            ) {
                return $this->error(
                    __(
                        'Condixia stopped site deletion because ownership of a WordPress navigation object could not be verified.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            if (
                ! wp_delete_post(
                    $objectId,
                    true
                )
            ) {
                return $this->error(
                    __(
                        'A Condixia-owned WordPress navigation object could not be deleted.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            $deleted++;
        }

        return $deleted;
    }

    private function deleteRows(
        int $siteId
    ): true|\WP_Error {
        global $wpdb;

        $wpdb->query(
            'START TRANSACTION'
        );

        $tables = [
            $this->tables->revisions(),
            $this->tables->overrides(),
            $this->tables->assignments(),
            $this->tables->bindings(),
            $this->tables->artifacts(),
            $this->tables->files(),
            $this->tables->manifests(),
        ];

        foreach ($tables as $table) {
            $result = $wpdb->delete(
                $table,
                [
                    'site_id' => $siteId,
                ],
                [
                    '%d',
                ]
            );

            if ($result === false) {
                $wpdb->query(
                    'ROLLBACK'
                );

                return $this->error();
            }
        }

        $siteDeleted = $wpdb->delete(
            $this->tables->sites(),
            [
                'id' => $siteId,
            ],
            [
                '%d',
            ]
        );

        if ($siteDeleted !== 1) {
            $wpdb->query(
                'ROLLBACK'
            );

            return $this->error();
        }

        $wpdb->query(
            'COMMIT'
        );

        return true;
    }

    private function error(
        ?string $message = null
    ): \WP_Error {
        return new \WP_Error(
            'condixia_ai_stw_site_delete',
            $message
                ?? __(
                    'Condixia could not safely delete the imported site.',
                    'condixia-ai-site-to-wordpress'
                )
        );
    }
}
