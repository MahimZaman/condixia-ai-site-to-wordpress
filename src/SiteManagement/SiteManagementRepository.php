<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\SiteManagement;

use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class SiteManagementRepository
{
    private TableNames $tables;

    public function __construct(
        ?TableNames $tables = null
    ) {
        $this->tables = $tables
            ?? new TableNames();
    }

    public function all(): array|\WP_Error
    {
        global $wpdb;

        $sites = $this->tables->sites();
        $artifacts = $this->tables->artifacts();
        $files = $this->tables->files();

        $rows = $wpdb->get_results(
            "SELECT
                s.*,
                (
                    SELECT COUNT(*)
                    FROM {$artifacts} a
                    WHERE a.site_id = s.id
                        AND a.manifest_id = s.current_manifest_id
                        AND a.kind = 'page'
                ) AS page_count,
                (
                    SELECT COUNT(*)
                    FROM {$artifacts} a
                    WHERE a.site_id = s.id
                        AND a.manifest_id = s.current_manifest_id
                        AND a.kind = 'template'
                        AND a.status = 'confirmed'
                ) AS template_count,
                (
                    SELECT COUNT(*)
                    FROM {$artifacts} a
                    WHERE a.site_id = s.id
                        AND a.manifest_id = s.current_manifest_id
                        AND a.kind = 'site_part'
                        AND a.status = 'confirmed'
                ) AS site_part_count,
                (
                    SELECT COUNT(*)
                    FROM {$files} f
                    WHERE f.site_id = s.id
                        AND f.manifest_id = s.current_manifest_id
                        AND f.status = 'active'
                ) AS file_count
            FROM {$sites} s
            ORDER BY s.updated_at DESC, s.id DESC",
            ARRAY_A
        );

        if (! is_array($rows)) {
            return $this->error();
        }

        return array_map(
            [$this, 'normalizeSite'],
            $rows
        );
    }

    public function find(
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

        if (
            ! is_array($site)
            || (int) (
                $site['current_manifest_id']
                ?? 0
            ) < 1
        ) {
            return $this->error();
        }

        $site = $this->normalizeSite(
            $site
        );

        $manifest = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->manifests() . '
                WHERE id = %d
                    AND site_id = %d
                LIMIT 1',
                $site['current_manifest_id'],
                $siteId
            ),
            ARRAY_A
        );

        if (! is_array($manifest)) {
            return $this->error();
        }

        $artifacts = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->artifacts() . '
                WHERE site_id = %d
                    AND manifest_id = %d
                ORDER BY kind ASC, sort_order ASC, id ASC',
                $siteId,
                $site['current_manifest_id']
            ),
            ARRAY_A
        );

        if (! is_array($artifacts)) {
            return $this->error();
        }

        $grouped = [
            'pages' => [],
            'templates' => [],
            'site_parts' => [],
            'navigation' => [],
            'dynamic_grids' => [],
        ];

        foreach ($artifacts as $row) {
            $artifact = $this->normalizeArtifact(
                $row
            );

            $key = match ($artifact['kind']) {
                'page' => 'pages',
                'template' => 'templates',
                'site_part' => 'site_parts',
                'navigation' => 'navigation',
                'dynamic_grid' => 'dynamic_grids',
                default => '',
            };

            if ($key !== '') {
                $grouped[$key][] = $artifact;
            }
        }

        $fileCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->tables->files() . '
                WHERE site_id = %d
                    AND manifest_id = %d
                    AND status = %s',
                $siteId,
                $site['current_manifest_id'],
                'active'
            )
        );

        $sourceFiles = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT relative_path, file_type, size_bytes
                FROM ' . $this->tables->files() . '
                WHERE site_id = %d
                    AND manifest_id = %d
                    AND status = %s
                ORDER BY relative_path ASC
                LIMIT 500',
                $siteId,
                $site['current_manifest_id'],
                'active'
            ),
            ARRAY_A
        );

        $importHistory = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, version, created_at
                FROM ' . $this->tables->manifests() . '
                WHERE site_id = %d
                ORDER BY version DESC, id DESC
                LIMIT 50',
                $siteId
            ),
            ARRAY_A
        );

        if (! is_array($sourceFiles) || ! is_array($importHistory)) {
            return $this->error();
        }

        $manifest['id'] = (int) $manifest['id'];
        $manifest['site_id'] = (int) $manifest['site_id'];
        $manifest['version'] = (int) $manifest['version'];

        $warnings = json_decode(
            (string) (
                $manifest['warnings_json']
                ?? ''
            ),
            true
        );

        return [
            'site' => $site,
            'manifest' => $manifest,
            'file_count' => max(
                0,
                $fileCount
            ),
            'warnings' => is_array($warnings)
                ? $warnings
                : [],
            'artifacts' => $grouped,
            'source_files' => $sourceFiles,
            'import_history' => $importHistory,
        ];
    }

    public function artifactsForManagement(
        string $kind,
        int $siteId = 0
    ): array|\WP_Error {
        global $wpdb;

        if (! in_array($kind, ['template', 'site_part'], true)) {
            return $this->error();
        }

        $sql = 'SELECT a.*, s.name AS site_name
                FROM ' . $this->tables->artifacts() . ' a
                INNER JOIN ' . $this->tables->sites() . ' s
                    ON s.id = a.site_id
                    AND s.current_manifest_id = a.manifest_id
                WHERE a.kind = %s';
        $arguments = [$kind];

        if ($siteId > 0) {
            $sql .= ' AND a.site_id = %d';
            $arguments[] = $siteId;
        }

        $sql .= ' ORDER BY s.updated_at DESC, a.sort_order ASC, a.id ASC LIMIT 500';

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $arguments),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return $this->error();
        }

        return array_map([$this, 'normalizeArtifact'], $rows);
    }

    public function exists(
        int $siteId
    ): bool {
        global $wpdb;

        if ($siteId < 1) {
            return false;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->tables->sites() . '
                WHERE id = %d',
                $siteId
            )
        ) > 0;
    }

    private function normalizeSite(
        array $row
    ): array {
        foreach (
            [
                'id',
                'current_manifest_id',
                'home_page_id',
                'created_by',
                'page_count',
                'template_count',
                'site_part_count',
                'file_count',
            ]
            as $key
        ) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }

    private function normalizeArtifact(
        array $row
    ): array {
        $metadata = json_decode(
            (string) (
                $row['metadata_json']
                ?? ''
            ),
            true
        );

        $row['id'] = (int) (
            $row['id']
            ?? 0
        );

        $row['site_id'] = (int) (
            $row['site_id']
            ?? 0
        );

        $row['manifest_id'] = (int) (
            $row['manifest_id']
            ?? 0
        );

        $row['wp_object_id'] = (int) (
            $row['wp_object_id']
            ?? 0
        );

        $row['sort_order'] = (int) (
            $row['sort_order']
            ?? 0
        );

        $row['metadata'] = is_array($metadata)
            ? $metadata
            : [];

        return $row;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_site_management',
            __(
                'The imported Condixia site data could not be loaded.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
