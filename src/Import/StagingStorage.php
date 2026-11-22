<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Import;

defined('ABSPATH') || exit;

final class StagingStorage
{
    private const LIFETIME = HOUR_IN_SECONDS;

    public function stage(
        array $file,
        array $validated
    ): array|\WP_Error {
        $userId = get_current_user_id();

        if ($userId < 1) {
            return new \WP_Error(
                'condixia_ai_stw_staging_unavailable',
                __('A logged-in user is required to stage this upload.', 'condixia-ai-site-to-wordpress')
            );
        }

        $this->cleanupExpired();

        try {
            $token = bin2hex(
                random_bytes(16)
            );
        } catch (\Throwable) {
            return new \WP_Error(
                'condixia_ai_stw_staging_unavailable',
                __('A secure staging identifier could not be generated.', 'condixia-ai-site-to-wordpress')
            );
        }

        $directory = $this->tokenDirectory(
            $userId,
            $token
        );

        if (
            ! wp_mkdir_p($directory)
            || ! is_dir($directory)
            || ! is_writable($directory)
        ) {
            return new \WP_Error(
                'condixia_ai_stw_staging_unavailable',
                __('The temporary staging directory could not be created.', 'condixia-ai-site-to-wordpress')
            );
        }

        @chmod(
            $this->baseDirectory(),
            0700
        );

        @chmod(
            $this->userDirectory($userId),
            0700
        );

        @chmod(
            $directory,
            0700
        );

        $extension = (string) $validated['extension'];

        $destination = $directory
            . DIRECTORY_SEPARATOR
            . 'source.'
            . $extension;

        $temporaryPath = isset($file['tmp_name'])
            && is_scalar($file['tmp_name'])
            ? (string) $file['tmp_name']
            : '';

        if (
            $temporaryPath === ''
            || ! move_uploaded_file(
                $temporaryPath,
                $destination
            )
        ) {
            $this->deleteOwnedDirectory(
                $directory
            );

            return new \WP_Error(
                'condixia_ai_stw_move_failed',
                __('The uploaded site package could not be moved into secure temporary storage.', 'condixia-ai-site-to-wordpress')
            );
        }

        @chmod(
            $destination,
            0600
        );

        $checksum = hash_file(
            'sha256',
            $destination
        );

        if (! is_string($checksum)) {
            $this->deleteOwnedDirectory(
                $directory
            );

            return new \WP_Error(
                'condixia_ai_stw_staging_unavailable',
                __('The uploaded site package could not be fingerprinted.', 'condixia-ai-site-to-wordpress')
            );
        }

        $metadata = [
            'token' => $token,
            'blog_id' => get_current_blog_id(),
            'user_id' => $userId,
            'original_name' => (string) $validated['original_name'],
            'extension' => $extension,
            'mime_type' => (string) $validated['mime_type'],
            'size' => (int) $validated['size'],
            'checksum' => $checksum,
            'created_unix' => time(),
        ];

        $saved = $this->writeJson(
            $directory
                . DIRECTORY_SEPARATOR
                . 'upload.json',
            $metadata
        );

        if (is_wp_error($saved)) {
            $this->deleteOwnedDirectory(
                $directory
            );

            return $saved;
        }

        return $metadata;
    }

    public function package(
        string $token
    ): array|\WP_Error {
        if (
            preg_match('/^[a-f0-9]{32}$/', $token) !== 1
        ) {
            return $this->packageError();
        }

        $userId = get_current_user_id();

        if ($userId < 1) {
            return $this->packageError();
        }

        $directory = $this->tokenDirectory(
            $userId,
            $token
        );

        if (! $this->isOwnedTokenDirectory($directory)) {
            return $this->packageError();
        }

        $metadataPath = $directory
            . DIRECTORY_SEPARATOR
            . 'upload.json';

        if (
            ! is_file($metadataPath)
            || is_link($metadataPath)
            || ! is_readable($metadataPath)
        ) {
            return $this->packageError();
        }

        $json = file_get_contents(
            $metadataPath
        );

        if (! is_string($json)) {
            return $this->packageError();
        }

        $metadata = json_decode(
            $json,
            true
        );

        if (
            ! is_array($metadata)
            || ! isset(
                $metadata['token'],
                $metadata['blog_id'],
                $metadata['user_id'],
                $metadata['extension'],
                $metadata['size'],
                $metadata['checksum']
            )
            || ! hash_equals(
                $token,
                (string) $metadata['token']
            )
            || (int) $metadata['blog_id'] !== get_current_blog_id()
            || (int) $metadata['user_id'] !== $userId
        ) {
            return $this->packageError();
        }

        $extension = (string) $metadata['extension'];

        if (
            ! in_array(
                $extension,
                ['html', 'htm', 'zip'],
                true
            )
        ) {
            return $this->packageError();
        }

        $sourcePath = $directory
            . DIRECTORY_SEPARATOR
            . 'source.'
            . $extension;

        if (
            ! is_file($sourcePath)
            || is_link($sourcePath)
            || ! is_readable($sourcePath)
        ) {
            return $this->packageError();
        }

        $checksum = hash_file(
            'sha256',
            $sourcePath
        );

        if (
            ! is_string($checksum)
            || ! hash_equals(
                (string) $metadata['checksum'],
                $checksum
            )
        ) {
            return new \WP_Error(
                'condixia_ai_stw_staging_changed',
                __('The staged site package changed after upload and cannot be processed.', 'condixia-ai-site-to-wordpress')
            );
        }

        $metadata['directory'] = $directory;
        $metadata['source_path'] = $sourcePath;

        return $metadata;
    }

    public function resetExtractionDirectory(
        string $token
    ): string|\WP_Error {
        $package = $this->package(
            $token
        );

        if (is_wp_error($package)) {
            return $package;
        }

        $directory = (string) $package['directory']
            . DIRECTORY_SEPARATOR
            . 'extracted';

        if (is_link($directory)) {
            @unlink($directory);

            return new \WP_Error(
                'condixia_ai_stw_extract_symlink',
                __('The extraction staging path was not safe.', 'condixia-ai-site-to-wordpress')
            );
        }

        if (is_dir($directory)) {
            $this->deleteTree(
                $directory
            );
        } elseif (file_exists($directory)) {
            @unlink($directory);
        }

        if (
            ! wp_mkdir_p($directory)
            || ! is_dir($directory)
        ) {
            return new \WP_Error(
                'condixia_ai_stw_extract_destination',
                __('The secure extraction directory could not be created.', 'condixia-ai-site-to-wordpress')
            );
        }

        @chmod(
            $directory,
            0700
        );

        return $directory;
    }

    public function markPrepared(
        string $token,
        array $prepared
    ): true|\WP_Error {
        $package = $this->package(
            $token
        );

        if (is_wp_error($package)) {
            return $package;
        }

        $metadata = [
            'token' => $token,
            'blog_id' => get_current_blog_id(),
            'user_id' => get_current_user_id(),
            'source_type' => isset($prepared['source_type'])
                ? (string) $prepared['source_type']
                : '',
            'entry_count' => isset($prepared['entry_count'])
                ? (int) $prepared['entry_count']
                : 0,
            'file_count' => isset($prepared['file_count'])
                ? (int) $prepared['file_count']
                : 0,
            'uncompressed_size' => isset($prepared['uncompressed_size'])
                ? (int) $prepared['uncompressed_size']
                : 0,
            'prepared_unix' => time(),
        ];

        if (isset($prepared['source_file'])) {
            $metadata['source_file'] = (string) $prepared['source_file'];
        }

        if (isset($prepared['content_directory'])) {
            $metadata['content_directory'] = (string) $prepared['content_directory'];
        }

        return $this->writeJson(
            (string) $package['directory']
                . DIRECTORY_SEPARATOR
                . 'prepared.json',
            $metadata
        );
    }

    public function cleanupExpired(): void
    {
        $root = $this->baseDirectory();

        if (! is_dir($root)) {
            return;
        }

        $userDirectories = scandir(
            $root
        );

        if (! is_array($userDirectories)) {
            return;
        }

        foreach ($userDirectories as $userDirectory) {
            if (
                $userDirectory === '.'
                || $userDirectory === '..'
                || ! ctype_digit($userDirectory)
            ) {
                continue;
            }

            $userPath = $root
                . DIRECTORY_SEPARATOR
                . $userDirectory;

            if (
                ! is_dir($userPath)
                || is_link($userPath)
            ) {
                continue;
            }

            $tokens = scandir(
                $userPath
            );

            if (! is_array($tokens)) {
                continue;
            }

            foreach ($tokens as $token) {
                if (
                    preg_match(
                        '/^[a-f0-9]{32}$/',
                        $token
                    ) !== 1
                ) {
                    continue;
                }

                $tokenPath = $userPath
                    . DIRECTORY_SEPARATOR
                    . $token;

                if (
                    ! is_dir($tokenPath)
                    || is_link($tokenPath)
                ) {
                    continue;
                }

                $modified = filemtime(
                    $tokenPath
                );

                if (
                    is_int($modified)
                    && time() - $modified >= self::LIFETIME
                ) {
                    $this->deleteOwnedDirectory(
                        $tokenPath
                    );
                }
            }

            $remaining = scandir(
                $userPath
            );

            if (
                is_array($remaining)
                && count($remaining) === 2
            ) {
                @rmdir($userPath);
            }
        }
    }

    public function discard(
        string $token,
        ?int $userId = null
    ): void {
        if (
            preg_match('/^[a-f0-9]{32}$/', $token) !== 1
        ) {
            return;
        }

        $ownerId = $userId
            ?? get_current_user_id();

        if ($ownerId < 1) {
            return;
        }

        $directory = $this->tokenDirectory(
            $ownerId,
            $token
        );

        $this->deleteOwnedDirectory(
            $directory
        );
    }

    private function baseDirectory(): string
    {
        return rtrim(
            get_temp_dir(),
            '\\/'
        )
            . DIRECTORY_SEPARATOR
            . 'condixia-ai-site-to-wordpress'
            . DIRECTORY_SEPARATOR
            . get_current_blog_id();
    }

    private function userDirectory(
        int $userId
    ): string {
        return $this->baseDirectory()
            . DIRECTORY_SEPARATOR
            . $userId;
    }

    private function tokenDirectory(
        int $userId,
        string $token
    ): string {
        return $this->userDirectory($userId)
            . DIRECTORY_SEPARATOR
            . $token;
    }

    private function writeJson(
        string $path,
        array $data
    ): true|\WP_Error {
        $encoded = wp_json_encode(
            $data,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
        );

        if (! is_string($encoded)) {
            return new \WP_Error(
                'condixia_ai_stw_staging_metadata',
                __('The staging metadata could not be generated.', 'condixia-ai-site-to-wordpress')
            );
        }

        $written = @file_put_contents(
            $path,
            $encoded,
            LOCK_EX
        );

        if ($written === false) {
            return new \WP_Error(
                'condixia_ai_stw_staging_metadata',
                __('The staging metadata could not be saved.', 'condixia-ai-site-to-wordpress')
            );
        }

        @chmod(
            $path,
            0600
        );

        return true;
    }

    private function deleteOwnedDirectory(
        string $directory
    ): void {
        if (! $this->isOwnedTokenDirectory($directory)) {
            return;
        }

        $this->deleteTree(
            $directory
        );
    }

    private function deleteTree(
        string $directory
    ): void {
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

    private function isOwnedTokenDirectory(
        string $directory
    ): bool {
        $root = realpath(
            $this->baseDirectory()
        );

        $target = realpath(
            $directory
        );

        if (
            $root === false
            || $target === false
            || $root === $target
        ) {
            return false;
        }

        $normalizedRoot = rtrim(
            wp_normalize_path($root),
            '/'
        );

        $normalizedTarget = wp_normalize_path(
            $target
        );

        $prefix = $normalizedRoot . '/';
        $prefixLength = strlen(
            $prefix
        );

        $insideRoot = DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp(
                $normalizedTarget,
                $prefix,
                $prefixLength
            ) === 0
            : strncmp(
                $normalizedTarget,
                $prefix,
                $prefixLength
            ) === 0;

        if (! $insideRoot) {
            return false;
        }

        $relativePath = substr(
            $normalizedTarget,
            $prefixLength
        );

        return preg_match(
            '#^[0-9]+/[a-f0-9]{32}$#',
            $relativePath
        ) === 1;
    }

    private function packageError(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_staging_invalid',
            __('The staged site package is missing, invalid, or no longer available.', 'condixia-ai-site-to-wordpress')
        );
    }
}
