<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Media;

use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class ImportedMediaCleanup
{
    private const OWNED_META = '_condixia_ai_stw_owned';

    private TableNames $tables;

    public function __construct(
        ?TableNames $tables = null
    ) {
        $this->tables = $tables
            ?? new TableNames();
    }

    public function cleanupSite(
        int $siteId
    ): void {
        global $wpdb;

        if ($siteId < 1) {
            return;
        }

        $attachmentIds = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DISTINCT attachment_id
                FROM ' . $this->tables->files() . '
                WHERE site_id = %d
                    AND ownership = %s
                    AND attachment_id > 0',
                $siteId,
                'plugin'
            )
        );

        if (! is_array($attachmentIds)) {
            return;
        }

        foreach ($attachmentIds as $attachmentId) {
            $attachmentId = absint(
                $attachmentId
            );

            if (
                $attachmentId < 1
                || ! $this->ownedByPlugin(
                    $attachmentId
                )
                || $this->referencedByAnotherSite(
                    $attachmentId,
                    $siteId
                )
                || $this->usedOutsideCondixia(
                    $attachmentId
                )
            ) {
                continue;
            }

            wp_delete_attachment(
                $attachmentId,
                true
            );
        }
    }

    private function ownedByPlugin(
        int $attachmentId
    ): bool {
        return get_post_type($attachmentId)
            === 'attachment'
            && get_post_meta(
                $attachmentId,
                self::OWNED_META,
                true
            ) === '1';
    }

    private function referencedByAnotherSite(
        int $attachmentId,
        int $siteId
    ): bool {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->tables->files() . '
                WHERE attachment_id = %d
                    AND site_id <> %d',
                $attachmentId,
                $siteId
            )
        ) > 0;
    }

    private function usedOutsideCondixia(
        int $attachmentId
    ): bool {
        global $wpdb;

        $post = get_post(
            $attachmentId
        );

        if (
            ! $post instanceof \WP_Post
            || $post->post_type !== 'attachment'
        ) {
            return true;
        }

        if ((int) $post->post_parent > 0) {
            return true;
        }

        $metaUse = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                FROM {$wpdb->postmeta}
                WHERE (
                    meta_key = %s
                    AND meta_value = %s
                ) OR (
                    meta_key = %s
                    AND FIND_IN_SET(%s, meta_value) > 0
                )",
                '_thumbnail_id',
                (string) $attachmentId,
                '_product_image_gallery',
                (string) $attachmentId
            )
        );

        if ($metaUse > 0) {
            return true;
        }

        $url = wp_get_attachment_url(
            $attachmentId
        );

        $contentUse = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                FROM {$wpdb->posts}
                WHERE ID <> %d
                    AND post_type <> %s
                    AND post_status NOT IN (%s, %s)
                    AND (
                        post_content LIKE %s
                        OR post_content LIKE %s
                    )",
                $attachmentId,
                'revision',
                'trash',
                'auto-draft',
                '%wp-image-'
                    . $attachmentId
                    . '%',
                is_string($url)
                    && $url !== ''
                    ? '%'
                    . $wpdb->esc_like($url)
                    . '%'
                    : '__condixia_no_url__'
            )
        );

        return $contentUse > 0;
    }
}
