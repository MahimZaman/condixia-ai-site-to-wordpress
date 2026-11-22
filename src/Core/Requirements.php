<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Core;

defined('ABSPATH') || exit;

final class Requirements
{
    public function isSatisfied(): bool
    {
        return $this->errors() === [];
    }

    public function errors(): array
    {
        $errors = [];

        if (
            version_compare(
                PHP_VERSION,
                CONDIXIA_AI_STW_MIN_PHP_VERSION,
                '<'
            )
        ) {
            $errors[] = sprintf(
                __(
                    'Condixia AI Site-to-WordPress requires PHP %1$s or newer. Current version: %2$s.',
                    'condixia-ai-site-to-wordpress'
                ),
                CONDIXIA_AI_STW_MIN_PHP_VERSION,
                PHP_VERSION
            );
        }

        $wordpressVersion = $this->wordpressVersion();

        if (
            version_compare(
                $wordpressVersion,
                CONDIXIA_AI_STW_MIN_WP_VERSION,
                '<'
            )
        ) {
            $errors[] = sprintf(
                __(
                    'Condixia AI Site-to-WordPress requires WordPress %1$s or newer. Current version: %2$s.',
                    'condixia-ai-site-to-wordpress'
                ),
                CONDIXIA_AI_STW_MIN_WP_VERSION,
                $wordpressVersion
            );
        }

        return $errors;
    }

    public function message(): string
    {
        return implode(' ', $this->errors());
    }

    public function registerAdminNotice(): void
    {
        add_action(
            'admin_notices',
            [$this, 'renderAdminNotice']
        );

        add_action(
            'network_admin_notices',
            [$this, 'renderAdminNotice']
        );
    }

    public function renderAdminNotice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        $message = $this->message();

        if ($message === '') {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html($message);
        echo '</p></div>';
    }

    private function wordpressVersion(): string
    {
        global $wp_version;

        if (
            isset($wp_version)
            && is_string($wp_version)
            && $wp_version !== ''
        ) {
            return $wp_version;
        }

        return (string) get_bloginfo('version');
    }
}
