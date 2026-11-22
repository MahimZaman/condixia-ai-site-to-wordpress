<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Routing;

use Condixia\AISiteToWordPress\Admin\AdminMenu;
use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Security\NonceActions;
use Condixia\AISiteToWordPress\Security\RequestGuard;

defined('ABSPATH') || exit;

final class PageCreationController
{
    private RequestGuard $guard;
    private PageCreationService $service;

    public function __construct(
        ?RequestGuard $guard = null,
        ?PageCreationService $service = null
    ) {
        $this->guard = $guard
            ?? new RequestGuard();

        $this->service = $service
            ?? new PageCreationService();
    }

    public function register(): void
    {
        add_action(
            'admin_post_condixia_ai_stw_create_pages',
            [$this, 'handle']
        );
    }

    public function handle(): void
    {
        $siteId = isset($_POST['site_id'])
            ? absint($_POST['site_id'])
            : 0;

        if ($siteId < 1) {
            $this->redirect(
                0,
                'pages_failed'
            );
        }

        $securityError = $this->guard
            ->guardAdminAction(
                $_POST,
                Capabilities::IMPORT,
                NonceActions::resource(
                    NonceActions::CREATE_PAGES,
                    $siteId
                )
            );

        if (is_wp_error($securityError)) {
            $this->redirect(
                $siteId,
                'security'
            );
        }

        $result = $this->service->create(
            $siteId
        );

        if (is_wp_error($result)) {
            set_transient(
                'condixia_ai_stw_structure_'
                    . get_current_user_id()
                    . '_'
                    . $siteId,
                $result->get_error_messages(),
                MINUTE_IN_SECONDS
            );

            $this->redirect(
                $siteId,
                'pages_failed'
            );
        }

        $this->redirect(
            $siteId,
            'pages_created'
        );
    }

    private function redirect(
        int $siteId,
        string $status
    ): never {
        if ($siteId < 1) {
            wp_safe_redirect(
                admin_url(
                    'admin.php?page='
                        . AdminMenu::SITES_SLUG
                )
            );

            exit;
        }

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
