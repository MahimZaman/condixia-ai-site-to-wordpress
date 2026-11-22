<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Templates;

use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class TemplateResolver
{
    private TableNames $tables;
    private DisplayConditionResolver $conditions;

    public function __construct(
        ?TableNames $tables = null,
        ?DisplayConditionResolver $conditions = null
    ) {
        $this->tables = $tables
            ?? new TableNames();

        $this->conditions = $conditions
            ?? new DisplayConditionResolver();
    }

    public function resolve(): array|\WP_Error|null
    {
        $context = DisplayConditionContext::current();

        $queriedId = get_queried_object_id();

        $page = (
            $queriedId > 0
            && is_page()
        )
            ? $this->createdPage(
                $queriedId
            )
            : null;

        if (is_wp_error($page)) {
            return $page;
        }

        $preferredSiteId = is_array($page)
            ? (int) $page['site_id']
            : 0;

        $template = $this->conditions
            ->resolveTemplate(
                $context,
                $preferredSiteId
            );

        if (is_wp_error($template)) {
            return $template;
        }

        if ($page !== null) {
            if (
                $template !== null
                && (
                    $template['assignment']['context_type']
                    ?? ''
                ) === 'object'
            ) {
                return $template;
            }

            return $page;
        }

        return $template;
    }

    private function createdPage(
        int $postId
    ): array|\WP_Error|null {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT
                    a.*,
                    s.status AS site_status
                FROM ' . $this->tables->artifacts() . ' a
                INNER JOIN ' . $this->tables->sites() . ' s
                    ON s.id = a.site_id
                WHERE a.kind = %s
                    AND a.status = %s
                    AND a.wp_object_id = %d
                    AND s.status = %s
                LIMIT 1',
                'page',
                'created',
                $postId,
                'pages_created'
            ),
            ARRAY_A
        );

        if ($row === null) {
            return null;
        }

        if (! is_array($row)) {
            return $this->error();
        }

        return $this->normalize(
            $row,
            'page'
        );
    }

    private function normalize(
        array $row,
        string $resolution
    ): array {
        $metadata = isset(
            $row['metadata']
        ) && is_array(
            $row['metadata']
        )
            ? $row['metadata']
            : json_decode(
                (string) (
                    $row['metadata_json']
                    ?? ''
                ),
                true
            );

        return [
            'artifact_id' => (int) $row['id'],
            'site_id' => (int) $row['site_id'],
            'manifest_id' => (int) $row['manifest_id'],
            'source_path' => (string) $row['source_path'],
            'kind' => (string) $row['kind'],
            'role' => (string) $row['role'],
            'resolution' => $resolution,
            'metadata' => is_array($metadata)
                ? $metadata
                : [],
        ];
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_template_resolution',
            __(
                'Condixia could not resolve the current frontend template.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
