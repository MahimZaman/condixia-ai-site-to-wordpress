<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Integration\WooCommerce;

defined('ABSPATH') || exit;

final class WooCommerceStandaloneAssets
{
    private static array $scripts = [];

    public static function requireScript(
        string $handle
    ): void {
        $handle = sanitize_key($handle);

        if ($handle === '') {
            return;
        }

        self::$scripts[$handle] = true;
    }

    public static function inject(
        string $html
    ): string {
        if (self::$scripts === []) {
            return $html;
        }

        $handles = array_keys(
            self::$scripts
        );

        self::$scripts = [];

        foreach ($handles as $handle) {
            if (wp_script_is($handle, 'registered')) {
                wp_enqueue_script($handle);
            }
        }

        ob_start();

        wp_print_scripts($handles);

        $scripts = ob_get_clean();

        if (
            ! is_string($scripts)
            || $scripts === ''
        ) {
            return $html;
        }

        $position = strripos(
            $html,
            '</body>'
        );

        if ($position === false) {
            return $html . $scripts;
        }

        return substr($html, 0, $position)
            . $scripts
            . substr($html, $position);
    }
}
