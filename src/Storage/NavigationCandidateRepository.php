<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class NavigationCandidateRepository
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
                        $candidate['source_key'],
                        $candidate['representative_source']
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
                    'navigation',
                    'navigation',
                    (string) $candidate['title'],
                    (string) $candidate['slug'],
                    (string) $candidate['representative_source'],
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

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_navigation_candidate_persistence',
            __(
                'Condixia could not save the detected navigation candidates.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
