<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\SiteManagement;

use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class ArtifactManagementRepository
{
    private TableNames $tables;

    public function __construct(?TableNames $tables = null)
    {
        $this->tables = $tables ?? new TableNames();
    }

    public function change(
        int $siteId,
        int $artifactId,
        string $kind,
        string $operation
    ): true|\WP_Error {
        if (
            $siteId < 1
            || $artifactId < 1
            || ! in_array($kind, ['template', 'site_part'], true)
            || ! in_array($operation, ['trash', 'restore'], true)
        ) {
            return $this->error();
        }

        global $wpdb;

        if ($wpdb->query('START TRANSACTION') === false) {
            return $this->error();
        }

        try {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT a.*
                     FROM ' . $this->tables->artifacts() . ' a
                     INNER JOIN ' . $this->tables->sites() . ' s
                         ON s.id = a.site_id
                         AND s.current_manifest_id = a.manifest_id
                     WHERE a.id = %d AND a.site_id = %d AND a.kind = %s
                     LIMIT 1 FOR UPDATE',
                    $artifactId,
                    $siteId,
                    $kind
                ),
                ARRAY_A
            );

            if (! is_array($row)) {
                throw new \RuntimeException();
            }

            $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);

            if (! is_array($metadata)) {
                throw new \RuntimeException();
            }

            $now = gmdate('Y-m-d H:i:s');
            $enabledIds = [];

            if ($operation === 'trash') {
                if (isset($metadata['cdx_trash'])) {
                    throw new \RuntimeException();
                }

                $ids = $wpdb->get_col(
                    $wpdb->prepare(
                        'SELECT id FROM ' . $this->tables->assignments() . '
                         WHERE site_id = %d AND artifact_id = %d AND enabled = 1',
                        $siteId,
                        $artifactId
                    )
                );

                if (! is_array($ids)) {
                    throw new \RuntimeException();
                }

                $enabledIds = array_values(array_filter(array_map('absint', $ids)));

                if (count($enabledIds) > 1000) {
                    throw new \RuntimeException();
                }

                $metadata['cdx_trash'] = [
                    'previous_status' => (string) $row['status'],
                    'enabled_assignment_ids' => $enabledIds,
                    'trashed_at' => $now,
                ];
                $nextStatus = 'ignored';
            } else {
                $trash = $metadata['cdx_trash'] ?? null;

                if (
                    ! is_array($trash)
                    || (string) $row['status'] !== 'ignored'
                    || ! in_array(
                        (string) ($trash['previous_status'] ?? ''),
                        ['analyzed', 'confirmed', 'ignored'],
                        true
                    )
                    || ! is_array($trash['enabled_assignment_ids'] ?? null)
                ) {
                    throw new \RuntimeException();
                }

                $enabledIds = array_values(array_unique(array_filter(
                    array_map('absint', $trash['enabled_assignment_ids'])
                )));

                if (count($enabledIds) > 1000) {
                    throw new \RuntimeException();
                }

                $nextStatus = (string) $trash['previous_status'];
                unset($metadata['cdx_trash']);
            }

            $json = wp_json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            if (! is_string($json)) {
                throw new \RuntimeException();
            }

            $updated = $wpdb->update(
                $this->tables->artifacts(),
                [
                    'status' => $nextStatus,
                    'metadata_json' => $json,
                    'updated_at' => $now,
                ],
                [
                    'id' => $artifactId,
                    'site_id' => $siteId,
                    'manifest_id' => (int) $row['manifest_id'],
                    'kind' => $kind,
                ],
                ['%s', '%s', '%s'],
                ['%d', '%d', '%d', '%s']
            );

            if ($updated !== 1) {
                throw new \RuntimeException();
            }

            if ($operation === 'trash') {
                $changed = $wpdb->update(
                    $this->tables->assignments(),
                    ['enabled' => 0, 'updated_at' => $now],
                    ['site_id' => $siteId, 'artifact_id' => $artifactId],
                    ['%d', '%s'],
                    ['%d', '%d']
                );
            } elseif ($enabledIds !== []) {
                $placeholders = implode(',', array_fill(0, count($enabledIds), '%d'));
                $changed = $wpdb->query(
                    $wpdb->prepare(
                        'UPDATE ' . $this->tables->assignments() . '
                         SET enabled = 1, updated_at = %s
                         WHERE site_id = %d AND artifact_id = %d
                         AND id IN (' . $placeholders . ')',
                        array_merge([$now, $siteId, $artifactId], $enabledIds)
                    )
                );
            } else {
                $changed = 0;
            }

            if ($changed === false || $wpdb->query('COMMIT') === false) {
                throw new \RuntimeException();
            }

            return true;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');

            return $this->error();
        }
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_artifact_management',
            __('The selected template or site part could not be updated safely.', 'condixia-ai-site-to-wordpress')
        );
    }
}
