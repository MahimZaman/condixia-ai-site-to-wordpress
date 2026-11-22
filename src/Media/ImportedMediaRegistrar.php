<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Media;

use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class ImportedMediaRegistrar
{
    private TableNames $tables;
    private ImportedAttachmentFactory $attachments;

    public function __construct(
        ?TableNames $tables = null,
        ?ImportedAttachmentFactory $attachments = null
    ) {
        $this->tables = $tables
            ?? new TableNames();

        $this->attachments = $attachments
            ?? new ImportedAttachmentFactory();
    }

    public function registerManifest(
        int $siteId,
        int $manifestId
    ): true|\WP_Error {
        global $wpdb;

        if (
            $siteId < 1
            || $manifestId < 1
        ) {
            return $this->error();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, relative_path, mime_type, checksum, metadata_json
                FROM ' . $this->tables->files() . '
                WHERE site_id = %d
                    AND manifest_id = %d
                    AND file_type = %s
                    AND status = %s
                ORDER BY id ASC',
                $siteId,
                $manifestId,
                'image',
                'active'
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return $this->error();
        }

        $groups = [];

        foreach ($rows as $row) {
            $checksum = strtolower(
                trim(
                    (string) (
                        $row['checksum']
                        ?? ''
                    )
                )
            );

            if (
                preg_match(
                    '/^[a-f0-9]{64}$/',
                    $checksum
                ) !== 1
            ) {
                continue;
            }

            $groups[$checksum][] = $row;
        }

        foreach ($groups as $checksum => $group) {
            if ($group === []) {
                continue;
            }

            $attachmentId = $this
                ->attachments
                ->findByChecksum(
                    $checksum
                );

            $created = false;

            if ($attachmentId < 1) {
                $attachmentId = $this
                    ->attachments
                    ->create(
                        $checksum,
                        $group[0]
                    );

                if (is_wp_error($attachmentId)) {
                    return $attachmentId;
                }

                if ($attachmentId < 1) {
                    continue;
                }

                $created = true;
            }

            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . $this->tables->files() . '
                    SET attachment_id = %d,
                        ownership = %s,
                        updated_at = %s
                    WHERE site_id = %d
                        AND manifest_id = %d
                        AND checksum = %s
                        AND file_type = %s',
                    $attachmentId,
                    'plugin',
                    gmdate('Y-m-d H:i:s'),
                    $siteId,
                    $manifestId,
                    $checksum,
                    'image'
                )
            );

            if ($updated === false) {
                if ($created) {
                    wp_delete_attachment(
                        $attachmentId,
                        true
                    );
                }

                return $this->error();
            }
        }

        return true;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_media_registration',
            __(
                'Condixia could not connect the imported images to the WordPress Media Library.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
