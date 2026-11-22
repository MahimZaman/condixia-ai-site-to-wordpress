<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Editor;

use Condixia\AISiteToWordPress\Media\MediaDocumentRenderer;
use Condixia\AISiteToWordPress\Rendering\HtmlUrlRewriter;
use Condixia\AISiteToWordPress\Queries\DynamicGridRenderer;
use Condixia\AISiteToWordPress\Routing\PageRouteMapRepository;
use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Storage\AssetRepository;
use Condixia\AISiteToWordPress\Storage\SourceFileRepository;
use Condixia\AISiteToWordPress\Storage\TableNames;

defined('ABSPATH') || exit;

final class EditorArtifactPreview
{
    public const ACTION = 'condixia_ai_stw_editor_artifact_preview';

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'render']);
    }

    public function targetUrl(int $siteId, int $artifactId): string|\WP_Error
    {
        $artifact = $this->artifact($siteId, $artifactId);

        if (is_wp_error($artifact)) {
            return $artifact;
        }

        return add_query_arg(
            [
                'action' => self::ACTION,
                'site_id' => $siteId,
                'artifact_id' => $artifactId,
            ],
            admin_url('admin-post.php')
        );
    }

    public function render(): void
    {
        $siteId = isset($_GET['site_id']) && is_scalar($_GET['site_id'])
            ? absint($_GET['site_id'])
            : 0;

        $artifactId = isset($_GET['artifact_id']) && is_scalar($_GET['artifact_id'])
            ? absint($_GET['artifact_id'])
            : 0;

        if ($siteId < 1 || $artifactId < 1) {
            wp_die(
                esc_html__('Invalid editor preview request.', 'condixia-ai-site-to-wordpress'),
                '',
                ['response' => 403]
            );
        }

        $artifact = $this->artifact($siteId, $artifactId);

        if (is_wp_error($artifact)) {
            wp_die(
                esc_html($artifact->get_error_message()),
                '',
                ['response' => 403]
            );
        }

        $html = $this->html($artifact);

        if (is_wp_error($html)) {
            wp_die(
                esc_html($html->get_error_message()),
                '',
                ['response' => 500]
            );
        }

        nocache_headers();

        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox allow-scripts');

        echo $html;
        exit;
    }

    private function artifact(int $siteId, int $artifactId): array|\WP_Error
    {
        if (
            ! current_user_can(Capabilities::EDIT)
            || $siteId < 1
            || $artifactId < 1
        ) {
            return $this->error();
        }

        global $wpdb;

        $tables = new TableNames();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT a.*
                 FROM ' . $tables->artifacts() . ' a
                 INNER JOIN ' . $tables->sites() . ' s
                     ON s.id = a.site_id
                     AND s.current_manifest_id = a.manifest_id
                 WHERE a.id = %d
                     AND a.site_id = %d
                     AND a.kind IN (%s, %s)
                     AND a.status = %s
                     AND s.status = %s
                 LIMIT 1',
                $artifactId,
                $siteId,
                'template',
                'site_part',
                'confirmed',
                'pages_created'
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return $this->error();
        }

        $metadata = json_decode(
            (string) ($row['metadata_json'] ?? ''),
            true
        );

        if (
            ! is_array($metadata)
            || empty($row['source_path'])
        ) {
            return $this->error();
        }

        if (
            $row['kind'] === 'site_part'
            && empty($metadata['representative_path'])
        ) {
            return $this->error();
        }

        return [
            'site_id' => (int) $row['site_id'],
            'artifact_id' => (int) $row['id'],
            'manifest_id' => (int) $row['manifest_id'],
            'source_path' => (string) $row['source_path'],
            'kind' => (string) $row['kind'],
            'metadata' => $metadata,
        ];
    }

    private function html(array $artifact): string|\WP_Error
    {
        $siteId = $artifact['site_id'];
        $sourcePath = $artifact['source_path'];

        $editor = new EditorDocumentBridge();

        $html = (new SourceFileRepository())->readHtml(
            $siteId,
            $artifact['manifest_id'],
            $sourcePath
        );

        if (is_wp_error($html)) {
            return $html;
        }

        $html = $editor->prepareSource($sourcePath, $html);

        if (is_wp_error($html)) {
            return $html;
        }

        // Show active imported-grid conversions inside artifact previews too.
        $html = (new DynamicGridRenderer())->render(
            $siteId,
            $sourcePath,
            $html,
            ['site_id' => $siteId, 'artifact_id' => $artifact['artifact_id']]
        );

        if (is_wp_error($html)) {
            return $html;
        }

        $assets = (new AssetRepository())->contextForSite($siteId);

        if (is_wp_error($assets)) {
            return $assets;
        }

        $routes = (new PageRouteMapRepository())->mapForSite($siteId);

        if (is_wp_error($routes)) {
            return $routes;
        }

        $rewritten = (new HtmlUrlRewriter())->rewrite(
            $sourcePath,
            $html,
            $assets['asset_map'],
            $assets['file_index'],
            $routes
        );

        if (is_wp_error($rewritten)) {
            return $rewritten;
        }

        $html = (new MediaDocumentRenderer())->render(
            (string) $rewritten['html'],
            is_array($assets['media_map'] ?? null)
                ? $assets['media_map']
                : []
        );

        if (is_wp_error($html)) {
            return $html;
        }

        $html = $editor->normalizeIdentities($html);

        if (is_wp_error($html)) {
            return $html;
        }

        if ($artifact['kind'] === 'site_part') {
            $html = $this->scope(
                $html,
                (string) $artifact['metadata']['representative_path']
            );

            if (is_wp_error($html)) {
                return $html;
            }
        }

        $html = (new EditorOverrideRenderer())->render(
            $siteId,
            $artifact['artifact_id'],
            $html
        );

        if (is_wp_error($html)) {
            return $html;
        }

        return $editor->finalize($html, $artifact);
    }

    private function scope(string $html, string $path): string|\WP_Error
    {
        if (
            ! class_exists(\DOMDocument::class)
            || preg_match(
                '~^/html\[1\](?:/[a-z][a-z0-9-]*\[[1-9][0-9]*\])+$~',
                $path
            ) !== 1
        ) {
            return $this->error();
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

        if (! $loaded) {
            return $this->error();
        }

        $nodes = (new \DOMXPath($document))->query($path);

        $scope = $nodes instanceof \DOMNodeList
            ? $nodes->item(0)
            : null;

        if (
            ! $scope instanceof \DOMElement
            || $nodes->length !== 1
        ) {
            return $this->error();
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            if (
                ! $element instanceof \DOMElement
                || ! $element->hasAttribute('data-cdx-id')
            ) {
                continue;
            }

            $ancestor = $element;
            $inside = false;

            while ($ancestor instanceof \DOMElement) {
                if ($ancestor->isSameNode($scope)) {
                    $inside = true;
                    break;
                }

                $ancestor = $ancestor->parentNode;
            }

            if (! $inside) {
                $element->removeAttribute('data-cdx-id');
            }
        }

        foreach ($document->childNodes as $child) {
            if (
                $child->nodeType === XML_PI_NODE
                && strtolower($child->nodeName) === 'xml'
            ) {
                $document->removeChild($child);
                break;
            }
        }

        $result = $document->saveHTML();

        return is_string($result)
            ? $result
            : $this->error();
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_editor_artifact',
            __(
                'This editable template or site part is unavailable.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
