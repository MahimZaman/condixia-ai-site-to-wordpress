<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Editor;

use Condixia\AISiteToWordPress\Analysis\DynamicFieldMapper;
use Condixia\AISiteToWordPress\Bindings\GridPublicFieldCatalog;
use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Security\NonceActions;
use Condixia\AISiteToWordPress\Security\RequestGuard;
use Condixia\AISiteToWordPress\Storage\DynamicBindingRepository;
use Condixia\AISiteToWordPress\Storage\DynamicGridCandidateRepository;
use Condixia\AISiteToWordPress\Storage\SourceFileRepository;
use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

/** Manual grid conversion; all DOM references are derived from saved import source. */
final class DynamicGridManualController
{
    public const ACTION = 'condixia_ai_stw_manual_dynamic_grid';
    private const ELEMENT_ID = '/^cdx-stw-[a-f0-9]{24}$/';
    private const FIELDS = [
        'title' => ['post.title', 'text', ''],
        'excerpt' => ['post.excerpt', 'text', ''],
        'image' => ['post.featured_image', 'media', 'src'],
        'link' => ['post.permalink', 'url', 'href'],
        'date' => ['post.date', 'text', ''],
    ];
    private const EXTRA_NATIVE = [
        'post.title' => [
            'post.title',
            'text',
            '',
            'text',
            'Title',
        ],
        'post.excerpt' => [
            'post.excerpt',
            'text',
            '',
            'text',
            'Excerpt',
        ],
        'post.date' => [
            'post.date',
            'text',
            '',
            'text',
            'Date',
        ],
        'post.author' => [
            'post.author',
            'text',
            '',
            'text',
            'Author',
        ],
        'post.content' => [
            'post.content',
            'html',
            '',
            'html',
            'Post content (HTML)',
        ],
        'post.featured_image' => [
            'post.featured_image',
            'media',
            'src',
            'media',
            'Featured image',
        ],
        'post.permalink' => [
            'post.permalink',
            'url',
            'href',
            'url',
            'Permalink',
        ],
    ];
    private const ORDER_BY = ['date', 'modified', 'title', 'menu_order', 'id', 'rand', 'comment_count'];

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        $guard = (new RequestGuard())->guardAjaxAction(
            $_POST,
            Capabilities::EDIT,
            NonceActions::EDITOR_SAVE
        );
        if (is_wp_error($guard)) {
            $this->fail($guard);
        }

        $siteId = absint($_POST['site_id'] ?? 0);
        $artifactId = absint($_POST['artifact_id'] ?? 0);
        $operation = sanitize_key($this->posted('operation'));
        $elementId = $this->posted('element_id');
        if (
            $siteId < 1 || $artifactId < 1
            || ! in_array($operation, ['badges', 'inspect', 'save', 'revert', 'catalog'], true)
            || ($operation !== 'badges' && preg_match(self::ELEMENT_ID, $elementId) !== 1)
        ) {
            $this->fail($this->error('Invalid dynamic grid request.'));
        }

        $tables = new TableNames();
        $context = $this->context($tables, $siteId, $artifactId);
        if (is_wp_error($context)) {
            $this->fail($context);
        }
        $sourcePath = (string) $context['source_path'];
        $manifestId = (int) $context['manifest_id'];
        $existing = $this->existing($tables, $siteId, $manifestId, $sourcePath);

        if ($operation === 'catalog') {
            $type = sanitize_key($this->posted('post_type'));

            if (! isset($this->postTypes()[$type])) {
                $this->fail(
                    $this->error('This public post type is not available.')
                );
            }

            wp_send_json_success([
                'post_type' => $type,
                'field_catalog' => $this->catalogForType($type),
            ]);
        }

        if ($operation === 'badges') {
            wp_send_json_success(['items' => array_values(array_map(
                static fn(array $row): array => [
                    'id' => (string) ($row['metadata']['container_ref'] ?? ''),
                    'enabled' => $row['status'] === 'confirmed',
                ],
                array_filter($existing, static fn(array $row): bool =>
                $row['status'] === 'confirmed')
            ))]);
        }

        $html = (new SourceFileRepository())->readHtml($siteId, $manifestId, $sourcePath);
        if (is_wp_error($html)) {
            $this->fail($html);
        }
        $elements = $this->sourceElements((string) $html, $sourcePath);
        if (is_wp_error($elements)) {
            $this->fail($elements);
        }
        $container = $elements[$elementId] ?? null;
        if (! is_array($container) || ! in_array(
            $container['tag'],
            ['div', 'section', 'ul', 'ol', 'main', 'article', 'tbody'],
            true
        )) {
            $this->fail($this->error('Select the imported container holding the repeated cards.'));
        }
        $candidate = $this->candidate($elements, $elementId, $sourcePath);
        if (is_wp_error($candidate)) {
            $this->fail($candidate);
        }
        $matching = array_values(array_filter(
            $existing,
            static fn(array $row): bool => ($row['metadata']['container_path'] ?? '') === $candidate['container_path']
        ));
        // Do not overwrite an active ancestor/descendant grid's DOM coordinates.
        foreach ($existing as $other) {
            if ($other['status'] !== 'confirmed') continue;
            $otherPath = (string) ($other['metadata']['container_path'] ?? '');
            $path = $candidate['container_path'];
            if (
                $otherPath !== '' && $otherPath !== $path
                && (str_starts_with($path, $otherPath . '/')
                    || str_starts_with($otherPath, $path . '/'))
            ) {
                $this->fail($this->error('An existing dynamic grid overlaps this region.'));
            }
        }
        if (count($matching) > 1) {
            $this->fail($this->error('Multiple grid artifacts refer to this source region.'));
        }
        $row = $matching[0] ?? null;

        $sampleId = $this->posted('sample_id');
        $validSamples = array_column($candidate['items'], 'path', 'ref');
        if ($sampleId !== '' && ! isset($validSamples[$sampleId])) {
            $this->fail($this->error('The chosen card is not part of the imported grid.'));
        }
        if ($sampleId === '' && $row !== null) {
            $storedSample = (string) ($row['metadata']['sample_item_ref'] ?? '');
            if (isset($validSamples[$storedSample])) {
                $sampleId = $storedSample;
            }
        }
        if ($sampleId === '') {
            $sampleId = (string) $candidate['items'][0]['ref'];
        }
        $candidate['sample_item_ref'] = $sampleId;
        $candidate['sample_item_path'] = $validSamples[$sampleId];
        $mapData = (new DynamicFieldMapper())->mapHtml($sourcePath, (string) $html);
        $definitions = $this->fieldDefinitions($mapData);
        $fields = $this->fieldOptions(
            $elements,
            $sampleId,
            $mapData,
            $definitions
        );

        $fieldTargets = $this->fieldTargets($elements, $sampleId);
        $postTypes = $this->postTypes();

        if ($operation === 'inspect') {
            wp_send_json_success($this->result($candidate, $row, $fields, $postTypes, $definitions, $fieldTargets));
        }

        if ($operation === 'revert') {
            if ($row === null || $row['status'] !== 'confirmed') {
                $this->fail($this->error('This region is not an active dynamic grid.'));
            }
            global $wpdb;
            $wpdb->query('START TRANSACTION');
            $disabled = $wpdb->update(
                $tables->artifacts(),
                [
                    'status' => 'ignored',
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['id' => $row['id'], 'site_id' => $siteId, 'manifest_id' => $manifestId],
                ['%s', '%s'],
                ['%d', '%d', '%d']
            );
            $cleared = (new DynamicBindingRepository($tables))->replaceForArtifact(
                $siteId,
                (int) $row['id'],
                []
            );
            if ($disabled === false || is_wp_error($cleared)) {
                $wpdb->query('ROLLBACK');
                $this->fail($this->error('The static grid could not be restored.'));
            }
            $wpdb->query('COMMIT');
            $row['status'] = 'ignored';
            wp_send_json_success($this->result($candidate, $row, $fields, $postTypes, $definitions, $fieldTargets));
        }

        $postType = sanitize_key($this->posted('post_type'));
        if (! isset($postTypes[$postType])) {
            $this->fail($this->error('Select an available public post type.'));
        }
        $count = absint($_POST['count'] ?? 0);
        $orderBy = sanitize_key($this->posted('orderby'));
        $order = strtoupper($this->posted('order'));
        $taxonomy = sanitize_key($this->posted('taxonomy'));
        $termsInput = $this->posted('terms');
        $terms = preg_split('/[\s,]+/', $termsInput, -1, PREG_SPLIT_NO_EMPTY);
        $terms = is_array($terms) ? array_values(array_unique(array_map('sanitize_title', $terms))) : [];
        if (
            $count < 1 || $count > 100 || ! in_array($orderBy, self::ORDER_BY, true)
            || ! in_array($order, ['ASC', 'DESC'], true) || count($terms) > 30
            || strlen($termsInput) > 500
            || ($postType === 'product' && ! in_array($orderBy, ['date', 'modified', 'title', 'id', 'rand'], true))
        ) {
            $this->fail($this->error('Invalid item count, sorting or taxonomy filter.'));
        }
        if ($taxonomy !== '') {
            $tax = get_taxonomy($taxonomy);
            if (
                ! $tax instanceof \WP_Taxonomy || ! $tax->public
                || ! is_object_in_taxonomy($postType, $taxonomy)
                || ($postType === 'product' && ! in_array($taxonomy, ['product_cat', 'product_tag'], true))
                || $terms === []
            ) {
                $this->fail($this->error('Choose a public taxonomy and at least one term slug.'));
            }
            foreach ($terms as $term) {
                if ($term === '' || get_term_by('slug', $term, $taxonomy) === false) {
                    $this->fail($this->error('A selected taxonomy term does not exist.'));
                }
            }
        } elseif ($terms !== []) {
            $this->fail($this->error('Select a taxonomy before entering term slugs.'));
        }

        $selected = [];
        $used = [];
        foreach ($definitions as $key => [$source, $type, $target]) {
            $ref = $this->posted('map_' . $key);
            if ($key === 'price' && $postType !== 'product' && $ref !== '') {
                $this->fail($this->error('WooCommerce price can only be mapped for Products.'));
            }
            if ($ref === '') {
                continue;
            }
            $allowed = array_column($fields[$key], 'id');
            $channel = in_array($type, ['text', 'html'], true)
                ? 'content'
                : $type;

            $unique = $ref . "\0" . $channel;

            if (
                ! in_array($ref, $allowed, true)
                || isset($used[$unique])
            ) {
                $this->fail(
                    $this->error('A field mapping is not valid for this card.')
                );
            }

            $used[$unique] = true;
            $selected[] = [
                'element_id' => $ref,
                'binding_type' => $type,
                'binding_target' => $target,
                'data_source' => $source,
                'config' => [],
            ];
        }
        $extraInput = $this->posted('extra_mappings');

        if (strlen($extraInput) > 12000) {
            $this->fail(
                $this->error('Too many field mappings were submitted.')
            );
        }

        $extraMappings = $extraInput === ''
            ? []
            : json_decode($extraInput, true);

        if (
            ! is_array($extraMappings)
            || ! array_is_list($extraMappings)
            || count($extraMappings) > 25
        ) {
            $this->fail(
                $this->error('Invalid extra field mappings.')
            );
        }

        $catalog = $this->catalogForType($postType);

        foreach ($extraMappings as $mapping) {
            if (
                ! is_array($mapping)
                || ! is_string($mapping['field_id'] ?? null)
                || ! is_string($mapping['element_id'] ?? null)
            ) {
                $this->fail(
                    $this->error('Invalid extra field mapping.')
                );
            }

            $field = $catalog[$mapping['field_id']] ?? null;
            $targetRef = $mapping['element_id'];

            if (
                ! is_array($field)
                || ! in_array(
                    $targetRef,
                    array_column(
                        $fieldTargets[$field['kind']] ?? [],
                        'id'
                    ),
                    true
                )
            ) {
                $this->fail(
                    $this->error(
                        'The selected field cannot bind to that card element.'
                    )
                );
            }

            $channel = in_array(
                $field['kind'],
                ['text', 'html'],
                true
            ) ? 'content' : $field['kind'];

            $unique = $targetRef . "\0" . $channel;

            if (isset($used[$unique])) {
                $this->fail(
                    $this->error(
                        'Two fields cannot overwrite the same element target.'
                    )
                );
            }

            $used[$unique] = true;

            $selected[] = [
                'element_id' => $targetRef,
                'binding_type' => $field['binding_type'],
                'binding_target' => $field['binding_target'],
                'data_source' => $field['source'],
                'config' => array_merge(
                    $field['config'],
                    ['grid_mapping_id' => $mapping['field_id']]
                ),
            ];
        }
        if ($selected === []) {
            $this->fail($this->error('Map at least one card field before converting.'));
        }
        $query = [
            'post_type' => $postType,
            'posts_per_page' => $count,
            'orderby' => $orderBy,
            'order' => $order,
            'taxonomy' => $taxonomy,
            'terms' => $terms,
            'paginate' => ! empty($_POST['paginate']),
            'use_main_query' => ! empty($_POST['use_main_query']) && $postType !== 'product',
        ];
        if ($row !== null) {
            $candidate['title'] = (string) ($row['title'] ?? $candidate['title']);
            $candidate['slug'] = sanitize_title($candidate['title']);
            $candidate['source_key'] = (string) $row['source_key'];
        }
        $candidate['query'] = $query;
        $candidate['selected_mappings'] = $selected;
        $candidate['confirmed'] = true;
        $candidate['committed'] = true;
        $candidate['manual'] = true;
        $json = wp_json_encode($candidate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            $this->fail($this->error('Could not encode dynamic grid configuration.'));
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');
        $gridId = $row['id'] ?? 0;
        if ($gridId > 0) {
            $updated = $wpdb->update(
                $tables->artifacts(),
                [
                    'status' => 'confirmed',
                    'metadata_json' => $json,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['id' => $gridId, 'site_id' => $siteId, 'manifest_id' => $manifestId],
                ['%s', '%s', '%s'],
                ['%d', '%d', '%d']
            );
            if ($updated === false) {
                $wpdb->query('ROLLBACK');
                $this->fail($this->error('Could not update the dynamic grid.'));
            }
        } else {
            $inserted = (new DynamicGridCandidateRepository($tables))->insert(
                $siteId,
                $manifestId,
                [$candidate],
                gmdate('Y-m-d H:i:s')
            );
            if (is_wp_error($inserted)) {
                $wpdb->query('ROLLBACK');
                $this->fail($inserted);
            }
            $gridId = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . $tables->artifacts()
                    . ' WHERE site_id = %d AND manifest_id = %d AND kind = %s AND source_key = %s'
                    . ' ORDER BY id DESC LIMIT 1',
                $siteId,
                $manifestId,
                'dynamic_grid',
                $candidate['source_key']
            ));
            if ($gridId < 1 || $wpdb->update(
                $tables->artifacts(),
                [
                    'status' => 'confirmed',
                    'metadata_json' => $json,
                ],
                ['id' => $gridId, 'site_id' => $siteId],
                ['%s', '%s'],
                ['%d', '%d']
            ) === false) {
                $wpdb->query('ROLLBACK');
                $this->fail($this->error('Could not confirm the new dynamic grid.'));
            }
        }
        $bindingsSaved = (new DynamicBindingRepository($tables))->replaceForArtifact(
            $siteId,
            $gridId,
            $selected
        );
        if (is_wp_error($bindingsSaved)) {
            $wpdb->query('ROLLBACK');
            $this->fail($bindingsSaved);
        }
        $wpdb->query('COMMIT');
        $row = ['id' => $gridId, 'status' => 'confirmed', 'metadata' => $candidate];
        wp_send_json_success($this->result($candidate, $row, $fields, $postTypes, $definitions, $fieldTargets));
    }

    private function posted(string $key): string
    {
        return isset($_POST[$key]) && is_scalar($_POST[$key])
            ? trim((string) wp_unslash($_POST[$key])) : '';
    }

    private function context(TableNames $tables, int $siteId, int $artifactId): array|\WP_Error
    {
        global $wpdb;
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
        return is_array($row) && ! empty($row['source_path'])
            ? $row : $this->error('The editor artifact is not available for grid conversion.');
    }

    private function existing(TableNames $tables, int $siteId, int $manifestId, string $source): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, title, source_key, status, metadata_json FROM ' . $tables->artifacts()
                . ' WHERE site_id = %d AND manifest_id = %d AND kind = %s'
                . ' AND source_path = %s AND status IN (%s,%s,%s) ORDER BY id ASC',
            $siteId,
            $manifestId,
            'dynamic_grid',
            $source,
            'detected',
            'confirmed',
            'ignored'
        ), ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $meta = json_decode((string) $row['metadata_json'], true);
            if (! is_array($meta)) continue;
            $out[] = [
                'id' => (int) $row['id'],
                'status' => (string) $row['status'],
                'title' => (string) $row['title'],
                'source_key' => (string) $row['source_key'],
                'metadata' => $meta,
            ];
        }
        return $out;
    }

    /** Stable IDs use the same source-path/XPath identity as EditorDocumentBridge. */
    private function sourceElements(string $html, string $source): array|\WP_Error
    {
        if (! class_exists(\DOMDocument::class)) {
            return $this->error('The PHP DOM extension is required.');
        }
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded || ! $document->documentElement instanceof \DOMElement) {
            return $this->error('Could not parse the original imported HTML.');
        }
        $elements = [];
        $normalized = str_replace('\\', '/', $source);
        $walk = static function (\DOMElement $element, string $path, string $parent)
        use (&$walk, &$elements, $normalized): void {
            $id = 'cdx-stw-' . substr(hash('sha256', $normalized . "\0" . $path), 0, 24);
            $tag = strtolower($element->tagName);
            $elements[$id] = [
                'id' => $id,
                'tag' => $tag,
                'path' => $path,
                'parent' => $parent,
                'node' => $element,
                'children' => [],
            ];
            $counts = [];
            foreach ($element->childNodes as $child) {
                if (! $child instanceof \DOMElement) continue;
                $childTag = strtolower($child->tagName);
                $counts[$childTag] = ($counts[$childTag] ?? 0) + 1;
                $childPath = $path . '/' . $childTag . '[' . $counts[$childTag] . ']';
                $childId = 'cdx-stw-' . substr(hash('sha256', $normalized . "\0" . $childPath), 0, 24);
                $elements[$id]['children'][] = $childId;
                $walk($child, $childPath, $id);
            }
        };
        $root = $document->documentElement;
        $walk($root, '/' . strtolower($root->tagName) . '[1]', '');
        return $elements;
    }

    private function cardSignature(array $child): string
    {
        if (! in_array(
            $child['tag'],
            ['div', 'section', 'article', 'li'],
            true
        )) {
            return '';
        }

        $node = $child['node'];

        $classes = preg_split(
            '/\s+/',
            trim($node->getAttribute('class'))
        );

        $classes = is_array($classes)
            ? array_values(array_filter($classes))
            : [];

        // Recognize a card class regardless of its position.
        foreach ($classes as $class) {
            if (preg_match(
                '/(?:^|[-_])(?:product[-_])?(?:card|tile|item)$/i',
                $class
            )) {
                return 'card:' . $child['tag'] . ':' . strtolower($class);
            }
        }

        // Support repeated Bootstrap/grid wrappers containing card content.
        $first = sanitize_html_class((string) ($classes[0] ?? ''));

        if ($first === '') {
            return '';
        }

        $hasCardContent =
            $node->getElementsByTagName('a')->length > 0
            || $node->getElementsByTagName('img')->length > 0
            || $node->getElementsByTagName('h2')->length > 0
            || $node->getElementsByTagName('h3')->length > 0;

        return $hasCardContent
            ? 'wrapper:' . $child['tag'] . ':' . $first
            : '';
    }

    private function sourceCardParent(
        array $elements,
        string $selectedId
    ): string {
        $selected = $elements[$selectedId];

        // Clicking one card can select the entire sibling card group.
        if (
            $selected['parent'] !== ''
            && str_starts_with(
                $this->cardSignature($selected),
                'card:'
            )
        ) {
            $selectedId = $selected['parent'];
        }

        $queue = [[$selectedId, 0]];
        $best = $selectedId;
        $bestScore = -PHP_INT_MAX;

        // Inspect the selected source region, not the entire website.
        for (
            $index = 0;
            $index < count($queue) && $index < 350;
            $index++
        ) {
            [$id, $depth] = $queue[$index];
            $parent = $elements[$id];

            if (in_array(
                $parent['tag'],
                ['nav', 'header', 'footer', 'form'],
                true
            )) {
                continue;
            }

            if ($depth < 5) {
                foreach ($parent['children'] as $childId) {
                    if (isset($elements[$childId])) {
                        $queue[] = [$childId, $depth + 1];
                    }
                }
            }

            $groups = [];

            foreach ($parent['children'] as $childId) {
                if (! isset($elements[$childId])) {
                    continue;
                }

                $key = $this->cardSignature($elements[$childId]);

                if ($key !== '') {
                    $groups[$key] = ($groups[$key] ?? 0) + 1;
                }
            }

            foreach ($groups as $key => $count) {
                if ($count < 2 || $count > 100) {
                    continue;
                }

                $score =
                    (str_starts_with($key, 'card:') ? 1000 : 0)
                    + $count * 10
                    - $depth * 20;

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $id;
                }
            }
        }

        return $best;
    }

    private function candidate(array $elements, string $containerId, string $source): array|\WP_Error
    {
        $containerId = $this->sourceCardParent(
            $elements,
            $containerId
        );

        $container = $elements[$containerId];

        if (in_array(
            $container['tag'],
            ['nav', 'header', 'footer', 'form'],
            true
        )) {
            return $this->error(
                'Navigation, forms, headers and footers cannot be converted to grids.'
            );
        }

        $ancestorId = $container['parent'];
        for ($depth = 0; $ancestorId !== '' && $depth < 80; $depth++) {
            $ancestor = $elements[$ancestorId] ?? null;
            if (! is_array($ancestor)) break;
            if (in_array($ancestor['tag'], ['nav', 'form', 'header', 'footer'], true)) {
                return $this->error('Navigation, forms, headers and footers cannot be converted to grids.');
            }
            $ancestorId = $ancestor['parent'];
        }
        $direct = array_map(static fn(string $id): array => $elements[$id], $container['children']);
        $groups = [];
        foreach ($direct as $child) {
            if (in_array($child['tag'], ['script', 'style', 'template', 'nav', 'form', 'header', 'footer'], true)) {
                continue;
            }
            $signature = $this->cardSignature($child);

            if ($signature === '') {
                continue;
            }

            $groups[$signature][] = $child;
        }
        uasort($groups, static fn(array $a, array $b): int => count($b) <=> count($a));
        $group = reset($groups);
        if (! is_array($group) || count($group) < 2) {
            return $this->error(
                'No repeated source-backed cards were found in this region. '
                    . 'Browser-generated cards require a separate runtime converter.'
            );
        }
        $items = [];
        foreach ($group as $entry) {
            $items[] = ['ref' => $entry['id'], 'path' => $entry['path'], 'tag' => $entry['tag']];
        }
        $fingerprint = hash('sha256', $group[0]['tag'] . "\0" . count($group));
        return [
            'title' => 'Manual Grid — ' . basename($source),
            'slug' => sanitize_title('manual-grid-' . substr($containerId, 8, 12)),
            'source' => $source,
            'source_key' => hash('sha256', 'manual-grid' . "\0" . $source . "\0" . $container['path']),
            'container_ref' => $containerId,
            'container_path' => $container['path'],
            'container_tag' => $container['tag'],
            'item_fingerprint' => $fingerprint,
            'sample_item_ref' => $items[0]['ref'],
            'sample_item_path' => $items[0]['path'],
            'item_tag' => $items[0]['tag'],
            'repeat_count' => count($items),
            'sibling_count' => count($direct),
            'items' => $items,
            'requires_confirmation' => true,
            'auto_bind' => false,
        ];
    }

    private function fieldDefinitions(array $mapData): array
    {
        $definitions = self::FIELDS;
        // Only expose WooCommerce prices when the installed field mapper
        // explicitly advertises a compatible binding source and type.
        foreach ($mapData['available_sources'] ?? [] as $source) {
            if (! is_array($source) || ! in_array(
                (string) ($source['source'] ?? ''),
                ['product.price', 'product.price_html'],
                true
            )) continue;
            $types = is_array($source['binding_types'] ?? null) ? $source['binding_types'] : [];
            $type = in_array('html', $types, true) ? 'html'
                : (in_array('text', $types, true) ? 'text' : '');
            if ($type !== '') {
                $definitions['price'] = [(string) $source['source'], $type, ''];
                break;
            }
        }
        return $definitions;
    }

    private function fieldOptions(array $elements, string $sampleId, array $mapData, array $definitions): array
    {
        $list = array_fill_keys(array_keys($definitions), []);
        $samplePath = $elements[$sampleId]['path'];
        $suggestions = $mapData['suggestions'] ?? [];
        $suggested = [];
        foreach ($suggestions as $suggestion) {
            if (! is_array($suggestion)) continue;
            $path = (string) ($suggestion['source_path'] ?? '');
            if (! str_starts_with($path, $samplePath . '/')) continue;
            $suggested[(string) ($suggestion['data_source'] ?? '')][(string) ($suggestion['source_ref'] ?? '')] = true;
        }
        $candidates = [];
        foreach ($elements as $entry) {
            if (! str_starts_with($entry['path'], $samplePath . '/')) continue;
            $candidates[] = $entry;
        }
        foreach (array_slice($candidates, 0, 150) as $entry) {
            $tag = $entry['tag'];
            $node = $entry['node'];
            $id = $entry['id'];
            $plain = trim(preg_replace('/\s+/', ' ', (string) $node->textContent));
            $hint = $node->getAttribute('class');
            $label = strtoupper($tag) . ($plain !== '' ? ': ' . substr($plain, 0, 34) : ($hint !== '' ? ': ' . substr($hint, 0, 34) : ''));
            foreach ($definitions as $key => [$sourceKey, $bindingType, $target]) {
                $allowed = match ($key) {
                    'image' => $tag === 'img',
                    'link' => $tag === 'a' && $node->hasAttribute('href'),
                    'title', 'excerpt', 'date' => in_array(
                        $tag,
                        ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'time', 'small', 'strong', 'a', 'div', 'td', 'th'],
                        true
                    )
                        && $entry['children'] === [] && $plain !== '',
                    'price' => in_array(
                        $tag,
                        ['p', 'span', 'small', 'strong', 'div', 'bdi', 'ins', 'del'],
                        true
                    )
                        && ($bindingType === 'html' || $entry['children'] === [])
                        && $plain !== '',
                    default => false,
                };
                if ($allowed && count($list[$key]) < 80) {
                    $list[$key][] = [
                        'id' => $id,
                        'label' => $label,
                        'suggested' => isset($suggested[$sourceKey][$id]),
                    ];
                }
            }
        }
        return $list;
    }

    private function fieldTargets(
        array $elements,
        string $sampleId
    ): array {
        $samplePath = (string) $elements[$sampleId]['path'];

        $targets = [
            'text' => [],
            'html' => [],
            'media' => [],
            'url' => [],
        ];

        foreach ($elements as $element) {
            if (! str_starts_with(
                (string) $element['path'],
                $samplePath . '/'
            )) {
                continue;
            }

            $tag = $element['tag'];
            $node = $element['node'];

            $plain = trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    (string) $node->textContent
                )
            );

            $className = trim((string) $node->getAttribute('class'));

            $label = strtoupper($tag) . ' · ' . substr(
                $plain !== '' ? $plain : $className,
                0,
                46
            );

            $item = [
                'id' => $element['id'],
                'label' => $label,
            ];

            if ($tag === 'img' && count($targets['media']) < 100) {
                $targets['media'][] = $item;
            }

            if (
                $tag === 'a'
                && $node->hasAttribute('href')
                && count($targets['url']) < 100
            ) {
                $targets['url'][] = $item;
            }

            if (
                $element['children'] === []
                && $plain !== ''
                && in_array(
                    $tag,
                    [
                        'h1',
                        'h2',
                        'h3',
                        'h4',
                        'h5',
                        'h6',
                        'p',
                        'span',
                        'time',
                        'small',
                        'strong',
                        'em',
                        'a',
                        'div',
                        'td',
                        'th',
                        'bdi',
                        'ins',
                    ],
                    true
                )
            ) {
                if (count($targets['text']) < 100) {
                    $targets['text'][] = $item;
                }

                if (count($targets['html']) < 100) {
                    $targets['html'][] = $item;
                }
            }
        }

        return $targets;
    }

    private function catalogForType(string $postType): array
    {
        $out = [];

        foreach (
            self::EXTRA_NATIVE as
            $id => [$source, $bindingType, $bindingTarget, $kind, $label]
        ) {
            $out[$id] = [
                'id' => $id,
                'label' => $label . ' (WordPress)',
                'kind' => $kind,
                'source' => $source,
                'binding_type' => $bindingType,
                'binding_target' => $bindingTarget,
                'config' => [],
            ];
        }

        foreach (
            (new GridPublicFieldCatalog())->forType($postType)
            as $id => $entry
        ) {
            $kind = $entry['kind'];

            $entry['binding_type'] = $kind === 'url'
                ? 'url'
                : ($kind === 'media' ? 'media' : 'text');

            $entry['binding_target'] = $kind === 'url'
                ? 'href'
                : ($kind === 'media' ? 'src' : '');

            $out[$id] = $entry;
        }

        return $out;
    }

    private function postTypes(): array
    {
        $types = [];
        foreach (get_post_types(['public' => true], 'objects') as $postType) {
            if (! $postType instanceof \WP_Post_Type || $postType->name === 'attachment') continue;
            if ($postType->name === 'product' && (! class_exists('WooCommerce')
                || ! function_exists('wc_get_products') || ! function_exists('wc_get_product'))) continue;
            $types[$postType->name] = (string) $postType->labels->name;
        }
        return $types;
    }

    private function result(
        array $candidate,
        ?array $row,
        array $fields,
        array $postTypes,
        array $definitions,
        array $fieldTargets
    ): array {
        $meta = $row['metadata'] ?? [];
        $active = ($row['status'] ?? '') === 'confirmed';
        $saved = [];
        $extras = [];

        foreach (
            $active && is_array($meta['selected_mappings'] ?? null)
                ? $meta['selected_mappings']
                : []
            as $binding
        ) {
            if (! empty($binding['config']['grid_mapping_id'])) {
                $extras[] = [
                    'field_id' => (string) $binding['config']['grid_mapping_id'],
                    'element_id' => (string) ($binding['element_id'] ?? ''),
                ];

                continue;
            }

            foreach ($definitions as $key => [$source, $type, $target]) {
                if (($binding['data_source'] ?? '') === $source
                    && ($binding['binding_type'] ?? '') === $type
                    && ($binding['binding_target'] ?? '') === $target
                ) {
                    $saved[$key] = (string) ($binding['element_id'] ?? '');
                }
            }
        }
        $default = [];
        foreach ($fields as $key => $options) {
            $best = array_values(array_filter($options, static fn(array $opt): bool => $opt['suggested']));
            $default[$key] = $saved[$key]
                ?? ($active ? '' : ($best[0]['id'] ?? ''));
        }
        $query = $active && is_array($meta['query'] ?? null) ? $meta['query'] : [];
        $postType = (string) ($query['post_type'] ?? 'post');
        if (! isset($postTypes[$postType])) $postType = array_key_first($postTypes) ?? '';
        $taxonomiesByType = [];
        foreach (array_keys($postTypes) as $type) {
            $taxonomiesByType[$type] = [];
            foreach (get_object_taxonomies($type, 'objects') as $tax) {
                if (
                    $tax instanceof \WP_Taxonomy && $tax->public
                    && ($type !== 'product' || in_array($tax->name, ['product_cat', 'product_tag'], true))
                ) {
                    $taxonomiesByType[$type][$tax->name] = (string) $tax->labels->name;
                }
            }
        }
        return [
            'active' => $active,
            'found' => $row !== null,
            'count' => count($candidate['items']),
            'sample_id' => $candidate['sample_item_ref'],
            'samples' => array_map(static fn(array $item): array => [
                'id' => $item['ref'],
                'label' => strtoupper($item['tag']) . ' · ' . $item['path'],
            ], $candidate['items']),
            'fields' => $fields,
            'mappings' => $default,
            'field_targets' => $fieldTargets,
            'field_catalog' => $this->catalogForType($postType),
            'extra_mappings' => $extras,
            'price_supported' => isset($definitions['price']),
            'post_types' => $postTypes,
            'taxonomies_by_type' => $taxonomiesByType,
            'query' => [
                'post_type' => $postType,
                'posts_per_page' => (int) ($query['posts_per_page'] ?? count($candidate['items'])),
                'orderby' => $query['orderby'] ?? 'date',
                'order' => $query['order'] ?? 'DESC',
                'taxonomy' => $query['taxonomy'] ?? '',
                'terms' => implode(', ', is_array($query['terms'] ?? null) ? $query['terms'] : []),
                'paginate' => ! empty($query['paginate']),
                'use_main_query' => ! empty($query['use_main_query']),
            ],
            'note' => 'Imported card HTML remains available. Reverting removes the dynamic query and restores the static listing.',
        ];
    }

    private function error(string $message): \WP_Error
    {
        return new \WP_Error('condixia_ai_stw_manual_grid', $message, ['status' => 400]);
    }

    private function fail(\WP_Error $error): never
    {
        $data = $error->get_error_data();
        wp_send_json_error([
            'message' => $error->get_error_message(),
            'code' => $error->get_error_code(),
        ], is_array($data) ? (int) ($data['status'] ?? 400) : 400);
        exit;
    }
}
