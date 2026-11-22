<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Import;

use Condixia\AISiteToWordPress\Admin\AdminMenu;
use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Security\NonceActions;
use Condixia\AISiteToWordPress\Security\RequestGuard;
use Condixia\AISiteToWordPress\Storage\StructureConfirmationRepository;

defined('ABSPATH') || exit;

final class StructureConfirmationController
{
    private RequestGuard $guard;
    private StructureValidator $validator;
    private StructureConfirmationRepository $repository;

    public function __construct(
        ?RequestGuard $guard = null,
        ?StructureValidator $validator = null,
        ?StructureConfirmationRepository $repository = null
    ) {
        $this->guard = $guard
            ?? new RequestGuard();

        $this->validator = $validator
            ?? new StructureValidator();

        $this->repository = $repository
            ?? new StructureConfirmationRepository();
    }

    public function register(): void
    {
        add_action(
            'admin_post_condixia_ai_stw_confirm_structure',
            [$this, 'handle']
        );
    }

    public function handle(): void
    {
        $request = wp_unslash(
            $_POST
        );

        $siteId = isset($request['site_id'])
            ? absint($request['site_id'])
            : 0;

        if ($siteId < 1) {
            wp_safe_redirect(
                admin_url(
                    'admin.php?page='
                        . AdminMenu::SITES_SLUG
                )
            );

            exit;
        }

        $securityError = $this->guard
            ->guardAdminAction(
                $_POST,
                Capabilities::IMPORT,
                NonceActions::resource(
                    NonceActions::CONFIRM_STRUCTURE,
                    $siteId
                )
            );

        if (is_wp_error($securityError)) {
            $this->redirect(
                $siteId,
                'security'
            );
        }

        $structure = $this->repository->load(
            $siteId
        );

        if (is_wp_error($structure)) {
            $this->storeError(
                $siteId,
                $structure
            );

            $this->redirect(
                $siteId,
                'error'
            );
        }

        $validated = $this->validator->validate(
            $request,
            $structure
        );

        if (is_wp_error($validated)) {
            $this->storeError(
                $siteId,
                $validated
            );

            $this->redirect(
                $siteId,
                'conflict'
            );
        }

        $confirmed = $this->repository->confirm(
            $siteId,
            $validated
        );

        if (is_wp_error($confirmed)) {
            $this->storeError(
                $siteId,
                $confirmed
            );

            $this->redirect(
                $siteId,
                'error'
            );
        }

        $this->redirect(
            $siteId,
            'confirmed'
        );
    }

    private function storeError(
        int $siteId,
        \WP_Error $error
    ): void {
        set_transient(
            $this->errorKey($siteId),
            $error->get_error_messages(),
            MINUTE_IN_SECONDS
        );
    }

    private function errorKey(int $siteId): string
    {
        return 'condixia_ai_stw_structure_'
            . get_current_user_id()
            . '_'
            . $siteId;
    }

    private function redirect(
        int $siteId,
        string $status
    ): never {
        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => AdminMenu::CONFIRM_SLUG,
                    'site_id' => $siteId,
                    'condixia_structure' => $status,
                ],
                admin_url('admin.php')
            )
        );

        exit;
    }
}
