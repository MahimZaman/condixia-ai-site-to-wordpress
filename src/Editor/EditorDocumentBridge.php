<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Editor;

use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Storage\EditorStateRepository;
use Condixia\AISiteToWordPress\Navigation\RuntimeNavigationSupport;

defined('ABSPATH') || exit;

final class EditorDocumentBridge
{
    public const PREVIEW_QUERY
    = 'condixia_stw_editor_preview';

    private const EXCLUDED_TAGS = [
        'html',
        'head',
        'body',
        'base',
        'meta',
        'link',
        'script',
        'style',
        'title',
        'noscript',
        'template',
        'svg',
        'path',
    ];

    public function requiresEditorIdentities(): bool
    {
        return $this->isPreview() && $this->authorized();
    }

    public function prepareSource(
        string $sourcePath,
        string $html,
        bool $decorate = true
    ): string|\WP_Error {
        if (! $decorate) {
            return $html;
        }

        if (! class_exists(\DOMDocument::class)) {
            return $this->error();
        }

        $fullDocument = preg_match(
            '/(?:<!doctype\b|<html\b)/i',
            $html
        ) === 1;

        $document = $this->document(
            $html
        );

        if (is_wp_error($document)) {
            return $document;
        }

        $root = $document->documentElement;

        if (! $root instanceof \DOMElement) {
            return $this->error();
        }

        $this->decorate(
            $root,
            '/'
                . strtolower($root->tagName)
                . '[1]',
            str_replace(
                '\\',
                '/',
                $sourcePath
            )
        );

        return $this->serialize(
            $document,
            $fullDocument
        );
    }

    public function normalizeIdentities(
        string $html
    ): string|\WP_Error {
        if (
            ! class_exists(
                \DOMDocument::class
            )
        ) {
            return $this->error();
        }

        $fullDocument = preg_match(
            '/(?:<!doctype\b|<html\b)/i',
            $html
        ) === 1;

        $document = $this->document(
            $html
        );

        if (is_wp_error($document)) {
            return $document;
        }

        $groups = [];

        foreach (
            $document
                ->getElementsByTagName('*')
            as $element
        ) {
            if (
                ! $element
                    instanceof \DOMElement
            ) {
                continue;
            }

            $id = $element->getAttribute(
                'data-cdx-id'
            );

            if ($id !== '') {
                $groups[$id][] = $element;
            }
        }

        $hasDuplicates = false;

        foreach (
            $groups
            as $id => $elements
        ) {
            if (count($elements) < 2) {
                continue;
            }

            $hasDuplicates = true;

            foreach (
                $elements
                as $index => $element
            ) {
                $element->setAttribute(
                    'data-cdx-id',
                    $id
                        . '--'
                        . ($index + 1)
                );
            }
        }

        if (! $hasDuplicates) {
            return $html;
        }

        return $this->serialize(
            $document,
            $fullDocument
        );
    }

    public function finalize(
        string $html,
        array $resolved
    ): string {
        if ($this->isPreview()) {
            if (! $this->authorized()) {
                return $this
                    ->stripIdentities(
                        $html
                    );
            }

            return $this->injectPreviewBridge(
                $this->injectRuntimeSupport($html, $resolved, true),
                $resolved
            );
        }

        if (
            $this->authorized()
            && ! empty($resolved['site_id'])
        ) {
            $html = $this
                ->injectEntry(
                    $html
                );
        }

        return $this->injectRuntimeSupport(
            $this->stripIdentities($html),
            $resolved,
            false
        );
    }

    private function decorate(
        \DOMElement $element,
        string $path,
        string $sourcePath
    ): void {
        $tag = strtolower(
            $element->tagName
        );

        if (
            ! in_array(
                $tag,
                self::EXCLUDED_TAGS,
                true
            )
        ) {
            $element->setAttribute(
                'data-cdx-id',
                'cdx-stw-'
                    . substr(
                        hash(
                            'sha256',
                            $sourcePath
                                . "\0"
                                . $path
                        ),
                        0,
                        24
                    )
            );
        }

        $counts = [];

        foreach (
            $element->childNodes
            as $child
        ) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $childTag = strtolower(
                $child->tagName
            );

            $counts[$childTag] = (
                $counts[$childTag]
                ?? 0
            ) + 1;

            $this->decorate(
                $child,
                $path
                    . '/'
                    . $childTag
                    . '['
                    . $counts[$childTag]
                    . ']',
                $sourcePath
            );
        }
    }

    private function injectEntry(
        string $html
    ): string {
        $target = $this->currentUrl();

        if ($target === '') {
            return $html;
        }

        $editorUrl = add_query_arg(
            [
                'page' => EditorApplication::PAGE_SLUG,
                'target' => $target,
            ],
            admin_url('admin.php')
        );

        $entry = '<a href="'
            . esc_url($editorUrl)
            . '" style="position:fixed;right:18px;bottom:18px;z-index:2147483647;padding:10px 14px;border-radius:6px;background:#1d2327;color:#fff;font:600 13px/1.2 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;text-decoration:none;box-shadow:0 4px 18px rgba(0,0,0,.22)" aria-label="'
            . esc_attr__(
                'Edit this Condixia page',
                'condixia-ai-site-to-wordpress'
            )
            . '">'
            . esc_html__(
                'Edit Page',
                'condixia-ai-site-to-wordpress'
            )
            . '</a>';

        return $this->beforeClosingBody(
            $html,
            $entry
        );
    }

    private function assetVersion(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        $modified = is_file($path) ? filemtime($path) : false;

        return $modified === false
            ? CONDIXIA_AI_STW_VERSION
            : (string) $modified;
    }

    /**
     * Supports elements generated by browser JavaScript.
     * Does not modify the original imported HTML files.
     */
    private function injectRuntimeSupport(
        string $html,
        array $resolved,
        bool $preview
    ): string {
        $siteId = absint($resolved['site_id'] ?? 0);
        $artifactId = absint($resolved['artifact_id'] ?? 0);

        if ($siteId < 1 || $artifactId < 1) {
            return $html;
        }

        $rows = (new EditorStateRepository())->overrides(
            $siteId,
            $artifactId
        );

        $navigation = (new RuntimeNavigationSupport())->forSource(
            $siteId,
            absint($resolved['manifest_id'] ?? 0),
            (string) ($resolved['source_path'] ?? '')
        );

        if (! $preview && $rows === [] && $navigation === []) {
            return $html;
        }

        $safe = [];

        $allowed = [
            'text',
            'imagealt',
            'linkurl',
            'linktarget',
            'imagesrc',
            'backgroundimage',
            'color',
            'backgroundcolor',
            'fontsize',
            'fontweight',
            'textalign',
            'padding',
            'margin',
            'border',
            'borderradius',
        ];

        foreach (array_slice($rows, 0, 500) as $row) {
            if (
                ! is_array($row)
                || preg_match(
                    '/^cdx-stw-[a-f0-9]{24}$/D',
                    (string) ($row['element_id'] ?? '')
                ) !== 1
                || ! in_array(
                    (string) ($row['property_key'] ?? ''),
                    $allowed,
                    true
                )
            ) {
                continue;
            }

            $safe[] = [
                'element_id' => (string) $row['element_id'],
                'property_key' => (string) $row['property_key'],
                'value' => (string) ($row['value'] ?? ''),
            ];
        }

        $data = wp_json_encode(
            [
                'preview' => $preview,
                'parent_origin' => $preview ? $this->origin(admin_url('/')) : '',
                'navigation' => $navigation,
                'overrides' => $safe,
            ],
            JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
        );

        if (! is_string($data)) {
            return $html;
        }

        $url = CONDIXIA_AI_STW_URL
            . 'assets/js/editor/editor-runtime.js';

        $markup = '<script type="application/json" id="cdx-stw-runtime-data">'
            . $data
            . '</script>'
            . '<script src="'
            . esc_url($url)
            . '?ver='
            . rawurlencode(
                $this->assetVersion('assets/js/editor/editor-runtime.js')
            )
            . '"></script>';

        return $this->beforeClosingBody(
            $html,
            $markup
        );
    }

    private function injectPreviewBridge(
        string $html,
        array $resolved
    ): string {
        $styleUrl = CONDIXIA_AI_STW_URL
            . 'assets/css/editor/editor-preview.css';

        $scriptUrl = CONDIXIA_AI_STW_URL
            . 'assets/js/editor/editor-preview.js';

        $styleVersion = $this->assetVersion(
            'assets/css/editor/editor-preview.css'
        );

        $scriptVersion = $this->assetVersion(
            'assets/js/editor/editor-preview.js'
        );

        $parentOrigin = $this->origin(
            admin_url('/')
        );

        if ($parentOrigin === '') {
            return $html;
        }

        $style = '<link rel="stylesheet" href="'
            . esc_url($styleUrl)
            . '?ver='
            . rawurlencode(
                $styleVersion
            )
            . '">';

        $script = '<script src="'
            . esc_url($scriptUrl)
            . '?ver='
            . rawurlencode(
                $scriptVersion
            )
            . '" data-parent-origin="'
            . esc_attr($parentOrigin)
            . '" data-site-id="'
            . esc_attr(
                (string) absint(
                    $resolved['site_id']
                        ?? 0
                )
            )
            . '" data-artifact-id="'
            . esc_attr(
                (string) absint(
                    $resolved['artifact_id']
                        ?? 0
                )
            )
            . '"></script>';

        $headPosition = stripos(
            $html,
            '</head>'
        );

        if ($headPosition !== false) {
            $html = substr(
                $html,
                0,
                $headPosition
            )
                . $style
                . substr(
                    $html,
                    $headPosition
                );
        } else {
            $html = $style . $html;
        }

        return $this->beforeClosingBody(
            $html,
            $script
        );
    }

    private function stripIdentities(
        string $html
    ): string {
        $stripped = preg_replace(
            '~\sdata-cdx-id=(["\'])cdx-stw-[a-f0-9]{24}(?:--[1-9][0-9]*)?\1~i',
            '',
            $html
        );

        return is_string($stripped)
            ? $stripped
            : $html;
    }


    private function currentUrl(): string
    {
        if (
            ! isset($_SERVER['REQUEST_URI'])
            || ! is_string(
                $_SERVER['REQUEST_URI']
            )
        ) {
            return '';
        }

        $requestUri = wp_unslash(
            $_SERVER['REQUEST_URI']
        );

        if (
            $requestUri === ''
            || ! str_starts_with(
                $requestUri,
                '/'
            )
            || preg_match(
                '/[\x00-\x1F\x7F]/',
                $requestUri
            ) === 1
        ) {
            return '';
        }

        $origin = $this->origin(
            home_url('/')
        );

        if ($origin === '') {
            return '';
        }

        $url = esc_url_raw(
            $origin . $requestUri
        );

        if ($url === '') {
            return '';
        }

        return remove_query_arg(
            self::PREVIEW_QUERY,
            $url
        );
    }

    private function origin(
        string $url
    ): string {
        $parts = wp_parse_url(
            $url
        );

        if (
            ! is_array($parts)
            || empty($parts['scheme'])
            || empty($parts['host'])
        ) {
            return '';
        }

        $scheme = strtolower(
            (string) $parts['scheme']
        );

        if (
            ! in_array(
                $scheme,
                [
                    'http',
                    'https',
                ],
                true
            )
        ) {
            return '';
        }

        $origin = $scheme
            . '://'
            . strtolower(
                (string) $parts['host']
            );

        if (! empty($parts['port'])) {
            $origin .= ':'
                . absint($parts['port']);
        }

        return $origin;
    }

    private function authorized(): bool
    {
        return is_user_logged_in()
            && current_user_can(
                Capabilities::EDIT
            );
    }

    private function isPreview(): bool
    {
        return isset(
            $_GET[self::PREVIEW_QUERY]
        )
            && is_scalar(
                $_GET[self::PREVIEW_QUERY]
            )
            && sanitize_text_field(
                wp_unslash(
                    (string) $_GET[self::PREVIEW_QUERY]
                )
            ) === '1';
    }

    private function beforeClosingBody(
        string $html,
        string $markup
    ): string {
        $position = strripos(
            $html,
            '</body>'
        );

        if ($position === false) {
            return $html . $markup;
        }

        return substr(
            $html,
            0,
            $position
        )
            . $markup
            . substr(
                $html,
                $position
            );
    }

    private function document(
        string $html
    ): \DOMDocument|\WP_Error {
        $previous = libxml_use_internal_errors(
            true
        );

        libxml_clear_errors();

        $document = new \DOMDocument(
            '1.0',
            'UTF-8'
        );

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'
                . $html,
            LIBXML_NONET
                | LIBXML_COMPACT
                | LIBXML_NOERROR
                | LIBXML_NOWARNING
        );

        libxml_clear_errors();

        libxml_use_internal_errors(
            $previous
        );

        if (! $loaded) {
            return $this->error();
        }

        $node = $document->firstChild;

        while ($node !== null) {
            $next = $node->nextSibling;

            if (
                $node->nodeType
                === XML_PI_NODE
                && strtolower(
                    $node->nodeName
                ) === 'xml'
            ) {
                $document->removeChild(
                    $node
                );
            }

            $node = $next;
        }

        return $document;
    }

    private function serialize(
        \DOMDocument $document,
        bool $fullDocument
    ): string|\WP_Error {
        if ($fullDocument) {
            $output = $document->saveHTML();

            return is_string($output)
                ? $output
                : $this->error();
        }

        $body = $document
            ->getElementsByTagName('body')
            ->item(0);

        if (! $body instanceof \DOMElement) {
            return $this->error();
        }

        $output = '';

        foreach ($body->childNodes as $child) {
            $fragment = $document->saveHTML(
                $child
            );

            if (is_string($fragment)) {
                $output .= $fragment;
            }
        }

        return $output;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_editor_preview',
            __(
                'The Condixia editor preview could not be prepared.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
