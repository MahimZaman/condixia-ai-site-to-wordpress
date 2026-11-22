<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class NavigationBindingRepository
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
        string $elementId,
        string $provider,
        int $objectId,
        array $config
    ): true|\WP_Error {
        global $wpdb;

        $bindingHash = hash(
            'sha256',
            'navigation'
                . "\0"
                . $artifactId
        );

        $json = wp_json_encode(
            $config,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        if (! is_string($json)) {
            return $this->error();
        }

        $existingId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id
                FROM ' . $this->tables->bindings() . '
                WHERE artifact_id = %d
                    AND binding_hash = %s
                LIMIT 1',
                $artifactId,
                $bindingHash
            )
        );

        $now = gmdate(
            'Y-m-d H:i:s'
        );

        if ($existingId > 0) {
            $updated = $wpdb->update(
                $this->tables->bindings(),
                [
                    'element_id'
                    => $elementId,
                    'binding_type'
                    => 'navigation',
                    'binding_target'
                    => (string) $objectId,
                    'data_source'
                    => $provider,
                    'config_json'
                    => $json,
                    'enabled'
                    => 1,
                    'updated_at'
                    => $now,
                ],
                [
                    'id' => $existingId,
                ],
                [
                    '%s',
                    '%s',
                    '%s',
                    '%s',
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
            $this->tables->bindings(),
            [
                'site_id' => $siteId,
                'artifact_id' => $artifactId,
                'element_id' => $elementId,
                'binding_hash' => $bindingHash,
                'binding_type' => 'navigation',
                'binding_target'
                => (string) $objectId,
                'data_source' => $provider,
                'config_json' => $json,
                'enabled' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
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

    /** Preserve the original artifact and WordPress menu when detaching. */
    public function disable(int $artifactId, int $siteId): true|\WP_Error
    {
        global $wpdb;
        $result = $wpdb->update(
            $this->tables->bindings(),
            ['enabled' => 0, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['artifact_id' => $artifactId, 'site_id' => $siteId, 'binding_type' => 'navigation'],
            ['%d', '%s'], ['%d', '%d', '%s']
        );
        return $result === false ? $this->error() : true;
    }

    public function forArtifact(
        int $artifactId
    ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->bindings() . '
                WHERE artifact_id = %d
                    AND binding_type = %s
                    AND enabled = 1
                LIMIT 1',
                $artifactId,
                'navigation'
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        $config = json_decode(
            (string) $row['config_json'],
            true
        );

        $row['id'] = (int) $row['id'];
        $row['site_id'] = (int) $row['site_id'];
        $row['artifact_id'] = (int) $row['artifact_id'];
        $row['binding_target'] = (int) $row['binding_target'];
        $row['config'] = is_array($config)
            ? $config
            : [];

        return $row;
    }

    public function artifact(
        int $artifactId
    ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->artifacts() . '
                WHERE id = %d
                    AND kind = %s
                LIMIT 1',
                $artifactId,
                'navigation'
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        $metadata = json_decode(
            (string) $row['metadata_json'],
            true
        );

        $row['id'] = (int) $row['id'];
        $row['site_id'] = (int) $row['site_id'];
        $row['manifest_id'] = (int) $row['manifest_id'];
        $row['wp_object_id'] = (int) $row['wp_object_id'];
        $row['metadata'] = is_array($metadata)
            ? $metadata
            : [];

        return $row;
    }

    public function forSource(
        int $siteId,
        string $sourcePath
    ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->artifacts() . '
                WHERE site_id = %d
                    AND kind = %s
                    AND status = %s
                ORDER BY sort_order ASC, id ASC',
                $siteId,
                'navigation',
                'created'
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        $matches = [];

        foreach ($rows as $row) {
            $metadata = json_decode(
                (string) $row['metadata_json'],
                true
            );

            if (! is_array($metadata)) {
                continue;
            }

            foreach (
                $metadata['members'] ?? []
                as $member
            ) {
                if (
                    is_array($member)
                    && (
                        $member['source']
                        ?? ''
                    ) === $sourcePath
                ) {
                    $binding = $this
                        ->forArtifact(
                            (int) $row['id']
                        );

                    if ($binding !== null) {
                        $row['id'] = (int) $row['id'];

                        $row['metadata']
                            = $metadata;

                        $row['binding']
                            = $binding;

                        $matches[] = $row;
                    }

                    break;
                }
            }
        }

        return $matches;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_navigation_binding_persistence',
            __(
                'Condixia could not save the navigation binding.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
