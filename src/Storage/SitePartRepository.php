<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class SitePartRepository
{
    private TableNames $tables;

    public function __construct(
        ?TableNames $tables = null
    ) {
        $this->tables = $tables
            ?? new TableNames();
    }

    public function memberForSource(
        int $siteId,
        string $role,
        string $sourcePath,
        int $preferredArtifactId = 0
    ): ?array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, metadata_json
                FROM ' . $this->tables->artifacts() . '
                WHERE site_id = %d
                    AND kind = %s
                    AND role = %s
                    AND status = %s
                ORDER BY id ASC',
                $siteId,
                'site_part',
                $role,
                'confirmed'
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return null;
        }

        $matches = [];

        foreach ($rows as $row) {
            $metadata = json_decode(
                (string) $row['metadata_json'],
                true
            );

            if (! is_array($metadata)) {
                continue;
            }

            foreach (
                $metadata['members']
                    ?? []
                as $member
            ) {
                if (
                    is_array($member)
                    && (
                        $member['source']
                        ?? ''
                    ) === $sourcePath
                ) {
                    $match = [
                        'artifact_id' => (int) $row['id'],
                        'member' => $member,
                    ];

                    if (
                        $preferredArtifactId > 0
                        && (int) $row['id'] === $preferredArtifactId
                    ) {
                        return $match;
                    }

                    $matches[] = $match;

                    break;
                }
            }
        }

        return count($matches) === 1
            ? $matches[0]
            : null;
    }
}
