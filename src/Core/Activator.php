<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Core;

use Condixia\AISiteToWordPress\Security\CapabilityInstaller;
use Condixia\AISiteToWordPress\Storage\SchemaMigrator;

defined('ABSPATH') || exit;

final class Activator
{
    public static function activate(bool $networkWide = false): void
    {
        $requirements = new Requirements();

        if (! $requirements->isSatisfied()) {
            self::fail(
                $requirements->message(),
                $networkWide
            );
        }

        $migrationError = (new SchemaMigrator())->migrate();

        if (is_wp_error($migrationError)) {
            self::fail(
                $migrationError->get_error_message(),
                $networkWide
            );
        }

        $capabilityError = (new CapabilityInstaller())->install(true);

        if (is_wp_error($capabilityError)) {
            self::fail(
                $capabilityError->get_error_message(),
                $networkWide
            );
        }

        do_action(
            'condixia_ai_site_to_wordpress/activate',
            $networkWide
        );
    }

    private static function fail(
        string $message,
        bool $networkWide
    ): void {
        deactivate_plugins(
            CONDIXIA_AI_STW_BASENAME,
            true,
            $networkWide
        );

        wp_die(
            esc_html($message),
            esc_html__(
                'Condixia AI Site-to-WordPress Activation Error',
                'condixia-ai-site-to-wordpress'
            ),
            [
                'back_link' => true,
            ]
        );
    }
}
