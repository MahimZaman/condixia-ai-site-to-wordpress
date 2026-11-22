<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Security;

defined('ABSPATH') || exit;

final class Capabilities
{
    public const MANAGE = 'manage_condixia_ai_stw';
    public const IMPORT = 'import_condixia_ai_stw_sites';
    public const EDIT = 'edit_condixia_ai_stw_sites';
    public const DELETE = 'delete_condixia_ai_stw_sites';
    public const MANAGE_FILES = 'manage_condixia_ai_stw_files';

    public static function all(): array
    {
        return [
            self::MANAGE,
            self::IMPORT,
            self::EDIT,
            self::DELETE,
            self::MANAGE_FILES,
        ];
    }
}
