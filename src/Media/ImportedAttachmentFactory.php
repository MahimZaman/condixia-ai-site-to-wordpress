<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Media;

defined('ABSPATH') || exit;

final class ImportedAttachmentFactory
{
    private const OWNED_META = '_condixia_ai_stw_owned';
    private const CHECKSUM_META = '_condixia_ai_stw_checksum';
    private const SOURCE_META = '_condixia_ai_stw_source_path';

    public function findByChecksum(
        string $checksum
    ): int {
        $ids = get_posts(
            [
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => self::OWNED_META,
                        'value' => '1',
                    ],
                    [
                        'key' => self::CHECKSUM_META,
                        'value' => $checksum,
                    ],
                ],
            ]
        );

        if (
            ! is_array($ids)
            || $ids === []
        ) {
            return 0;
        }

        $attachmentId = absint(
            $ids[0]
        );

        if (
            $attachmentId < 1
            || get_post_type($attachmentId)
            !== 'attachment'
            || ! wp_attachment_is_image(
                $attachmentId
            )
        ) {
            return 0;
        }

        $file = get_attached_file(
            $attachmentId
        );

        return is_string($file)
            && $file !== ''
            && is_file($file)
            ? $attachmentId
            : 0;
    }

    public function create(
        string $checksum,
        array $row
    ): int|\WP_Error {
        $metadata = json_decode(
            (string) (
                $row['metadata_json']
                ?? ''
            ),
            true
        );

        if (! is_array($metadata)) {
            return $this->error();
        }

        $source = $this->publicFile(
            $metadata
        );

        if ($source === null) {
            return 0;
        }

        $mimeType = sanitize_mime_type(
            (string) (
                $row['mime_type']
                ?? ''
            )
        );

        if (
            ! str_starts_with(
                $mimeType,
                'image/'
            )
        ) {
            return 0;
        }

        $filename = wp_basename(
            (string) (
                $row['relative_path']
                ?? ''
            )
        );

        if ($filename === '') {
            return 0;
        }

        $filetype = wp_check_filetype(
            $filename
        );

        if (
            empty($filetype['type'])
            || ! is_string(
                $filetype['type']
            )
            || ! str_starts_with(
                $filetype['type'],
                'image/'
            )
        ) {
            return 0;
        }

        $uploads = wp_upload_dir();

        if (
            ! empty($uploads['error'])
            || empty($uploads['path'])
        ) {
            return $this->error();
        }

        $destinationName = wp_unique_filename(
            (string) $uploads['path'],
            $filename
        );

        $destination = trailingslashit(
            (string) $uploads['path']
        )
            . $destinationName;

        if (
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

        $attachmentId = wp_insert_attachment(
            [
                'post_mime_type' => $mimeType,
                'post_title' => sanitize_text_field(
                    pathinfo(
                        $filename,
                        PATHINFO_FILENAME
                    )
                ),
                'post_status' => 'inherit',
            ],
            $destination,
            0,
            true
        );

        if (is_wp_error($attachmentId)) {
            @unlink($destination);

            return $attachmentId;
        }

        $attachmentId = absint(
            $attachmentId
        );

        if ($attachmentId < 1) {
            @unlink($destination);

            return $this->error();
        }

        update_post_meta(
            $attachmentId,
            self::OWNED_META,
            '1'
        );

        update_post_meta(
            $attachmentId,
            self::CHECKSUM_META,
            $checksum
        );

        update_post_meta(
            $attachmentId,
            self::SOURCE_META,
            (string) (
                $row['relative_path']
                ?? ''
            )
        );

        if (
            ! function_exists(
                'wp_generate_attachment_metadata'
            )
        ) {
            require_once ABSPATH
                . 'wp-admin/includes/image.php';
        }

        $attachmentMetadata
            = wp_generate_attachment_metadata(
                $attachmentId,
                $destination
            );

        if (is_array($attachmentMetadata)) {
            wp_update_attachment_metadata(
                $attachmentId,
                $attachmentMetadata
            );
        }

        return $attachmentId;
    }

    private function publicFile(
        array $metadata
    ): ?string {
        $publicKey = isset(
            $metadata['public_key']
        ) && is_string(
            $metadata['public_key']
        )
            ? $metadata['public_key']
            : '';

        if (
            $publicKey === ''
            || str_contains(
                $publicKey,
                '\\'
            )
            || str_starts_with(
                $publicKey,
                '/'
            )
        ) {
            return null;
        }

        foreach (
            explode('/', $publicKey)
            as $segment
        ) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
            ) {
                return null;
            }
        }

        $uploads = wp_get_upload_dir();

        if (
            ! empty($uploads['error'])
            || empty($uploads['basedir'])
        ) {
            return null;
        }

        $root = rtrim(
            (string) $uploads['basedir'],
            '\\/'
        )
            . DIRECTORY_SEPARATOR
            . 'condixia-ai-site-to-wordpress';

        $path = $root
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $publicKey
            );

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
            || is_link($resolvedPath)
            || ! is_file($resolvedPath)
            || ! is_readable($resolvedPath)
        ) {
            return null;
        }

        return $resolvedPath;
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

        if ($path === $root) {
            return false;
        }

        $prefix = $root . '/';

        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp(
                $path,
                $prefix,
                strlen($prefix)
            ) === 0
            : strncmp(
                $path,
                $prefix,
                strlen($prefix)
            ) === 0;
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_media_library',
            __(
                'Condixia could not register the imported image with the WordPress Media Library.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }
}
