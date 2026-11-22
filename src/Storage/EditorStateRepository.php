<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class EditorStateRepository
{
    private TableNames $tables;

    public function __construct(
        ?TableNames $tables = null
    ) {
        $this->tables = $tables
            ?? new TableNames();
    }

    public function artifactExists(
        int $siteId,
        int $artifactId
    ): bool {
        global $wpdb;

        if (
            $siteId < 1
            || $artifactId < 1
        ) {
            return false;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->tables->artifacts() . ' a
                INNER JOIN ' . $this->tables->sites() . ' s
                    ON s.id = a.site_id
                WHERE a.id = %d
                    AND a.site_id = %d
                    AND a.manifest_id = s.current_manifest_id
                    AND (
                        a.kind NOT IN (%s, %s)
                        OR a.status = %s
                    )',
                $artifactId,
                $siteId,
                'template',
                'site_part',
                'confirmed'
            )
        ) > 0;
    }

    public function overrides(
        int $siteId,
        int $artifactId
    ): array {
        global $wpdb;

        if (
            ! $this->artifactExists(
                $siteId,
                $artifactId
            )
        ) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT
                    element_id,
                    value_type,
                    property_key,
                    value_json
                FROM ' . $this->tables->overrides() . '
                WHERE site_id = %d
                    AND artifact_id = %d
                ORDER BY
                    element_id ASC,
                    property_key ASC',
                $siteId,
                $artifactId
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        $overrides = [];

        foreach ($rows as $row) {
            $value = json_decode(
                (string) (
                    $row['value_json']
                    ?? ''
                ),
                true
            );

            if (
                ! is_array($value)
                || ! array_key_exists(
                    'value',
                    $value
                )
            ) {
                continue;
            }

            $overrides[] = [
                'element_id' => (string) (
                    $row['element_id']
                    ?? ''
                ),
                'value_type' => (string) (
                    $row['value_type']
                    ?? ''
                ),
                'property_key' => (string) (
                    $row['property_key']
                    ?? ''
                ),
                'value' => is_scalar(
                    $value['value']
                )
                    ? (string) $value['value']
                    : '',
                'attachment_id' => absint(
                    $value['attachment_id']
                        ?? 0
                ),
            ];
        }

        return $overrides;
    }

    public function applyChanges(
        int $siteId,
        int $artifactId,
        array $changes,
        int $userId
    ): true|\WP_Error {
        global $wpdb;

        if (
            ! $this->artifactExists(
                $siteId,
                $artifactId
            )
        ) {
            return $this->error();
        }

        $now = gmdate(
            'Y-m-d H:i:s'
        );

        foreach ($changes as $change) {
            if (! is_array($change)) {
                return $this->error();
            }

            $elementId = (string) (
                $change['element_id']
                ?? ''
            );

            $propertyKey = (string) (
                $change['property_key']
                ?? ''
            );

            $valueType = (string) (
                $change['value_type']
                ?? ''
            );

            $value = (string) (
                $change['value']
                ?? ''
            );

            $attachmentId = absint(
                $change['attachment_id']
                    ?? 0
            );

            if (
                $elementId === ''
                || $propertyKey === ''
                || $valueType === ''
            ) {
                return $this->error();
            }

            $json = wp_json_encode(
                [
                    'value' => $value,
                    'attachment_id'
                    => $attachmentId,
                ],
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );

            if (! is_string($json)) {
                return $this->error();
            }

            $hash = hash(
                'sha256',
                $elementId
                    . "\0"
                    . $propertyKey
            );

            $sql = $wpdb->prepare(
                'INSERT INTO '
                    . $this->tables->overrides()
                    . ' (
                        site_id,
                        artifact_id,
                        element_id,
                        override_hash,
                        value_type,
                        property_key,
                        value_json,
                        created_by,
                        created_at,
                        updated_at
                    ) VALUES (
                        %d,%d,%s,%s,%s,%s,%s,%d,%s,%s
                    )
                    ON DUPLICATE KEY UPDATE
                        value_type = VALUES(value_type),
                        property_key = VALUES(property_key),
                        value_json = VALUES(value_json),
                        created_by = VALUES(created_by),
                        updated_at = VALUES(updated_at)',
                $siteId,
                $artifactId,
                $elementId,
                $hash,
                $valueType,
                $propertyKey,
                $json,
                $userId,
                $now,
                $now
            );

            if (
                $wpdb->query($sql)
                === false
            ) {
                return $this->error();
            }
        }

        return true;
    }

    public function clearElement(
        int $siteId,
        int $artifactId,
        string $elementId
    ): true|\WP_Error {
        global $wpdb;

        if (
            ! $this->artifactExists(
                $siteId,
                $artifactId
            )
            || $elementId === ''
        ) {
            return $this->error();
        }

        $deleted = $wpdb->delete(
            $this->tables->overrides(),
            [
                'site_id' => $siteId,
                'artifact_id' => $artifactId,
                'element_id' => $elementId,
            ],
            [
                '%d',
                '%d',
                '%s',
            ]
        );

        return $deleted === false
            ? $this->error()
            : true;
    }

    public function clearArtifact(
        int $siteId,
        int $artifactId
    ): true|\WP_Error {
        global $wpdb;

        if (
            ! $this->artifactExists(
                $siteId,
                $artifactId
            )
        ) {
            return $this->error();
        }

        $deleted = $wpdb->delete(
            $this->tables->overrides(),
            [
                'site_id' => $siteId,
                'artifact_id' => $artifactId,
            ],
            [
                '%d',
                '%d',
            ]
        );

        return $deleted === false
            ? $this->error()
            : true;
    }

    public function replaceOverrides(
        int $siteId,
        int $artifactId,
        array $overrides,
        int $userId
    ): true|\WP_Error {
        global $wpdb;

        if (
            ! $this->artifactExists(
                $siteId,
                $artifactId
            )
        ) {
            return $this->error();
        }

        $wpdb->query(
            'START TRANSACTION'
        );

        $cleared = $this->clearArtifact(
            $siteId,
            $artifactId
        );

        if (is_wp_error($cleared)) {
            $wpdb->query('ROLLBACK');

            return $cleared;
        }

        $saved = $this->applyChanges(
            $siteId,
            $artifactId,
            $overrides,
            $userId
        );

        if (is_wp_error($saved)) {
            $wpdb->query('ROLLBACK');

            return $saved;
        }

        $wpdb->query('COMMIT');

        return true;
    }

    public function createRevision(
        int $siteId,
        int $artifactId,
        string $revisionType,
        int $userId
    ): int|\WP_Error {
        global $wpdb;

        if (
            ! $this->artifactExists(
                $siteId,
                $artifactId
            )
        ) {
            return $this->error();
        }

        $snapshot = wp_json_encode(
            [
                'version' => 1,
                'overrides' => $this->overrides(
                    $siteId,
                    $artifactId
                ),
            ],
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        if (! is_string($snapshot)) {
            return $this->error();
        }

        $inserted = $wpdb->insert(
            $this->tables->revisions(),
            [
                'site_id' => $siteId,
                'artifact_id' => $artifactId,
                'element_id' => '',
                'revision_type'
                => $revisionType,
                'snapshot_json' => $snapshot,
                'created_by' => $userId,
                'created_at' => gmdate(
                    'Y-m-d H:i:s'
                ),
            ],
            [
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
            ]
        );

        if ($inserted === false) {
            return $this->error();
        }

        return (int) $wpdb->insert_id;
    }

    public function revisions(
        int $siteId,
        int $artifactId,
        int $limit = 25
    ): array {
        global $wpdb;

        if (
            ! $this->artifactExists(
                $siteId,
                $artifactId
            )
        ) {
            return [];
        }

        $limit = max(
            1,
            min(
                100,
                $limit
            )
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT
                    id,
                    revision_type,
                    created_by,
                    created_at
                FROM ' . $this->tables->revisions() . '
                WHERE site_id = %d
                    AND artifact_id = %d
                ORDER BY id DESC
                LIMIT %d',
                $siteId,
                $artifactId,
                $limit
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        return array_map(
            static fn(
                array $row
            ): array => [
                'id' => (int) (
                    $row['id']
                    ?? 0
                ),
                'revision_type' => (string) (
                    $row['revision_type']
                    ?? ''
                ),
                'created_by' => (int) (
                    $row['created_by']
                    ?? 0
                ),
                'created_at' => (string) (
                    $row['created_at']
                    ?? ''
                ),
            ],
            $rows
        );
    }

    public function revisionOverrides(
        int $siteId,
        int $artifactId,
        int $revisionId
    ): array|\WP_Error {
        global $wpdb;

        if (
            ! $this->artifactExists(
                $siteId,
                $artifactId
            )
            || $revisionId < 1
        ) {
            return $this->error();
        }

        $json = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT snapshot_json
                FROM ' . $this->tables->revisions() . '
                WHERE id = %d
                    AND site_id = %d
                    AND artifact_id = %d
                LIMIT 1',
                $revisionId,
                $siteId,
                $artifactId
            )
        );

        if (
            ! is_string($json)
            || $json === ''
        ) {
            return $this->error();
        }

        $snapshot = json_decode(
            $json,
            true
        );

        if (
            ! is_array($snapshot)
            || ! is_array(
                $snapshot['overrides']
                    ?? null
            )
        ) {
            return $this->error();
        }

        return $snapshot['overrides'];
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_editor_state',
            __(
                'Condixia could not update the saved editor state.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
