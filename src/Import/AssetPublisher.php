<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Import;

use Condixia\AISiteToWordPress\Rendering\CssUrlRewriter;

defined('ABSPATH') || exit;

final class AssetPublisher
{
    private const PUBLISHABLE_TYPES = [
        'css',
        'js',
        'image',
        'font',
        'svg',
    ];

    private CssUrlRewriter $cssRewriter;

    public function __construct(
        ?CssUrlRewriter $cssRewriter = null
    ) {
        $this->cssRewriter = $cssRewriter
            ?? new CssUrlRewriter();
    }

    public function publish(
        string $siteUuid,
        array $files
    ): array|\WP_Error {
        if (! $this->validUuid($siteUuid)) {
            return $this->error();
        }

        $location = $this->location(
            $siteUuid
        );

        if (is_wp_error($location)) {
            return $location;
        }

        $fileIndex = [];
        $assetMap = [];
        $projection = [];

        foreach ($files as $file) {
            $path = isset($file['path'])
                ? (string) $file['path']
                : '';

            if (
                $path === ''
                || ! $this->safeRelativePath($path)
            ) {
                return $this->error();
            }

            $fileIndex[$path] = true;

            $type = (string) ($file['type'] ?? '');
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            $supportedOther = $type === 'other'
                && in_array(
                    $extension,
                    [
                        'json',
                        'webmanifest',
                        'mp4',
                        'webm',
                        'mp3',
                        'ogg',
                        'wav',
                        'pdf',
                    ],
                    true
                );

            if (
                ! in_array($type, self::PUBLISHABLE_TYPES, true)
                && ! $supportedOther
            ) {
                continue;
            }

            $publicKey = 'sites/'
                . $siteUuid
                . '/public/'
                . $path;

            $publicUrl = $location['base_url']
                . '/'
                . $this->encodeUrlPath($path);

            $assetMap[$path] = $publicUrl;

            $projection[$path] = [
                'public_key' => $publicKey,
                'public_url' => $publicUrl,
            ];
        }

        foreach ($files as $file) {
            $path = (string) $file['path'];

            if (! isset($projection[$path])) {
                continue;
            }

            $source = isset($file['physical_path'])
                ? (string) $file['physical_path']
                : '';

            if (
                $source === ''
                || ! is_file($source)
                || ! is_readable($source)
                || is_link($source)
            ) {
                return $this->error();
            }

            $destination = $location['directory']
                . DIRECTORY_SEPARATOR
                . str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $path
                );

            $directory = dirname(
                $destination
            );

            if (
                ! wp_mkdir_p($directory)
                || ! is_dir($directory)
            ) {
                return $this->error();
            }

            @chmod(
                $directory,
                0755
            );

            if ((string) $file['type'] === 'css') {
                $css = file_get_contents(
                    $source
                );

                if (! is_string($css)) {
                    return $this->error();
                }

                $css = $this->cssRewriter->rewrite(
                    $path,
                    $css,
                    $assetMap,
                    $fileIndex
                );

                $written = @file_put_contents(
                    $destination,
                    $css,
                    LOCK_EX
                );

                if ($written === false) {
                    return $this->error();
                }
            } elseif (
                ! @copy(
                    $source,
                    $destination
                )
            ) {
                return $this->error();
            }

            @chmod(
                $destination,
                0644
            );
        }

        ksort(
            $projection,
            SORT_STRING
        );

        return $projection;
    }

    private function location(
        string $siteUuid
    ): array|\WP_Error {
        $uploads = wp_upload_dir(
            null,
            false
        );

        if (
            ! empty($uploads['error'])
            || empty($uploads['basedir'])
            || empty($uploads['baseurl'])
        ) {
            return $this->error();
        }

        $directory = rtrim(
            (string) $uploads['basedir'],
            '\\/'
        )
            . DIRECTORY_SEPARATOR
            . 'condixia-ai-site-to-wordpress'
            . DIRECTORY_SEPARATOR
            . 'sites'
            . DIRECTORY_SEPARATOR
            . $siteUuid
            . DIRECTORY_SEPARATOR
            . 'public';

        if (
            ! wp_mkdir_p($directory)
            || ! is_dir($directory)
        ) {
            return $this->error();
        }

        @chmod(
            $directory,
            0755
        );

        return [
            'directory' => $directory,
            'base_url' => rtrim(
                (string) $uploads['baseurl'],
                '/'
            )
                . '/condixia-ai-site-to-wordpress/sites/'
                . rawurlencode($siteUuid)
                . '/public',
        ];
    }

    private function encodeUrlPath(
        string $path
    ): string {
        return implode(
            '/',
            array_map(
                'rawurlencode',
                explode('/', $path)
            )
        );
    }

    private function safeRelativePath(
        string $path
    ): bool {
        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('/^[A-Za-z]:/', $path) === 1
        ) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
            ) {
                return false;
            }
        }

        return true;
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
            'condixia_ai_stw_asset_publish_failed',
            __(
                'Condixia could not publish the validated frontend assets.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
