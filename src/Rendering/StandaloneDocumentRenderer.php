<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Rendering;

use Condixia\AISiteToWordPress\Bindings\DynamicBindingDocumentRenderer;
use Condixia\AISiteToWordPress\Editor\EditorDocumentBridge;
use Condixia\AISiteToWordPress\Editor\EditorOverrideRenderer;
use Condixia\AISiteToWordPress\Integration\WooCommerce\WooCommerceSingleProductRenderer;
use Condixia\AISiteToWordPress\Integration\WooCommerce\WooCommerceStandaloneAssets;
use Condixia\AISiteToWordPress\Media\MediaDocumentRenderer;
use Condixia\AISiteToWordPress\Navigation\NavigationDocumentRenderer;
use Condixia\AISiteToWordPress\Queries\DynamicGridRenderer;
use Condixia\AISiteToWordPress\Routing\PageRouteMapRepository;
use Condixia\AISiteToWordPress\Storage\AssetRepository;
use Condixia\AISiteToWordPress\Storage\SourceFileRepository;

defined('ABSPATH') || exit;

final class StandaloneDocumentRenderer
{
    private SourceFileRepository $sources;
    private AssetRepository $assets;
    private PageRouteMapRepository $routes;
    private HtmlUrlRewriter $urlRewriter;
    private DynamicGridRenderer $grids;
    private SitePartConditionRenderer $siteParts;
    private DynamicBindingDocumentRenderer $bindings;
    private WooCommerceSingleProductRenderer $wooSingleProduct;
    private MediaDocumentRenderer $media;
    private EditorDocumentBridge $editor;
    private EditorOverrideRenderer $overrides;
    private NavigationDocumentRenderer $navigation;

    public function __construct(
        ?SourceFileRepository $sources = null,
        ?AssetRepository $assets = null,
        ?PageRouteMapRepository $routes = null,
        ?HtmlUrlRewriter $urlRewriter = null,
        ?DynamicGridRenderer $grids = null,
        ?SitePartConditionRenderer $siteParts = null,
        ?DynamicBindingDocumentRenderer $bindings = null,
        ?WooCommerceSingleProductRenderer $wooSingleProduct = null,
        ?MediaDocumentRenderer $media = null,
        ?EditorDocumentBridge $editor = null,
        ?EditorOverrideRenderer $overrides = null,
        ?NavigationDocumentRenderer $navigation = null
    ) {
        $this->sources = $sources
            ?? new SourceFileRepository();

        $this->assets = $assets
            ?? new AssetRepository();

        $this->routes = $routes
            ?? new PageRouteMapRepository();

        $this->urlRewriter = $urlRewriter
            ?? new HtmlUrlRewriter();

        $this->grids = $grids
            ?? new DynamicGridRenderer();

        $this->siteParts = $siteParts
            ?? new SitePartConditionRenderer();

        $this->bindings = $bindings
            ?? new DynamicBindingDocumentRenderer();

        $this->wooSingleProduct = $wooSingleProduct
            ?? new WooCommerceSingleProductRenderer();

        $this->media = $media
            ?? new MediaDocumentRenderer();

        $this->editor = $editor
            ?? new EditorDocumentBridge();

        $this->overrides = $overrides
            ?? new EditorOverrideRenderer();

        $this->navigation = $navigation
            ?? new NavigationDocumentRenderer();
    }

    public function render(
        array $resolved
    ): string|\WP_Error {
        if (
            empty($resolved['site_id'])
            || empty($resolved['manifest_id'])
            || empty($resolved['source_path'])
        ) {
            return $this->error();
        }

        $siteId = (int) $resolved['site_id'];

        $manifestId = (int) $resolved['manifest_id'];

        $sourcePath = (string) $resolved['source_path'];

        $html = $this->sources->readHtml(
            $siteId,
            $manifestId,
            $sourcePath
        );

        if (is_wp_error($html)) {
            return $html;
        }

        $artifactId = isset($resolved['artifact_id'])
            ? (int) $resolved['artifact_id']
            : 0;

        $hasOverrides = $this->overrides->hasOverrides(
            $siteId,
            $artifactId
        );

        $needsIdentities = $hasOverrides
            || $this->editor->requiresEditorIdentities();

        $html = $this->editor->prepareSource(
            $sourcePath,
            $html,
            $needsIdentities
        );

        if (is_wp_error($html)) {
            return $html;
        }

        $html = $this->grids->render(
            $siteId,
            $sourcePath,
            $html,
            $resolved
        );

        if (is_wp_error($html)) {
            return $html;
        }

        $html = $this->siteParts->render(
            $siteId,
            $sourcePath,
            $html
        );

        if (is_wp_error($html)) {
            return $html;
        }

        $html = $this->bindings->render(
            $siteId,
            isset($resolved['artifact_id'])
                ? (int) $resolved['artifact_id']
                : 0,
            $sourcePath,
            $html,
            $resolved
        );

        if (is_wp_error($html)) {
            return $html;
        }

        $html = $this->wooSingleProduct->render(
            $sourcePath,
            $html,
            $resolved
        );

        if (is_wp_error($html)) {
            return $html;
        }

        $html = $this->navigation->render(
            $siteId,
            $sourcePath,
            $html
        );

        if (is_wp_error($html)) {
            return $html;
        }

        $assetContext = $this->assets
            ->contextForSite(
                $siteId
            );

        if (is_wp_error($assetContext)) {
            return $assetContext;
        }

        $routeMap = $this->routes
            ->mapForSite(
                $siteId
            );

        $rewritten = $this->urlRewriter
            ->rewrite(
                $sourcePath,
                $html,
                $assetContext['asset_map'],
                $assetContext['file_index'],
                $routeMap
            );

        if (is_wp_error($rewritten)) {
            return $rewritten;
        }

        $html = $this->media->render(
            (string) $rewritten['html'],
            is_array(
                $assetContext['media_map']
                    ?? null
            )
                ? $assetContext['media_map']
                : []
        );

        if (is_wp_error($html)) {
            return $html;
        }

        $html = WooCommerceStandaloneAssets::inject(
            $html
        );

        if ($needsIdentities) {
            $html = $this->editor->normalizeIdentities($html);

            if (is_wp_error($html)) {
                return $html;
            }
        }

        if ($hasOverrides) {
            $html = $this->overrides->render(
                $siteId,
                $artifactId,
                $html
            );

            if (is_wp_error($html)) {
                return $html;
            }
        }

        return $this->editor->finalize(
            $html,
            $resolved
        );
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_standalone_render',
            __(
                'The Condixia document could not be rendered.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
