<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Navigation;

use Condixia\AISiteToWordPress\Routing\PageRouteMapRepository;
use Condixia\AISiteToWordPress\Storage\NavigationBindingRepository;
use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class NavigationCreationService
{
    private TableNames $tables;
    private PageRouteMapRepository $routes;
    private NavigationProviderResolver $providers;
    private NavigationBindingRepository $bindings;

    public function __construct(
        ?TableNames $tables = null,
        ?PageRouteMapRepository $routes = null,
        ?NavigationProviderResolver $providers = null,
        ?NavigationBindingRepository $bindings = null
    ) {
        $this->tables = $tables
            ?? new TableNames();

        $this->routes = $routes
            ?? new PageRouteMapRepository(
                $this->tables
            );

        $this->providers = $providers
            ?? new NavigationProviderResolver();

        $this->bindings = $bindings
            ?? new NavigationBindingRepository(
                $this->tables
            );
    }

    public function createForSite(
        int $siteId
    ): array|\WP_Error {
        global $wpdb;

        $site = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, name, current_manifest_id
                FROM ' . $this->tables->sites() . '
                WHERE id = %d
                LIMIT 1',
                $siteId
            ),
            ARRAY_A
        );

        if (! is_array($site)) {
            return $this->error();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT *
                FROM ' . $this->tables->artifacts() . '
                WHERE site_id = %d
                    AND manifest_id = %d
                    AND kind = %s
                    AND status IN (%s, %s)
                ORDER BY sort_order ASC, id ASC',
                $siteId,
                (int) $site['current_manifest_id'],
                'navigation',
                'detected',
                'created'
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return $this->error();
        }

        if ($rows === []) {
            return [
                'provider' => '',
                'created' => 0,
                'existing' => 0,
                'ambiguous_skipped' => 0,
            ];
        }

        $provider = $this->providers->current();

        if (is_wp_error($provider)) {
            return $provider;
        }

        $pageObjects = $this->routes
            ->objectsForSite(
                $siteId
            );

        $created = 0;
        $existing = 0;
        $ambiguousSkipped = 0;

        foreach ($rows as $row) {
            $artifactId = (int) $row['id'];

            $metadata = json_decode(
                (string) $row['metadata_json'],
                true
            );

            if (! is_array($metadata)) {
                continue;
            }

            if (empty($metadata['auto_bind'])) {
                $ambiguousSkipped++;

                continue;
            }

            $binding = $this->bindings
                ->forArtifact(
                    $artifactId
                );

            if ($binding !== null) {
                $boundProvider = $this
                    ->providers
                    ->byKey(
                        (string) $binding['data_source']
                    );

                if (
                    $boundProvider !== null
                    && $boundProvider->exists(
                        (int) $binding['binding_target']
                    )
                ) {
                    $existing++;

                    continue;
                }
            }

            $items = $this->resolveItems(
                is_array(
                    $metadata['items']
                        ?? null
                )
                    ? $metadata['items']
                    : [],
                $pageObjects
            );

            if ($items === []) {
                continue;
            }

            $name = sanitize_text_field(
                (string) $site['name']
                    . ' — '
                    . (string) $row['title']
            );

            $objectId = $provider->create(
                $name,
                $items,
                [
                    'site_id' => $siteId,
                    'artifact_id'
                    => $artifactId,
                ]
            );

            if (is_wp_error($objectId)) {
                return $objectId;
            }

            $objectId = (int) $objectId;

            $saved = $this->bindings->save(
                $siteId,
                $artifactId,
                (string) (
                    $metadata['representative_ref'] ?? ''
                ),
                $provider->key(),
                $objectId,
                [
                    'provider'
                    => $provider->key(),
                    'object_id'
                    => $objectId,
                    'manage_url'
                    => $provider->manageUrl(
                        $objectId
                    ),
                ]
            );

            if (is_wp_error($saved)) {
                return $saved;
            }

            $metadata['native_navigation'] = [
                'provider'
                => $provider->key(),
                'object_id'
                => $objectId,
                'manage_url'
                => $provider->manageUrl(
                    $objectId
                ),
            ];

            $metadataJson = wp_json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );

            if (! is_string($metadataJson)) {
                return $this->error();
            }

            $updated = $wpdb->update(
                $this->tables->artifacts(),
                [
                    'wp_object_id'
                    => $objectId,
                    'status'
                    => 'created',
                    'metadata_json'
                    => $metadataJson,
                    'updated_at'
                    => gmdate(
                        'Y-m-d H:i:s'
                    ),
                ],
                [
                    'id' => $artifactId,
                    'site_id' => $siteId,
                ],
                [
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                ],
                [
                    '%d',
                    '%d',
                ]
            );

            if ($updated === false) {
                return $this->error();
            }

            $created++;
        }

        return [
            'provider' => $provider->key(),
            'created' => $created,
            'existing' => $existing,
            'ambiguous_skipped'
            => $ambiguousSkipped,
        ];
    }

    /** Resolve imported source URLs to created WordPress pages. */
    public function itemsForManual(int $siteId, array $items): array|\WP_Error
    {
        $pageObjects = $this->routes->objectsForSite($siteId);
        if (is_wp_error($pageObjects)) return $pageObjects;
        return $this->resolveItems($items, $pageObjects);
    }

    private function resolveItems(
        array $items,
        array $pageObjects
    ): array {
        $resolved = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = sanitize_text_field(
                (string) (
                    $item['source_label']
                    ?? ''
                )
            );

            if ($label === '') {
                continue;
            }

            $target = isset(
                $item['target']
            ) && is_string(
                $item['target']
            )
                ? $item['target']
                : null;

            $url = (string) (
                $item['source_url']
                ?? ''
            );

            $postId = 0;

            if (
                $target !== null
                && isset(
                    $pageObjects[$target]
                )
            ) {
                $postId = (int) $pageObjects[$target]['post_id'];

                $url = (string) $pageObjects[$target]['url'];
            } elseif (
                ! str_starts_with(
                    $url,
                    '#'
                )
            ) {
                $url = esc_url_raw(
                    $url
                );
            }

            if ($url === '') {
                continue;
            }

            $children = $this->resolveItems(
                is_array(
                    $item['children']
                        ?? null
                )
                    ? $item['children']
                    : [],
                $pageObjects
            );

            $resolved[] = [
                'key' => (string) (
                    $item['key']
                    ?? ''
                ),
                'label' => $label,
                'url' => $url,
                'post_id' => $postId,
                'opens_in_new_tab'
                => ! empty($item['opens_in_new_tab']),
                'rel' => sanitize_text_field(
                    (string) (
                        $item['rel']
                        ?? ''
                    )
                ),
                'children' => $children,
            ];
        }

        return $resolved;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_navigation_creation',
            __(
                'Condixia could not create the WordPress navigation data.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
