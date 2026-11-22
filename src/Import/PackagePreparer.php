<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Import;

defined('ABSPATH') || exit;

final class PackagePreparer
{
    private StagingStorage $storage;
    private ZipArchiveValidator $validator;
    private SecureZipExtractor $extractor;

    public function __construct(
        ?StagingStorage $storage = null,
        ?ZipArchiveValidator $validator = null,
        ?SecureZipExtractor $extractor = null
    ) {
        $this->storage = $storage ?? new StagingStorage();
        $this->validator = $validator ?? new ZipArchiveValidator();
        $this->extractor = $extractor ?? new SecureZipExtractor();
    }

    public function prepare(
        string $token
    ): array|\WP_Error {
        $package = $this->storage->package(
            $token
        );

        if (is_wp_error($package)) {
            return $package;
        }

        $extension = (string) $package['extension'];

        if (
            $extension === 'html'
            || $extension === 'htm'
        ) {
            $prepared = [
                'token' => $token,
                'source_type' => 'html',
                'original_name' => (string) $package['original_name'],
                'package_checksum' => (string) $package['checksum'],
                'package_size' => (int) $package['size'],
                'source_file' => basename(
                    (string) $package['source_path']
                ),
                'source_path' => (string) $package['source_path'],
                'entry_count' => 1,
                'file_count' => 1,
                'uncompressed_size' => (int) $package['size'],
            ];

            $saved = $this->storage->markPrepared(
                $token,
                $prepared
            );

            if (is_wp_error($saved)) {
                return $saved;
            }

            return $prepared;
        }

        if ($extension !== 'zip') {
            return new \WP_Error(
                'condixia_ai_stw_package_type',
                __('The staged package type is not supported.', 'condixia-ai-site-to-wordpress')
            );
        }

        if (! class_exists(\ZipArchive::class)) {
            return new \WP_Error(
                'condixia_ai_stw_zip_support',
                __('This server does not provide the ZIP support required for secure website extraction.', 'condixia-ai-site-to-wordpress')
            );
        }

        $archive = new \ZipArchive();

        $opened = $archive->open(
            (string) $package['source_path'],
            \ZipArchive::CHECKCONS
        );

        if ($opened !== true) {
            return new \WP_Error(
                'condixia_ai_stw_archive_open',
                __('The ZIP archive could not be opened safely.', 'condixia-ai-site-to-wordpress')
            );
        }

        try {
            $validated = $this->validator->validate(
                $archive
            );

            if (is_wp_error($validated)) {
                return $validated;
            }

            $destination = $this->storage
                ->resetExtractionDirectory(
                    $token
                );

            if (is_wp_error($destination)) {
                return $destination;
            }

            $extracted = $this->extractor->extract(
                $archive,
                $validated['entries'],
                $destination
            );

            if (is_wp_error($extracted)) {
                return $extracted;
            }

            $prepared = [
                'token' => $token,
                'source_type' => 'zip',
                'original_name' => (string) $package['original_name'],
                'package_checksum' => (string) $package['checksum'],
                'package_size' => (int) $package['size'],
                'content_directory' => 'extracted',
                'content_root' => $destination,
                'entry_count' => (int) $validated['entry_count'],
                'file_count' => (int) $validated['file_count'],
                'ignored_file_count' => (int) $validated['ignored_file_count'],
                'ignored_file_paths' => $validated['ignored_file_paths'],
                'uncompressed_size' => (int) $validated['uncompressed_size'],
                'ignored_count' => (int) ($validated['ignored_count'] ?? 0),
                'ignored_files' => $validated['ignored_files'] ?? [],
            ];

            $saved = $this->storage->markPrepared(
                $token,
                $prepared
            );

            if (is_wp_error($saved)) {
                return $saved;
            }

            return $prepared;
        } finally {
            $archive->close();
        }
    }
}
