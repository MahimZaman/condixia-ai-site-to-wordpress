<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Routing;

use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class PageRouteMapRepository
{
    private TableNames $tables;

    public function __construct(
        ?TableNames $tables = null
    ) {
        $this->tables = $tables
            ?? new TableNames();
    }

    public function mapForSite(
        int $siteId
    ): array {
        $objects = $this->objectsForSite(
            $siteId
        );

        $map = [];

        foreach ($objects as $source => $object) {
            $map[$source] = $object['url'];
        }

        return $map;
    }

    public function objectsForSite(
        int $siteId
    ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT source_path, wp_object_id
            FROM ' . $this->tables->artifacts() . '
            WHERE site_id = %d
                AND kind = %s
                AND wp_object_id > 0
                AND status = %s
            ORDER BY source_path ASC',
                $siteId,
                'page',
                'created'
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        $map = [];

        foreach ($rows as $row) {
            $postId = (int) $row['wp_object_id'];

            $permalink = get_permalink(
                $postId
            );

            if (
                ! is_string($permalink)
                || $permalink === ''
            ) {
                continue;
            }

            $map[(string) $row['source_path']] = [
                'post_id' => $postId,
                'url' => $permalink,
            ];
        }

        ksort(
            $map,
            SORT_STRING
        );

        return $map;
    }
}
