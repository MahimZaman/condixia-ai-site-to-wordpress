<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Media;

use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class MediaReplacementService
{
    private TableNames $tables;

    public function __construct(
        ?TableNames $tables = null
    ) {
        $this->tables = $tables
            ?? new TableNames();
    }

    public function replace(
        int $siteId,
        string $relativePath,
        int $attachmentId
    ): true|\WP_Error {
        if (
            $siteId < 1
            || $relativePath === ''
            || $attachmentId < 1
            || get_post_type($attachmentId)
            !== 'attachment'
            || ! wp_attachment_is_image(
                $attachmentId
            )
        ) {
            return $this->error();
        }

        $url = wp_get_attachment_url(
            $attachmentId
        );

        if (
            ! is_string($url)
            || $url === ''
        ) {
            return $this->error();
        }

        return $this->updateMetadata(
            $siteId,
            $relativePath,
            static function (
                array $metadata
            ) use ($attachmentId): array {
                $metadata['replacement_attachment_id'] = $attachmentId;

                return $metadata;
            }
        );
    }

    public function reset(
        int $siteId,
        string $relativePath
    ): true|\WP_Error {
        return $this->updateMetadata(
            $siteId,
            $relativePath,
            static function (
                array $metadata
            ): array {
                unset(
                    $metadata['replacement_attachment_id']
                );

                return $metadata;
            }
        );
    }

    public function updateAlt(
        int $attachmentId,
        string $alt
    ): true|\WP_Error {
        if (
            $attachmentId < 1
            || get_post_type($attachmentId)
            !== 'attachment'
            || ! wp_attachment_is_image(
                $attachmentId
            )
        ) {
            return $this->error();
        }

        $alt = sanitize_text_field(
            $alt
        );

        $updated = update_post_meta(
            $attachmentId,
            '_wp_attachment_image_alt',
            $alt
        );

        if (
            $updated === false
            && get_post_meta(
                $attachmentId,
                '_wp_attachment_image_alt',
                true
            ) !== $alt
        ) {
            return $this->error();
        }

        return true;
    }

    private function updateMetadata(
        int $siteId,
        string $relativePath,
        callable $mutator
    ): true|\WP_Error {
        global $wpdb;

        if (
            $siteId < 1
            || $relativePath === ''
        ) {
            return $this->error();
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT f.id, f.metadata_json
                FROM ' . $this->tables->files() . ' f
                INNER JOIN ' . $this->tables->sites() . ' s
                    ON s.id = f.site_id
                WHERE f.site_id = %d
                    AND f.manifest_id = s.current_manifest_id
                    AND f.relative_path = %s
                    AND f.file_type = %s
                    AND f.status = %s
                LIMIT 1',
                $siteId,
                $relativePath,
                'image',
                'active'
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return $this->error();
        }

        $metadata = json_decode(
            (string) (
                $row['metadata_json']
                ?? ''
            ),
            true
        );

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $metadata = $mutator(
            $metadata
        );

        if (! is_array($metadata)) {
            return $this->error();
        }

        $json = wp_json_encode(
            $metadata,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        if (! is_string($json)) {
            return $this->error();
        }

        $updated = $wpdb->update(
            $this->tables->files(),
            [
                'metadata_json' => $json,
                'updated_at' => gmdate(
                    'Y-m-d H:i:s'
                ),
            ],
            [
                'id' => (int) $row['id'],
            ],
            [
                '%s',
                '%s',
            ],
            [
                '%d',
            ]
        );

        return $updated === false
            ? $this->error()
            : true;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_media_replacement',
            __(
                'Condixia could not update the Media Library replacement.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
