<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

use Condixia\AISiteToWordPress\Analysis\DynamicFieldMapper;
use Condixia\AISiteToWordPress\Analysis\TemplateRoles;

defined('ABSPATH') || exit;

final class StructureConfirmationRepository
{
    private TableNames $tables;
    private DynamicBindingRepository $dynamicBindings;
    private SourceFileRepository $sourceFiles;
    private DynamicFieldMapper $fieldMapper;

    public function __construct(
        ?TableNames $tables = null,
        ?DynamicBindingRepository $dynamicBindings = null,
        ?SourceFileRepository $sourceFiles = null,
        ?DynamicFieldMapper $fieldMapper = null
    ) {
        $this->tables = $tables
            ?? new TableNames();

        $this->dynamicBindings = $dynamicBindings
            ?? new DynamicBindingRepository(
                $this->tables
            );

        $this->sourceFiles = $sourceFiles
            ?? new SourceFileRepository(
                $this->tables
            );

        $this->fieldMapper = $fieldMapper
            ?? new DynamicFieldMapper();
    }

    public function load(int $siteId): array|\WP_Error
    {
        global $wpdb;

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
            || (int) $site['current_manifest_id'] < 1
        ) {
            return $this->error(
                __(
                    'The requested Condixia site could not be found.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $manifestRow = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT manifest_json, warnings_json
                FROM ' . $this->tables->manifests() . '
                WHERE id = %d
                    AND site_id = %d
                LIMIT 1',
                (int) $site['current_manifest_id'],
                $siteId
            ),
            ARRAY_A
        );

        if (! is_array($manifestRow)) {
            return $this->error(
                __(
                    'The current site manifest could not be loaded.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $manifest = json_decode(
            (string) $manifestRow['manifest_json'],
            true
        );

        if (! is_array($manifest)) {
            return $this->error(
                __(
                    'The current site manifest is invalid.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->artifacts() . '
                WHERE site_id = %d
                    AND manifest_id = %d
                ORDER BY kind ASC, sort_order ASC, id ASC',
                $siteId,
                (int) $site['current_manifest_id']
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return $this->error(
                __(
                    'The detected import structure could not be loaded.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $pages = [];
        $templates = [];
        $siteParts = [];
        $dynamicGrids = [];

        foreach ($rows as $row) {
            $artifact = $this->normalizeArtifact(
                $row
            );

            if (
                $artifact['kind'] === 'template'
                && ! in_array(
                    $site['status'],
                    ['analyzed'],
                    true
                )
            ) {
                $artifact = $this->templateFieldMapping($artifact);
            }

            match ($artifact['kind']) {
                'page' => $pages[] = $artifact,
                'template' => $templates[] = $artifact,
                'site_part' => $siteParts[] = $artifact,
                'dynamic_grid' => $dynamicGrids[] = $artifact,
                default => null,
            };
        }

        return [
            'site' => $site,
            'manifest' => $manifest,
            'pages' => $pages,
            'templates' => $templates,
            'site_parts' => $siteParts,
            'warnings' => is_array(
                $manifest['warnings'] ?? null
            )
                ? $manifest['warnings']
                : [],
            'navigation_candidates' => $this
                ->navigationCandidates($manifest),
            'dynamic_grid_candidates' => $dynamicGrids,
            'woocommerce_active' => class_exists(
                'WooCommerce'
            ) || defined('WC_VERSION'),
            'woocommerce_required' => $this
                ->woocommerceRequired($templates),
        ];
    }

    public function confirm(
        int $siteId,
        array $normalized
    ): true|\WP_Error {
        global $wpdb;

        $structure = $this->load(
            $siteId
        );

        if (is_wp_error($structure)) {
            return $structure;
        }

        $manifestId = (int) $structure['site']['current_manifest_id'];

        $wpdb->query(
            'START TRANSACTION'
        );

        try {
            foreach (
                $normalized['pages']
                as $artifactId => $page
            ) {
                $artifact = $this->artifact(
                    $artifactId,
                    $structure['pages']
                );

                if ($artifact === null) {
                    throw new \RuntimeException();
                }

                $metadata = $artifact['metadata'];

                $metadata['confirmed'] = true;
                $metadata['confirmed_home'] = ! empty($page['home']);

                $result = $wpdb->update(
                    $this->tables->artifacts(),
                    [
                        'title' => $page['title'],
                        'slug' => $page['slug'],
                        'status' => $page['include']
                            ? 'confirmed'
                            : 'ignored',
                        'metadata_json' => $this->json(
                            $metadata
                        ),
                        'updated_at' => gmdate(
                            'Y-m-d H:i:s'
                        ),
                    ],
                    [
                        'id' => $artifactId,
                        'site_id' => $siteId,
                        'manifest_id' => $manifestId,
                    ],
                    [
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                    ],
                    [
                        '%d',
                        '%d',
                        '%d',
                    ]
                );

                if ($result === false) {
                    throw new \RuntimeException();
                }
            }

            foreach (
                $normalized['templates']
                as $artifactId => $template
            ) {
                $artifact = $this->artifact(
                    $artifactId,
                    $structure['templates']
                );

                if ($artifact === null) {
                    throw new \RuntimeException();
                }

                $metadata = $artifact['metadata'];

                $metadata['confirmed'] = true;
                $metadata['committed'] = false;
                $metadata['target'] = $template['target'];
                $metadata['requires_woocommerce']
                    = TemplateRoles::requiresWooCommerce(
                        $template['role']
                    );
                $metadata['selected_mappings']
                    = $template['mappings'];

                $label = TemplateRoles::labels()[$template['role']] ?? $artifact['title'];

                $result = $wpdb->update(
                    $this->tables->artifacts(),
                    [
                        'role' => $template['role'],
                        'title' => $label,
                        'slug' => sanitize_title(
                            'template-' . $label
                        ),
                        'status' => $template['include']
                            ? 'confirmed'
                            : 'ignored',
                        'metadata_json' => $this->json(
                            $metadata
                        ),
                        'updated_at' => gmdate(
                            'Y-m-d H:i:s'
                        ),
                    ],
                    [
                        'id' => $artifactId,
                        'site_id' => $siteId,
                        'manifest_id' => $manifestId,
                    ],
                    [
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                    ],
                    [
                        '%d',
                        '%d',
                        '%d',
                    ]
                );

                if ($result === false) {
                    throw new \RuntimeException();
                }

                $dynamicTemplateRoles = [
                    'single_post',
                    'cpt_single',
                    'post_archive',
                    'cpt_archive',
                    'taxonomy_archive',
                    'search',
                    'single_product',
                ];

                $wasDynamic = in_array(
                    (string) $artifact['role'],
                    $dynamicTemplateRoles,
                    true
                );

                $isDynamic = in_array(
                    $template['role'],
                    $dynamicTemplateRoles,
                    true
                );

                if ($wasDynamic || $isDynamic) {
                    $bindingsSaved = $this
                        ->dynamicBindings
                        ->replaceForArtifact(
                            $siteId,
                            $artifactId,
                            $template['include']
                                && $isDynamic
                                ? $this->persistentMappings(
                                    $template['mappings']
                                )
                                : []
                        );

                    if (is_wp_error($bindingsSaved)) {
                        throw new \RuntimeException();
                    }
                }
            }

            foreach (
                $normalized['site_parts']
                as $artifactId => $part
            ) {
                $artifact = $this->artifact(
                    $artifactId,
                    $structure['site_parts']
                );

                if ($artifact === null) {
                    throw new \RuntimeException();
                }

                $metadata = $artifact['metadata'];

                $metadata['confirmed'] = true;

                $result = $wpdb->update(
                    $this->tables->artifacts(),
                    [
                        'role' => $part['role'],
                        'title' => $part['title'],
                        'slug' => sanitize_title(
                            $part['title']
                        ),
                        'status' => $part['include']
                            ? 'confirmed'
                            : 'ignored',
                        'metadata_json' => $this->json(
                            $metadata
                        ),
                        'updated_at' => gmdate(
                            'Y-m-d H:i:s'
                        ),
                    ],
                    [
                        'id' => $artifactId,
                        'site_id' => $siteId,
                        'manifest_id' => $manifestId,
                    ],
                    [
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                    ],
                    [
                        '%d',
                        '%d',
                        '%d',
                    ]
                );

                if ($result === false) {
                    throw new \RuntimeException();
                }
            }

            foreach (
                $normalized['dynamic_grids']
                as $artifactId => $grid
            ) {
                $artifact = $this->artifact(
                    $artifactId,
                    $structure['dynamic_grid_candidates']
                );

                if ($artifact === null) {
                    throw new \RuntimeException();
                }

                $metadata = $artifact['metadata'];

                $metadata['confirmed'] = true;
                $metadata['committed']
                    = $grid['include'];
                $metadata['query'] = $grid['query'];
                $metadata['selected_mappings']
                    = $grid['mappings'];

                $result = $wpdb->update(
                    $this->tables->artifacts(),
                    [
                        'status' => $grid['include']
                            ? 'confirmed'
                            : 'ignored',
                        'metadata_json' => $this->json(
                            $metadata
                        ),
                        'updated_at' => gmdate(
                            'Y-m-d H:i:s'
                        ),
                    ],
                    [
                        'id' => $artifactId,
                        'site_id' => $siteId,
                        'manifest_id' => $manifestId,
                    ],
                    [
                        '%s',
                        '%s',
                        '%s',
                    ],
                    [
                        '%d',
                        '%d',
                        '%d',
                    ]
                );

                if ($result === false) {
                    throw new \RuntimeException();
                }

                $bindingsSaved = $this
                    ->dynamicBindings
                    ->replaceForArtifact(
                        $siteId,
                        $artifactId,
                        $grid['include']
                            ? $grid['mappings']
                            : []
                    );

                if (is_wp_error($bindingsSaved)) {
                    throw new \RuntimeException();
                }
            }

            $updated = $wpdb->update(
                $this->tables->sites(),
                [
                    'status' => 'confirmed',
                    'updated_at' => gmdate(
                        'Y-m-d H:i:s'
                    ),
                ],
                [
                    'id' => $siteId,
                ],
                [
                    '%s',
                    '%s',
                ],
                [
                    '%d',
                ]
            );

            if ($updated === false) {
                throw new \RuntimeException();
            }

            $wpdb->query(
                'COMMIT'
            );

            return true;
        } catch (\Throwable) {
            $wpdb->query(
                'ROLLBACK'
            );

            return $this->error(
                __(
                    'Condixia could not save the confirmed import structure.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }
    }

    private function templateFieldMapping(
        array $artifact
    ): array {
        $metadata = is_array(
            $artifact['metadata'] ?? null
        )
            ? $artifact['metadata']
            : [];

        $mappingVersion = 2;

        if (
            TemplateRoles::requiresWooCommerce(
                (string) (
                    $artifact['role']
                    ?? ''
                )
            )
            && (
                class_exists('WooCommerce')
                || defined('WC_VERSION')
            )
            && function_exists('wc_get_product')
        ) {
            $mappingVersion = 3;
        }

        if (
            isset($metadata['field_mapping'])
            && is_array(
                $metadata['field_mapping']
            )
            && (int) (
                $metadata['field_mapping_version']
                ?? 0
            ) >= $mappingVersion
        ) {
            return $artifact;
        }

        $html = $this->sourceFiles->readHtml(
            (int) $artifact['site_id'],
            (int) $artifact['manifest_id'],
            (string) $artifact['source_path']
        );

        if (is_wp_error($html)) {
            return $artifact;
        }

        $artifact['metadata']['field_mapping']
            = $this->fieldMapper->mapHtml(
                (string) $artifact['source_path'],
                $html
            );

        $artifact['metadata']['field_mapping_version'] = $mappingVersion;

        return $artifact;
    }

    private function persistentMappings(
        array $mappings
    ): array {
        return array_values(
            array_filter(
                $mappings,
                static fn(
                    mixed $mapping
                ): bool =>
                is_array($mapping)
                    && (
                        $mapping['binding_type']
                        ?? ''
                    ) !== 'functional'
            )
        );
    }


    private function normalizeArtifact(
        array $row
    ): array {
        $metadata = json_decode(
            (string) ($row['metadata_json'] ?? ''),
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

    private function artifact(
        int $id,
        array $artifacts
    ): ?array {
        foreach ($artifacts as $artifact) {
            if (
                (int) $artifact['id']
                === $id
            ) {
                return $artifact;
            }
        }

        return null;
    }

    private function navigationCandidates(
        array $manifest
    ): array {
        $candidates = $manifest['navigation_detection']['candidates'] ?? [];

        if (! is_array($candidates)) {
            return [];
        }

        return array_values(
            array_filter(
                $candidates,
                static fn(
                    mixed $candidate
                ): bool =>
                is_array($candidate)
            )
        );
    }

    private function woocommerceRequired(
        array $templates
    ): bool {
        foreach ($templates as $template) {
            if (
                ! empty($template['metadata']['requires_woocommerce'])
            ) {
                return true;
            }
        }

        return false;
    }

    private function json(array $value): string
    {
        $json = wp_json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        if (! is_string($json)) {
            throw new \RuntimeException();
        }

        return $json;
    }

    private function error(string $message): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_structure_storage',
            $message
        );
    }
}
