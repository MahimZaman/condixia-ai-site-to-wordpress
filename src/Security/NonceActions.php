<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Security;

defined('ABSPATH') || exit;

final class NonceActions
{
    public const SETTINGS = 'condixia_ai_stw_settings';
    public const IMPORT = 'condixia_ai_stw_import';
    public const EDITOR_SAVE = 'condixia_ai_stw_editor_save';
    public const DELETE_SITE = 'condixia_ai_stw_delete_site';
    public const MANAGE_ARTIFACT = 'condixia_ai_stw_manage_artifact';
    public const FILE_OPERATION = 'condixia_ai_stw_file_operation';
    public const CONFIRM_STRUCTURE = 'condixia_ai_stw_confirm_structure';
    public const CREATE_PAGES = 'condixia_ai_stw_create_pages';

    public static function resource(
        string $action,
        int $resourceId
    ): string {
        if ($resourceId < 1) {
            return $action;
        }

        return $action . ':' . $resourceId;
    }
}
