<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Core;

defined('ABSPATH') || exit;

final class Autoloader
{
    private const PREFIX = 'Condixia\\AISiteToWordPress\\';

    private static $baseDirectory = '';

    public static function register(string $baseDirectory): void
    {
        if (self::$baseDirectory !== '') {
            return;
        }

        $resolvedBaseDirectory = realpath($baseDirectory);

        if ($resolvedBaseDirectory === false || ! is_dir($resolvedBaseDirectory)) {
            return;
        }

        self::$baseDirectory = rtrim(
            $resolvedBaseDirectory,
            '\\/'
        );

        spl_autoload_register([self::class, 'autoload']);
    }

    public static function autoload(string $class): void
    {
        if (self::$baseDirectory === '') {
            return;
        }

        $prefixLength = strlen(self::PREFIX);

        if (strncmp($class, self::PREFIX, $prefixLength) !== 0) {
            return;
        }

        $relativeClass = substr($class, $prefixLength);

        if (
            $relativeClass === ''
            || preg_match('/[^A-Za-z0-9_\\\\]/', $relativeClass) === 1
        ) {
            return;
        }

        $segments = explode('\\', $relativeClass);

        foreach ($segments as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
            ) {
                return;
            }
        }

        $relativePath = implode(
            DIRECTORY_SEPARATOR,
            $segments
        ) . '.php';

        $candidateFile = self::$baseDirectory
            . DIRECTORY_SEPARATOR
            . $relativePath;

        $resolvedFile = realpath($candidateFile);

        if ($resolvedFile === false || ! is_file($resolvedFile)) {
            return;
        }

        $basePrefix = self::$baseDirectory . DIRECTORY_SEPARATOR;
        $basePrefixLength = strlen($basePrefix);

        if (DIRECTORY_SEPARATOR === '\\') {
            $insideBaseDirectory = strncasecmp(
                $resolvedFile,
                $basePrefix,
                $basePrefixLength
            ) === 0;
        } else {
            $insideBaseDirectory = strncmp(
                $resolvedFile,
                $basePrefix,
                $basePrefixLength
            ) === 0;
        }

        if (! $insideBaseDirectory) {
            return;
        }

        require_once $resolvedFile;
    }
}
