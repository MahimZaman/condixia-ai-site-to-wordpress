<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Admin;

use Condixia\AISiteToWordPress\Security\Capabilities;

defined('ABSPATH') || exit;

final class AdminMenu
{
    public const SITES_SLUG = 'condixia-ai-site-to-wordpress';
    public const IMPORT_SLUG = 'condixia-ai-site-to-wordpress-import';
    public const TEMPLATES_SLUG = 'condixia-ai-site-to-wordpress-templates';
    public const SITE_PARTS_SLUG = 'condixia-ai-site-to-wordpress-site-parts';
    public const SETTINGS_SLUG = 'condixia-ai-site-to-wordpress-settings';
    public const CONFIRM_SLUG = 'condixia-ai-site-to-wordpress-confirm';

    private PageRenderer $renderer;
    private array $hookSuffixes = [];

    public function __construct(PageRenderer $renderer)
    {
        $this->renderer = $renderer;
    }

    public function register(): void
    {
        $this->addHook(
            add_menu_page(
                __(
                    'Condixia AI Site-to-WordPress',
                    'condixia-ai-site-to-wordpress'
                ),
                __('Condixia', 'condixia-ai-site-to-wordpress'),
                Capabilities::MANAGE,
                self::SITES_SLUG,
                [$this->renderer, 'sites'],
                'dashicons-admin-site-alt3',
                58
            )
        );

        $this->addHook(
            add_submenu_page(
                self::SITES_SLUG,
                __('Sites', 'condixia-ai-site-to-wordpress'),
                __('Sites', 'condixia-ai-site-to-wordpress'),
                Capabilities::MANAGE,
                self::SITES_SLUG,
                [$this->renderer, 'sites']
            )
        );

        $this->addHook(
            add_submenu_page(
                self::SITES_SLUG,
                __('Import Site', 'condixia-ai-site-to-wordpress'),
                __('Import Site', 'condixia-ai-site-to-wordpress'),
                Capabilities::IMPORT,
                self::IMPORT_SLUG,
                [$this->renderer, 'importSite']
            )
        );

        $this->addHook(
            add_submenu_page(
                self::SITES_SLUG,
                __('Templates', 'condixia-ai-site-to-wordpress'),
                __('Templates', 'condixia-ai-site-to-wordpress'),
                Capabilities::EDIT,
                self::TEMPLATES_SLUG,
                [$this->renderer, 'templates']
            )
        );

        $this->addHook(
            add_submenu_page(
                self::SITES_SLUG,
                __('Site Parts', 'condixia-ai-site-to-wordpress'),
                __('Site Parts', 'condixia-ai-site-to-wordpress'),
                Capabilities::EDIT,
                self::SITE_PARTS_SLUG,
                [$this->renderer, 'siteParts']
            )
        );

        $this->addHook(
            add_submenu_page(
                self::SITES_SLUG,
                __('Settings', 'condixia-ai-site-to-wordpress'),
                __('Settings', 'condixia-ai-site-to-wordpress'),
                Capabilities::MANAGE,
                self::SETTINGS_SLUG,
                [$this->renderer, 'settings']
            )
        );

        $this->addHook(
            add_submenu_page(
                null,
                __(
                    'Confirm Site Structure',
                    'condixia-ai-site-to-wordpress'
                ),
                __(
                    'Confirm Site Structure',
                    'condixia-ai-site-to-wordpress'
                ),
                Capabilities::IMPORT,
                self::CONFIRM_SLUG,
                [$this->renderer, 'confirmStructure']
            )
        );
    }

    public function isPluginScreen(string $hookSuffix): bool
    {
        return in_array(
            $hookSuffix,
            $this->hookSuffixes,
            true
        );
    }

    private function addHook(string|false $hookSuffix): void
    {
        if ($hookSuffix === false) {
            return;
        }

        $this->hookSuffixes[] = $hookSuffix;
    }
}
