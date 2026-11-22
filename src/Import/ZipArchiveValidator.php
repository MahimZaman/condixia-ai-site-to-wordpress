<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Import;

defined('ABSPATH') || exit;

final class ZipArchiveValidator
{
    private const MAX_ENTRIES = 20000;
    private const MAX_FILE_SIZE = 268435456;
    private const MAX_TOTAL_SIZE = 1073741824;
    private const MAX_COMPRESSION_RATIO = 250;

    private const UNIX_DIRECTORY = 0040000;
    private const UNIX_REGULAR_FILE = 0100000;

    private const FRONTEND_EXTENSIONS = [
        'html',
        'htm',
        'css',
        'js',
        'mjs',
        'cjs',
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'avif',
        'ico',
        'bmp',
        'svg',
        'woff',
        'woff2',
        'ttf',
        'otf',
        'eot',
        'json',
        'webmanifest',
        'mp4',
        'webm',
        'mp3',
        'ogg',
        'wav',
        'pdf',
    ];

    private const IGNORED_DIRECTORIES = [
        '.git',
        '.svn',
        '.hg',
        '.idea',
        '.vscode',
        'node_modules',
        'vendor',
        '__macosx',
        'coverage',
        '.cache',
        '.next',
        '.nuxt',
        'tests',
        '__tests__',
    ];

    public function validate(\ZipArchive $archive): array|\WP_Error
    {
        if ($archive->numFiles < 1) {
            return $this->error(
                'condixia_ai_stw_archive_empty',
                __('The ZIP archive is empty.', 'condixia-ai-site-to-wordpress')
            );
        }

        if ($archive->numFiles > self::MAX_ENTRIES) {
            return $this->error(
                'condixia_ai_stw_archive_limit',
                __('The ZIP archive contains too many entries.', 'condixia-ai-site-to-wordpress')
            );
        }

        $entries = [];
        $knownPaths = [];
        $implicitDirectories = [];
        $totalSize = 0;
        $fileCount = 0;
        $ignoredCount = 0;
        $ignoredPaths = [];
        $ignoredFiles = [];

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex(
                $index,
                \ZipArchive::FL_UNCHANGED
            );

            if (! is_array($stat)) {
                return $this->error(
                    'condixia_ai_stw_archive_invalid_entry',
                    __('The ZIP archive contains an unreadable entry.', 'condixia-ai-site-to-wordpress')
                );
            }

            $rawName = isset($stat['name'])
                ? (string) $stat['name']
                : '';

            $pathResult = $this->validatePath($rawName);

            if (is_wp_error($pathResult)) {
                return $pathResult;
            }

            $path = $pathResult;
            $unixType = $this->unixType(
                $archive,
                $index
            );

            if (
                $unixType !== null
                && $unixType !== 0
                && $unixType !== self::UNIX_DIRECTORY
                && $unixType !== self::UNIX_REGULAR_FILE
            ) {
                return $this->error(
                    'condixia_ai_stw_archive_special_entry',
                    __('The ZIP archive contains a symbolic link or unsupported special filesystem entry.', 'condixia-ai-site-to-wordpress')
                );
            }

            $directory = str_ends_with($rawName, '/')
                || str_ends_with($rawName, '\\')
                || $unixType === self::UNIX_DIRECTORY;

            $path = rtrim($path, '/');

            if ($path === '') {
                return $this->error(
                    'condixia_ai_stw_archive_unsafe_path',
                    __('The ZIP archive contains an unsafe path.', 'condixia-ai-site-to-wordpress')
                );
            }

            $pathKey = strtolower($path);

            $conflict = $this->checkPathConflict(
                $path,
                $pathKey,
                $directory,
                $knownPaths,
                $implicitDirectories
            );

            if (is_wp_error($conflict)) {
                return $conflict;
            }

            if ($directory) {
                $knownPaths[$pathKey] = 'directory';

                $entries[] = [
                    'index' => $index,
                    'path' => $path,
                    'directory' => true,
                    'size' => 0,
                ];

                continue;
            }

            $classification = $this->classifyFile($path);

            $size = isset($stat['size'])
                ? (int) $stat['size']
                : -1;

            $compressedSize = isset($stat['comp_size'])
                ? (int) $stat['comp_size']
                : -1;

            if ($size < 0 || $compressedSize < 0) {
                return $this->error(
                    'condixia_ai_stw_archive_invalid_entry',
                    __('The ZIP archive contains invalid file metadata.', 'condixia-ai-site-to-wordpress')
                );
            }

            if ($size > self::MAX_FILE_SIZE) {
                return $this->error(
                    'condixia_ai_stw_archive_limit',
                    __('The ZIP archive contains a file that is too large to import safely.', 'condixia-ai-site-to-wordpress')
                );
            }

            $totalSize += $size;

            if ($totalSize > self::MAX_TOTAL_SIZE) {
                return $this->error(
                    'condixia_ai_stw_archive_limit',
                    __('The ZIP archive expands beyond the safe import size limit.', 'condixia-ai-site-to-wordpress')
                );
            }

            if (
                $size > 1048576
                && $compressedSize === 0
            ) {
                return $this->error(
                    'condixia_ai_stw_archive_ratio',
                    __('The ZIP archive contains a suspiciously compressed file.', 'condixia-ai-site-to-wordpress')
                );
            }

            if (
                $size > 1048576
                && $compressedSize > 0
                && ($size / $compressedSize) > self::MAX_COMPRESSION_RATIO
            ) {
                return $this->error(
                    'condixia_ai_stw_archive_ratio',
                    __('The ZIP archive contains a suspicious compression ratio.', 'condixia-ai-site-to-wordpress')
                );
            }

            $knownPaths[$pathKey] = 'file';

            $this->registerImplicitDirectories(
                $path,
                $implicitDirectories
            );

            if ($classification !== 'frontend') {
                $ignoredCount++;

                if (count($ignoredPaths) < 100) {
                    $ignoredPaths[] = $path;
                }

                $ignoredFiles[] = [
                    'path' => $path,
                    'reason' => $classification,
                ];

                continue;
            }

            $entries[] = [
                'index' => $index,
                'path' => $path,
                'directory' => false,
                'size' => $size,
            ];

            $fileCount++;
        }

        if ($fileCount < 1) {
            return $this->error(
                'condixia_ai_stw_archive_empty',
                __('The ZIP archive does not contain any files.', 'condixia-ai-site-to-wordpress')
            );
        }

        return [
            'entries' => $entries,
            'file_count' => $fileCount,
            'ignored_file_count' => $ignoredCount,
            'ignored_file_paths' => $ignoredPaths,
            'entry_count' => count($entries),
            'uncompressed_size' => $totalSize,
            'ignored_count' => count($ignoredFiles),
            'ignored_files' => $ignoredFiles,
        ];
    }

    private function validatePath(
        string $rawName
    ): string|\WP_Error {
        if (
            $rawName === ''
            || strlen($rawName) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $rawName) === 1
        ) {
            return $this->unsafePath();
        }

        $path = str_replace(
            '\\',
            '/',
            $rawName
        );

        if (
            str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || preg_match('/^[A-Za-z]:/', $path) === 1
        ) {
            return $this->unsafePath();
        }

        $trimmedPath = rtrim($path, '/');

        if ($trimmedPath === '') {
            return $this->unsafePath();
        }

        $segments = explode(
            '/',
            $trimmedPath
        );

        foreach ($segments as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || strlen($segment) > 255
                || str_contains($segment, ':')
                || preg_match('/[. ]$/', $segment) === 1
                || $this->isWindowsDeviceName($segment)
            ) {
                return $this->unsafePath();
            }
        }

        return implode(
            '/',
            $segments
        );
    }

    private function classifyFile(string $path): string
    {
        $segments = explode('/', strtolower($path));
        $basename = (string) end($segments);
        $extension = strtolower(
            pathinfo($basename, PATHINFO_EXTENSION)
        );

        $blockedDirectories = [
            '.git',
            '.svn',
            '.hg',
            '.github',
            '.vscode',
            '.idea',
            'node_modules',
            'vendor',
            '__macosx',
            'tests',
            'test',
            'coverage',
            '.next',
            '.nuxt',
        ];

        foreach (array_slice($segments, 0, -1) as $segment) {
            if (
                in_array($segment, $blockedDirectories, true)
                || (
                    str_starts_with($segment, '.')
                    && $segment !== '.well-known'
                )
            ) {
                return 'development';
            }
        }

        $serverFiles = [
            '.htaccess',
            '.htpasswd',
            '.user.ini',
            'web.config',
            'php.ini',
            'php5.ini',
            '.env',
            '.env.local',
            '.env.production',
            '.env.development',
        ];

        if (
            in_array($basename, $serverFiles, true)
            || str_starts_with($basename, '.env.')
        ) {
            return 'server_configuration';
        }

        if (
            preg_match(
                '/(?:^|\.)(?:php[0-9]*|phtml|pht|phtm|phar|phps|cgi|fcgi|pl|py|rb|sh|bash|zsh|exe|dll|so|dylib|com|bat|cmd|ps1|asp|aspx|jsp|jspx)(?:\.|$)/i',
                $basename
            ) === 1
        ) {
            return 'server_executable';
        }

        if (str_starts_with($basename, '.')) {
            return 'project_metadata';
        }

        if (
            in_array(
                $basename,
                [
                    '.gitignore',
                    '.gitattributes',
                    '.editorconfig',
                    '.npmrc',
                    '.nvmrc',
                    'package.json',
                    'package-lock.json',
                    'yarn.lock',
                    'pnpm-lock.yaml',
                    'composer.json',
                    'composer.lock',
                    'vite.config.js',
                    'vite.config.ts',
                    'webpack.config.js',
                    'webpack.config.ts',
                    'rollup.config.js',
                    'rollup.config.ts',
                    'postcss.config.js',
                    'postcss.config.cjs',
                    'postcss.config.mjs',
                    'tailwind.config.js',
                    'tailwind.config.cjs',
                    'tailwind.config.mjs',
                    'eslint.config.js',
                    'prettier.config.js',
                    'tsconfig.json',
                ],
                true
            )
            || preg_match(
                '/^(?:readme|changelog|contributing|license|licence)(?:[._-].*)?$/i',
                $basename
            ) === 1
        ) {
            return 'project_metadata';
        }

        if (
            in_array(
                $extension,
                [
                    'html',
                    'htm',
                    'css',
                    'js',
                    'mjs',
                    'cjs',
                    'svg',
                    'jpg',
                    'jpeg',
                    'png',
                    'gif',
                    'webp',
                    'avif',
                    'ico',
                    'bmp',
                    'woff',
                    'woff2',
                    'ttf',
                    'otf',
                    'eot',
                    'json',
                    'webmanifest',
                    'xml',
                    'txt',
                    'webm',
                    'mp4',
                    'mp3',
                    'ogg',
                    'wav',
                    'pdf',
                ],
                true
            )
        ) {
            return 'frontend';
        }

        return 'unsupported';
    }

    private function checkPathConflict(
        string $path,
        string $pathKey,
        bool $directory,
        array $knownPaths,
        array $implicitDirectories
    ): ?\WP_Error {
        if (isset($knownPaths[$pathKey])) {
            if (
                $directory
                && $knownPaths[$pathKey] === 'directory'
            ) {
                return null;
            }

            return $this->error(
                'condixia_ai_stw_archive_path_conflict',
                __('The ZIP archive contains duplicate or conflicting paths.', 'condixia-ai-site-to-wordpress')
            );
        }

        if (
            ! $directory
            && isset($implicitDirectories[$pathKey])
        ) {
            return $this->error(
                'condixia_ai_stw_archive_path_conflict',
                __('The ZIP archive contains conflicting file and directory paths.', 'condixia-ai-site-to-wordpress')
            );
        }

        $segments = explode(
            '/',
            $path
        );

        array_pop($segments);

        $parent = '';

        foreach ($segments as $segment) {
            $parent = $parent === ''
                ? $segment
                : $parent . '/' . $segment;

            $parentKey = strtolower($parent);

            if (
                isset($knownPaths[$parentKey])
                && $knownPaths[$parentKey] === 'file'
            ) {
                return $this->error(
                    'condixia_ai_stw_archive_path_conflict',
                    __('The ZIP archive contains a file where a directory is required.', 'condixia-ai-site-to-wordpress')
                );
            }
        }

        return null;
    }

    private function registerImplicitDirectories(
        string $path,
        array &$implicitDirectories
    ): void {
        $segments = explode(
            '/',
            $path
        );

        array_pop($segments);

        $parent = '';

        foreach ($segments as $segment) {
            $parent = $parent === ''
                ? $segment
                : $parent . '/' . $segment;

            $implicitDirectories[strtolower($parent)] = true;
        }
    }

    private function unixType(
        \ZipArchive $archive,
        int $index
    ): ?int {
        $operatingSystem = 0;
        $attributes = 0;

        if (
            ! $archive->getExternalAttributesIndex(
                $index,
                $operatingSystem,
                $attributes,
                \ZipArchive::FL_UNCHANGED
            )
        ) {
            return null;
        }

        if ($operatingSystem !== \ZipArchive::OPSYS_UNIX) {
            return null;
        }

        return ($attributes >> 16) & 0170000;
    }

    private function isWindowsDeviceName(
        string $segment
    ): bool {
        $base = strtoupper(
            explode('.', $segment, 2)[0]
        );

        return preg_match(
            '/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/',
            $base
        ) === 1;
    }

    private function unsafePath(): \WP_Error
    {
        return $this->error(
            'condixia_ai_stw_archive_unsafe_path',
            __('The ZIP archive contains an unsafe or traversing path.', 'condixia-ai-site-to-wordpress')
        );
    }

    private function forbiddenExecutable(): \WP_Error
    {
        return $this->error(
            'condixia_ai_stw_archive_executable',
            __('The ZIP archive contains a forbidden server-executable or server-configuration file.', 'condixia-ai-site-to-wordpress')
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
