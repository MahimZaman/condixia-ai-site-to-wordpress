<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class SitePartCandidateRepository
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
                        $candidate['role'],
                        $candidate['title'],
                        $candidate['slug'],
                        $candidate['source_key'],
                        $candidate['representative_source'],
                        $candidate['fingerprint']
                    )
                ) {
                    return $this->error();
                }

                $metadata = wp_json_encode(
                    [
                        'fingerprint' => (string) $candidate['fingerprint'],
                        'representative_ref' => (string) (
                            $candidate['representative_ref']
                            ?? ''
                        ),
                        'representative_path' => (string) (
                            $candidate['representative_path']
                            ?? ''
                        ),
                        'variant' => (int) (
                            $candidate['variant']
                            ?? 0
                        ),
                        'occurrence_count' => (int) (
                            $candidate['occurrence_count']
                            ?? 0
                        ),
                        'document_count' => (int) (
                            $candidate['document_count']
                            ?? 0
                        ),
                        'document_coverage' => (float) (
                            $candidate['document_coverage']
                            ?? 0
                        ),
                        'confidence' => (float) (
                            $candidate['confidence']
                            ?? 0
                        ),
                        'ambiguous' => ! empty($candidate['ambiguous']),
                        'members' => is_array(
                            $candidate['members']
                                ?? null
                        )
                            ? $candidate['members']
                            : [],
                    ],
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                );

                if (! is_string($metadata)) {
                    return $this->error();
                }

                $placeholders[] = '(
                    %s,%d,%d,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s,%d
                )';

                array_push(
                    $values,
                    wp_generate_uuid4(),
                    $siteId,
                    $manifestId,
                    'site_part',
                    (string) $candidate['role'],
                    (string) $candidate['title'],
                    (string) $candidate['slug'],
                    (string) $candidate['representative_source'],
                    (string) $candidate['source_key'],
                    'detected',
                    $index,
                    $metadata,
                    $now,
                    $now,
                    0
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
                    updated_at,
                    wp_object_id
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

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_site_part_persistence',
            __(
                'Condixia could not save the detected site-part candidates.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
