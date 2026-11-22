<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Core;

use Condixia\AISiteToWordPress\Plugin;
use Condixia\AISiteToWordPress\Security\CapabilityInstaller;
use Condixia\AISiteToWordPress\Storage\SchemaMigrator;

defined('ABSPATH') || exit;

final class Bootstrap
{
    private static bool $booted = false;
    private static string $startupError = '';

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        $requirements = new Requirements();

        if (! $requirements->isSatisfied()) {
            $requirements->registerAdminNotice();

            return;
        }

        $migrationError = (new SchemaMigrator())->migrate();

        if (is_wp_error($migrationError)) {
            self::fail(
                $migrationError->get_error_message()
            );

            return;
        }

        $capabilityError = (new CapabilityInstaller())->install();

        if (is_wp_error($capabilityError)) {
            self::fail(
                $capabilityError->get_error_message()
            );

            return;
        }

        $plugin = new Plugin();
        $plugin->boot();
    }

    public static function renderStartupNotice(): void
    {
        if (
            self::$startupError === ''
            || ! current_user_can('activate_plugins')
        ) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html(self::$startupError);
        echo '</p></div>';
    }

    private static function fail(string $message): void
    {
        self::$startupError = $message;

        add_action(
            'admin_notices',
            [self::class, 'renderStartupNotice']
        );

        add_action(
            'network_admin_notices',
            [self::class, 'renderStartupNotice']
        );
    }
}
