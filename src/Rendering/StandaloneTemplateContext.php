<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Rendering;

defined('ABSPATH') || exit;

final class StandaloneTemplateContext
{
    private static ?string $html = null;

    public static function set(
        string $html
    ): void {
        self::$html = $html;
    }

    public static function take(): ?string
    {
        $html = self::$html;

        self::$html = null;

        return $html;
    }
}
