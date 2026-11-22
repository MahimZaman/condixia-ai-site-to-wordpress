<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Admin;

use Condixia\AISiteToWordPress\Import\StagingStorage;
use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\SiteManagement\SiteManagementController;
use Condixia\AISiteToWordPress\SiteManagement\SiteManagementRepository;
use Condixia\AISiteToWordPress\Storage\StructureConfirmationRepository;

defined('ABSPATH') || exit;

final class PageRenderer
{
    private StructureConfirmationRepository $structureRepository;
    private SiteManagementRepository $siteManagement;

    public function __construct(
        ?StructureConfirmationRepository $structureRepository = null,
        ?SiteManagementRepository $siteManagement = null
    ) {
        $this->structureRepository = $structureRepository
            ?? new StructureConfirmationRepository();

        $this->siteManagement = $siteManagement
            ?? new SiteManagementRepository();
    }

    public function sites(): void
    {
        $sites = $this->siteManagement->all();

        if (is_wp_error($sites)) {
            wp_die(
                esc_html(
                    $sites->get_error_message()
                )
            );
        }

        $siteId = isset($_GET['site_id'])
            ? absint($_GET['site_id'])
            : 0;

        $selected = null;

        if ($siteId > 0) {
            $selected = $this->siteManagement
                ->find($siteId);

            if (is_wp_error($selected)) {
                wp_die(
                    esc_html(
                        $selected->get_error_message()
                    )
                );
            }
        }

        $this->render(
            'site-manager',
            Capabilities::MANAGE,
            [
                'sites' => $sites,
                'selected_site' => $selected,
                'notice' => $this->siteManagementNotice(),
            ]
        );
    }

    public function importSite(): void
    {
        $storage = new StagingStorage();
        $storage->cleanupExpired();

        $this->render(
            'import-site',
            Capabilities::IMPORT,
            [
                'maximum_upload_size' => wp_max_upload_size(),
                'notice' => $this->importNotice(),
            ]
        );
    }

    public function confirmStructure(): void
    {
        $siteId = isset($_GET['site_id'])
            ? absint($_GET['site_id'])
            : 0;

        if ($siteId < 1) {
            wp_die(
                esc_html__(
                    'A valid Condixia site is required.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $structure = $this->structureRepository->load(
            $siteId
        );

        if (is_wp_error($structure)) {
            wp_die(
                esc_html(
                    $structure->get_error_message()
                )
            );
        }

        $this->render(
            'confirm-structure',
            Capabilities::IMPORT,
            [
                'structure' => $structure,
                'notice' => $this->structureNotice(
                    $siteId
                ),
            ]
        );
    }

    public function templates(): void
    {
        $this->renderArtifactManagement('template', 'artifact-list');
    }

    public function siteParts(): void
    {
        $this->renderArtifactManagement('site_part', 'artifact-list');
    }

    private function renderArtifactManagement(string $kind, string $view): void
    {
        $siteId = isset($_GET['site_id']) && is_scalar($_GET['site_id'])
            ? absint($_GET['site_id'])
            : 0;

        $artifacts = $this->siteManagement->artifactsForManagement($kind, $siteId);

        if (is_wp_error($artifacts)) {
            wp_die(esc_html($artifacts->get_error_message()));
        }

        $this->render(
            $view,
            Capabilities::EDIT,
            [
                'kind' => $kind,
                'artifacts' => $artifacts,
                'site_id' => $siteId,
                'notice' => $this->siteManagementNotice(),
            ]
        );
    }

    public function settings(): void
    {
        $this->render(
            'settings',
            Capabilities::MANAGE
        );
    }

    private function render(
        string $view,
        string $capability,
        array $viewData = []
    ): void {
        if (! current_user_can($capability)) {
            wp_die(
                esc_html__(
                    'You do not have permission to access this Condixia screen.',
                    'condixia-ai-site-to-wordpress'
                ),
                esc_html__(
                    'Access denied',
                    'condixia-ai-site-to-wordpress'
                ),
                [
                    'response' => 403,
                ]
            );
        }

        $viewFile = CONDIXIA_AI_STW_PATH
            . 'views'
            . DIRECTORY_SEPARATOR
            . 'admin'
            . DIRECTORY_SEPARATOR
            . $view
            . '.php';

        if (! is_file($viewFile)) {
            wp_die(
                esc_html__(
                    'The requested Condixia admin screen is unavailable.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        require $viewFile;
    }

    private function siteManagementNotice(): ?array
    {
        $notice = get_transient(
            SiteManagementController::noticeKey()
        );

        if ($notice !== false) {
            delete_transient(
                SiteManagementController::noticeKey()
            );

            return is_array($notice)
                ? $notice
                : null;
        }

        $status = isset(
            $_GET['condixia_site_status']
        ) && is_scalar(
            $_GET['condixia_site_status']
        )
            ? sanitize_key(
                wp_unslash(
                    (string)
                    $_GET['condixia_site_status']
                )
            )
            : '';

        return match ($status) {
            'deleted' => [
                'type' => 'success',
                'message' => __(
                    'The imported Condixia site was deleted safely.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            default => null,
        };
    }


    private function structureNotice(
        int $siteId
    ): ?array {
        $status = isset(
            $_GET['condixia_structure']
        )
            ? sanitize_key(
                wp_unslash(
                    (string) $_GET['condixia_structure']
                )
            )
            : '';

        $errorKey = 'condixia_ai_stw_structure_'
            . get_current_user_id()
            . '_'
            . $siteId;

        $details = get_transient(
            $errorKey
        );

        if ($details !== false) {
            delete_transient(
                $errorKey
            );
        }

        return match ($status) {
            'confirmed' => [
                'type' => 'success',
                'message' => __(
                    'The import structure was confirmed successfully.',
                    'condixia-ai-site-to-wordpress'
                ),
                'details' => [],
            ],
            'pages_created' => [
                'type' => 'success',
                'message' => __(
                    'The confirmed WordPress Pages were created successfully.',
                    'condixia-ai-site-to-wordpress'
                ),
                'details' => [],
            ],
            'conflict' => [
                'type' => 'error',
                'message' => __(
                    'Resolve the critical structure conflict before continuing.',
                    'condixia-ai-site-to-wordpress'
                ),
                'details' => is_array($details)
                    ? $details
                    : [],
            ],
            'security' => [
                'type' => 'error',
                'message' => __(
                    'The structure request could not be verified.',
                    'condixia-ai-site-to-wordpress'
                ),
                'details' => [],
            ],
            'error',
            'pages_failed' => [
                'type' => 'error',
                'message' => __(
                    'Condixia could not complete the requested import operation.',
                    'condixia-ai-site-to-wordpress'
                ),
                'details' => is_array($details)
                    ? $details
                    : [],
            ],
            default => null,
        };
    }

    private function importNotice(): ?array
    {
        if (
            ! isset($_GET['condixia_upload'])
            || ! is_scalar($_GET['condixia_upload'])
        ) {
            return null;
        }

        $status = sanitize_key(
            wp_unslash(
                (string) $_GET['condixia_upload']
            )
        );

        return match ($status) {
            'analyzed' => [
                'type' => 'success',
                'message' => __(
                    'The site package was securely analyzed and its reusable source manifest was saved.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'security' => [
                'type' => 'error',
                'message' => __(
                    'The upload request could not be verified. Refresh the page and try again.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'no_file' => [
                'type' => 'error',
                'message' => __(
                    'Choose an HTML or ZIP site package to upload.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'empty' => [
                'type' => 'error',
                'message' => __(
                    'The selected site package is empty.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'oversized' => [
                'type' => 'error',
                'message' => __(
                    'The selected site package exceeds the server upload limit.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'unsupported' => [
                'type' => 'error',
                'message' => __(
                    'Only .html, .htm, and .zip site packages are supported.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'invalid_html' => [
                'type' => 'error',
                'message' => __(
                    'The selected HTML file does not appear to contain valid website markup.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'invalid_zip' => [
                'type' => 'error',
                'message' => __(
                    'The selected ZIP archive is invalid or corrupted.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'zip_support' => [
                'type' => 'error',
                'message' => __(
                    'This server is missing the ZIP support required for secure site extraction.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'unsafe_path' => [
                'type' => 'error',
                'message' => __(
                    'The ZIP archive contains an unsafe path that could escape its extraction directory.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'executable' => [
                'type' => 'error',
                'message' => __(
                    'The ZIP archive contains a forbidden server-executable or server-configuration file.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'special_entry' => [
                'type' => 'error',
                'message' => __(
                    'The ZIP archive contains a symbolic link or unsupported special filesystem entry.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'archive_limit' => [
                'type' => 'error',
                'message' => __(
                    'The ZIP archive exceeds Condixia safe extraction limits.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'path_conflict' => [
                'type' => 'error',
                'message' => __(
                    'The ZIP archive contains duplicate or conflicting filesystem paths.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'archive_empty' => [
                'type' => 'error',
                'message' => __(
                    'The ZIP archive does not contain importable files.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'staging_changed' => [
                'type' => 'error',
                'message' => __(
                    'The staged package changed after upload and was discarded.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'staging' => [
                'type' => 'error',
                'message' => __(
                    'Condixia could not create secure temporary storage for this upload.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'move_failed' => [
                'type' => 'error',
                'message' => __(
                    'The uploaded package could not be moved into temporary storage.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'extract_failed' => [
                'type' => 'error',
                'message' => __(
                    'The ZIP package could not be extracted safely and its temporary files were removed.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'dom_failed' => [
                'type' => 'error',
                'message' => __(
                    'Condixia could not safely build the HTML structural model. The original source was not modified and the temporary import was removed.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'analysis_failed' => [
                'type' => 'error',
                'message' => __(
                    'Condixia could not analyze the prepared website source. The temporary import was removed.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            'persistence_failed' => [
                'type' => 'error',
                'message' => __(
                    'Condixia could not save the analyzed site. Temporary and partial persistent data were cleaned up.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
            default => [
                'type' => 'error',
                'message' => __(
                    'The site package could not be prepared safely.',
                    'condixia-ai-site-to-wordpress'
                ),
            ],
        };
    }
}
