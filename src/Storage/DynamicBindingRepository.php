<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class DynamicBindingRepository
{
    private const TYPES = [
        'text',
        'html',
        'taxonomy',
        'attribute',
        'url',
        'media',
    ];

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
        string $bindingType,
        string $bindingTarget,
        string $dataSource,
        array $config = [],
        bool $enabled = true
    ): true|\WP_Error {
        global $wpdb;

        $bindingType = sanitize_key(
            $bindingType
        );

        $bindingTarget = strtolower(
            trim($bindingTarget)
        );

        $dataSource = strtolower(
            trim($dataSource)
        );

        if (
            $siteId < 1
            || $artifactId < 1
            || preg_match(
                '/^cdx-stw-[a-f0-9]{24}$/',
                $elementId
            ) !== 1
            || ! in_array(
                $bindingType,
                self::TYPES,
                true
            )
            || strlen($bindingTarget) > 191
            || preg_match(
                '/^[a-z0-9._-]+$/',
                $dataSource
            ) !== 1
        ) {
            return $this->error();
        }

        $artifactExists = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->tables->artifacts() . '
                WHERE id = %d
                    AND site_id = %d',
                $artifactId,
                $siteId
            )
        );

        if ($artifactExists !== 1) {
            return $this->error();
        }

        $bindingHash = hash(
            'sha256',
            $elementId
                . "\0"
                . $bindingType
                . "\0"
                . $bindingTarget
        );

        $json = wp_json_encode(
            $config,
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
                FROM ' . $this->tables->bindings() . '
                WHERE artifact_id = %d
                    AND binding_hash = %s
                LIMIT 1',
                $artifactId,
                $bindingHash
            )
        );

        if ($existingId > 0) {
            $updated = $wpdb->update(
                $this->tables->bindings(),
                [
                    'site_id' => $siteId,
                    'element_id' => $elementId,
                    'binding_type' => $bindingType,
                    'binding_target' => $bindingTarget,
                    'data_source' => $dataSource,
                    'config_json' => $json,
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
                'binding_type' => $bindingType,
                'binding_target' => $bindingTarget,
                'data_source' => $dataSource,
                'config_json' => $json,
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

    public function replaceForArtifact(
        int $siteId,
        int $artifactId,
        array $bindings
    ): true|\WP_Error {
        global $wpdb;

        $prepared = $wpdb->prepare(
            'DELETE FROM ' . $this->tables->bindings() . '
        WHERE artifact_id = %d
            AND binding_type IN (%s, %s, %s, %s, %s, %s)',
            $artifactId,
            'text',
            'html',
            'taxonomy',
            'attribute',
            'url',
            'media'
        );

        $deleted = $wpdb->query(
            $prepared
        );

        if ($deleted === false) {
            return $this->error();
        }

        foreach ($bindings as $binding) {
            if (! is_array($binding)) {
                return $this->error();
            }

            $saved = $this->save(
                $siteId,
                $artifactId,
                (string) (
                    $binding['element_id']
                    ?? ''
                ),
                (string) (
                    $binding['binding_type']
                    ?? ''
                ),
                (string) (
                    $binding['binding_target']
                    ?? ''
                ),
                (string) (
                    $binding['data_source']
                    ?? ''
                ),
                is_array(
                    $binding['config']
                        ?? null
                )
                    ? $binding['config']
                    : []
            );

            if (is_wp_error($saved)) {
                return $saved;
            }
        }

        return true;
    }

    public function forArtifact(
        int $artifactId
    ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->bindings() . '
                WHERE artifact_id = %d
                    AND enabled = 1
                    AND binding_type IN (%s, %s, %s, %s, %s, %s)
                ORDER BY id ASC',
                $artifactId,
                'text',
                'html',
                'taxonomy',
                'attribute',
                'url',
                'media'
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $config = json_decode(
                (string) $row['config_json'],
                true
            );

            $row['id'] = (int) $row['id'];
            $row['site_id'] = (int) $row['site_id'];
            $row['artifact_id'] = (int) $row['artifact_id'];
            $row['config'] = is_array($config)
                ? $config
                : [];
        }

        unset($row);

        return $rows;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_dynamic_binding_storage',
            __(
                'Condixia could not save the dynamic binding.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
