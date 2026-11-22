<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class SchemaMigrator
{
    private Schema $schema;

    public function __construct(?Schema $schema = null)
    {
        $this->schema = $schema ?? new Schema();
    }

    public function migrate(): ?\WP_Error
    {
        $installedVersion = (int) get_option(
            Schema::VERSION_OPTION,
            0
        );

        if ($installedVersion > Schema::VERSION) {
            return new \WP_Error(
                'condixia_ai_stw_schema_newer',
                __(
                    'The Condixia AI Site-to-WordPress database schema is newer than this plugin version supports.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        if ($installedVersion === Schema::VERSION) {
            return null;
        }

        while ($installedVersion < Schema::VERSION) {
            $nextVersion = $installedVersion + 1;

            $result = $this->migrateToVersion($nextVersion);

            if (is_wp_error($result)) {
                return $result;
            }

            update_option(
                Schema::VERSION_OPTION,
                $nextVersion,
                false
            );

            $storedVersion = (int) get_option(
                Schema::VERSION_OPTION,
                0
            );

            if ($storedVersion !== $nextVersion) {
                return new \WP_Error(
                    'condixia_ai_stw_schema_version_write_failed',
                    __(
                        'Condixia AI Site-to-WordPress could not save its database schema version.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            $installedVersion = $nextVersion;
        }

        return null;
    }

    private function migrateToVersion(int $version): ?\WP_Error
    {
        if ($version !== 1) {
            return new \WP_Error(
                'condixia_ai_stw_unknown_schema_migration',
                __(
                    'Condixia AI Site-to-WordPress encountered an unsupported database migration.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta($this->schema->queries());

        return $this->verifyTables();
    }

    private function verifyTables(): ?\WP_Error
    {
        global $wpdb;

        foreach ($this->schema->tableNames() as $tableName) {
            $foundTable = $wpdb->get_var(
                $wpdb->prepare(
                    'SHOW TABLES LIKE %s',
                    $wpdb->esc_like($tableName)
                )
            );

            if (
                ! is_string($foundTable)
                || strcasecmp($foundTable, $tableName) !== 0
            ) {
                return new \WP_Error(
                    'condixia_ai_stw_schema_table_missing',
                    __(
                        'Condixia AI Site-to-WordPress could not create or update its required database tables.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }
        }

        return null;
    }
}
