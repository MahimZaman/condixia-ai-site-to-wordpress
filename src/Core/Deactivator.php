<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Core;

defined('ABSPATH') || exit;

final class Deactivator
{
    public static function deactivate(
        bool $networkDeactivating = false
    ): void {
        do_action(
            'condixia_ai_site_to_wordpress/deactivate',
            $networkDeactivating
        );
    }
}
