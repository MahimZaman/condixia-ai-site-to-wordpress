<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Templates;

use Condixia\AISiteToWordPress\Storage\AssignmentRepository;

defined('ABSPATH') || exit;

final class DefaultAssignmentSeeder
{
    private AssignmentRepository $assignments;

    public function __construct(
        ?AssignmentRepository $assignments = null
    ) {
        $this->assignments = $assignments
            ?? new AssignmentRepository();
    }

    public function ensureCompletedSites(): true|\WP_Error
    {
        foreach (
            $this->assignments
                ->completedSiteIds()
            as $siteId
        ) {
            $result = $this->ensureSite(
                $siteId
            );

            if (is_wp_error($result)) {
                return $result;
            }
        }

        return true;
    }

    public function ensureSite(
        int $siteId
    ): true|\WP_Error {
        global $wpdb;

        if (
            $siteId < 1
            || $this->assignments
            ->countForSite($siteId) > 0
        ) {
            return true;
        }

        $artifacts = $this->assignments
            ->artifactsForSite(
                $siteId
            );

        if ($artifacts === []) {
            return true;
        }

        $pagesBySource = [];
        $templatesBySource = [];
        $templates = [];
        $siteParts = [];

        foreach ($artifacts as $artifact) {
            if (
                $artifact['kind'] === 'page'
                && $artifact['status'] === 'created'
                && $artifact['wp_object_id'] > 0
            ) {
                $pagesBySource[$artifact['source_path']] = $artifact;

                continue;
            }

            if (
                $artifact['kind'] === 'template'
                && $artifact['status'] === 'confirmed'
            ) {
                $templates[] = $artifact;

                $templatesBySource[$artifact['source_path']][] = $artifact;

                continue;
            }

            if (
                $artifact['kind'] === 'site_part'
                && $artifact['status'] === 'confirmed'
            ) {
                $siteParts[] = $artifact;
            }
        }

        $wpdb->query(
            'START TRANSACTION'
        );

        foreach ($templates as $template) {
            if (! empty($template['metadata']['ambiguous'])) {
                continue;
            }

            $condition = $this
                ->templateCondition(
                    $template
                );

            if ($condition === null) {
                continue;
            }

            $saved = $this->assignments
                ->save(
                    $siteId,
                    (int) $template['id'],
                    $condition['type'],
                    $condition['key'],
                    0,
                    [
                        'origin' => 'detected_template',
                    ]
                );

            if (is_wp_error($saved)) {
                $wpdb->query('ROLLBACK');

                return $saved;
            }
        }

        foreach ($siteParts as $sitePart) {
            if (
                ! empty($sitePart['metadata']['ambiguous'])
                || (float) ($sitePart['metadata']['document_coverage'] ?? 0) < 0.5
            ) {
                continue;
            }

            $conditions = [];

            if (
                (float) (
                    $sitePart['metadata']['document_coverage']
                    ?? 0
                ) >= 0.999
            ) {
                $conditions[] = [
                    'type' => 'entire_site',
                    'key' => '',
                ];
            }

            foreach (
                $sitePart['metadata']['members']
                    ?? []
                as $member
            ) {
                if (! is_array($member)) {
                    continue;
                }

                $source = (string) (
                    $member['source']
                    ?? ''
                );

                if ($source === '') {
                    continue;
                }

                if (
                    isset(
                        $pagesBySource[$source]
                    )
                ) {
                    $conditions[] = [
                        'type' => 'object',
                        'key' => (string) $pagesBySource[$source]['wp_object_id'],
                    ];
                }

                foreach (
                    $templatesBySource[$source]
                        ?? []
                    as $template
                ) {
                    $condition = $this
                        ->templateCondition(
                            $template
                        );

                    if ($condition !== null) {
                        $conditions[] = $condition;
                    }
                }
            }

            $unique = [];

            foreach ($conditions as $condition) {
                $key = $condition['type']
                    . "\0"
                    . $condition['key'];

                $unique[$key] = $condition;
            }

            foreach ($unique as $condition) {
                $saved = $this->assignments
                    ->save(
                        $siteId,
                        (int) $sitePart['id'],
                        $condition['type'],
                        $condition['key'],
                        0,
                        [
                            'origin' => 'detected_site_part',
                        ]
                    );

                if (is_wp_error($saved)) {
                    $wpdb->query('ROLLBACK');

                    return $saved;
                }
            }
        }

        $wpdb->query(
            'COMMIT'
        );

        return true;
    }

    private function templateCondition(
        array $template
    ): ?array {
        $role = (string) (
            $template['role']
            ?? ''
        );

        $target = sanitize_key(
            (string) (
                $template['metadata']['target']
                ?? ''
            )
        );

        return match ($role) {
            'front_page' => [
                'type' => 'front_page',
                'key' => '',
            ],
            'default_page' => [
                'type' => 'all_pages',
                'key' => '',
            ],
            'single_post' => [
                'type' => 'single_post',
                'key' => '',
            ],
            'post_archive' => [
                'type' => 'post_archive',
                'key' => '',
            ],
            'cpt_single' => $target !== ''
                ? [
                    'type' => 'post_type',
                    'key' => $target,
                ]
                : null,
            'cpt_archive' => $target !== ''
                ? [
                    'type' => 'post_type_archive',
                    'key' => $target,
                ]
                : null,
            'taxonomy_archive' => $target !== ''
                ? [
                    'type' => 'taxonomy_archive',
                    'key' => $target,
                ]
                : null,
            'search' => [
                'type' => 'search',
                'key' => '',
            ],
            '404' => [
                'type' => '404',
                'key' => '',
            ],
            'single_product' => [
                'type' => 'single_product',
                'key' => '',
            ],
            'product_archive' => [
                'type' => 'shop',
                'key' => '',
            ],
            'product_taxonomy_archive' => [
                'type' => 'product_taxonomy',
                'key' => '',
            ],
            default => null,
        };
    }
}
