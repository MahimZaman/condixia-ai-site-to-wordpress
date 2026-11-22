<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Security;

defined('ABSPATH') || exit;

final class CapabilityInstaller
{
    public const VERSION = 1;
    public const VERSION_OPTION = 'condixia_ai_stw_capabilities_version';

    public function install(bool $force = false): ?\WP_Error
    {
        $installedVersion = (int) get_option(
            self::VERSION_OPTION,
            0
        );

        if ($installedVersion > self::VERSION) {
            return new \WP_Error(
                'condixia_ai_stw_capabilities_newer',
                __(
                    'The Condixia AI Site-to-WordPress capability configuration is newer than this plugin version supports.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        if (
            ! $force
            && $installedVersion === self::VERSION
        ) {
            return null;
        }

        $administrator = get_role('administrator');

        if (! $administrator instanceof \WP_Role) {
            return new \WP_Error(
                'condixia_ai_stw_administrator_role_missing',
                __(
                    'Condixia AI Site-to-WordPress could not locate the WordPress administrator role.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        foreach (Capabilities::all() as $capability) {
            $administrator->add_cap($capability, true);
        }

        foreach (Capabilities::all() as $capability) {
            if (! $administrator->has_cap($capability)) {
                return new \WP_Error(
                    'condixia_ai_stw_capability_install_failed',
                    __(
                        'Condixia AI Site-to-WordPress could not install its required administrator capabilities.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }
        }

        update_option(
            self::VERSION_OPTION,
            self::VERSION,
            false
        );

        if (
            (int) get_option(
                self::VERSION_OPTION,
                0
            ) !== self::VERSION
        ) {
            return new \WP_Error(
                'condixia_ai_stw_capability_version_write_failed',
                __(
                    'Condixia AI Site-to-WordPress could not save its capability configuration version.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        return null;
    }
}
