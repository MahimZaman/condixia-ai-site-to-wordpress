<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Editor;

use Condixia\AISiteToWordPress\Analysis\NavigationDetector;
use Condixia\AISiteToWordPress\Navigation\NavigationCreationService;
use Condixia\AISiteToWordPress\Navigation\NavigationProviderResolver;
use Condixia\AISiteToWordPress\Navigation\RuntimeNavigationSupport;
use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Security\NonceActions;
use Condixia\AISiteToWordPress\Security\RequestGuard;
use Condixia\AISiteToWordPress\Storage\AssetRepository;
use Condixia\AISiteToWordPress\Storage\NavigationBindingRepository;
use Condixia\AISiteToWordPress\Storage\NavigationCandidateRepository;
use Condixia\AISiteToWordPress\Storage\SourceFileRepository;
use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

/** Server-authoritative manual binding of imported navigation markup. */
final class NavigationManualController
{
    public const ACTION = 'condixia_ai_stw_manual_navigation';
    private const ELEMENT_PATTERN = '/^cdx-stw-[a-f0-9]{24}$/';

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        $guard = new RequestGuard();
        $error = $guard->guardAjaxAction($_POST, Capabilities::EDIT, NonceActions::EDITOR_SAVE);
        if (is_wp_error($error)) {
            $this->fail($error);
        }

        // Native menu management requires WordPress' native menu capability too.
        if (! current_user_can('edit_theme_options')) {
            $this->fail(new \WP_Error('condixia_nav_forbidden', 'You cannot manage WordPress menus.', ['status' => 403]));
        }

        $siteId = absint($_POST['site_id'] ?? 0);
        $artifactId = absint($_POST['artifact_id'] ?? 0);
        $elementId = isset($_POST['element_id']) && is_scalar($_POST['element_id'])
            ? sanitize_text_field(wp_unslash((string) $_POST['element_id'])) : '';
        $operation = isset($_POST['operation']) && is_scalar($_POST['operation'])
            ? sanitize_key(wp_unslash((string) $_POST['operation'])) : '';

        if (
            $siteId < 1 || $artifactId < 1
            || ! in_array($operation, ['badges', 'inspect', 'create', 'connect', 'detach'], true)
            || ($operation !== 'badges' && preg_match(self::ELEMENT_PATTERN, $elementId) !== 1)
        ) {
            $this->fail($this->error('Invalid navigation selection.'));
        }

        $context = $this->context($siteId, $artifactId);
        if (is_wp_error($context)) {
            $this->fail($context);
        }
        if ($operation === 'badges') {
            wp_send_json_success([
                'ids' => $this->boundElementIds(
                    $siteId,
                    (int) $context['manifest_id'],
                    (string) $context['source_path']
                ),
            ]);
        }

        $source = (new SourceFileRepository())->readHtml(
            $siteId,
            (int) $context['manifest_id'],
            (string) $context['source_path']
        );
        $assets = (new AssetRepository())->contextForSite($siteId);
        if (is_wp_error($source) || is_wp_error($assets)) {
            $this->fail($this->error('The imported source is unavailable.'));
        }

        $candidate = (new NavigationDetector())->manualCandidate(
            (string) $context['source_path'],
            $source,
            $elementId,
            is_array($assets['file_index'] ?? null) ? $assets['file_index'] : []
        );
        if (is_wp_error($candidate)) {
            // Only an administrator with a valid editor nonce can submit the
            // browser-rendered menu descriptor. Never accept arbitrary HTML.
            $raw = isset($_POST['runtime_menu']) && is_scalar($_POST['runtime_menu'])
                ? wp_unslash((string) $_POST['runtime_menu']) : '';
            $capture = strlen($raw) <= 32768 ? json_decode($raw, true) : null;
            if (
                is_array($capture)
                && ($capture['id'] ?? '') === $elementId
                && ! empty($capture['runtime'])
                && in_array(
                    $candidate->get_error_code(),
                    [
                        'condixia_navigation_manual_runtime',
                        'condixia_navigation_manual_unsafe',
                        'condixia_navigation_manual_few_links'
                    ],
                    true
                )
            ) {
                $candidate = (new RuntimeNavigationSupport())->candidate(
                    $capture,
                    $context,
                    is_array($assets['file_index'] ?? null) ? $assets['file_index'] : [],
                    $siteId
                );
            }
            if (is_wp_error($candidate)) {
                $this->fail($candidate);
            }
        }

        $tables = new TableNames();
        $existing = $this->existingArtifact(
            $tables,
            $siteId,
            (int) $context['manifest_id'],
            (string) $context['source_path'],
            (string) $candidate['representative_path']
        );
        $bindings = new NavigationBindingRepository($tables);
        $providers = new NavigationProviderResolver();
        $binding = $existing ? $bindings->forArtifact((int) $existing['id']) : null;
        $memberCount = $existing ? (int) $existing['member_count'] : 1;

        if ($operation === 'inspect') {
            wp_send_json_success($this->response($candidate, $binding, $memberCount, $providers));
        }

        if ($memberCount > 1 && empty($_POST['confirm_shared'])) {
            $this->fail($this->error('This navigation is shared across imported pages. Confirm the site-wide change first.'));
        }

        if ($operation === 'detach') {
            if ($existing && $binding) {
                $result = $bindings->disable((int) $existing['id'], $siteId);
                if (is_wp_error($result)) {
                    $this->fail($result);
                }
            }
            wp_send_json_success($this->response($candidate, null, $memberCount, $providers));
        }

        $providerKey = isset($_POST['provider']) && is_scalar($_POST['provider'])
            ? sanitize_key(wp_unslash((string) $_POST['provider'])) : '';
        $provider = $operation === 'create'
            ? $providers->current()
            : $providers->byKey($providerKey);
        if ($provider === null || is_wp_error($provider) || ! $provider->available()) {
            $this->fail($this->error('The selected navigation provider is unavailable.'));
        }

        $objectId = absint($_POST['object_id'] ?? 0);
        if ($operation === 'connect' && ! $provider->exists($objectId)) {
            $this->fail($this->error('The selected WordPress menu no longer exists.'));
        }

        $navArtifactId = $existing ? (int) $existing['id'] : 0;
        if ($navArtifactId === 0) {
            // The repository stores exactly the same metadata as auto-detected navigation.
            $candidate['title'] = 'Manual Navigation — ' . basename((string) $context['source_path']);
            $candidate['slug'] = sanitize_title($candidate['title'] . '-' . substr($elementId, 8, 8));
            $candidate['source_key'] = hash('sha256', 'manual-navigation' . "\0" . $siteId . "\0"
                . $context['source_path'] . "\0" . $candidate['representative_path']);
            $inserted = (new NavigationCandidateRepository($tables))->insert(
                $siteId,
                (int) $context['manifest_id'],
                [$candidate],
                gmdate('Y-m-d H:i:s')
            );
            if (is_wp_error($inserted)) {
                $this->fail($inserted);
            }
            $navArtifactId = (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
                'SELECT id FROM ' . $tables->artifacts()
                    . ' WHERE site_id = %d AND manifest_id = %d AND kind = %s AND source_key = %s LIMIT 1',
                $siteId,
                (int) $context['manifest_id'],
                'navigation',
                $candidate['source_key']
            ));
            if ($navArtifactId < 1) {
                $this->fail($this->error('The navigation artifact was not created.'));
            }
        }

        if ($operation === 'create') {
            $items = (new NavigationCreationService())->itemsForManual($siteId, $candidate['items']);
            if (is_wp_error($items) || $items === []) {
                $this->fail($this->error('The imported navigation links could not be mapped.'));
            }
            // Unique title avoids replacing a previously created, user-edited classic menu.
            $menuName = 'Condixia Navigation ' . $navArtifactId . ' ' . wp_generate_password(6, false, false);
            $objectId = $provider->create($menuName, $items, [
                'site_id' => $siteId,
                'artifact_id' => $navArtifactId,
            ]);
            if (is_wp_error($objectId)) {
                $this->fail($objectId);
            }
        }

        $saved = $bindings->save(
            $siteId,
            $navArtifactId,
            $elementId,
            $provider->key(),
            (int) $objectId,
            [
                'provider' => $provider->key(),
                'object_id' => (int) $objectId,
                'manage_url' => $provider->manageUrl((int) $objectId),
            ]
        );
        if (is_wp_error($saved)) {
            // A newly created native object should not be orphaned by failed binding persistence.
            if ($operation === 'create') {
                if ($provider->key() === 'classic') {
                    wp_delete_nav_menu((int) $objectId);
                } else {
                    wp_delete_post((int) $objectId, true);
                }
            }
            $this->fail($saved);
        }

        $updated = $GLOBALS['wpdb']->update(
            $tables->artifacts(),
            ['status' => 'created', 'wp_object_id' => (int) $objectId, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $navArtifactId, 'site_id' => $siteId, 'manifest_id' => (int) $context['manifest_id']],
            ['%s', '%d', '%s'],
            ['%d', '%d', '%d']
        );
        if ($updated === false) {
            $this->fail($this->error('The WordPress menu was connected, but artifact status could not be updated.'));
        }

        $binding = $bindings->forArtifact($navArtifactId);
        wp_send_json_success($this->response($candidate, $binding, $memberCount, $providers));
    }

    private function context(int $siteId, int $artifactId): array|\WP_Error
    {
        global $wpdb;
        $tables = new TableNames();
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT a.manifest_id, a.source_path FROM ' . $tables->artifacts() . ' a'
                . ' INNER JOIN ' . $tables->sites() . ' s ON s.id = a.site_id AND s.current_manifest_id = a.manifest_id'
                . ' WHERE a.id = %d AND a.site_id = %d'
                . ' AND ('
                . ' (a.kind IN (%s,%s) AND a.status = %s)'
                . ' OR (a.kind = %s AND a.status = %s)'
                . ' ) AND s.status = %s LIMIT 1',
            $artifactId,
            $siteId,
            'template',
            'site_part',
            'confirmed',
            'page',
            'created',
            'pages_created'
        ), ARRAY_A);
        return is_array($row) && ! empty($row['source_path']) ? $row : $this->error('Invalid editor artifact.');
    }

    private function existingArtifact(
        TableNames $tables,
        int $siteId,
        int $manifestId,
        string $source,
        string $path
    ): ?array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, metadata_json FROM ' . $tables->artifacts()
                . ' WHERE site_id = %d AND manifest_id = %d AND kind = %s AND status IN (%s,%s)'
                . ' ORDER BY id ASC',
            $siteId,
            $manifestId,
            'navigation',
            'detected',
            'created'
        ), ARRAY_A);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $meta = json_decode((string) $row['metadata_json'], true);
            if (! is_array($meta)) continue;
            $members = is_array($meta['members'] ?? null) ? $meta['members'] : [];
            foreach ($members as $member) {
                if (
                    is_array($member) && ($member['source'] ?? '') === $source
                    && ($member['container_path'] ?? '') === $path
                ) {
                    return ['id' => (int) $row['id'], 'member_count' => count($members)];
                }
            }
        }
        return null;
    }

    /** Rebuild Navigator badges after iframe reload without parsing the HTML again. */
    private function boundElementIds(int $siteId, int $manifestId, string $source): array
    {
        global $wpdb;
        $tables = new TableNames();
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT a.metadata_json FROM ' . $tables->artifacts() . ' a'
                . ' INNER JOIN ' . $tables->bindings() . ' b ON b.artifact_id = a.id'
                . ' AND b.site_id = a.site_id AND b.enabled = 1 AND b.binding_type = %s'
                . ' WHERE a.site_id = %d AND a.manifest_id = %d AND a.kind = %s AND a.status = %s',
            'navigation',
            $siteId,
            $manifestId,
            'navigation',
            'created'
        ), ARRAY_A);
        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $meta = json_decode((string) $row['metadata_json'], true);
            foreach (is_array($meta['members'] ?? null) ? $meta['members'] : [] as $member) {
                if (! is_array($member) || ($member['source'] ?? '') !== $source) continue;
                $path = (string) ($member['container_path'] ?? '');
                if (str_starts_with($path, 'runtime:')) {
                    $id = substr($path, strlen('runtime:'));
                    if (preg_match(self::ELEMENT_PATTERN, $id) === 1) {
                        $ids[] = $id;
                    }
                } elseif ($path !== '') {
                    $ids[] = 'cdx-stw-' . substr(hash('sha256', str_replace('\\', '/', $source)
                        . "\0" . $path), 0, 24);
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function response(array $candidate, ?array $binding, int $memberCount, NavigationProviderResolver $providers): array
    {
        $total = 0;
        $nested = 0;
        $walk = static function (array $items, int $depth) use (&$walk, &$total, &$nested): void {
            foreach ($items as $item) {
                $total++;
                if ($depth > 0) $nested++;
                $walk(is_array($item['children'] ?? null) ? $item['children'] : [], $depth + 1);
            }
        };
        $walk($candidate['items'], 0);

        $menus = [];
        if ($providers->byKey('classic')?->available()) {
            $classicMenus = wp_get_nav_menus(['hide_empty' => false]);
            foreach (is_array($classicMenus) ? array_slice($classicMenus, 0, 100) : [] as $menu) {
                $menus[] = [
                    'provider' => 'classic',
                    'id' => (int) $menu->term_id,
                    'label' => (string) $menu->name
                ];
            }
        }
        if ($providers->byKey('block')?->available()) {
            $posts = get_posts([
                'post_type' => 'wp_navigation',
                'post_status' => 'publish',
                'numberposts' => 100,
                'orderby' => 'title',
                'order' => 'ASC'
            ]);
            foreach ($posts as $post) {
                $menus[] = [
                    'provider' => 'block',
                    'id' => (int) $post->ID,
                    'label' => (string) $post->post_title
                ];
            }
        }
        $manageUrl = '';
        if ($binding) {
            $provider = $providers->byKey((string) $binding['data_source']);
            if ($provider && $provider->exists((int) $binding['binding_target'])) {
                $manageUrl = $provider->manageUrl((int) $binding['binding_target']);
            }
        }
        return [
            'links' => $total,
            'nested' => $nested,
            'shared' => $memberCount,
            'bound' => (bool) $binding,
            'manage_url' => $manageUrl,
            'provider' => $binding['data_source'] ?? '',
            'object_id' => (int) ($binding['binding_target'] ?? 0),
            'menus' => $menus,
        ];
    }

    private function error(string $message): \WP_Error
    {
        return new \WP_Error('condixia_ai_stw_manual_navigation', $message, ['status' => 400]);
    }

    private function fail(\WP_Error $error): never
    {
        $data = $error->get_error_data();
        wp_send_json_error(
            ['message' => $error->get_error_message(), 'code' => $error->get_error_code()],
            is_array($data) ? (int) ($data['status'] ?? 400) : 400
        );
        exit;
    }
}
