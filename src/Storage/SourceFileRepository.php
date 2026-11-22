<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class SourceFileRepository
{
    private TableNames $tables;
    private SourceStore $store;

    public function __construct(
        ?TableNames $tables = null,
        ?SourceStore $store = null
    ) {
        $this->tables = $tables
            ?? new TableNames();

        $this->store = $store
            ?? new SourceStore();
    }

    public function readHtml(
        int $siteId,
        int $manifestId,
        string $sourcePath
    ): string|\WP_Error {
        global $wpdb;

        if (
            $siteId < 1
            || $manifestId < 1
            || $sourcePath === ''
        ) {
            return $this->error();
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT file_type, checksum, metadata_json
                FROM ' . $this->tables->files() . '
                WHERE site_id = %d
                    AND manifest_id = %d
                    AND relative_path = %s
                    AND status = %s
                LIMIT 1',
                $siteId,
                $manifestId,
                $sourcePath,
                'active'
            ),
            ARRAY_A
        );

        if (
            ! is_array($row)
            || (string) $row['file_type'] !== 'html'
        ) {
            return $this->error();
        }

        $metadata = json_decode(
            (string) $row['metadata_json'],
            true
        );

        if (
            ! is_array($metadata)
            || empty($metadata['storage_key'])
            || ! is_string(
                $metadata['storage_key']
            )
        ) {
            return $this->error();
        }

        $html = $this->store->read(
            $metadata['storage_key']
        );

        if (is_wp_error($html)) {
            return $html;
        }

        $expectedChecksum = (string) ($row['checksum'] ?? '');

        if (
            ! preg_match('/^[a-f0-9]{64}$/i', $expectedChecksum)
            || ! hash_equals($expectedChecksum, hash('sha256', $html))
        ) {
            return $this->error();
        }

        return $html;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_source_file_missing',
            __(
                'The imported HTML source could not be loaded.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
