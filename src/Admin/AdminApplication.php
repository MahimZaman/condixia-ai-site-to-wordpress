<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Admin;

use Condixia\AISiteToWordPress\Import\StructureConfirmationController;
use Condixia\AISiteToWordPress\Import\UploadController;
use Condixia\AISiteToWordPress\Routing\PageCreationController;
use Condixia\AISiteToWordPress\SiteManagement\SiteManagementController;

defined('ABSPATH') || exit;

final class AdminApplication
{
    private AdminMenu $menu;
    private AdminAssets $assets;
    private UploadController $uploadController;
    private StructureConfirmationController $structureController;
    private PageCreationController $pageCreationController;
    private SiteManagementController $siteManagementController;

    public function __construct()
    {
        $renderer = new PageRenderer();

        $this->menu = new AdminMenu($renderer);
        $this->assets = new AdminAssets($this->menu);
        $this->uploadController = new UploadController();
        $this->structureController = new StructureConfirmationController();
        $this->pageCreationController = new PageCreationController();
        $this->siteManagementController = new SiteManagementController();
    }

    public function register(): void
    {
        add_action(
            'admin_menu',
            [$this->menu, 'register']
        );

        add_action(
            'admin_enqueue_scripts',
            [$this->assets, 'enqueue']
        );

        $this->uploadController->register();
        $this->structureController->register();
        $this->pageCreationController->register();
        $this->siteManagementController->register();
    }
}
