<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class AssignmentRepository
{
    private TableNames $tables;

    public function __construct(
        ?TableNames $tables = null
    ) {
        $this->tables = $tables
            ?? new TableNames();
    }

    public function save(
        int $siteId,
        int $artifactId,
        string $contextType,
        string $contextKey = '',
        int $priority = 0,
        array $conditions = [],
        bool $enabled = true
    ): true|\WP_Error {
        global $wpdb;

        $contextType = sanitize_key(
            $contextType
        );

        $contextKey = sanitize_text_field(
            $contextKey
        );

        if (
            $siteId < 1
            || $artifactId < 1
            || $contextType === ''
            || strlen($contextKey) > 191
        ) {
            return $this->error();
        }

        $artifactExists = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->tables->artifacts() . '
                WHERE id = %d
                    AND site_id = %d
                    AND kind IN (%s, %s)',
                $artifactId,
                $siteId,
                'template',
                'site_part'
            )
        );

        if ($artifactExists !== 1) {
            return $this->error();
        }

        $conditionHash = hash(
            'sha256',
            $contextType
                . "\0"
                . $contextKey
        );

        $conditions['context_type'] = $contextType;
        $conditions['context_key'] = $contextKey;

        $json = wp_json_encode(
            $conditions,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        if (! is_string($json)) {
            return $this->error();
        }

        $now = gmdate(
            'Y-m-d H:i:s'
        );

        $existingId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id
                FROM ' . $this->tables->assignments() . '
                WHERE artifact_id = %d
                    AND condition_hash = %s
                LIMIT 1',
                $artifactId,
                $conditionHash
            )
        );

        if ($existingId > 0) {
            $updated = $wpdb->update(
                $this->tables->assignments(),
                [
                    'site_id' => $siteId,
                    'context_type' => $contextType,
                    'context_key' => $contextKey,
                    'priority' => $priority,
                    'conditions_json' => $json,
                    'enabled' => $enabled
                        ? 1
                        : 0,
                    'updated_at' => $now,
                ],
                [
                    'id' => $existingId,
                ],
                [
                    '%d',
                    '%s',
                    '%s',
                    '%d',
                    '%s',
                    '%d',
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

        $inserted = $wpdb->insert(
            $this->tables->assignments(),
            [
                'site_id' => $siteId,
                'artifact_id' => $artifactId,
                'context_type' => $contextType,
                'context_key' => $contextKey,
                'condition_hash' => $conditionHash,
                'priority' => $priority,
                'conditions_json' => $json,
                'enabled' => $enabled
                    ? 1
                    : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%d',
                '%s',
                '%s',
            ]
        );

        return $inserted === 1
            ? true
            : $this->error();
    }

    public function countForSite(
        int $siteId
    ): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->tables->assignments() . '
                WHERE site_id = %d',
                $siteId
            )
        );
    }

    public function completedSiteIds(): array
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id
                FROM ' . $this->tables->sites() . '
                WHERE status = %s
                ORDER BY id ASC',
                'pages_created'
            )
        );

        if (! is_array($ids)) {
            return [];
        }

        return array_values(
            array_map(
                'intval',
                $ids
            )
        );
    }

    public function artifactsForSite(
        int $siteId
    ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->artifacts() . '
                WHERE site_id = %d
                    AND kind IN (%s, %s, %s)
                ORDER BY kind ASC, sort_order ASC, id ASC',
                $siteId,
                'page',
                'template',
                'site_part'
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        return array_map(
            [$this, 'normalizeArtifact'],
            $rows
        );
    }

    public function candidates(
        string $kind,
        string $role = '',
        int $siteId = 0
    ): array {
        global $wpdb;

        $kind = sanitize_key($kind);
        $role = sanitize_key($role);

        if (
            ! in_array(
                $kind,
                [
                    'template',
                    'site_part',
                ],
                true
            )
        ) {
            return [];
        }

        if (
            $siteId > 0
            && $role !== ''
        ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT
                        x.id AS assignment_id,
                        x.context_type,
                        x.context_key,
                        x.priority,
                        x.conditions_json,
                        a.*
                    FROM ' . $this->tables->assignments() . ' x
                    INNER JOIN ' . $this->tables->artifacts() . ' a
                        ON a.id = x.artifact_id
                    INNER JOIN ' . $this->tables->sites() . ' s
                        ON s.id = a.site_id
                    WHERE x.enabled = 1
                        AND a.kind = %s
                        AND a.role = %s
                        AND a.site_id = %d
                        AND a.status IN (%s, %s)
                        AND s.status = %s
                    ORDER BY x.id ASC',
                    $kind,
                    $role,
                    $siteId,
                    'confirmed',
                    'created',
                    'pages_created'
                ),
                ARRAY_A
            );
        } elseif ($siteId > 0) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT
                        x.id AS assignment_id,
                        x.context_type,
                        x.context_key,
                        x.priority,
                        x.conditions_json,
                        a.*
                    FROM ' . $this->tables->assignments() . ' x
                    INNER JOIN ' . $this->tables->artifacts() . ' a
                        ON a.id = x.artifact_id
                    INNER JOIN ' . $this->tables->sites() . ' s
                        ON s.id = a.site_id
                    WHERE x.enabled = 1
                        AND a.kind = %s
                        AND a.site_id = %d
                        AND a.status IN (%s, %s)
                        AND s.status = %s
                    ORDER BY x.id ASC',
                    $kind,
                    $siteId,
                    'confirmed',
                    'created',
                    'pages_created'
                ),
                ARRAY_A
            );
        } elseif ($role !== '') {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT
                        x.id AS assignment_id,
                        x.context_type,
                        x.context_key,
                        x.priority,
                        x.conditions_json,
                        a.*
                    FROM ' . $this->tables->assignments() . ' x
                    INNER JOIN ' . $this->tables->artifacts() . ' a
                        ON a.id = x.artifact_id
                    INNER JOIN ' . $this->tables->sites() . ' s
                        ON s.id = a.site_id
                    WHERE x.enabled = 1
                        AND a.kind = %s
                        AND a.role = %s
                        AND a.status IN (%s, %s)
                        AND s.status = %s
                    ORDER BY x.id ASC',
                    $kind,
                    $role,
                    'confirmed',
                    'created',
                    'pages_created'
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT
                        x.id AS assignment_id,
                        x.context_type,
                        x.context_key,
                        x.priority,
                        x.conditions_json,
                        a.*
                    FROM ' . $this->tables->assignments() . ' x
                    INNER JOIN ' . $this->tables->artifacts() . ' a
                        ON a.id = x.artifact_id
                    INNER JOIN ' . $this->tables->sites() . ' s
                        ON s.id = a.site_id
                    WHERE x.enabled = 1
                        AND a.kind = %s
                        AND a.status IN (%s, %s)
                        AND s.status = %s
                    ORDER BY x.id ASC',
                    $kind,
                    'confirmed',
                    'created',
                    'pages_created'
                ),
                ARRAY_A
            );
        }

        if (! is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row = $this->normalizeArtifact(
                $row
            );

            $conditions = json_decode(
                (string) (
                    $row['conditions_json']
                    ?? ''
                ),
                true
            );

            $row['assignment_id'] = (int) (
                $row['assignment_id']
                ?? 0
            );

            $row['priority'] = (int) (
                $row['priority']
                ?? 0
            );

            $row['conditions'] = is_array(
                $conditions
            )
                ? $conditions
                : [];
        }

        unset($row);

        return $rows;
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

        $row['metadata'] = is_array(
            $metadata
        )
            ? $metadata
            : [];

        return $row;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_assignment_storage',
            __(
                'Condixia could not save the display condition.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
