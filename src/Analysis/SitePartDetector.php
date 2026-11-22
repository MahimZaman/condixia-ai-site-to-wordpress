<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class SitePartDetector
{
    public function detect(array $domDocuments): array
    {
        if ($domDocuments === []) {
            return [
                'candidates' => [],
                'header_variants' => 0,
                'footer_variants' => 0,
                'ambiguous' => 0,
            ];
        }

        ksort(
            $domDocuments,
            SORT_STRING
        );

        $groups = [];
        $roleCountsByDocument = [];
        $siteLevelPaths = [];

        foreach ($domDocuments as $sourcePath => $document) {
            if (
                ! isset($document['elements'])
                || ! is_array($document['elements'])
            ) {
                continue;
            }

            $mainAncestors = [];

            foreach ($document['elements'] as $record) {
                if (
                    ! is_array($record)
                    || strtolower((string) ($record['tag'] ?? '')) !== 'main'
                ) {
                    continue;
                }

                $parentPath = (string) ($record['path'] ?? '');

                while (($lastSlash = strrpos($parentPath, '/')) !== false) {
                    $parentPath = substr($parentPath, 0, $lastSlash);

                    if ($parentPath === '') {
                        break;
                    }

                    $mainAncestors[$parentPath] = true;
                }
            }

            foreach ($document['elements'] as $element) {
                if (! is_array($element)) {
                    continue;
                }

                $role = $this->role(
                    $element
                );

                if ($role === null) {
                    continue;
                }

                if (
                    ! $this->isLikelySiteLevel($element)
                    || isset($mainAncestors[(string) ($element['path'] ?? '')])
                ) {
                    continue;
                }

                $fingerprint = isset(
                    $element['subtree_fingerprint']
                )
                    ? (string) $element['subtree_fingerprint']
                    : '';

                $ref = isset($element['ref'])
                    ? (string) $element['ref']
                    : '';

                $path = isset($element['path'])
                    ? (string) $element['path']
                    : '';

                if (
                    $fingerprint === ''
                    || $ref === ''
                    || $path === ''
                ) {
                    continue;
                }

                $insideAnotherSitePart = false;

                foreach ($siteLevelPaths[$sourcePath][$role] ?? [] as $parentPath) {
                    if (str_starts_with($path, $parentPath . '/')) {
                        $insideAnotherSitePart = true;
                        break;
                    }
                }

                if ($insideAnotherSitePart) {
                    continue;
                }

                $siteLevelPaths[$sourcePath][$role][] = $path;

                if (
                    ! isset(
                        $roleCountsByDocument[$sourcePath]
                    )
                ) {
                    $roleCountsByDocument[$sourcePath] = [
                        'header' => 0,
                        'footer' => 0,
                    ];
                }

                $roleCountsByDocument[$sourcePath][$role]++;

                $groupKey = $role
                    . ':'
                    . $fingerprint;

                if (! isset($groups[$groupKey])) {
                    $groups[$groupKey] = [
                        'role' => $role,
                        'fingerprint' => $fingerprint,
                        'members' => [],
                    ];
                }

                $groups[$groupKey]['members'][] = [
                    'source' => (string) $sourcePath,
                    'element_ref' => $ref,
                    'path' => $path,
                    'quality' => $this->quality(
                        $element
                    ),
                ];
            }
        }

        $candidates = [];

        foreach ($groups as $group) {
            $candidate = $this->buildCandidate(
                $group,
                $domDocuments,
                $roleCountsByDocument
            );

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        usort(
            $candidates,
            static function (
                array $left,
                array $right
            ): int {
                $roleCompare = strcmp(
                    $left['role'],
                    $right['role']
                );

                if ($roleCompare !== 0) {
                    return $roleCompare;
                }

                $sourceCompare = strcmp(
                    $left['representative_source'],
                    $right['representative_source']
                );

                if ($sourceCompare !== 0) {
                    return $sourceCompare;
                }

                $pathCompare = strcmp(
                    $left['representative_path'],
                    $right['representative_path']
                );

                if ($pathCompare !== 0) {
                    return $pathCompare;
                }

                return strcmp(
                    $left['fingerprint'],
                    $right['fingerprint']
                );
            }
        );

        $headerIndex = 0;
        $footerIndex = 0;
        $ambiguous = 0;

        foreach ($candidates as &$candidate) {
            if ($candidate['role'] === 'header') {
                $headerIndex++;

                $candidate['variant'] = $headerIndex;

                $candidate['title'] = sprintf(
                    __(
                        'Header %s',
                        'condixia-ai-site-to-wordpress'
                    ),
                    $this->variantLabel(
                        $headerIndex
                    )
                );
            } else {
                $footerIndex++;

                $candidate['variant'] = $footerIndex;

                $candidate['title'] = sprintf(
                    __(
                        'Footer %s',
                        'condixia-ai-site-to-wordpress'
                    ),
                    $this->variantLabel(
                        $footerIndex
                    )
                );
            }

            $candidate['slug'] = sanitize_title(
                $candidate['title']
            );

            $candidate['source_key'] = hash(
                'sha256',
                $candidate['role']
                    . "\0"
                    . $candidate['fingerprint']
            );

            if ($candidate['ambiguous']) {
                $ambiguous++;
            }
        }

        unset($candidate);

        return [
            'candidates' => $candidates,
            'header_variants' => $headerIndex,
            'footer_variants' => $footerIndex,
            'ambiguous' => $ambiguous,
        ];
    }

    private function buildCandidate(
        array $group,
        array $domDocuments,
        array $roleCountsByDocument
    ): ?array {
        $members = $group['members'] ?? [];

        if (! is_array($members) || $members === []) {
            return null;
        }

        usort(
            $members,
            static function (
                array $left,
                array $right
            ): int {
                $sourceCompare = strcmp(
                    $left['source'],
                    $right['source']
                );

                if ($sourceCompare !== 0) {
                    return $sourceCompare;
                }

                return strcmp(
                    $left['path'],
                    $right['path']
                );
            }
        );

        $documents = [];
        $qualityTotal = 0.0;
        $documentHasMultipleRoleCandidates = false;

        foreach ($members as $member) {
            $source = (string) $member['source'];

            $documents[$source] = true;

            $qualityTotal += isset(
                $member['quality']
            )
                ? (float) $member['quality']
                : 0.0;

            $roleCount = $roleCountsByDocument[$source][$group['role']] ?? 0;

            if ($roleCount > 1) {
                $documentHasMultipleRoleCandidates = true;
            }
        }

        $documentCount = count(
            $documents
        );

        $totalDocuments = max(
            1,
            count($domDocuments)
        );

        $quality = $qualityTotal
            / count($members);

        $repeatScore = match (true) {
            $documentCount >= 3 => 0.99,
            $documentCount === 2 => 0.94,
            default => 0.68,
        };

        $coverage = min(
            1.0,
            $documentCount / $totalDocuments
        );

        $confidence = round(
            min(
                0.99,
                ($repeatScore * 0.70)
                    + ($quality * 0.20)
                    + ($coverage * 0.10)
            ),
            3
        );

        $ambiguous = $documentCount < 2
            || $confidence < 0.85
            || $documentHasMultipleRoleCandidates;

        return [
            'role' => (string) $group['role'],
            'fingerprint' => (string) $group['fingerprint'],
            'representative_source' => (string) $members[0]['source'],
            'representative_ref' => (string) $members[0]['element_ref'],
            'representative_path' => (string) $members[0]['path'],
            'occurrence_count' => count($members),
            'document_count' => $documentCount,
            'document_coverage' => round(
                $coverage,
                3
            ),
            'confidence' => $confidence,
            'ambiguous' => $ambiguous,
            'members' => array_map(
                static function (array $member): array {
                    return [
                        'source' => (string) $member['source'],
                        'element_ref' => (string) $member['element_ref'],
                        'path' => (string) $member['path'],
                    ];
                },
                $members
            ),
        ];
    }

    private function role(array $element): ?string
    {
        $tag = strtolower((string) ($element['tag'] ?? ''));
        $attributes = is_array($element['attributes'] ?? null)
            ? $element['attributes']
            : [];
        $ariaRole = strtolower((string) ($attributes['role'] ?? ''));

        if ($ariaRole === 'banner' || $tag === 'header') {
            return 'header';
        }

        if ($ariaRole === 'contentinfo' || $tag === 'footer') {
            return 'footer';
        }

        if (! in_array($tag, ['div', 'section', 'nav'], true)) {
            return null;
        }

        $markers = is_array($attributes['class'] ?? null)
            ? $attributes['class']
            : [];

        if (is_string($attributes['id'] ?? null)) {
            $markers[] = $attributes['id'];
        }

        foreach ($markers as $marker) {
            $marker = strtolower((string) $marker);

            if (in_array($marker, [
                'header',
                'site-header',
                'main-header',
                'header-wrapper',
                'header-area',
                'masthead',
                'nav-bar',
                'navbar',
                'site-navbar',
                'navbar-wrapper',
            ], true)) {
                return 'header';
            }

            if (in_array($marker, [
                'footer',
                'site-footer',
                'main-footer',
                'footer-wrapper',
                'footer-area',
                'footer-section',
                'colophon',
            ], true)) {
                return 'footer';
            }
        }

        return null;
    }

    private function isLikelySiteLevel(array $element): bool
    {
        $path = strtolower((string) ($element['path'] ?? ''));
        $depth = (int) ($element['depth'] ?? 999);

        if ($path === '' || $depth > 8) {
            return false;
        }

        return preg_match(
            '#^/html\[1\]/body\[1\](?:/div\[[1-9][0-9]*\]){0,6}/(?:div|section|nav|header|footer)\[[1-9][0-9]*\]$#',
            $path
        ) === 1;
    }

    private function quality(array $element): float
    {
        $path = isset($element['path'])
            ? strtolower(
                (string) $element['path']
            )
            : '';

        $depth = isset($element['depth'])
            ? (int) $element['depth']
            : 999;

        $attributes = isset(
            $element['attributes']
        ) && is_array($element['attributes'])
            ? $element['attributes']
            : [];

        if (
            preg_match(
                '#^/html\[1\]/body\[1\]/(?:header|footer)\[\d+\]$#',
                $path
            ) === 1
        ) {
            $quality = 1.0;
        } elseif ($depth <= 3) {
            $quality = 0.95;
        } elseif ($depth <= 4) {
            $quality = 0.88;
        } elseif ($depth <= 5) {
            $quality = 0.80;
        } else {
            $quality = 0.72;
        }

        if (
            isset($attributes['role'])
            && in_array(
                $attributes['role'],
                [
                    'banner',
                    'contentinfo',
                ],
                true
            )
        ) {
            $quality = min(
                1.0,
                $quality + 0.04
            );
        }

        return $quality;
    }

    private function variantLabel(int $index): string
    {
        if (
            $index >= 1
            && $index <= 26
        ) {
            return chr(
                64 + $index
            );
        }

        return (string) $index;
    }
}
