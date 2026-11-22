<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Editor;

use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Security\NonceActions;
use Condixia\AISiteToWordPress\Security\RequestGuard;
use Condixia\AISiteToWordPress\Storage\AssignmentRepository;
use Condixia\AISiteToWordPress\Storage\SourceFileRepository;
use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class SitePartManualController
{
    public const ACTION = 'condixia_ai_stw_manual_site_part';

    private const ELEMENT_PATTERN = '/^cdx-stw-[a-f0-9]{24}$/';

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
            $siteId < 1
            || $artifactId < 1
            || ! in_array(
                $operation,
                ['badges', 'inspect', 'assign', 'map', 'detach'],
                true
            )
            || (
                $operation !== 'badges'
                && preg_match(self::ELEMENT_PATTERN, $elementId) !== 1
            )
        ) {
            $this->fail($this->error('Invalid site part request.'));
        }

        $context = $this->context($siteId, $artifactId);

        if (is_wp_error($context)) {
            $this->fail($context);
        }

        $tables = new TableNames();
        $manifestId = (int) $context['manifest_id'];
        $source = (string) $context['source_path'];

        $parts = $this->parts($tables, $siteId, $manifestId);

        if ($operation === 'badges') {
            wp_send_json_success([
                'items' => $this->badges($parts, $source),
            ]);
        }

        $html = (new SourceFileRepository())->readHtml(
            $siteId,
            $manifestId,
            $source
        );

        if (is_wp_error($html)) {
            $this->fail($html);
        }

        $path = $this->sourcePath(
            (string) $html,
            $source,
            $elementId
        );

        if (is_wp_error($path)) {
            $this->fail($path);
        }

        $existing = $this->match($parts, $source, $path);

        if (is_wp_error($existing)) {
            $this->fail($existing);
        }

        $pageId = $this->pageId(
            $tables,
            $siteId,
            $manifestId,
            $source
        );

        if ($operation === 'inspect') {
            wp_send_json_success(
                $this->result($parts, $existing, $pageId, $source)
            );
        }

        if ($operation === 'map') {
            if ($existing !== null) {
                $this->fail(
                    $this->error(
                        'This region is already mapped to a site part.'
                    )
                );
            }

            $targetId = absint($_POST['target_artifact_id'] ?? 0);
            $target = $parts[$targetId] ?? null;

            if (
                $target === null
                || ! in_array(
                    $target['role'],
                    ['header', 'footer'],
                    true
                )
                || empty($target['assignments'])
            ) {
                $this->fail(
                    $this->error(
                        'Choose an assigned site part from this import.'
                    )
                );
            }

            foreach ($target['metadata']['members'] ?? [] as $member) {
                if (
                    is_array($member)
                    && ($member['source'] ?? '') === $source
                ) {
                    $this->fail(
                        $this->error(
                            'This site part already has a target on this document.'
                        )
                    );
                }
            }

            $target['metadata']['members'][] = [
                'source' => $source,
                'path' => $path,
                'element_ref' => $elementId,
            ];

            $json = wp_json_encode(
                $target['metadata'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            if (! is_string($json)) {
                $this->fail(
                    $this->error('Site part metadata could not be encoded.')
                );
            }

            global $wpdb;

            $updated = $wpdb->update(
                $tables->artifacts(),
                [
                    'metadata_json' => $json,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ],
                [
                    'id' => $targetId,
                    'site_id' => $siteId,
                    'manifest_id' => $manifestId,
                ],
                ['%s', '%s'],
                ['%d', '%d', '%d']
            );

            if ($updated === false) {
                $this->fail(
                    $this->error('Could not map this source region.')
                );
            }

            $parts = $this->parts($tables, $siteId, $manifestId);

            wp_send_json_success(
                $this->result(
                    $parts,
                    $parts[$targetId],
                    $pageId,
                    $source
                )
            );
        }

        if ($operation === 'detach') {
            if ($existing === null) {
                $this->fail(
                    $this->error(
                        'This region is not assigned to a site part.'
                    )
                );
            }

            $members = is_array(
                $existing['metadata']['members'] ?? null
            ) ? $existing['metadata']['members'] : [];

            if (
                count($members) > 1
                && ! $this->posted('confirm_shared')
            ) {
                $this->fail(
                    $this->error(
                        'Confirm the change to this shared site part.'
                    )
                );
            }

            $representative =
                ($existing['metadata']['representative_source'] ?? '')
                === $source
                && ($existing['metadata']['representative_path'] ?? '')
                === $path;

            global $wpdb;

            if ($representative) {
                $changed = $wpdb->update(
                    $tables->assignments(),
                    [
                        'enabled' => 0,
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ],
                    [
                        'artifact_id' => $existing['id'],
                        'site_id' => $siteId,
                    ],
                    ['%d', '%s'],
                    ['%d', '%d']
                );
            } else {
                $remaining = array_values(array_filter(
                    $members,
                    static function ($member) use ($source, $path): bool {
                        return ! is_array($member)
                            || ($member['source'] ?? '') !== $source
                            || ($member['path'] ?? '') !== $path;
                    }
                ));

                $existing['metadata']['members'] = $remaining;

                $json = wp_json_encode(
                    $existing['metadata'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );

                $changed = is_string($json)
                    ? $wpdb->update(
                        $tables->artifacts(),
                        [
                            'metadata_json' => $json,
                            'updated_at' => gmdate('Y-m-d H:i:s'),
                        ],
                        [
                            'id' => $existing['id'],
                            'site_id' => $siteId,
                        ],
                        ['%s', '%s'],
                        ['%d', '%d']
                    )
                    : false;
            }

            if ($changed === false) {
                $this->fail(
                    $this->error('Could not detach this site part.')
                );
            }

            $parts = $this->parts($tables, $siteId, $manifestId);

            wp_send_json_success(
                $this->result($parts, null, $pageId, $source)
            );
        }

        $role = sanitize_key($this->posted('role'));
        $scope = sanitize_key($this->posted('scope'));
        $title = sanitize_text_field($this->posted('title'));

        if (
            ! in_array($role, ['header', 'footer'], true)
            || ! in_array(
                $scope,
                ['entire_site', 'front_page', 'all_pages', 'object'],
                true
            )
            || $title === ''
            || strlen($title) > 100
            || ($scope === 'object' && $pageId < 1)
        ) {
            $this->fail(
                $this->error(
                    'Choose a valid role, name and display condition.'
                )
            );
        }

        $members = is_array(
            $existing['metadata']['members'] ?? null
        ) ? $existing['metadata']['members'] : [];

        if (
            count($members) > 1
            && ! $this->posted('confirm_shared')
        ) {
            $this->fail(
                $this->error(
                    'Confirm the change to this shared site part.'
                )
            );
        }

        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query('START TRANSACTION');

        $id = $existing['id'] ?? 0;

        if ($id === 0) {
            $metadata = [
                'manual' => true,
                'role' => $role,
                'representative_source' => $source,
                'representative_path' => $path,
                'representative_ref' => $elementId,
                'members' => [
                    [
                        'source' => $source,
                        'path' => $path,
                        'element_ref' => $elementId,
                    ],
                ],
                'document_coverage' => 0,
            ];

            $json = wp_json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            $inserted = is_string($json) && $wpdb->insert(
                $tables->artifacts(),
                [
                    'uuid' => wp_generate_uuid4(),
                    'site_id' => $siteId,
                    'manifest_id' => $manifestId,
                    'kind' => 'site_part',
                    'role' => $role,
                    'title' => $title,
                    'slug' => sanitize_title($title),
                    'source_path' => $source,
                    'source_key' => hash(
                        'sha256',
                        'manual-site-part'
                            . "\0" . $siteId
                            . "\0" . $source
                            . "\0" . $path
                    ),
                    'status' => 'confirmed',
                    'sort_order' => 0,
                    'metadata_json' => $json ?: '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    '%s',
                    '%d',
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                ]
            ) === 1;

            $id = $inserted ? (int) $wpdb->insert_id : 0;

            if ($id < 1) {
                $wpdb->query('ROLLBACK');
                $this->fail(
                    $this->error(
                        'The new site part could not be stored.'
                    )
                );
            }
        } else {
            $updated = $wpdb->update(
                $tables->artifacts(),
                [
                    'role' => $role,
                    'title' => $title,
                    'slug' => sanitize_title($title),
                    'updated_at' => $now,
                ],
                [
                    'id' => $id,
                    'site_id' => $siteId,
                    'manifest_id' => $manifestId,
                ],
                ['%s', '%s', '%s', '%s'],
                ['%d', '%d', '%d']
            );

            if ($updated === false) {
                $wpdb->query('ROLLBACK');
                $this->fail(
                    $this->error('The site part could not be updated.')
                );
            }
        }

        $disabled = $wpdb->update(
            $tables->assignments(),
            [
                'enabled' => 0,
                'updated_at' => $now,
            ],
            [
                'artifact_id' => $id,
                'site_id' => $siteId,
            ],
            ['%d', '%s'],
            ['%d', '%d']
        );

        if ($disabled === false) {
            $wpdb->query('ROLLBACK');
            $this->fail(
                $this->error(
                    'Old display conditions could not be disabled.'
                )
            );
        }

        $saved = (new AssignmentRepository($tables))->save(
            $siteId,
            $id,
            $scope,
            $scope === 'object' ? (string) $pageId : '',
            100,
            ['origin' => 'manual_site_part'],
            true
        );

        if (is_wp_error($saved)) {
            $wpdb->query('ROLLBACK');
            $this->fail($saved);
        }

        $wpdb->query('COMMIT');

        $parts = $this->parts($tables, $siteId, $manifestId);

        wp_send_json_success(
            $this->result(
                $parts,
                $parts[$id] ?? null,
                $pageId,
                $source
            )
        );
    }

    private function posted(string $key): string
    {
        return isset($_POST[$key]) && is_scalar($_POST[$key])
            ? trim((string) wp_unslash($_POST[$key]))
            : '';
    }

    private function context(
        int $siteId,
        int $artifactId
    ): array|\WP_Error {
        global $wpdb;

        $tables = new TableNames();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT a.manifest_id, a.source_path
                 FROM ' . $tables->artifacts() . ' a
                 INNER JOIN ' . $tables->sites() . ' s
                    ON s.id = a.site_id
                    AND s.current_manifest_id = a.manifest_id
                 WHERE a.id = %d
                    AND a.site_id = %d
                    AND (
                        (a.kind IN (%s, %s) AND a.status = %s)
                        OR (a.kind = %s AND a.status = %s)
                    )
                    AND s.status = %s
                 LIMIT 1',
                $artifactId,
                $siteId,
                'template',
                'site_part',
                'confirmed',
                'page',
                'created',
                'pages_created'
            ),
            ARRAY_A
        );

        return is_array($row) && ! empty($row['source_path'])
            ? $row
            : $this->error(
                'This editor document is not available for site-part assignment.'
            );
    }

    private function sourcePath(
        string $html,
        string $source,
        string $elementId
    ): string|\WP_Error {
        if (! class_exists(\DOMDocument::class)) {
            return $this->error('The PHP DOM extension is required.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET
                    | LIBXML_COMPACT
                    | LIBXML_NOERROR
                    | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (
            ! $loaded
            || ! $document->documentElement instanceof \DOMElement
        ) {
            return $this->error(
                'The imported document could not be parsed.'
            );
        }

        $found = [];

        $walk = static function (
            \DOMElement $element,
            string $path
        ) use (&$walk, &$found, $elementId, $source): void {
            $tag = strtolower($element->tagName);

            $id = 'cdx-stw-' . substr(
                hash(
                    'sha256',
                    str_replace('\\', '/', $source)
                        . "\0" . $path
                ),
                0,
                24
            );

            if ($id === $elementId) {
                if (
                    in_array(
                        $tag,
                        ['header', 'footer', 'section', 'aside', 'div'],
                        true
                    )
                    && $element->getElementsByTagName('main')->length === 0
                ) {
                    $found[] = $path;
                }
            }

            $counts = [];

            foreach ($element->childNodes as $child) {
                if (! $child instanceof \DOMElement) {
                    continue;
                }

                $childTag = strtolower($child->tagName);

                $counts[$childTag] = ($counts[$childTag] ?? 0) + 1;

                $walk(
                    $child,
                    $path
                        . '/'
                        . $childTag
                        . '['
                        . $counts[$childTag]
                        . ']'
                );
            }
        };

        $root = $document->documentElement;

        $walk(
            $root,
            '/' . strtolower($root->tagName) . '[1]'
        );

        return count($found) === 1
            ? $found[0]
            : $this->error(
                'Select a unique header, footer or section container from the imported document.'
            );
    }

    private function parts(
        TableNames $tables,
        int $siteId,
        int $manifestId
    ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, title, role, metadata_json
                 FROM ' . $tables->artifacts() . '
                 WHERE site_id = %d
                    AND manifest_id = %d
                    AND kind = %s
                    AND status = %s
                 ORDER BY id ASC',
                $siteId,
                $manifestId,
                'site_part',
                'confirmed'
            ),
            ARRAY_A
        );

        $result = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) $row['id'];

            $metadata = json_decode(
                (string) $row['metadata_json'],
                true
            );

            $assignments = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT context_type, context_key
                     FROM ' . $tables->assignments() . '
                     WHERE site_id = %d
                        AND artifact_id = %d
                        AND enabled = 1
                     ORDER BY priority DESC, id ASC',
                    $siteId,
                    $id
                ),
                ARRAY_A
            );

            $result[$id] = [
                'id' => $id,
                'title' => (string) $row['title'],
                'role' => (string) $row['role'],
                'metadata' => is_array($metadata) ? $metadata : [],
                'assignments' => is_array($assignments)
                    ? $assignments
                    : [],
            ];
        }

        return $result;
    }

    private function match(
        array $parts,
        string $source,
        string $path
    ): array|\WP_Error|null {
        $found = null;

        foreach ($parts as $part) {
            foreach ($part['metadata']['members'] ?? [] as $member) {
                if (
                    is_array($member)
                    && ($member['source'] ?? '') === $source
                    && ($member['path'] ?? '') === $path
                ) {
                    if ($found !== null) {
                        return $this->error(
                            'Multiple site parts claim this source region.'
                        );
                    }

                    $found = $part;
                }
            }
        }

        return $found;
    }

    private function pageId(
        TableNames $tables,
        int $siteId,
        int $manifestId,
        string $source
    ): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT wp_object_id
                 FROM ' . $tables->artifacts() . '
                 WHERE site_id = %d
                    AND manifest_id = %d
                    AND source_path = %s
                    AND kind = %s
                    AND status = %s
                    AND wp_object_id > 0
                 ORDER BY id ASC
                 LIMIT 1',
                $siteId,
                $manifestId,
                $source,
                'page',
                'created'
            )
        );
    }

    private function badges(
        array $parts,
        string $source
    ): array {
        $items = [];

        foreach ($parts as $part) {
            if ($part['assignments'] === []) {
                continue;
            }

            foreach ($part['metadata']['members'] ?? [] as $member) {
                if (
                    ! is_array($member)
                    || ($member['source'] ?? '') !== $source
                ) {
                    continue;
                }

                $path = (string) ($member['path'] ?? '');

                if ($path === '') {
                    continue;
                }

                $items[] = [
                    'id' => 'cdx-stw-' . substr(
                        hash(
                            'sha256',
                            str_replace('\\', '/', $source)
                                . "\0" . $path
                        ),
                        0,
                        24
                    ),
                    'role' => $part['role'],
                ];
            }
        }

        return $items;
    }

    private function result(
        array $parts,
        ?array $existing,
        int $pageId,
        string $source
    ): array {
        $options = [];

        foreach ($parts as $part) {
            if (
                $part['assignments'] === []
                || $part['id'] === ($existing['id'] ?? 0)
            ) {
                continue;
            }

            $members = is_array(
                $part['metadata']['members'] ?? null
            ) ? $part['metadata']['members'] : [];

            foreach ($members as $member) {
                if (
                    is_array($member)
                    && ($member['source'] ?? '') === $source
                ) {
                    continue 2;
                }
            }

            $options[] = [
                'id' => $part['id'],
                'title' => $part['title'],
                'role' => $part['role'],
            ];
        }

        $conditions = $existing['assignments'] ?? [];
        $first = $conditions[0] ?? [];

        return [
            'bound' => $existing !== null && $conditions !== [],
            'mapped' => $existing !== null,
            'id' => $existing['id'] ?? 0,
            'title' => $existing['title'] ?? '',
            'role' => $existing['role'] ?? '',
            'scope' => count($conditions) === 1
                ? ($first['context_type'] ?? '')
                : '',
            'page_id' => $pageId,
            'shared' => count($existing['metadata']['members'] ?? []),
            'options' => array_slice($options, 0, 100),
            'notice' => 'This assignment only replaces regions mapped in imported source documents. It does not insert missing sections into other pages.',
        ];
    }

    private function error(string $message): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_manual_site_part',
            $message,
            ['status' => 400]
        );
    }

    private function fail(\WP_Error $error): never
    {
        $data = $error->get_error_data();

        wp_send_json_error(
            [
                'message' => $error->get_error_message(),
                'code' => $error->get_error_code(),
            ],
            is_array($data)
                ? (int) ($data['status'] ?? 400)
                : 400
        );

        exit;
    }
}
