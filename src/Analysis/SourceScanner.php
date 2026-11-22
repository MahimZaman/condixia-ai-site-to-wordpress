<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class SourceScanner
{
    public function scan(array $prepared): array|\WP_Error
    {
        $sourceType = isset($prepared['source_type'])
            ? (string) $prepared['source_type']
            : '';

        if ($sourceType === 'html') {
            return $this->scanSingleHtml($prepared);
        }

        if ($sourceType === 'zip') {
            return $this->scanDirectory($prepared);
        }

        return new \WP_Error(
            'condixia_ai_stw_analysis_source_type',
            __('The prepared source type cannot be analyzed.', 'condixia-ai-site-to-wordpress')
        );
    }

    private function scanSingleHtml(
        array $prepared
    ): array|\WP_Error {
        if (
            ! isset(
                $prepared['source_path'],
                $prepared['original_name']
            )
        ) {
            return $this->invalidSource();
        }

        $absolutePath = realpath(
            (string) $prepared['source_path']
        );

        if (
            $absolutePath === false
            || ! is_file($absolutePath)
            || ! is_readable($absolutePath)
            || is_link($absolutePath)
        ) {
            return $this->invalidSource();
        }

        $logicalPath = sanitize_file_name(
            (string) $prepared['original_name']
        );

        if ($logicalPath === '') {
            $logicalPath = 'index.html';
        }

        $file = $this->scanFile(
            $logicalPath,
            $absolutePath
        );

        if (is_wp_error($file)) {
            return $file;
        }

        return [$file];
    }

    private function scanDirectory(
        array $prepared
    ): array|\WP_Error {
        if (! isset($prepared['content_root'])) {
            return $this->invalidSource();
        }

        $root = realpath(
            (string) $prepared['content_root']
        );

        if (
            $root === false
            || ! is_dir($root)
            || is_link($root)
        ) {
            return $this->invalidSource();
        }

        $normalizedRoot = rtrim(
            wp_normalize_path($root),
            '/'
        );

        $paths = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root,
                    \FilesystemIterator::SKIP_DOTS
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isLink()) {
                    return new \WP_Error(
                        'condixia_ai_stw_analysis_symlink',
                        __('A symbolic link appeared inside the validated source.', 'condixia-ai-site-to-wordpress')
                    );
                }

                if (! $fileInfo->isFile()) {
                    continue;
                }

                $absolutePath = $fileInfo->getPathname();
                $normalizedPath = wp_normalize_path(
                    $absolutePath
                );

                $prefix = $normalizedRoot . '/';

                if (
                    strncmp(
                        $normalizedPath,
                        $prefix,
                        strlen($prefix)
                    ) !== 0
                ) {
                    return $this->invalidSource();
                }

                $relativePath = substr(
                    $normalizedPath,
                    strlen($prefix)
                );

                if ($relativePath === '') {
                    continue;
                }

                $paths[$relativePath] = $absolutePath;
            }
        } catch (\UnexpectedValueException) {
            return $this->invalidSource();
        }

        ksort(
            $paths,
            SORT_STRING
        );

        $files = [];

        foreach ($paths as $relativePath => $absolutePath) {
            $file = $this->scanFile(
                $relativePath,
                $absolutePath
            );

            if (is_wp_error($file)) {
                return $file;
            }

            $files[] = $file;
        }

        if ($files === []) {
            return new \WP_Error(
                'condixia_ai_stw_analysis_empty',
                __('The prepared website does not contain any readable files.', 'condixia-ai-site-to-wordpress')
            );
        }

        return $files;
    }

    private function scanFile(
        string $logicalPath,
        string $absolutePath
    ): array|\WP_Error {
        $size = filesize(
            $absolutePath
        );

        $checksum = hash_file(
            'sha256',
            $absolutePath
        );

        if (
            $size === false
            || ! is_string($checksum)
        ) {
            return new \WP_Error(
                'condixia_ai_stw_analysis_file',
                __('A source file could not be fingerprinted.', 'condixia-ai-site-to-wordpress')
            );
        }

        $type = $this->classify(
            $logicalPath
        );

        $fileType = wp_check_filetype(
            basename($logicalPath)
        );

        $mimeType = isset($fileType['type'])
            && is_string($fileType['type'])
            && $fileType['type'] !== ''
            ? $fileType['type']
            : $this->fallbackMime($type);

        return [
            'path' => str_replace(
                '\\',
                '/',
                $logicalPath
            ),
            'physical_path' => $absolutePath,
            'type' => $type,
            'mime_type' => $mimeType,
            'size' => (int) $size,
            'checksum' => $checksum,
        ];
    }

    private function classify(string $path): string
    {
        $extension = strtolower(
            pathinfo(
                $path,
                PATHINFO_EXTENSION
            )
        );

        return match ($extension) {
            'html', 'htm' => 'html',
            'css' => 'css',
            'js', 'mjs', 'cjs' => 'js',
            'svg' => 'svg',
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'avif', 'ico', 'bmp' => 'image',
            'woff', 'woff2', 'ttf', 'otf', 'eot' => 'font',
            default => 'other',
        };
    }

    private function fallbackMime(string $type): string
    {
        return match ($type) {
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'text/javascript',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    private function invalidSource(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_analysis_source',
            __('The prepared website source is missing or no longer safe to read.', 'condixia-ai-site-to-wordpress')
        );
    }
}
