<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class Schema
{
    public const VERSION = 1;
    public const VERSION_OPTION = 'condixia_ai_stw_schema_version';

    private TableNames $tables;

    public function __construct(?TableNames $tables = null)
    {
        $this->tables = $tables ?? new TableNames();
    }

    public function queries(): array
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $sites = $this->tables->sites();
        $manifests = $this->tables->manifests();
        $artifacts = $this->tables->artifacts();
        $assignments = $this->tables->assignments();
        $bindings = $this->tables->bindings();
        $overrides = $this->tables->overrides();
        $revisions = $this->tables->revisions();
        $files = $this->tables->files();

        return [
            "CREATE TABLE {$sites} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                uuid char(36) NOT NULL,
                name varchar(191) NOT NULL,
                status varchar(32) NOT NULL DEFAULT 'draft',
                source_type varchar(32) NOT NULL DEFAULT 'html',
                source_label varchar(191) NOT NULL DEFAULT '',
                current_manifest_id bigint(20) unsigned NOT NULL DEFAULT 0,
                home_page_id bigint(20) unsigned NOT NULL DEFAULT 0,
                created_by bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uuid (uuid),
                KEY status (status),
                KEY current_manifest_id (current_manifest_id),
                KEY created_by (created_by)
            ) {$charsetCollate};",

            "CREATE TABLE {$manifests} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                site_id bigint(20) unsigned NOT NULL,
                version bigint(20) unsigned NOT NULL,
                checksum char(64) NOT NULL,
                manifest_json longtext NOT NULL,
                warnings_json longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY site_version (site_id,version),
                KEY site_id (site_id),
                KEY checksum (checksum)
            ) {$charsetCollate};",

            "CREATE TABLE {$artifacts} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                uuid char(36) NOT NULL,
                site_id bigint(20) unsigned NOT NULL,
                manifest_id bigint(20) unsigned NOT NULL,
                kind varchar(32) NOT NULL,
                role varchar(64) NOT NULL DEFAULT '',
                title varchar(191) NOT NULL DEFAULT '',
                slug varchar(191) NOT NULL DEFAULT '',
                source_path text NOT NULL,
                source_key char(64) NOT NULL,
                wp_object_id bigint(20) unsigned NOT NULL DEFAULT 0,
                status varchar(32) NOT NULL DEFAULT 'detected',
                sort_order int(11) NOT NULL DEFAULT 0,
                metadata_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uuid (uuid),
                UNIQUE KEY site_source (site_id,kind,source_key),
                KEY site_kind (site_id,kind),
                KEY manifest_id (manifest_id),
                KEY role (role),
                KEY wp_object_id (wp_object_id)
            ) {$charsetCollate};",

            "CREATE TABLE {$assignments} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                site_id bigint(20) unsigned NOT NULL,
                artifact_id bigint(20) unsigned NOT NULL,
                context_type varchar(64) NOT NULL,
                context_key varchar(191) NOT NULL DEFAULT '',
                condition_hash char(64) NOT NULL,
                priority int(11) NOT NULL DEFAULT 0,
                conditions_json longtext NOT NULL,
                enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY artifact_condition (artifact_id,condition_hash),
                KEY site_context (site_id,context_type),
                KEY artifact_id (artifact_id),
                KEY priority (priority)
            ) {$charsetCollate};",

            "CREATE TABLE {$bindings} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                site_id bigint(20) unsigned NOT NULL,
                artifact_id bigint(20) unsigned NOT NULL,
                element_id varchar(191) NOT NULL,
                binding_hash char(64) NOT NULL,
                binding_type varchar(64) NOT NULL,
                binding_target varchar(191) NOT NULL DEFAULT '',
                data_source varchar(191) NOT NULL DEFAULT '',
                config_json longtext NOT NULL,
                enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY artifact_binding (artifact_id,binding_hash),
                KEY site_artifact (site_id,artifact_id),
                KEY binding_type (binding_type)
            ) {$charsetCollate};",

            "CREATE TABLE {$overrides} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                site_id bigint(20) unsigned NOT NULL,
                artifact_id bigint(20) unsigned NOT NULL,
                element_id varchar(191) NOT NULL,
                override_hash char(64) NOT NULL,
                value_type varchar(32) NOT NULL,
                property_key varchar(191) NOT NULL,
                value_json longtext NOT NULL,
                created_by bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY artifact_override (artifact_id,override_hash),
                KEY site_artifact (site_id,artifact_id),
                KEY created_by (created_by)
            ) {$charsetCollate};",

            "CREATE TABLE {$revisions} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                site_id bigint(20) unsigned NOT NULL,
                artifact_id bigint(20) unsigned NOT NULL DEFAULT 0,
                element_id varchar(191) NOT NULL DEFAULT '',
                revision_type varchar(32) NOT NULL,
                snapshot_json longtext NOT NULL,
                created_by bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY site_artifact (site_id,artifact_id),
                KEY artifact_created (artifact_id,created_at),
                KEY revision_type (revision_type),
                KEY created_by (created_by)
            ) {$charsetCollate};",

            "CREATE TABLE {$files} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                site_id bigint(20) unsigned NOT NULL,
                manifest_id bigint(20) unsigned NOT NULL,
                relative_path text NOT NULL,
                path_hash char(64) NOT NULL,
                file_type varchar(32) NOT NULL,
                mime_type varchar(191) NOT NULL DEFAULT '',
                size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
                checksum char(64) NOT NULL DEFAULT '',
                ownership varchar(32) NOT NULL DEFAULT 'plugin',
                attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
                status varchar(32) NOT NULL DEFAULT 'active',
                metadata_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY site_manifest_path (site_id,manifest_id,path_hash),
                KEY site_id (site_id),
                KEY manifest_id (manifest_id),
                KEY checksum (checksum),
                KEY attachment_id (attachment_id),
                KEY ownership (ownership)
            ) {$charsetCollate};",
        ];
    }

    public function tableNames(): array
    {
        return $this->tables->all();
    }
}
