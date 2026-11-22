<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class ReferenceResolver
{
    private const IGNORED_SCHEMES = [
        'data',
        'blob',
        'javascript',
        'mailto',
        'tel',
        'sms',
        'about',
        'cid',
    ];

    public function resolve(
        string $sourcePath,
        string $reference,
        array $fileIndex
    ): array {
        $reference = trim(
            $reference
        );

        if (
            $reference === ''
            || str_starts_with($reference, '#')
            || str_starts_with($reference, '?')
        ) {
            return [
                'kind' => 'ignore',
            ];
        }

        if (str_starts_with($reference, '//')) {
            return [
                'kind' => 'external',
                'reference' => $reference,
            ];
        }

        if (
            preg_match(
                '/^([A-Za-z][A-Za-z0-9+.-]*):/',
                $reference,
                $schemeMatch
            ) === 1
        ) {
            $scheme = strtolower(
                $schemeMatch[1]
            );

            if (
                in_array(
                    $scheme,
                    self::IGNORED_SCHEMES,
                    true
                )
            ) {
                return [
                    'kind' => 'ignore',
                ];
            }

            if ($scheme === 'file') {
                return [
                    'kind' => 'unsafe',
                    'reference' => $reference,
                ];
            }

            return [
                'kind' => 'external',
                'reference' => $reference,
            ];
        }

        $pathPart = preg_split(
            '/[?#]/',
            $reference,
            2
        );

        $pathPart = is_array($pathPart)
            ? (string) ($pathPart[0] ?? '')
            : '';

        if ($pathPart === '') {
            return [
                'kind' => 'ignore',
            ];
        }

        $directoryHint = str_ends_with(
            str_replace('\\', '/', $pathPart),
            '/'
        );

        $decoded = rawurldecode(
            $pathPart
        );

        if (
            str_contains($decoded, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
        ) {
            return [
                'kind' => 'unsafe',
                'reference' => $reference,
            ];
        }

        $decoded = str_replace(
            '\\',
            '/',
            $decoded
        );

        $target = $this->normalizePath(
            $sourcePath,
            $decoded
        );

        if ($target === null) {
            return [
                'kind' => 'unsafe',
                'reference' => $reference,
            ];
        }

        $candidates = [
            $target,
        ];

        $extension = pathinfo(
            $target,
            PATHINFO_EXTENSION
        );

        if ($directoryHint) {
            $candidates[] = $target . '/index.html';
            $candidates[] = $target . '/index.htm';
        } elseif ($extension === '') {
            $candidates[] = $target . '.html';
            $candidates[] = $target . '.htm';
            $candidates[] = $target . '/index.html';
            $candidates[] = $target . '/index.htm';
        }

        foreach ($candidates as $candidate) {
            if (isset($fileIndex[$candidate])) {
                return [
                    'kind' => 'local',
                    'status' => 'found',
                    'target' => $candidate,
                ];
            }
        }

        return [
            'kind' => 'local',
            'status' => 'missing',
            'target' => $target,
        ];
    }

    private function normalizePath(
        string $sourcePath,
        string $reference
    ): ?string {
        if (str_starts_with($reference, '/')) {
            $combined = ltrim(
                $reference,
                '/'
            );
        } else {
            $position = strrpos(
                $sourcePath,
                '/'
            );

            $directory = $position === false
                ? ''
                : substr(
                    $sourcePath,
                    0,
                    $position
                );

            $combined = $directory === ''
                ? $reference
                : $directory . '/' . $reference;
        }

        $segments = explode(
            '/',
            $combined
        );

        $normalized = [];

        foreach ($segments as $segment) {
            if (
                $segment === ''
                || $segment === '.'
            ) {
                continue;
            }

            if ($segment === '..') {
                if ($normalized === []) {
                    return null;
                }

                array_pop(
                    $normalized
                );

                continue;
            }

            $normalized[] = $segment;
        }

        if ($normalized === []) {
            return null;
        }

        return implode(
            '/',
            $normalized
        );
    }
}
