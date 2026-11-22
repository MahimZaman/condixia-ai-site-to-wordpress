<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Import;

use Condixia\AISiteToWordPress\Admin\AdminMenu;
use Condixia\AISiteToWordPress\Analysis\SiteAnalyzer;
use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Security\NonceActions;
use Condixia\AISiteToWordPress\Security\RequestGuard;
use Condixia\AISiteToWordPress\Storage\SiteAnalysisRepository;

defined('ABSPATH') || exit;

final class UploadController
{
    private RequestGuard $guard;
    private UploadValidator $validator;
    private StagingStorage $storage;
    private PackagePreparer $preparer;
    private SiteAnalyzer $analyzer;
    private SiteAnalysisRepository $repository;

    public function __construct(
        ?RequestGuard $guard = null,
        ?UploadValidator $validator = null,
        ?StagingStorage $storage = null,
        ?PackagePreparer $preparer = null,
        ?SiteAnalyzer $analyzer = null,
        ?SiteAnalysisRepository $repository = null
    ) {
        $this->guard = $guard ?? new RequestGuard();
        $this->validator = $validator ?? new UploadValidator();
        $this->storage = $storage ?? new StagingStorage();

        $this->preparer = $preparer
            ?? new PackagePreparer(
                $this->storage
            );

        $this->analyzer = $analyzer
            ?? new SiteAnalyzer();

        $this->repository = $repository
            ?? new SiteAnalysisRepository();
    }

    public function register(): void
    {
        add_action(
            'admin_post_condixia_ai_stw_upload_site',
            [$this, 'handle']
        );
    }

    public function handle(): void
    {
        $securityError = $this->guard->guardAdminAction(
            $_POST,
            Capabilities::IMPORT,
            NonceActions::IMPORT
        );

        if (is_wp_error($securityError)) {
            $this->redirect('security');

            return;
        }

        if (
            ! isset($_FILES['site_package'])
            || ! is_array($_FILES['site_package'])
        ) {
            $this->redirect('no_file');

            return;
        }

        $validation = $this->validator->validate(
            $_FILES['site_package']
        );

        if (is_wp_error($validation)) {
            $this->redirect(
                $this->statusForError($validation)
            );

            return;
        }

        $staged = $this->storage->stage(
            $_FILES['site_package'],
            $validation
        );

        if (is_wp_error($staged)) {
            $this->redirect(
                $this->statusForError($staged)
            );

            return;
        }

        $token = (string) $staged['token'];

        $prepared = $this->preparer->prepare(
            $token
        );

        if (is_wp_error($prepared)) {
            $status = $this->statusForError(
                $prepared
            );

            $this->storage->discard(
                $token
            );

            $this->redirect(
                $status
            );

            return;
        }

        $analysis = $this->analyzer->analyze(
            $prepared
        );

        if (is_wp_error($analysis)) {
            $errorCode = $analysis->get_error_code();

            $this->storage->discard(
                $token
            );

            $this->redirect(
                str_starts_with(
                    $errorCode,
                    'condixia_ai_stw_dom_'
                )
                    ? 'dom_failed'
                    : 'analysis_failed'
            );

            return;
        }

        $persisted = $this->repository->persist(
            $analysis,
            $prepared
        );

        if (is_wp_error($persisted)) {
            $this->storage->discard(
                $token
            );

            $this->redirect(
                'persistence_failed'
            );

            return;
        }

        $this->storage->discard(
            $token
        );

        $this->redirect(
            'analyzed',
            (int) $persisted['site_id']
        );
    }

    private function statusForError(
        \WP_Error $error
    ): string {
        return match ($error->get_error_code()) {
            'condixia_ai_stw_no_file' => 'no_file',
            'condixia_ai_stw_empty_upload' => 'empty',
            'condixia_ai_stw_oversized' => 'oversized',
            'condixia_ai_stw_unsupported_type' => 'unsupported',
            'condixia_ai_stw_invalid_html' => 'invalid_html',
            'condixia_ai_stw_invalid_zip' => 'invalid_zip',
            'condixia_ai_stw_staging_unavailable',
            'condixia_ai_stw_staging_metadata' => 'staging',
            'condixia_ai_stw_move_failed' => 'move_failed',
            'condixia_ai_stw_zip_support' => 'zip_support',
            'condixia_ai_stw_archive_unsafe_path' => 'unsafe_path',
            'condixia_ai_stw_archive_executable' => 'executable',
            'condixia_ai_stw_archive_special_entry',
            'condixia_ai_stw_extract_symlink' => 'special_entry',
            'condixia_ai_stw_archive_limit',
            'condixia_ai_stw_archive_ratio' => 'archive_limit',
            'condixia_ai_stw_archive_path_conflict' => 'path_conflict',
            'condixia_ai_stw_archive_empty' => 'archive_empty',
            'condixia_ai_stw_staging_changed' => 'staging_changed',
            'condixia_ai_stw_archive_open',
            'condixia_ai_stw_archive_invalid_entry',
            'condixia_ai_stw_extract_destination',
            'condixia_ai_stw_extract_conflict',
            'condixia_ai_stw_extract_stream',
            'condixia_ai_stw_extract_write',
            'condixia_ai_stw_extract_read',
            'condixia_ai_stw_extract_size',
            'condixia_ai_stw_extract_directory',
            'condixia_ai_stw_extract_escape',
            'condixia_ai_stw_extract_failed' => 'extract_failed',
            default => 'failed',
        };
    }

    private function redirect(
        string $status,
        int $siteId = 0
    ): void {
        $arguments = [
            'page' => AdminMenu::IMPORT_SLUG,
            'condixia_upload' => $status,
        ];

        if (
            $status === 'analyzed'
            && $siteId > 0
        ) {
            $arguments['site_id'] = $siteId;
        }

        if (
            $status === 'analyzed'
            && $siteId > 0
        ) {
            $arguments = [
                'page' => AdminMenu::CONFIRM_SLUG,
                'site_id' => $siteId,
            ];
        }

        $url = add_query_arg(
            $arguments,
            admin_url('admin.php')
        );

        wp_safe_redirect($url);

        exit;
    }
}
