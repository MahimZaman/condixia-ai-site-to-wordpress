<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Editor;

use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Security\NonceActions;

defined('ABSPATH') || exit;

final class EditorPage
{
    private EditorArtifactPreview $artifactPreview;

    public function __construct(?EditorArtifactPreview $artifactPreview = null)
    {
        $this->artifactPreview = $artifactPreview
            ?? new EditorArtifactPreview();
    }

    public function configuration(): array|\WP_Error
    {
        if (! current_user_can(Capabilities::EDIT)) {
            return $this->error(
                __(
                    'You do not have permission to use the Condixia visual editor.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $siteId = isset($_GET['site_id']) && is_scalar($_GET['site_id'])
            ? absint($_GET['site_id'])
            : 0;

        $artifactId = isset($_GET['artifact_id']) && is_scalar($_GET['artifact_id'])
            ? absint($_GET['artifact_id'])
            : 0;

        $target = ($siteId > 0 || $artifactId > 0)
            ? $this->artifactPreview->targetUrl($siteId, $artifactId)
            : $this->target();

        if (is_wp_error($target)) {
            return $target;
        }

        $preview = add_query_arg(
            EditorDocumentBridge::PREVIEW_QUERY,
            '1',
            $target
        );

        return [
            'siteId' => $siteId,
            'artifactId' => $artifactId,
            'targetUrl' => $target,
            'previewUrl' => $preview,
            'previewMessageOrigin' => 'null',
            'ajaxUrl' => admin_url(
                'admin-ajax.php'
            ),
            'nonce' => wp_create_nonce(
                NonceActions::EDITOR_SAVE
            ),
            'actions' => [
                'save'
                => EditorSaveController::SAVE_ACTION,
                'restoreElement'
                => EditorSaveController::RESTORE_ELEMENT_ACTION,
                'restoreOriginal'
                => EditorSaveController::RESTORE_ORIGINAL_ACTION,
                'revisions'
                => EditorSaveController::REVISIONS_ACTION,
                'restoreRevision'
                => EditorSaveController::RESTORE_REVISION_ACTION,
                'manualNavigation'
                => NavigationManualController::ACTION,
                'manualSitePart'
                => SitePartManualController::ACTION,
                'manualDynamicGrid'
                => DynamicGridManualController::ACTION,
            ],
            'mediaTitle' => __(
                'Choose an image',
                'condixia-ai-site-to-wordpress'
            ),
            'mediaButton' => __(
                'Use this image',
                'condixia-ai-site-to-wordpress'
            ),
            'readyText' => __(
                'Preview ready',
                'condixia-ai-site-to-wordpress'
            ),
            'changedText' => __(
                'Preview changed — not saved',
                'condixia-ai-site-to-wordpress'
            ),
            'selectText' => __(
                'Select an element in the preview.',
                'condixia-ai-site-to-wordpress'
            ),
            'savingText' => __(
                'Saving changes…',
                'condixia-ai-site-to-wordpress'
            ),
            'savedText' => __(
                'Changes saved.',
                'condixia-ai-site-to-wordpress'
            ),
            'saveErrorText' => __(
                'The changes could not be saved.',
                'condixia-ai-site-to-wordpress'
            ),
            'discardConfirmText' => __(
                'Discard all unsaved changes on this page?',
                'condixia-ai-site-to-wordpress'
            ),
            'restoreElementConfirmText' => __(
                'Restore this element to its imported/dynamic state? Unsaved page changes will also be discarded.',
                'condixia-ai-site-to-wordpress'
            ),
            'restoreOriginalConfirmText' => __(
                'Remove all saved visual overrides from this page/template and restore the imported state?',
                'condixia-ai-site-to-wordpress'
            ),
            'restoreRevisionConfirmText' => __(
                'Restore the selected saved revision?',
                'condixia-ai-site-to-wordpress'
            ),
        ];
    }

    public function render(): void
    {
        $configuration = $this
            ->configuration();

        if (is_wp_error($configuration)) {
            wp_die(
                esc_html(
                    $configuration
                        ->get_error_message()
                ),
                esc_html__(
                    'Condixia Visual Editor',
                    'condixia-ai-site-to-wordpress'
                ),
                [
                    'response' => 400,
                ]
            );
        }

        $targetUrl = (string) $configuration['targetUrl'];

        $previewUrl = (string) $configuration['previewUrl'];

        $view = dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR
            . 'views'
            . DIRECTORY_SEPARATOR
            . 'editor'
            . DIRECTORY_SEPARATOR
            . 'editor.php';

        if (
            ! is_file($view)
            || ! is_readable($view)
        ) {
            wp_die(
                esc_html__(
                    'The Condixia editor view is unavailable.',
                    'condixia-ai-site-to-wordpress'
                ),
                esc_html__(
                    'Condixia Visual Editor',
                    'condixia-ai-site-to-wordpress'
                ),
                [
                    'response' => 500,
                ]
            );
        }

        require $view;
    }

    private function target(): string|\WP_Error
    {
        if (
            ! isset($_GET['target'])
            || ! is_scalar($_GET['target'])
        ) {
            return $this->error(
                __(
                    'The editor target is missing.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $target = esc_url_raw(
            wp_unslash(
                (string) $_GET['target']
            )
        );

        if ($target === '') {
            return $this->error(
                __(
                    'The editor target is invalid.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $target = remove_query_arg(
            EditorDocumentBridge::PREVIEW_QUERY,
            $target
        );

        $targetParts = wp_parse_url(
            $target
        );

        $homeParts = wp_parse_url(
            home_url('/')
        );

        if (
            ! is_array($targetParts)
            || ! is_array($homeParts)
            || empty($targetParts['scheme'])
            || empty($targetParts['host'])
            || empty($homeParts['host'])
            || ! in_array(
                strtolower(
                    (string) $targetParts['scheme']
                ),
                [
                    'http',
                    'https',
                ],
                true
            )
            || strtolower(
                (string) $targetParts['host']
            ) !== strtolower(
                (string) $homeParts['host']
            )
            || (int) (
                $targetParts['port']
                ?? 0
            ) !== (int) (
                $homeParts['port']
                ?? 0
            )
            || isset($targetParts['user'])
            || isset($targetParts['pass'])
        ) {
            return $this->error(
                __(
                    'The visual editor can only open pages from this WordPress site.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        return $target;
    }

    private function error(
        string $message
    ): \WP_Error {
        return new \WP_Error(
            'condixia_ai_stw_editor',
            $message
        );
    }
}
