<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Security;

defined('ABSPATH') || exit;

final class RestPermissions
{
    private RequestGuard $guard;

    public function __construct(?RequestGuard $guard = null)
    {
        $this->guard = $guard ?? new RequestGuard();
    }

    public function manage(
        \WP_REST_Request $request
    ): bool|\WP_Error {
        return $this->check(Capabilities::MANAGE);
    }

    public function importSites(
        \WP_REST_Request $request
    ): bool|\WP_Error {
        return $this->check(Capabilities::IMPORT);
    }

    public function editSites(
        \WP_REST_Request $request
    ): bool|\WP_Error {
        return $this->check(Capabilities::EDIT);
    }

    public function deleteSites(
        \WP_REST_Request $request
    ): bool|\WP_Error {
        return $this->check(Capabilities::DELETE);
    }

    public function manageFiles(
        \WP_REST_Request $request
    ): bool|\WP_Error {
        return $this->check(Capabilities::MANAGE_FILES);
    }

    private function check(
        string $capability
    ): bool|\WP_Error {
        $error = $this->guard->authorize($capability);

        if (is_wp_error($error)) {
            return $error;
        }

        return true;
    }
}
