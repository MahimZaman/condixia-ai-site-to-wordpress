<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class DynamicGridCandidateRepository
{
    private TableNames $tables;

    public function __construct(
        ?TableNames $tables = null
    ) {
        $this->tables = $tables
            ?? new TableNames();
    }

    public function insert(
        int $siteId,
        int $manifestId,
        array $candidates,
        string $now
    ): true|\WP_Error {
        global $wpdb;

        if ($candidates === []) {
            return true;
        }

        foreach (
            array_chunk(
                $candidates,
                50
            )
            as $chunk
        ) {
            $placeholders = [];
            $values = [];

            foreach (
                $chunk
                as $index => $candidate
            ) {
                if (
                    ! isset(
                        $candidate['title'],
                        $candidate['slug'],
                        $candidate['source'],
                        $candidate['source_key']
                    )
                ) {
                    return $this->error();
                }

                $metadata = wp_json_encode(
                    $candidate,
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                );

                if (! is_string($metadata)) {
                    return $this->error();
                }

                $placeholders[] = '(
                    %s,%d,%d,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s
                )';

                array_push(
                    $values,
                    wp_generate_uuid4(),
                    $siteId,
                    $manifestId,
                    'dynamic_grid',
                    'repeated_region',
                    (string) $candidate['title'],
                    (string) $candidate['slug'],
                    (string) $candidate['source'],
                    (string) $candidate['source_key'],
                    'detected',
                    $index,
                    $metadata,
                    $now,
                    $now
                );
            }

            $sql = 'INSERT INTO '
                . $this->tables->artifacts()
                . ' (
                    uuid,
                    site_id,
                    manifest_id,
                    kind,
                    role,
                    title,
                    slug,
                    source_path,
                    source_key,
                    status,
                    sort_order,
                    metadata_json,
                    created_at,
                    updated_at
                ) VALUES '
                . implode(
                    ',',
                    $placeholders
                );

            $prepared = $wpdb->prepare(
                $sql,
                ...$values
            );

            $inserted = $wpdb->query(
                $prepared
            );

            if (
                $inserted === false
                || $inserted
                !== count($chunk)
            ) {
                return $this->error();
            }
        }

        return true;
    }

    public function confirmedForSource(
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
                AND source_path = %s
            ORDER BY sort_order ASC, id ASC',
                $siteId,
                'dynamic_grid',
                'confirmed',
                $sourcePath
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            $metadata = json_decode(
                (string) (
                    $row['metadata_json']
                    ?? ''
                ),
                true
            );

            if (! is_array($metadata)) {
                continue;
            }

            $row['id'] = (int) $row['id'];
            $row['site_id'] = (int) $row['site_id'];
            $row['manifest_id'] = (int) $row['manifest_id'];
            $row['metadata'] = $metadata;

            $result[] = $row;
        }

        return $result;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_dynamic_grid_persistence',
            __(
                'Condixia could not save the repeated-grid candidates.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
