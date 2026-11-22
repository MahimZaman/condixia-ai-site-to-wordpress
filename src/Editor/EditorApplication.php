<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Editor;

use Condixia\AISiteToWordPress\Security\Capabilities;

defined('ABSPATH') || exit;

final class EditorApplication
{
    public const PAGE_SLUG = 'condixia-ai-site-to-wordpress-editor';

    private EditorPage $page;
    private EditorSaveController $saveController;
    private EditorArtifactPreview $artifactPreview;
    private string $hookSuffix = '';

    public function __construct(
        ?EditorPage $page = null,
        ?EditorSaveController $saveController = null,
        ?EditorArtifactPreview $artifactPreview = null
    ) {
        $this->page = $page
            ?? new EditorPage();

        $this->saveController = $saveController
            ?? new EditorSaveController();

        $this->artifactPreview = $artifactPreview
            ?? new EditorArtifactPreview();
    }

    public function register(): void
    {
        add_action(
            'admin_menu',
            [$this, 'registerPage'],
            99
        );

        add_action(
            'admin_enqueue_scripts',
            [$this, 'enqueue']
        );

        add_filter(
            'admin_body_class',
            [$this, 'editorBodyClass']
        );

        $this->saveController->register();
        $this->artifactPreview->register();
        (new NavigationManualController())->register();
        (new SitePartManualController())->register();
        (new DynamicGridManualController())->register();
    }

    public function registerPage(): void
    {
        $hookSuffix = add_submenu_page(
            null,
            __(
                'Condixia Visual Editor',
                'condixia-ai-site-to-wordpress'
            ),
            __(
                'Condixia Visual Editor',
                'condixia-ai-site-to-wordpress'
            ),
            Capabilities::EDIT,
            self::PAGE_SLUG,
            [$this->page, 'render']
        );

        if (is_string($hookSuffix)) {
            $this->hookSuffix = $hookSuffix;
        }
    }

    public function editorBodyClass(string $classes): string
    {
        $screen = get_current_screen();

        if (
            $this->hookSuffix !== ''
            && $screen instanceof \WP_Screen
            && $screen->id === $this->hookSuffix
        ) {
            return trim($classes . ' cdx-stw-editor-mode');
        }

        return $classes;
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

    public function enqueue(
        string $hookSuffix
    ): void {
        if (
            $this->hookSuffix === ''
            || $hookSuffix !== $this->hookSuffix
        ) {
            return;
        }

        $configuration = $this->page
            ->configuration();

        if (is_wp_error($configuration)) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'condixia-ai-stw-editor-shell',
            CONDIXIA_AI_STW_URL
                . 'assets/css/editor/editor-shell.css',
            [],
            $this->assetVersion('assets/css/editor/editor-shell.css')
        );

        wp_enqueue_script(
            'condixia-ai-stw-editor-navigator-model',
            CONDIXIA_AI_STW_URL
                . 'assets/js/editor/editor-navigator-model.js',
            [],
            $this->assetVersion('assets/js/editor/editor-navigator-model.js'),
            true
        );

        wp_enqueue_script(
            'condixia-ai-stw-editor-shell',
            CONDIXIA_AI_STW_URL
                . 'assets/js/editor/editor-shell.js',
            [
                'jquery',
                'jquery-ui-sortable',
                'condixia-ai-stw-editor-navigator-model',
            ],
            $this->assetVersion('assets/js/editor/editor-shell.js'),
            true
        );

        wp_enqueue_script(
            'condixia-ai-stw-editor-save',
            CONDIXIA_AI_STW_URL
                . 'assets/js/editor/editor-save.js',
            [
                'condixia-ai-stw-editor-shell',
            ],
            $this->assetVersion('assets/js/editor/editor-save.js'),
            true
        );

        $json = wp_json_encode(
            $configuration,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
        );

        if (! is_string($json)) {
            return;
        }

        wp_add_inline_script(
            'condixia-ai-stw-editor-shell',
            'window.CondixiaEditorConfig='
                . $json
                . ';',
            'before'
        );
    }
}
