<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\SiteManagement;

use Condixia\AISiteToWordPress\Storage\SourceStore;
use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class SiteExportService
{
    private TableNames $tables;
    private SourceStore $sourceStore;

    public function __construct(
        ?TableNames $tables = null,
        ?SourceStore $sourceStore = null
    ) {
        $this->tables = $tables
            ?? new TableNames();

        $this->sourceStore = $sourceStore
            ?? new SourceStore();
    }

    public function build(
        int $siteId
    ): array|\WP_Error {
        global $wpdb;

        if (
            $siteId < 1
            || ! class_exists(\ZipArchive::class)
        ) {
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

        if (
            ! is_array($site)
            || (int) (
                $site['current_manifest_id']
                ?? 0
            ) < 1
        ) {
            return $this->error();
        }

        $manifestId = (int)
        $site['current_manifest_id'];

        $manifest = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->manifests() . '
                WHERE id = %d
                    AND site_id = %d
                LIMIT 1',
                $manifestId,
                $siteId
            ),
            ARRAY_A
        );

        if (! is_array($manifest)) {
            return $this->error();
        }

        $files = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->files() . '
                WHERE site_id = %d
                    AND manifest_id = %d
                    AND status = %s
                ORDER BY relative_path ASC',
                $siteId,
                $manifestId,
                'active'
            ),
            ARRAY_A
        );

        if (! is_array($files)) {
            return $this->error();
        }

        if (! function_exists('wp_tempnam')) {
            require_once ABSPATH
                . 'wp-admin/includes/file.php';
        }

        $temporary = wp_tempnam(
            'condixia-site-export.zip'
        );

        if (
            ! is_string($temporary)
            || $temporary === ''
        ) {
            return $this->error();
        }

        $zip = new \ZipArchive();

        $opened = $zip->open(
            $temporary,
            \ZipArchive::CREATE
                | \ZipArchive::OVERWRITE
        );

        if ($opened !== true) {
            @unlink($temporary);

            return $this->error();
        }

        $fileState = [];

        foreach ($files as $row) {
            $relativePath = (string) (
                $row['relative_path']
                ?? ''
            );

            if (
                ! $this->safeRelativePath(
                    $relativePath
                )
            ) {
                $zip->close();
                @unlink($temporary);

                return $this->error();
            }

            $metadata = json_decode(
                (string) (
                    $row['metadata_json']
                    ?? ''
                ),
                true
            );

            if (
                ! is_array($metadata)
                || empty($metadata['storage_key'])
                || ! is_string(
                    $metadata['storage_key']
                )
            ) {
                $zip->close();
                @unlink($temporary);

                return $this->error();
            }

            $content = $this
                ->sourceStore
                ->read(
                    $metadata['storage_key']
                );

            if (is_wp_error($content)) {
                $zip->close();
                @unlink($temporary);

                return $content;
            }

            if (
                ! $zip->addFromString(
                    'source/'
                        . $relativePath,
                    $content
                )
            ) {
                $zip->close();
                @unlink($temporary);

                return $this->error();
            }

            $fileState[] = [
                'relative_path'
                => $relativePath,
                'file_type' => (string) (
                    $row['file_type']
                    ?? ''
                ),
                'mime_type' => (string) (
                    $row['mime_type']
                    ?? ''
                ),
                'size_bytes' => (int) (
                    $row['size_bytes']
                    ?? 0
                ),
                'checksum' => (string) (
                    $row['checksum']
                    ?? ''
                ),
                'ownership' => (string) (
                    $row['ownership']
                    ?? ''
                ),
                'attachment_id' => (int) (
                    $row['attachment_id']
                    ?? 0
                ),
                'replacement_attachment_id'
                => absint(
                    $metadata['replacement_attachment_id']
                        ?? 0
                ),
            ];
        }

        $state = [
            'format'
            => 'condixia-ai-site-to-wordpress-state',
            'version' => 1,
            'exported_at' => gmdate('c'),
            'site' => $this->siteState(
                $site
            ),
            'manifest' => [
                'id' => (int) (
                    $manifest['id']
                    ?? 0
                ),
                'version' => (int) (
                    $manifest['version']
                    ?? 0
                ),
                'checksum' => (string) (
                    $manifest['checksum']
                    ?? ''
                ),
                'manifest' => $this->decode(
                    (string) (
                        $manifest['manifest_json']
                        ?? ''
                    ),
                    []
                ),
                'warnings' => $this->decode(
                    (string) (
                        $manifest['warnings_json']
                        ?? ''
                    ),
                    []
                ),
            ],
            'files' => $fileState,
            'artifacts' => $this->rows(
                $this->tables->artifacts(),
                $siteId,
                $manifestId
            ),
            'assignments' => $this->rows(
                $this->tables->assignments(),
                $siteId
            ),
            'bindings' => $this->rows(
                $this->tables->bindings(),
                $siteId
            ),
            'overrides' => $this->rows(
                $this->tables->overrides(),
                $siteId
            ),
            'revisions' => $this->rows(
                $this->tables->revisions(),
                $siteId
            ),
        ];

        $json = wp_json_encode(
            $state,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        if (
            ! is_string($json)
            || ! $zip->addFromString(
                'condixia-state.json',
                $json
            )
        ) {
            $zip->close();
            @unlink($temporary);

            return $this->error();
        }

        if (! $zip->close()) {
            @unlink($temporary);

            return $this->error();
        }

        $filename = sanitize_file_name(
            (string) (
                $site['name']
                ?? 'condixia-site'
            )
                . '-'
                . gmdate('Y-m-d')
                . '.zip'
        );

        if ($filename === '') {
            $filename = 'condixia-site-export.zip';
        }

        return [
            'path' => $temporary,
            'filename' => $filename,
        ];
    }

    private function rows(
        string $table,
        int $siteId,
        int $manifestId = 0
    ): array {
        global $wpdb;

        if ($manifestId > 0) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT *
                    FROM {$table}
                    WHERE site_id = %d
                        AND manifest_id = %d
                    ORDER BY id ASC",
                    $siteId,
                    $manifestId
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT *
                    FROM {$table}
                    WHERE site_id = %d
                    ORDER BY id ASC",
                    $siteId
                ),
                ARRAY_A
            );
        }

        if (! is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            foreach (
                [
                    'metadata_json',
                    'conditions_json',
                    'config_json',
                    'value_json',
                    'snapshot_json',
                ]
                as $key
            ) {
                if (
                    ! isset($row[$key])
                    || ! is_string(
                        $row[$key]
                    )
                ) {
                    continue;
                }

                $decoded = json_decode(
                    $row[$key],
                    true
                );

                if (is_array($decoded)) {
                    $row[$key] = $decoded;
                }
            }
        }

        unset($row);

        return $rows;
    }

    private function siteState(
        array $site
    ): array {
        return [
            'id' => (int) (
                $site['id']
                ?? 0
            ),
            'uuid' => (string) (
                $site['uuid']
                ?? ''
            ),
            'name' => (string) (
                $site['name']
                ?? ''
            ),
            'status' => (string) (
                $site['status']
                ?? ''
            ),
            'source_type' => (string) (
                $site['source_type']
                ?? ''
            ),
            'source_label' => (string) (
                $site['source_label']
                ?? ''
            ),
            'current_manifest_id' => (int) (
                $site['current_manifest_id']
                ?? 0
            ),
            'home_page_id' => (int) (
                $site['home_page_id']
                ?? 0
            ),
            'created_by' => (int) (
                $site['created_by']
                ?? 0
            ),
            'created_at' => (string) (
                $site['created_at']
                ?? ''
            ),
            'updated_at' => (string) (
                $site['updated_at']
                ?? ''
            ),
        ];
    }

    private function decode(
        string $json,
        array $fallback
    ): array {
        $decoded = json_decode(
            $json,
            true
        );

        return is_array($decoded)
            ? $decoded
            : $fallback;
    }

    private function safeRelativePath(
        string $path
    ): bool {
        if (
            $path === ''
            || str_starts_with(
                $path,
                '/'
            )
            || str_contains(
                $path,
                '\\'
            )
            || preg_match(
                '/^[A-Za-z]:/',
                $path
            ) === 1
        ) {
            return false;
        }

        foreach (
            explode('/', $path)
            as $segment
        ) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
            ) {
                return false;
            }
        }

        return true;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_site_export',
            __(
                'Condixia could not export the current imported site package and state.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
