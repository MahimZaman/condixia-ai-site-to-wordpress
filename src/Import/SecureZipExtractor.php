<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Import;

defined('ABSPATH') || exit;

final class SecureZipExtractor
{
    public function extract(
        \ZipArchive $archive,
        array $entries,
        string $destinationRoot
    ): true|\WP_Error {
        $root = realpath(
            $destinationRoot
        );

        if (
            $root === false
            || ! is_dir($root)
        ) {
            return $this->error(
                'condixia_ai_stw_extract_destination',
                __('The secure extraction directory is unavailable.', 'condixia-ai-site-to-wordpress')
            );
        }

        foreach ($entries as $entry) {
            if (
                ! isset(
                    $entry['index'],
                    $entry['path'],
                    $entry['directory'],
                    $entry['size']
                )
            ) {
                return $this->error(
                    'condixia_ai_stw_extract_failed',
                    __('The validated ZIP entry data is incomplete.', 'condixia-ai-site-to-wordpress')
                );
            }

            $relativePath = (string) $entry['path'];

            $destination = $root
                . DIRECTORY_SEPARATOR
                . str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relativePath
                );

            if ((bool) $entry['directory']) {
                $directoryResult = $this->ensureDirectory(
                    $root,
                    $destination
                );

                if (is_wp_error($directoryResult)) {
                    return $directoryResult;
                }

                continue;
            }

            $parent = dirname(
                $destination
            );

            $directoryResult = $this->ensureDirectory(
                $root,
                $parent
            );

            if (is_wp_error($directoryResult)) {
                return $directoryResult;
            }

            if (
                file_exists($destination)
                || is_link($destination)
            ) {
                return $this->error(
                    'condixia_ai_stw_extract_conflict',
                    __('A validated ZIP file would overwrite an existing staging path.', 'condixia-ai-site-to-wordpress')
                );
            }

            $source = $archive->getStreamIndex(
                (int) $entry['index']
            );

            if (! is_resource($source)) {
                return $this->error(
                    'condixia_ai_stw_extract_stream',
                    __('A ZIP entry could not be read. Encrypted or unsupported archive entries cannot be imported.', 'condixia-ai-site-to-wordpress')
                );
            }

            $target = @fopen(
                $destination,
                'xb'
            );

            if (! is_resource($target)) {
                fclose($source);

                return $this->error(
                    'condixia_ai_stw_extract_write',
                    __('A ZIP entry could not be created in secure temporary storage.', 'condixia-ai-site-to-wordpress')
                );
            }

            $copyResult = $this->copyStream(
                $source,
                $target,
                (int) $entry['size']
            );

            fclose($source);
            fclose($target);

            if (is_wp_error($copyResult)) {
                @unlink($destination);

                return $copyResult;
            }

            @chmod(
                $destination,
                0600
            );
        }

        return true;
    }

    private function copyStream(
        $source,
        $target,
        int $expectedSize
    ): true|\WP_Error {
        $written = 0;

        while (! feof($source)) {
            $chunk = fread(
                $source,
                1048576
            );

            if ($chunk === false) {
                return $this->error(
                    'condixia_ai_stw_extract_read',
                    __('A ZIP entry could not be read completely.', 'condixia-ai-site-to-wordpress')
                );
            }

            if ($chunk === '') {
                if (feof($source)) {
                    break;
                }

                continue;
            }

            $chunkLength = strlen(
                $chunk
            );

            $written += $chunkLength;

            if ($written > $expectedSize) {
                return $this->error(
                    'condixia_ai_stw_extract_size',
                    __('A ZIP entry expanded beyond its validated size.', 'condixia-ai-site-to-wordpress')
                );
            }

            $offset = 0;

            while ($offset < $chunkLength) {
                $result = fwrite(
                    $target,
                    substr(
                        $chunk,
                        $offset
                    )
                );

                if (
                    $result === false
                    || $result === 0
                ) {
                    return $this->error(
                        'condixia_ai_stw_extract_write',
                        __('A ZIP entry could not be written completely.', 'condixia-ai-site-to-wordpress')
                    );
                }

                $offset += $result;
            }
        }

        if ($written !== $expectedSize) {
            return $this->error(
                'condixia_ai_stw_extract_size',
                __('A ZIP entry did not match its validated size.', 'condixia-ai-site-to-wordpress')
            );
        }

        return true;
    }

    private function ensureDirectory(
        string $root,
        string $directory
    ): true|\WP_Error {
        if (is_link($directory)) {
            return $this->error(
                'condixia_ai_stw_extract_symlink',
                __('A symbolic link was detected inside the extraction path.', 'condixia-ai-site-to-wordpress')
            );
        }

        if (! is_dir($directory)) {
            if (! wp_mkdir_p($directory)) {
                return $this->error(
                    'condixia_ai_stw_extract_directory',
                    __('A secure extraction directory could not be created.', 'condixia-ai-site-to-wordpress')
                );
            }

            @chmod(
                $directory,
                0700
            );
        }

        $resolved = realpath(
            $directory
        );

        if (
            $resolved === false
            || ! $this->isInsideRoot(
                $root,
                $resolved
            )
        ) {
            return $this->error(
                'condixia_ai_stw_extract_escape',
                __('A ZIP entry attempted to escape the secure extraction directory.', 'condixia-ai-site-to-wordpress')
            );
        }

        return true;
    }

    private function isInsideRoot(
        string $root,
        string $path
    ): bool {
        $normalizedRoot = rtrim(
            wp_normalize_path($root),
            '/'
        );

        $normalizedPath = wp_normalize_path(
            $path
        );

        if ($normalizedPath === $normalizedRoot) {
            return true;
        }

        $prefix = $normalizedRoot . '/';
        $length = strlen(
            $prefix
        );

        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp(
                $normalizedPath,
                $prefix,
                $length
            ) === 0
            : strncmp(
                $normalizedPath,
                $prefix,
                $length
            ) === 0;
    }

    private function error(
        string $code,
        string $message
    ): \WP_Error {
        return new \WP_Error(
            $code,
            $message
        );
    }
}
