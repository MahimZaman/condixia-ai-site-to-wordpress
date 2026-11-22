<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

use Condixia\AISiteToWordPress\Import\AssetPublisher;
use Condixia\AISiteToWordPress\Media\ImportedMediaCleanup;
use Condixia\AISiteToWordPress\Media\ImportedMediaRegistrar;

defined('ABSPATH') || exit;

final class SiteAnalysisRepository
{
    private TableNames $tables;
    private SourceStore $sourceStore;
    private AssetPublisher $assetPublisher;
    private PageCandidateRepository $pageCandidates;
    private SitePartCandidateRepository $sitePartCandidates;
    private TemplateCandidateRepository $templateCandidates;
    private NavigationCandidateRepository $navigationCandidates;
    private DynamicGridCandidateRepository $dynamicGridCandidates;
    private ImportedMediaRegistrar $mediaRegistrar;
    private ImportedMediaCleanup $mediaCleanup;

    public function __construct(
        ?TableNames $tables = null,
        ?SourceStore $sourceStore = null,
        ?AssetPublisher $assetPublisher = null,
        ?PageCandidateRepository $pageCandidates = null,
        ?SitePartCandidateRepository $sitePartCandidates = null,
        ?TemplateCandidateRepository $templateCandidates = null,
        ?NavigationCandidateRepository $navigationCandidates = null,
        ?DynamicGridCandidateRepository $dynamicGridCandidates = null,
        ?ImportedMediaRegistrar $mediaRegistrar = null,
        ?ImportedMediaCleanup $mediaCleanup = null
    ) {
        $this->tables = $tables
            ?? new TableNames();

        $this->sourceStore = $sourceStore
            ?? new SourceStore();

        $this->assetPublisher = $assetPublisher
            ?? new AssetPublisher();

        $this->pageCandidates = $pageCandidates
            ?? new PageCandidateRepository(
                $this->tables
            );

        $this->sitePartCandidates = $sitePartCandidates
            ?? new SitePartCandidateRepository(
                $this->tables
            );

        $this->templateCandidates = $templateCandidates
            ?? new TemplateCandidateRepository(
                $this->tables
            );

        $this->navigationCandidates = $navigationCandidates
            ?? new NavigationCandidateRepository(
                $this->tables
            );

        $this->dynamicGridCandidates = $dynamicGridCandidates
            ?? new DynamicGridCandidateRepository(
                $this->tables
            );

        $this->mediaRegistrar = $mediaRegistrar
            ?? new ImportedMediaRegistrar(
                $this->tables
            );

        $this->mediaCleanup = $mediaCleanup
            ?? new ImportedMediaCleanup(
                $this->tables
            );
    }

    public function persist(
        array $analysis,
        array $prepared
    ): array|\WP_Error {
        global $wpdb;

        $siteUuid = wp_generate_uuid4();

        $storedFiles = $this->sourceStore->store(
            $siteUuid,
            $analysis['files']
        );

        if (is_wp_error($storedFiles)) {
            return $storedFiles;
        }

        $assetProjection = $this->assetPublisher->publish(
            $siteUuid,
            $analysis['files']
        );

        if (is_wp_error($assetProjection)) {
            $this->sourceStore->deleteSite(
                $siteUuid
            );

            return $assetProjection;
        }

        $now = gmdate(
            'Y-m-d H:i:s'
        );

        $siteName = $this->siteName(
            (string) $prepared['original_name']
        );

        $inserted = $wpdb->insert(
            $this->tables->sites(),
            [
                'uuid' => $siteUuid,
                'name' => $siteName,
                'status' => 'analyzed',
                'source_type' => (string) $prepared['source_type'],
                'source_label' => $this->limit(
                    (string) $prepared['original_name'],
                    191
                ),
                'current_manifest_id' => 0,
                'home_page_id' => 0,
                'created_by' => get_current_user_id(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
            ]
        );

        if ($inserted !== 1) {
            $this->sourceStore->deleteSite(
                $siteUuid
            );

            return $this->databaseError();
        }

        $siteId = (int) $wpdb->insert_id;

        $warningsJson = wp_json_encode(
            $analysis['manifest']['warnings'],
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        if (! is_string($warningsJson)) {
            $warningsJson = '[]';
        }

        $inserted = $wpdb->insert(
            $this->tables->manifests(),
            [
                'site_id' => $siteId,
                'version' => 1,
                'checksum' => (string) $analysis['checksum'],
                'manifest_json' => (string) $analysis['manifest_json'],
                'warnings_json' => $warningsJson,
                'created_at' => $now,
            ],
            [
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );

        if ($inserted !== 1) {
            $this->cleanup(
                $siteId,
                $siteUuid
            );

            return $this->databaseError();
        }

        $manifestId = (int) $wpdb->insert_id;

        $filesInserted = $this->insertFiles(
            $siteId,
            $manifestId,
            $analysis['files'],
            $storedFiles,
            $assetProjection,
            $now
        );

        if (is_wp_error($filesInserted)) {
            $this->cleanup(
                $siteId,
                $siteUuid
            );

            return $filesInserted;
        }

        $mediaRegistered = $this
            ->mediaRegistrar
            ->registerManifest(
                $siteId,
                $manifestId
            );

        if (is_wp_error($mediaRegistered)) {
            $this->cleanup(
                $siteId,
                $siteUuid
            );

            return $mediaRegistered;
        }

        $pageCandidates = $analysis['manifest']['page_detection']['candidates'] ?? [];

        $pageCandidatesInserted = $this
            ->pageCandidates
            ->insert(
                $siteId,
                $manifestId,
                is_array($pageCandidates)
                    ? $pageCandidates
                    : [],
                $now
            );

        if (is_wp_error($pageCandidatesInserted)) {
            $this->cleanup(
                $siteId,
                $siteUuid
            );

            return $pageCandidatesInserted;
        }

        $sitePartCandidates = $analysis['manifest']['site_part_detection']['candidates'] ?? [];

        $sitePartCandidatesInserted = $this
            ->sitePartCandidates
            ->insert(
                $siteId,
                $manifestId,
                is_array($sitePartCandidates)
                    ? $sitePartCandidates
                    : [],
                $now
            );

        if (is_wp_error($sitePartCandidatesInserted)) {
            $this->cleanup(
                $siteId,
                $siteUuid
            );

            return $sitePartCandidatesInserted;
        }

        $templateCandidates = $analysis['manifest']['template_detection']['candidates'] ?? [];

        $templateCandidatesInserted = $this
            ->templateCandidates
            ->insert(
                $siteId,
                $manifestId,
                is_array($templateCandidates)
                    ? $templateCandidates
                    : [],
                $now
            );

        if (is_wp_error($templateCandidatesInserted)) {
            $this->cleanup(
                $siteId,
                $siteUuid
            );

            return $templateCandidatesInserted;
        }

        $navigationCandidates = $analysis['manifest']['navigation_detection']['candidates']
            ?? [];

        $navigationCandidatesInserted = $this
            ->navigationCandidates
            ->insert(
                $siteId,
                $manifestId,
                is_array($navigationCandidates)
                    ? $navigationCandidates
                    : [],
                $now
            );

        if (is_wp_error($navigationCandidatesInserted)) {
            $this->cleanup(
                $siteId,
                $siteUuid
            );

            return $navigationCandidatesInserted;
        }

        $dynamicGridCandidates = $analysis['manifest']['repeated_grid_detection']['candidates']
            ?? [];

        $dynamicGridCandidatesInserted = $this
            ->dynamicGridCandidates
            ->insert(
                $siteId,
                $manifestId,
                is_array($dynamicGridCandidates)
                    ? $dynamicGridCandidates
                    : [],
                $now
            );

        if (is_wp_error($dynamicGridCandidatesInserted)) {
            $this->cleanup(
                $siteId,
                $siteUuid
            );

            return $dynamicGridCandidatesInserted;
        }

        $updated = $wpdb->update(
            $this->tables->sites(),
            [
                'current_manifest_id' => $manifestId,
                'updated_at' => $now,
            ],
            [
                'id' => $siteId,
            ],
            [
                '%d',
                '%s',
            ],
            [
                '%d',
            ]
        );

        if ($updated === false) {
            $this->cleanup(
                $siteId,
                $siteUuid
            );

            return $this->databaseError();
        }

        return [
            'site_id' => $siteId,
            'site_uuid' => $siteUuid,
            'manifest_id' => $manifestId,
            'page_count' => is_array($pageCandidates)
                ? count($pageCandidates)
                : 0,
            'site_part_count' => is_array($sitePartCandidates)
                ? count($sitePartCandidates)
                : 0,
            'template_count' => is_array($templateCandidates)
                ? count($templateCandidates)
                : 0,
            'navigation_count' => is_array($navigationCandidates)
                ? count($navigationCandidates)
                : 0,
            'dynamic_grid_count' => is_array($dynamicGridCandidates)
                ? count($dynamicGridCandidates)
                : 0,
        ];
    }

    private function insertFiles(
        int $siteId,
        int $manifestId,
        array $files,
        array $storedFiles,
        array $assetProjection,
        string $now
    ): true|\WP_Error {
        global $wpdb;

        foreach (
            array_chunk(
                $files,
                200
            )
            as $chunk
        ) {
            $placeholders = [];
            $values = [];

            foreach ($chunk as $file) {
                $path = (string) $file['path'];

                if (! isset($storedFiles[$path])) {
                    return $this->databaseError();
                }

                $metadataData = [
                    'storage_key' => $storedFiles[$path],
                ];

                if (isset($assetProjection[$path])) {
                    $metadataData['public_key'] = $assetProjection[$path]['public_key'];

                    $metadataData['public_url'] = $assetProjection[$path]['public_url'];
                }

                $metadata = wp_json_encode(
                    $metadataData,
                    JSON_UNESCAPED_SLASHES
                );

                if (! is_string($metadata)) {
                    return $this->databaseError();
                }

                $placeholders[] = '(
                    %d,%d,%s,%s,%s,%s,%d,%s,%s,%d,%s,%s,%s,%s
                )';

                array_push(
                    $values,
                    $siteId,
                    $manifestId,
                    $path,
                    hash(
                        'sha256',
                        $path
                    ),
                    $file['type'],
                    $file['mime_type'],
                    $file['size'],
                    $file['checksum'],
                    'plugin',
                    0,
                    'active',
                    $metadata,
                    $now,
                    $now
                );
            }

            $sql = 'INSERT INTO '
                . $this->tables->files()
                . ' (
                    site_id,
                    manifest_id,
                    relative_path,
                    path_hash,
                    file_type,
                    mime_type,
                    size_bytes,
                    checksum,
                    ownership,
                    attachment_id,
                    status,
                    metadata_json,
                    created_at,
                    updated_at
                ) VALUES '
                . implode(
                    ',',
                    $placeholders
                );

            $preparedQuery = $wpdb->prepare(
                $sql,
                ...$values
            );

            $result = $wpdb->query(
                $preparedQuery
            );

            if (
                $result === false
                || $result !== count($chunk)
            ) {
                return $this->databaseError();
            }
        }

        return true;
    }

    private function cleanup(
        int $siteId,
        string $siteUuid
    ): void {
        global $wpdb;

        $this->mediaCleanup->cleanupSite(
            $siteId
        );

        $wpdb->delete(
            $this->tables->files(),
            [
                'site_id' => $siteId,
            ],
            [
                '%d',
            ]
        );

        $wpdb->delete(
            $this->tables->artifacts(),
            [
                'site_id' => $siteId,
            ],
            [
                '%d',
            ]
        );

        $wpdb->delete(
            $this->tables->manifests(),
            [
                'site_id' => $siteId,
            ],
            [
                '%d',
            ]
        );

        $wpdb->delete(
            $this->tables->sites(),
            [
                'id' => $siteId,
            ],
            [
                '%d',
            ]
        );

        $this->sourceStore->deleteSite(
            $siteUuid
        );
    }

    private function siteName(
        string $originalName
    ): string {
        $name = pathinfo(
            $originalName,
            PATHINFO_FILENAME
        );

        $name = sanitize_text_field(
            $name
        );

        if ($name === '') {
            return __(
                'Imported Site',
                'condixia-ai-site-to-wordpress'
            );
        }

        return $this->limit(
            $name,
            191
        );
    }

    private function limit(
        string $value,
        int $length
    ): string {
        if (function_exists('mb_substr')) {
            return mb_substr(
                $value,
                0,
                $length,
                'UTF-8'
            );
        }

        return substr(
            $value,
            0,
            $length
        );
    }

    private function databaseError(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_analysis_persistence',
            __(
                'Condixia could not save the analyzed site manifest.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
