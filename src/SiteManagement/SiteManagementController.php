<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\SiteManagement;

use Condixia\AISiteToWordPress\Admin\AdminMenu;
use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Security\NonceActions;
use Condixia\AISiteToWordPress\Security\RequestGuard;

defined('ABSPATH') || exit;

final class SiteManagementController
{
    public const DELETE_ACTION
    = 'condixia_ai_stw_delete_site';

    public const EXPORT_ACTION
    = 'condixia_ai_stw_export_site';

    public const ARTIFACT_ACTION
    = 'condixia_ai_stw_change_artifact';

    private RequestGuard $guard;
    private SiteDeletionService $deletion;
        private SiteExportService $export;
    private ArtifactManagementRepository $artifacts;

    public function __construct(
        ?RequestGuard $guard = null,
        ?SiteDeletionService $deletion = null,
        ?SiteExportService $export = null,
        ?ArtifactManagementRepository $artifacts = null
    ) {
        $this->guard = $guard
            ?? new RequestGuard();

        $this->deletion = $deletion
            ?? new SiteDeletionService();

        $this->export = $export
            ?? new SiteExportService();

        $this->artifacts = $artifacts
            ?? new ArtifactManagementRepository();
    }

    public function register(): void
    {
        add_action(
            'admin_post_'
                . self::DELETE_ACTION,
            [$this, 'delete']
        );

        add_action(
            'admin_post_'
                . self::EXPORT_ACTION,
            [$this, 'export']
        );

        add_action(
            'admin_post_' . self::ARTIFACT_ACTION,
            [$this, 'changeArtifact']
        );
    }

    public function delete(): void
    {
        $siteId = absint(
            $_POST['site_id']
                ?? 0
        );

        $security = $this->guard
            ->guardAdminAction(
                $_POST,
                Capabilities::DELETE,
                NonceActions::resource(
                    NonceActions::DELETE_SITE,
                    $siteId
                )
            );

        if (is_wp_error($security)) {
            $this->redirectError(
                'delete_failed',
                $security
            );

            return;
        }

        if (
            empty($_POST['confirm_delete'])
        ) {
            $this->redirectError(
                'delete_failed',
                $this->error(
                    __(
                        'Confirm permanent site deletion before continuing.',
                        'condixia-ai-site-to-wordpress'
                    )
                ),
                $siteId
            );

            return;
        }

        $deleted = $this->deletion
            ->delete(
                $siteId
            );

        if (is_wp_error($deleted)) {
            $this->redirectError(
                'delete_failed',
                $deleted,
                $siteId
            );

            return;
        }

        $this->redirect(
            'deleted'
        );
    }

        public function changeArtifact(): void
    {
        $siteId = isset($_POST['site_id']) && is_scalar($_POST['site_id'])
            ? absint($_POST['site_id'])
            : 0;
        $artifactId = isset($_POST['artifact_id']) && is_scalar($_POST['artifact_id'])
            ? absint($_POST['artifact_id'])
            : 0;
        $kind = isset($_POST['artifact_kind']) && is_string($_POST['artifact_kind'])
            ? sanitize_key(wp_unslash($_POST['artifact_kind']))
            : '';
        $operation = isset($_POST['artifact_operation']) && is_string($_POST['artifact_operation'])
            ? sanitize_key(wp_unslash($_POST['artifact_operation']))
            : '';

        $security = $this->guard->guardAdminAction(
            $_POST,
            Capabilities::DELETE,
            NonceActions::resource(NonceActions::MANAGE_ARTIFACT, $artifactId)
        );

        if (is_wp_error($security)) {
            $this->artifactRedirect($kind, $siteId, $security);
        }

        if (
            $operation === 'trash'
            && (
                ! isset($_POST['confirm_trash'])
                || ! is_scalar($_POST['confirm_trash'])
                || (string) $_POST['confirm_trash'] !== '1'
            )
        ) {
            $this->artifactRedirect($kind, $siteId, $this->error());
        }

        $result = $this->artifacts->change($siteId, $artifactId, $kind, $operation);

        if (is_wp_error($result)) {
            $this->artifactRedirect($kind, $siteId, $result);
        }

        $this->artifactRedirect($kind, $siteId, null, $operation);
    }

    private function artifactRedirect(
        string $kind,
        int $siteId,
        ?\WP_Error $error,
        string $operation = ''
    ): never {
        set_transient(
            self::noticeKey(),
            [
                'type' => $error === null ? 'success' : 'error',
                'message' => $error !== null
                    ? $error->get_error_message()
                    : ($operation === 'trash'
                        ? __('Item moved to Trash. Original source files were preserved.', 'condixia-ai-site-to-wordpress')
                        : __('Item restored with its previous display assignments.', 'condixia-ai-site-to-wordpress')),
            ],
            MINUTE_IN_SECONDS
        );

        $page = $kind === 'site_part'
            ? AdminMenu::SITE_PARTS_SLUG
            : AdminMenu::TEMPLATES_SLUG;

        wp_safe_redirect(add_query_arg(
            ['page' => $page, 'site_id' => $siteId],
            admin_url('admin.php')
        ));
        exit;
    }

    public function export(): void
    {
        $siteId = absint(
            $_POST['site_id']
                ?? 0
        );

        $security = $this->guard
            ->guardAdminAction(
                $_POST,
                Capabilities::MANAGE_FILES,
                NonceActions::resource(
                    NonceActions::FILE_OPERATION,
                    $siteId
                )
            );

        if (is_wp_error($security)) {
            $this->redirectError(
                'export_failed',
                $security,
                $siteId
            );

            return;
        }

        $export = $this->export
            ->build(
                $siteId
            );

        if (is_wp_error($export)) {
            $this->redirectError(
                'export_failed',
                $export,
                $siteId
            );

            return;
        }

        $path = (string) (
            $export['path']
            ?? ''
        );

        $filename = (string) (
            $export['filename']
            ?? ''
        );

        if (
            $path === ''
            || ! is_file($path)
            || ! is_readable($path)
            || $filename === ''
        ) {
            if ($path !== '') {
                @unlink($path);
            }

            $this->redirectError(
                'export_failed',
                $this->error(),
                $siteId
            );

            return;
        }

        nocache_headers();

        header(
            'Content-Type: application/zip'
        );

        header(
            'X-Content-Type-Options: nosniff'
        );

        header(
            'Content-Disposition: attachment; filename="'
                . str_replace(
                    '"',
                    '',
                    $filename
                )
                . '"'
        );

        $size = filesize($path);

        if (
            is_int($size)
            && $size >= 0
        ) {
            header(
                'Content-Length: '
                    . $size
            );
        }

        readfile($path);

        @unlink($path);

        exit;
    }

    private function redirectError(
        string $status,
        \WP_Error $error,
        int $siteId = 0
    ): void {
        set_transient(
            self::noticeKey(),
            [
                'type' => 'error',
                'message'
                => $error
                    ->get_error_message(),
            ],
            MINUTE_IN_SECONDS
        );

        $this->redirect(
            $status,
            $siteId
        );
    }

    private function redirect(
        string $status,
        int $siteId = 0
    ): void {
        $arguments = [
            'page'
            => AdminMenu::SITES_SLUG,
            'condixia_site_status'
            => sanitize_key($status),
        ];

        if ($siteId > 0) {
            $arguments['site_id']
                = $siteId;
        }

        wp_safe_redirect(
            add_query_arg(
                $arguments,
                admin_url('admin.php')
            )
        );

        exit;
    }

    public static function noticeKey(): string
    {
        return 'condixia_ai_stw_site_management_'
            . get_current_user_id();
    }

    private function error(
        ?string $message = null
    ): \WP_Error {
        return new \WP_Error(
            'condixia_ai_stw_site_management_action',
            $message
                ?? __(
                    'The Condixia site-management operation could not be completed.',
                    'condixia-ai-site-to-wordpress'
                )
        );
    }
}
