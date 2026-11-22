<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class RepeatedGridDetector
{
    private const EXCLUDED_ANCESTOR_TAGS = [
        'header',
        'footer',
        'nav',
        'select',
        'datalist',
        'svg',
    ];

    private const SEMANTIC_CLASSES = [
        'grid',
        'cards',
        'card-grid',
        'posts',
        'post-grid',
        'products',
        'product-grid',
        'listing',
        'list',
        'results',
        'items',
        'archive',
        'loop',
    ];

    public function detect(
        array $domDocuments
    ): array {
        $candidates = [];

        foreach (
            $domDocuments
            as $sourcePath => $document
        ) {
            if (
                ! is_array($document)
                || ! isset($document['elements'])
                || ! is_array(
                    $document['elements']
                )
            ) {
                continue;
            }

            $candidates = array_merge(
                $candidates,
                $this->documentCandidates(
                    (string) $sourcePath,
                    $document
                )
            );
        }

        usort(
            $candidates,
            static function (
                array $left,
                array $right
            ): int {
                $source = strcmp(
                    $left['source'],
                    $right['source']
                );

                if ($source !== 0) {
                    return $source;
                }

                return strcmp(
                    $left['container_path'],
                    $right['container_path']
                );
            }
        );

        foreach (
            $candidates
            as $index => &$candidate
        ) {
            $number = $index + 1;

            $candidate['title'] = sprintf(
                __(
                    'Repeated Grid %d',
                    'condixia-ai-site-to-wordpress'
                ),
                $number
            );

            $candidate['slug'] = sanitize_title(
                $candidate['title']
            );

            $candidate['source_key'] = hash(
                'sha256',
                $candidate['source']
                    . "\0"
                    . $candidate['container_ref']
                    . "\0"
                    . $candidate['item_fingerprint']
            );
        }

        unset($candidate);

        return [
            'candidates' => $candidates,
            'count' => count($candidates),
            'ambiguous_count' => count(
                array_filter(
                    $candidates,
                    static fn(
                        array $candidate
                    ): bool =>
                    ! empty($candidate['ambiguous'])
                )
            ),
        ];
    }

    private function documentCandidates(
        string $sourcePath,
        array $document
    ): array {
        $elements = $document['elements'];

        $byRef = [];
        $children = [];

        foreach ($elements as $element) {
            if (
                ! is_array($element)
                || empty($element['ref'])
            ) {
                continue;
            }

            $ref = (string) $element['ref'];

            $byRef[$ref] = $element;

            $parentRef = isset(
                $element['parent_ref']
            ) && is_string(
                $element['parent_ref']
            )
                ? $element['parent_ref']
                : '';

            if ($parentRef !== '') {
                if (! isset($children[$parentRef])) {
                    $children[$parentRef] = [];
                }

                $children[$parentRef][] = $element;
            }
        }

        $candidates = [];

        foreach (
            $children
            as $parentRef => $siblings
        ) {
            if (count($siblings) < 2) {
                continue;
            }

            if (! isset($byRef[$parentRef])) {
                continue;
            }

            $parent = $byRef[$parentRef];

            if (
                $this->excluded(
                    $parent,
                    $byRef
                )
            ) {
                continue;
            }

            $groups = [];

            foreach ($siblings as $sibling) {
                $fingerprint = (string) (
                    $sibling['subtree_fingerprint']
                    ?? ''
                );

                if ($fingerprint === '') {
                    continue;
                }

                if (! isset($groups[$fingerprint])) {
                    $groups[$fingerprint] = [];
                }

                $groups[$fingerprint][] = $sibling;
            }

            foreach ($groups as $group) {
                if (count($group) < 2) {
                    continue;
                }

                $candidate = $this->candidate(
                    $sourcePath,
                    $parent,
                    $group,
                    count($siblings)
                );

                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $this->deduplicate(
            $candidates
        );
    }

    private function candidate(
        string $sourcePath,
        array $container,
        array $items,
        int $siblingCount
    ): ?array {
        $sample = $items[0];

        $containerRef = (string) (
            $container['ref']
            ?? ''
        );

        $containerPath = (string) (
            $container['path']
            ?? ''
        );

        $sampleRef = (string) (
            $sample['ref']
            ?? ''
        );

        $samplePath = (string) (
            $sample['path']
            ?? ''
        );

        $fingerprint = (string) (
            $sample['subtree_fingerprint']
            ?? ''
        );

        if (
            $containerRef === ''
            || $containerPath === ''
            || $sampleRef === ''
            || $samplePath === ''
            || $fingerprint === ''
        ) {
            return null;
        }

        $repeatCount = count($items);

        $coverage = $siblingCount > 0
            ? min(
                1.0,
                $repeatCount
                    / $siblingCount
            )
            : 0.0;

        $confidence = 0.55;

        if ($repeatCount >= 3) {
            $confidence += 0.15;
        }

        if ($repeatCount >= 4) {
            $confidence += 0.05;
        }

        if ($coverage >= 0.75) {
            $confidence += 0.10;
        }

        if ($coverage >= 0.99) {
            $confidence += 0.05;
        }

        if (
            in_array(
                (string) (
                    $sample['tag']
                    ?? ''
                ),
                [
                    'article',
                    'li',
                    'tr',
                ],
                true
            )
        ) {
            $confidence += 0.05;
        }

        if ($this->semanticContainer($container)) {
            $confidence += 0.05;
        }

        $confidence = round(
            min(
                0.99,
                $confidence
            ),
            3
        );

        $itemRecords = [];

        foreach ($items as $item) {
            $itemRecords[] = [
                'ref' => (string) (
                    $item['ref']
                    ?? ''
                ),
                'path' => (string) (
                    $item['path']
                    ?? ''
                ),
                'tag' => (string) (
                    $item['tag']
                    ?? ''
                ),
            ];
        }

        return [
            'source' => $sourcePath,
            'container_ref' => $containerRef,
            'container_path' => $containerPath,
            'container_tag' => (string) (
                $container['tag']
                ?? ''
            ),
            'item_fingerprint' => $fingerprint,
            'sample_item_ref' => $sampleRef,
            'sample_item_path' => $samplePath,
            'item_tag' => (string) (
                $sample['tag']
                ?? ''
            ),
            'repeat_count' => $repeatCount,
            'sibling_count' => $siblingCount,
            'coverage' => round(
                $coverage,
                3
            ),
            'confidence' => $confidence,
            'ambiguous' => $confidence < 0.85,
            'requires_confirmation' => true,
            'auto_bind' => false,
            'items' => $itemRecords,
        ];
    }

    private function excluded(
        array $element,
        array $byRef
    ): bool {
        $current = $element;

        while (true) {
            $tag = strtolower(
                (string) (
                    $current['tag']
                    ?? ''
                )
            );

            if (
                in_array(
                    $tag,
                    self::EXCLUDED_ANCESTOR_TAGS,
                    true
                )
            ) {
                return true;
            }

            $parentRef = isset(
                $current['parent_ref']
            ) && is_string(
                $current['parent_ref']
            )
                ? $current['parent_ref']
                : '';

            if (
                $parentRef === ''
                || ! isset($byRef[$parentRef])
            ) {
                break;
            }

            $current = $byRef[$parentRef];
        }

        return false;
    }

    private function semanticContainer(
        array $container
    ): bool {
        $attributes = is_array(
            $container['attributes']
                ?? null
        )
            ? $container['attributes']
            : [];

        $classes = is_array(
            $attributes['class']
                ?? null
        )
            ? $attributes['class']
            : [];

        foreach ($classes as $class) {
            foreach (
                self::SEMANTIC_CLASSES
                as $needle
            ) {
                if (
                    str_contains(
                        strtolower(
                            (string) $class
                        ),
                        $needle
                    )
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function deduplicate(
        array $candidates
    ): array {
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = $candidate['source']
                . "\0"
                . $candidate['container_ref']
                . "\0"
                . $candidate['item_fingerprint'];

            if (
                ! isset($unique[$key])
                || (
                    $candidate['confidence']
                    > $unique[$key]['confidence']
                )
            ) {
                $unique[$key] = $candidate;
            }
        }

        return array_values(
            $unique
        );
    }
}
