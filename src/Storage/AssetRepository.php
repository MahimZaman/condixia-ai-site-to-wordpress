<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class AssetRepository
{
    private TableNames $tables;

    public function __construct(
        ?TableNames $tables = null
    ) {
        $this->tables = $tables
            ?? new TableNames();
    }

    public function contextForSite(
        int $siteId
    ): array|\WP_Error {
        global $wpdb;

        if ($siteId < 1) {
            return $this->error();
        }

        $site = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT uuid, current_manifest_id
                FROM ' . $this->tables->sites() . '
                WHERE id = %d
                LIMIT 1',
                $siteId
            ),
            ARRAY_A
        );

        if (
            ! is_array($site)
            || empty($site['uuid'])
            || (int) $site['current_manifest_id'] < 1
        ) {
            return $this->error();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT relative_path, file_type, attachment_id, metadata_json
                FROM ' . $this->tables->files() . '
                WHERE site_id = %d
                    AND manifest_id = %d
                    AND status = %s
                ORDER BY relative_path ASC',
                $siteId,
                (int) $site['current_manifest_id'],
                'active'
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return $this->error();
        }

        $fileIndex = [];
        $assetMap = [];
        $mediaMap = [];

        foreach ($rows as $row) {
            $path = (string) $row['relative_path'];

            $fileIndex[$path] = true;

            $metadata = json_decode(
                (string) $row['metadata_json'],
                true
            );

            if (! is_array($metadata)) {
                $metadata = [];
            }

            $importedAttachmentId = absint(
                $row['attachment_id']
                    ?? 0
            );

            $replacementAttachmentId = absint(
                $metadata['replacement_attachment_id']
                    ?? 0
            );

            $effectiveAttachmentId = 0;
            $url = '';

            if (
                $replacementAttachmentId > 0
                && get_post_type(
                    $replacementAttachmentId
                ) === 'attachment'
                && wp_attachment_is_image(
                    $replacementAttachmentId
                )
            ) {
                $replacementUrl = wp_get_attachment_url(
                    $replacementAttachmentId
                );

                if (
                    is_string($replacementUrl)
                    && $replacementUrl !== ''
                ) {
                    $effectiveAttachmentId
                        = $replacementAttachmentId;

                    $url = $replacementUrl;
                }
            }

            if (
                $url === ''
                && $importedAttachmentId > 0
                && get_post_type(
                    $importedAttachmentId
                ) === 'attachment'
                && wp_attachment_is_image(
                    $importedAttachmentId
                )
            ) {
                $importedUrl = wp_get_attachment_url(
                    $importedAttachmentId
                );

                if (
                    is_string($importedUrl)
                    && $importedUrl !== ''
                ) {
                    $effectiveAttachmentId
                        = $importedAttachmentId;

                    $url = $importedUrl;
                }
            }

            if (
                $url === ''
                && isset($metadata['public_url'])
                && is_string($metadata['public_url'])
                && $metadata['public_url'] !== ''
            ) {
                $url = $metadata['public_url'];
            }

            if ($url !== '') {
                $assetMap[$path] = $url;
            }

            if ((string) $row['file_type'] === 'image') {
                $applyAlt = false;
                $alt = '';

                if ($effectiveAttachmentId > 0) {
                    $applyAlt = $replacementAttachmentId > 0
                        || metadata_exists(
                            'post',
                            $effectiveAttachmentId,
                            '_wp_attachment_image_alt'
                        );

                    if ($applyAlt) {
                        $alt = (string) get_post_meta(
                            $effectiveAttachmentId,
                            '_wp_attachment_image_alt',
                            true
                        );
                    }
                }

                $mediaMap[$path] = [
                    'attachment_id' => $importedAttachmentId,
                    'replacement_attachment_id'
                    => $replacementAttachmentId,
                    'effective_attachment_id'
                    => $effectiveAttachmentId,
                    'url' => $url,
                    'alt' => $alt,
                    'apply_alt' => $applyAlt,
                ];
            }
        }

        return [
            'site_uuid' => (string) $site['uuid'],
            'file_index' => $fileIndex,
            'asset_map' => $assetMap,
            'media_map' => $mediaMap,
        ];
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_asset_context_missing',
            __(
                'The Condixia asset context could not be loaded.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
