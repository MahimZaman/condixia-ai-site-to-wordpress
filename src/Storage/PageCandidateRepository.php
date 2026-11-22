<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class PageCandidateRepository
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
                100
            )
            as $chunk
        ) {
            $placeholders = [];
            $values = [];

            foreach ($chunk as $index => $candidate) {
                if (
                    ! isset(
                        $candidate['source'],
                        $candidate['title'],
                        $candidate['slug']
                    )
                ) {
                    return $this->error();
                }

                $source = (string) $candidate['source'];
                $title = $this->limit(
                    (string) $candidate['title'],
                    191
                );
                $slug = $this->limit(
                    (string) $candidate['slug'],
                    191
                );

                $metadata = wp_json_encode(
                    [
                        'home_candidate' => ! empty($candidate['home_candidate']),
                        'confidence' => isset(
                            $candidate['confidence']
                        )
                            ? (float) $candidate['confidence']
                            : 0.0,
                        'reason' => isset(
                            $candidate['reason']
                        )
                            ? (string) $candidate['reason']
                            : '',
                    ],
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                );

                if (! is_string($metadata)) {
                    return $this->error();
                }

                $placeholders[] = '(
                    %s,%d,%d,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s
                )';

                array_push(
                    $values,
                    wp_generate_uuid4(),
                    $siteId,
                    $manifestId,
                    'page',
                    ! empty($candidate['home_candidate'])
                        ? 'front_page_candidate'
                        : 'page',
                    $title,
                    $slug,
                    $source,
                    hash(
                        'sha256',
                        $source
                    ),
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
                || $inserted !== count($chunk)
            ) {
                return $this->error();
            }
        }

        return true;
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

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_page_candidate_persistence',
            __(
                'Condixia could not save the detected page candidates.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
