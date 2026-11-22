<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Security;

defined('ABSPATH') || exit;

final class RequestGuard
{
    public function authorize(string $capability): ?\WP_Error
    {
        if (! is_user_logged_in()) {
            return new \WP_Error(
                'condixia_ai_stw_authentication_required',
                __(
                    'You must be logged in to perform this action.',
                    'condixia-ai-site-to-wordpress'
                ),
                [
                    'status' => 401,
                ]
            );
        }

        if (! current_user_can($capability)) {
            return new \WP_Error(
                'condixia_ai_stw_forbidden',
                __(
                    'You do not have permission to perform this action.',
                    'condixia-ai-site-to-wordpress'
                ),
                [
                    'status' => 403,
                ]
            );
        }

        return null;
    }

    public function verifyNonce(
        string $nonce,
        string $action
    ): ?\WP_Error {
        if (
            $nonce === ''
            || wp_verify_nonce($nonce, $action) === false
        ) {
            return new \WP_Error(
                'condixia_ai_stw_invalid_nonce',
                __(
                    'The security token is missing, invalid, or expired.',
                    'condixia-ai-site-to-wordpress'
                ),
                [
                    'status' => 403,
                ]
            );
        }

        return null;
    }

    public function guardAdminAction(
        array $request,
        string $capability,
        string $nonceAction,
        string $nonceField = '_wpnonce'
    ): ?\WP_Error {
        return $this->guard(
            $request,
            $capability,
            $nonceAction,
            $nonceField
        );
    }

    public function guardAjaxAction(
        array $request,
        string $capability,
        string $nonceAction,
        string $nonceField = '_ajax_nonce'
    ): ?\WP_Error {
        return $this->guard(
            $request,
            $capability,
            $nonceAction,
            $nonceField
        );
    }

    private function guard(
        array $request,
        string $capability,
        string $nonceAction,
        string $nonceField
    ): ?\WP_Error {
        $authorizationError = $this->authorize($capability);

        if (is_wp_error($authorizationError)) {
            return $authorizationError;
        }

        if (
            ! isset($request[$nonceField])
            || ! is_scalar($request[$nonceField])
        ) {
            return new \WP_Error(
                'condixia_ai_stw_nonce_missing',
                __(
                    'The security token is missing.',
                    'condixia-ai-site-to-wordpress'
                ),
                [
                    'status' => 403,
                ]
            );
        }

        $nonce = sanitize_text_field(
            wp_unslash(
                (string) $request[$nonceField]
            )
        );

        return $this->verifyNonce(
            $nonce,
            $nonceAction
        );
    }
}
