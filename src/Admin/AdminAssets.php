<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Admin;

defined('ABSPATH') || exit;

final class AdminAssets
{
    private AdminMenu $menu;

    public function __construct(AdminMenu $menu)
    {
        $this->menu = $menu;
    }

    public function enqueue(string $hookSuffix): void
    {
        if (! $this->menu->isPluginScreen($hookSuffix)) {
            return;
        }

        wp_enqueue_style(
            'condixia-ai-stw-admin-shell',
            CONDIXIA_AI_STW_URL
                . 'assets/css/admin/admin-shell.css',
            [],
            CONDIXIA_AI_STW_VERSION
        );
    }
}
