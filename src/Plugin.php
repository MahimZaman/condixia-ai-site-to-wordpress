<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress;

use Condixia\AISiteToWordPress\Admin\AdminApplication;
use Condixia\AISiteToWordPress\Editor\EditorApplication;
use Condixia\AISiteToWordPress\Rendering\StandaloneRenderingEngine;

defined('ABSPATH') || exit;

final class Plugin
{
    public function boot(): void
    {
        if (is_admin()) {
            $admin = new AdminApplication();
            $admin->register();

            $editor = new EditorApplication();
            $editor->register();
        }

        if (! is_admin()) {
            $renderer = new StandaloneRenderingEngine();
            $renderer->register();
        }

        do_action(
            'condixia_ai_site_to_wordpress/boot',
            $this
        );
    }
}
