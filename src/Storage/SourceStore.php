<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class SourceStore
{
    public function store(
        string $siteUuid,
        array $files
    ): array|\WP_Error {
        if (! $this->validUuid($siteUuid)) {
            return $this->error();
        }

        $root = $this->rootDirectory();

        if (is_wp_error($root)) {
            return $root;
        }

        $siteDirectory = $root
            . DIRECTORY_SEPARATOR
            . 'sites'
            . DIRECTORY_SEPARATOR
            . $siteUuid;

        if (
            ! wp_mkdir_p($siteDirectory)
            || ! is_dir($siteDirectory)
        ) {
            return $this->error();
        }

        $stored = [];

        foreach ($files as $file) {
            $pathHash = hash(
                'sha256',
                (string) $file['path']
            );

            $storageKey = 'sites/'
                . $siteUuid
                . '/raw/'
                . substr($pathHash, 0, 2)
                . '/'
                . $pathHash
                . '.cdxsrc';

            $destination = $root
                . DIRECTORY_SEPARATOR
                . str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $storageKey
                );

            $directory = dirname(
                $destination
            );

            if (
                ! wp_mkdir_p($directory)
                || ! is_dir($directory)
            ) {
                $this->deleteSite($siteUuid);

                return $this->error();
            }

            if (
                ! @copy(
                    $file['physical_path'],
                    $destination
                )
            ) {
                $this->deleteSite($siteUuid);

                return $this->error();
            }

            @chmod(
                $destination,
                0600
            );

            $checksum = hash_file(
                'sha256',
                $destination
            );

            if (
                ! is_string($checksum)
                || ! hash_equals(
                    (string) $file['checksum'],
                    $checksum
                )
            ) {
                $this->deleteSite($siteUuid);

                return $this->error();
            }

            $stored[$file['path']] = $storageKey;
        }

        return $stored;
    }

    public function read(
        string $storageKey
    ): string|\WP_Error {
        if (! $this->validStorageKey($storageKey)) {
            return $this->error();
        }

        $root = $this->rootDirectory();

        if (is_wp_error($root)) {
            return $root;
        }

        $path = $root
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $storageKey
            );

        if (
            is_link($path)
            || ! is_file($path)
            || ! is_readable($path)
        ) {
            return $this->error();
        }

        $resolvedRoot = realpath(
            $root
        );

        $resolvedPath = realpath(
            $path
        );

        if (
            $resolvedRoot === false
            || $resolvedPath === false
            || ! $this->inside(
                $resolvedRoot,
                $resolvedPath
            )
        ) {
            return $this->error();
        }

        $content = file_get_contents(
            $resolvedPath
        );

        if (! is_string($content)) {
            return $this->error();
        }

        return $content;
    }

    public function deleteSite(string $siteUuid): void
    {
        if (! $this->validUuid($siteUuid)) {
            return;
        }

        $root = $this->rootDirectory();

        if (is_wp_error($root)) {
            return;
        }

        $directory = $root
            . DIRECTORY_SEPARATOR
            . 'sites'
            . DIRECTORY_SEPARATOR
            . $siteUuid;

        if (is_link($directory)) {
            @unlink($directory);

            return;
        }

        if (! is_dir($directory)) {
            return;
        }

        $resolvedRoot = realpath(
            $root
        );

        $resolvedDirectory = realpath(
            $directory
        );

        if (
            $resolvedRoot === false
            || $resolvedDirectory === false
            || ! $this->inside(
                $resolvedRoot,
                $resolvedDirectory
            )
        ) {
            return;
        }

        $this->deleteTree(
            $resolvedDirectory
        );
    }

    private function rootDirectory(): string|\WP_Error
    {
        $uploads = wp_upload_dir(
            null,
            false
        );

        if (! empty($uploads['error'])) {
            return $this->error();
        }

        $root = rtrim(
            (string) $uploads['basedir'],
            '\\/'
        )
            . DIRECTORY_SEPARATOR
            . 'condixia-ai-site-to-wordpress';

        if (
            ! wp_mkdir_p($root)
            || ! is_dir($root)
            || ! is_writable($root)
        ) {
            return $this->error();
        }

        return $root;
    }

    private function deleteTree(string $directory): void
    {
        if (is_link($directory)) {
            @unlink($directory);

            return;
        }

        $entries = scandir(
            $directory
        );

        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (
                $entry === '.'
                || $entry === '..'
            ) {
                continue;
            }

            $path = $directory
                . DIRECTORY_SEPARATOR
                . $entry;

            if (
                is_link($path)
                || is_file($path)
            ) {
                @unlink($path);

                continue;
            }

            if (is_dir($path)) {
                $this->deleteTree(
                    $path
                );
            }
        }

        @rmdir(
            $directory
        );
    }

    private function inside(
        string $root,
        string $path
    ): bool {
        $root = rtrim(
            wp_normalize_path($root),
            '/'
        );

        $path = wp_normalize_path(
            $path
        );

        $prefix = $root . '/';

        return strncmp(
            $path,
            $prefix,
            strlen($prefix)
        ) === 0;
    }

    private function validStorageKey(
        string $storageKey
    ): bool {
        return preg_match(
            '#^sites/[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}/raw/[a-f0-9]{2}/[a-f0-9]{64}\.cdxsrc$#i',
            $storageKey
        ) === 1;
    }

    private function validUuid(string $uuid): bool
    {
        return preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
            $uuid
        ) === 1;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_source_store_failed',
            __('Condixia could not persist the validated source files.', 'condixia-ai-site-to-wordpress')
        );
    }
}
