<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Import;

defined('ABSPATH') || exit;

final class UploadValidator
{
    private const ALLOWED_MIMES = [
        'html|htm' => 'text/html',
        'zip' => 'application/zip',
    ];

    public function validate(array $file): array|\WP_Error
    {
        if (
            ! isset(
                $file['name'],
                $file['tmp_name'],
                $file['size'],
                $file['error']
            )
        ) {
            return $this->error(
                'condixia_ai_stw_no_file',
                __('No site package was received.', 'condixia-ai-site-to-wordpress')
            );
        }

        $uploadError = (int) $file['error'];

        if ($uploadError !== UPLOAD_ERR_OK) {
            return $this->uploadError($uploadError);
        }

        if (
            ! is_scalar($file['name'])
            || ! is_scalar($file['tmp_name'])
            || ! is_numeric($file['size'])
        ) {
            return $this->error(
                'condixia_ai_stw_invalid_upload',
                __('The uploaded site package is invalid.', 'condixia-ai-site-to-wordpress')
            );
        }

        $originalName = sanitize_file_name(
            wp_basename((string) $file['name'])
        );

        $temporaryPath = (string) $file['tmp_name'];
        $size = (int) $file['size'];

        if ($originalName === '' || $temporaryPath === '') {
            return $this->error(
                'condixia_ai_stw_invalid_upload',
                __('The uploaded site package is invalid.', 'condixia-ai-site-to-wordpress')
            );
        }

        if ($size <= 0) {
            return $this->error(
                'condixia_ai_stw_empty_upload',
                __('The uploaded site package is empty.', 'condixia-ai-site-to-wordpress')
            );
        }

        $maximumSize = wp_max_upload_size();

        if (
            $maximumSize > 0
            && $size > $maximumSize
        ) {
            return $this->error(
                'condixia_ai_stw_oversized',
                __('The uploaded site package exceeds the server upload limit.', 'condixia-ai-site-to-wordpress')
            );
        }

        if (
            ! is_uploaded_file($temporaryPath)
            || ! is_readable($temporaryPath)
        ) {
            return $this->error(
                'condixia_ai_stw_invalid_upload',
                __('The uploaded site package could not be verified.', 'condixia-ai-site-to-wordpress')
            );
        }

        $extension = strtolower(
            pathinfo(
                $originalName,
                PATHINFO_EXTENSION
            )
        );

        if (! in_array($extension, ['html', 'htm', 'zip'], true)) {
            return $this->error(
                'condixia_ai_stw_unsupported_type',
                __('Only HTML and ZIP site packages are supported.', 'condixia-ai-site-to-wordpress')
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $fileType = wp_check_filetype_and_ext(
            $temporaryPath,
            $originalName,
            self::ALLOWED_MIMES
        );

        if (
            empty($fileType['ext'])
            || empty($fileType['type'])
        ) {
            return $this->error(
                'condixia_ai_stw_unsupported_type',
                __('The uploaded file type is not supported.', 'condixia-ai-site-to-wordpress')
            );
        }

        if ($extension === 'zip') {
            if (! wp_zip_file_is_valid($temporaryPath)) {
                return $this->error(
                    'condixia_ai_stw_invalid_zip',
                    __('The uploaded ZIP archive is invalid or corrupted.', 'condixia-ai-site-to-wordpress')
                );
            }
        } elseif (! $this->looksLikeHtml($temporaryPath)) {
            return $this->error(
                'condixia_ai_stw_invalid_html',
                __('The uploaded file does not appear to contain HTML.', 'condixia-ai-site-to-wordpress')
            );
        }

        return [
            'original_name' => $originalName,
            'extension' => $extension,
            'mime_type' => (string) $fileType['type'],
            'size' => $size,
        ];
    }

    private function looksLikeHtml(string $path): bool
    {
        $sample = file_get_contents(
            $path,
            false,
            null,
            0,
            65536
        );

        if (
            ! is_string($sample)
            || $sample === ''
            || str_contains($sample, "\0")
        ) {
            return false;
        }

        return preg_match(
            '/<!doctype\s+html|<html(?:\s|>)|<(?:head|body|main|section|div|header|footer|nav|article|form|script|style)(?:\s|>)/i',
            $sample
        ) === 1;
    }

    private function uploadError(int $uploadError): \WP_Error
    {
        if (
            $uploadError === UPLOAD_ERR_INI_SIZE
            || $uploadError === UPLOAD_ERR_FORM_SIZE
        ) {
            return $this->error(
                'condixia_ai_stw_oversized',
                __('The uploaded site package exceeds the server upload limit.', 'condixia-ai-site-to-wordpress')
            );
        }

        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            return $this->error(
                'condixia_ai_stw_no_file',
                __('Choose an HTML or ZIP site package to upload.', 'condixia-ai-site-to-wordpress')
            );
        }

        return $this->error(
            'condixia_ai_stw_upload_error',
            __('The site package could not be uploaded completely.', 'condixia-ai-site-to-wordpress')
        );
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
