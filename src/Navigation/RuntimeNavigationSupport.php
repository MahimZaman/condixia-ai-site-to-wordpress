<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Navigation;

use Condixia\AISiteToWordPress\Analysis\ReferenceResolver;
use Condixia\AISiteToWordPress\Routing\PageRouteMapRepository;
use Condixia\AISiteToWordPress\Storage\NavigationBindingRepository;

defined('ABSPATH') || exit;

/** Admin-authorized capture of browser-rendered menus; no imported file changes. */
final class RuntimeNavigationSupport
{
    private const ID = '/^cdx-stw-[a-f0-9]{24}$/D';
    private const PATH = '~^(?:body/)?(?:id:[a-zA-Z0-9_-]{1,90}|[a-z][a-z0-9-]*\[[1-9][0-9]{0,3}\])(?:/(?:id:[a-zA-Z0-9_-]{1,90}|[a-z][a-z0-9-]*\[[1-9][0-9]{0,3}\])){0,39}$~D';

    public function candidate(
        array $capture,
        array $context,
        array $fileIndex,
        int $siteId
    ): array|\WP_Error {
        $id = (string) ($capture['id'] ?? '');
        $path = (string) ($capture['path'] ?? '');
        $tag = strtolower((string) ($capture['tag'] ?? ''));
        $itemsInput = $capture['items'] ?? null;

        if (
            preg_match(self::ID, $id) !== 1
            || strlen($path) > 512
            || preg_match(self::PATH, $path) !== 1
            || ! in_array($tag, ['nav', 'ul', 'ol'], true)
            || ! is_array($itemsInput)
            || count($itemsInput) < 2
            || count($itemsInput) > 80
        ) {
            return new \WP_Error(
                'condixia_runtime_navigation_invalid',
                'Select a browser-rendered navigation with at least two menu links.'
            );
        }

        $source = (string) $context['source_path'];
        $routes = (new PageRouteMapRepository())->mapForSite($siteId);
        $total = 0;
        $items = $this->capturedItems($itemsInput, $source, $fileIndex, $routes, $total, 0);
        if (is_wp_error($items)) {
            return $items;
        }
        if (count($items) < 2 || $total > 80) {
            return new \WP_Error(
                'condixia_runtime_navigation_links',
                'The runtime menu needs at least two valid links (maximum 80).'
            );
        }

        $fingerprint = hash('sha256', 'runtime-navigation' . "\0" . $source . "\0" . $path);
        $containerPath = 'runtime:' . $id;
        $member = [
            'source' => $source,
            'container_ref' => $id,
            'container_path' => $containerPath,
            'runtime_path' => $path,
            'container_tag' => $tag,
            'mode' => 'runtime',
            'template_html' => '',
            'items' => $items,
        ];

        return [
            'fingerprint' => $fingerprint,
            'source_kind' => 'manual_runtime',
            'mode' => 'runtime',
            'representative_source' => $source,
            'representative_ref' => $id,
            'representative_path' => $containerPath,
            'container_tag' => $tag,
            'items' => $items,
            'members' => [$member],
            'document_count' => 1,
            'document_coverage' => 1.0,
            'confidence' => 1.0,
            'ambiguous' => false,
            'auto_bind' => false,
        ];
    }

    private function capturedItems(
        array $input,
        string $source,
        array $fileIndex,
        array $routes,
        int &$total,
        int $depth
    ): array|\WP_Error {
        if ($depth > 5) {
            return new \WP_Error('condixia_runtime_navigation_depth', 'The menu is too deeply nested.');
        }

        $items = [];
        $resolver = new ReferenceResolver();
        foreach ($input as $row) {
            if (! is_array($row) || ++$total > 80) {
                return new \WP_Error('condixia_runtime_navigation_limit', 'Invalid or oversized runtime menu.');
            }
            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            if ($label === '' || strlen($label) > 450 || strlen($url) > 2048) {
                return new \WP_Error('condixia_runtime_navigation_label', 'Invalid runtime menu label or link.');
            }
            if (
                preg_match('/[\x00-\x1F\x7F]/', $url) === 1
                || str_starts_with($url, '//')
                || preg_match('~^(?:javascript|data|vbscript):~i', $url) === 1
                || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1
                && preg_match('~^(?:https?|mailto|tel):~i', $url) !== 1
            ) {
                return new \WP_Error('condixia_runtime_navigation_url', 'An unsupported menu URL was rejected.');
            }
            $target = null;
            if (! str_starts_with($url, '#')) {
                $resolved = $resolver->resolve($source, $url, $fileIndex);
                if (
                    ($resolved['kind'] ?? '') === 'local'
                    && ($resolved['status'] ?? '') === 'found'
                    && preg_match('~\.html?$~i', (string) ($resolved['target'] ?? '')) === 1
                ) {
                    $target = (string) $resolved['target'];
                }
            }
            $childrenInput = $row['children'] ?? [];
            if (! is_array($childrenInput) || count($childrenInput) > 80) {
                return new \WP_Error('condixia_runtime_navigation_children', 'Invalid submenu structure.');
            }
            // Keep imported query filters and hash fragments when a static
            // page URL becomes a WordPress permalink.
            if ($target !== null && isset($routes[$target])) {
                $suffixAt = strcspn($url, '?#');
                if ($suffixAt < strlen($url)) {
                    $url = (string) $routes[$target] . substr($url, $suffixAt);
                    $target = null; // Already an absolute WordPress URL.
                }
            }
            $children = $this->capturedItems($childrenInput, $source, $fileIndex, $routes, $total, $depth + 1);
            if (is_wp_error($children)) {
                return $children;
            }
            $items[] = [
                'key' => substr(hash('sha256', $source . "\0" . $label . "\0" . $url . "\0" . $total), 0, 24),
                'position' => (string) (count($items) + 1),
                'source_label' => $label,
                'source_url' => $url,
                'target' => $target,
                'opens_in_new_tab' => ! empty($row['blank']),
                'rel' => sanitize_text_field((string) ($row['rel'] ?? '')),
                'children' => $children,
            ];
        }
        return $items;
    }

    /** Fresh WordPress menu items for each response, not a cached iframe snapshot. */
    public function forSource(int $siteId, int $manifestId, string $sourcePath): array
    {
        $repository = new NavigationBindingRepository();
        $providers = new NavigationProviderResolver();
        $output = [];
        foreach ($repository->forSource($siteId, $sourcePath) as $artifact) {
            if ((int) ($artifact['manifest_id'] ?? 0) !== $manifestId) {
                continue;
            }
            $members = $artifact['metadata']['members'] ?? [];
            foreach (is_array($members) ? $members : [] as $member) {
                if (
                    ! is_array($member)
                    || ($member['source'] ?? '') !== $sourcePath
                    || ($member['mode'] ?? '') !== 'runtime'
                    || preg_match(self::ID, (string) ($member['container_ref'] ?? '')) !== 1
                    || preg_match(self::PATH, (string) ($member['runtime_path'] ?? '')) !== 1
                ) {
                    continue;
                }
                $binding = $artifact['binding'];
                $provider = $providers->byKey((string) $binding['data_source']);
                if (! $provider || ! $provider->exists((int) $binding['binding_target'])) {
                    continue;
                }
                $items = $provider->items((int) $binding['binding_target']);
                if (is_wp_error($items) || ! is_array($items)) {
                    continue;
                }
                $count = 0;
                $output[] = [
                    'id' => (string) $member['container_ref'],
                    'path' => (string) $member['runtime_path'],
                    'items' => $this->publicItems($items, $count, 0),
                ];
                break;
            }
        }
        return array_slice($output, 0, 20);
    }

    private function publicItems(array $items, int &$count, int $depth): array
    {
        if ($depth > 5) return [];
        $safe = [];
        foreach ($items as $item) {
            if (! is_array($item) || ++$count > 80) break;
            $url = trim((string) ($item['url'] ?? ''));
            if (
                $url === '' || strlen($url) > 2048
                || preg_match('/[\x00-\x1F\x7F]/', $url)
                || str_starts_with($url, '//')
                || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1
                && preg_match('~^(?:https?|mailto|tel):~i', $url) !== 1
            ) continue;
            $safe[] = [
                'label' => sanitize_text_field((string) ($item['label'] ?? '')),
                'url' => esc_url_raw($url),
                'blank' => ! empty($item['opens_in_new_tab']),
                'rel' => sanitize_text_field((string) ($item['rel'] ?? '')),
                'children' => $this->publicItems(
                    is_array($item['children'] ?? null) ? $item['children'] : [],
                    $count,
                    $depth + 1
                ),
            ];
        }
        return $safe;
    }
}
