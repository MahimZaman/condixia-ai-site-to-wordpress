<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class NavigationDetector
{
    private DomParser $parser;
    private ReferenceResolver $resolver;

    public function __construct(
        ?DomParser $parser = null,
        ?ReferenceResolver $resolver = null
    ) {
        $this->parser = $parser
            ?? new DomParser();

        $this->resolver = $resolver
            ?? new ReferenceResolver();
    }

    public function detectDocument(
        string $sourcePath,
        string $html,
        array $dom,
        array $fileIndex
    ): array|\WP_Error {
        $parsed = $this->parser->parse(
            $html
        );

        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $document = $parsed['document'];

        $root = $document->documentElement;

        if (! $root instanceof \DOMElement) {
            return [];
        }

        $pathMap = [];

        $this->mapPaths(
            $root,
            '/'
                . strtolower($root->tagName)
                . '[1]',
            $pathMap
        );

        $domByPath = [];

        foreach (
            $dom['elements'] ?? []
            as $element
        ) {
            if (
                is_array($element)
                && isset($element['path'])
            ) {
                $domByPath[(string) $element['path']] = $element;
            }
        }

        $candidates = [];

        foreach (
            $document->getElementsByTagName('nav')
            as $navigation
        ) {
            if (! $navigation instanceof \DOMElement) {
                continue;
            }

            $candidate = $this->candidate(
                $sourcePath,
                $navigation,
                'nav',
                true,
                $document,
                $pathMap,
                $domByPath,
                $fileIndex
            );

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        foreach (
            ['ul', 'ol']
            as $tagName
        ) {
            foreach (
                $document->getElementsByTagName(
                    $tagName
                )
                as $list
            ) {
                if (! $list instanceof \DOMElement) {
                    continue;
                }

                if (
                    $this->hasAncestorTag(
                        $list,
                        'nav'
                    )
                    || $this->hasAncestorList(
                        $list
                    )
                ) {
                    continue;
                }

                if (
                    ! $this->isNavigationList($list)
                    || count($this->directListItems($list)) < 2
                ) {
                    continue;
                }

                $candidate = $this->candidate(
                    $sourcePath,
                    $list,
                    'linked_list',
                    false,
                    $document,
                    $pathMap,
                    $domByPath,
                    $fileIndex
                );

                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        foreach ($document->getElementsByTagName('div') as $container) {
            if (
                ! $container instanceof \DOMElement
                || ! $this->hasMenuMarker($container)
                || count($this->directAnchors($container)) < 2
            ) {
                continue;
            }

            $candidate = $this->candidate(
                $sourcePath,
                $container,
                'class_menu',
                false,
                $document,
                $pathMap,
                $domByPath,
                $fileIndex
            );

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Reuse the automatic detector's exact template/item extraction for one
     * user-selected original source element. Never trust iframe-supplied HTML.
     */
    public function manualCandidate(
        string $sourcePath,
        string $html,
        string $elementId,
        array $fileIndex
    ): array|\WP_Error {
        if (preg_match('/^cdx-stw-[a-f0-9]{24}$/', $elementId) !== 1) {
            return new \WP_Error('condixia_navigation_manual_id', 'Choose an original imported element.');
        }

        $parsed = $this->parser->parse($html);
        if (is_wp_error($parsed)) return $parsed;
        $document = $parsed['document'];
        $root = $document->documentElement;
        if (! $root instanceof \DOMElement) {
            return new \WP_Error('condixia_navigation_manual_root', 'The original document cannot be parsed.');
        }

        $dom = (new DomNormalizer())->normalize($sourcePath, $document);
        if (is_wp_error($dom)) return $dom;

        $byPath = [];
        foreach ($dom['elements'] as $record) {
            $byPath[(string) $record['path']] = $record;
        }
        $paths = [];
        $this->mapPaths($root, '/' . strtolower($root->tagName) . '[1]', $paths);

        $target = null;
        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof \DOMElement) continue;
            $path = $paths[spl_object_id($element)] ?? '';
            $id = 'cdx-stw-' . substr(hash('sha256', str_replace('\\', '/', $sourcePath)
                . "\0" . $path), 0, 24);
            if ($id === $elementId) {
                $target = $element;
                break;
            }
        }

        if (! $target) {
            return new \WP_Error(
                'condixia_navigation_manual_runtime',
                'This element was generated by JavaScript and is not in the imported source. '
                    . 'Runtime menu conversion requires a separate rendering adapter.'
            );
        }

        $renderer = new \Condixia\AISiteToWordPress\Navigation\NavigationDocumentRenderer();
        $regions = [];

        // Walk upward from a clicked link to the nearest safe menu region.
        for (
            $node = $target, $depth = 0;
            $node instanceof \DOMElement && $depth < 12;
            $node = $node->parentNode, $depth++
        ) {
            if ($renderer->isNavigationOnlyContainer($node)) {
                $regions[] = $node;
            }

            if (in_array(
                strtolower($node->tagName),
                ['header', 'body'],
                true
            )) {
                break;
            }
        }

        // A branded nav may contain a safe inner menu.
        // Preserve the logo and other unrelated controls.
        if (
            $regions === []
            && strtolower($target->tagName) === 'nav'
        ) {
            foreach ($target->getElementsByTagName('*') as $node) {
                if (
                    $node instanceof \DOMElement
                    && in_array(
                        strtolower($node->tagName),
                        ['ul', 'ol', 'div'],
                        true
                    )
                    && $renderer->isNavigationOnlyContainer($node)
                ) {
                    $regions[] = $node;
                }
            }
        }

        $candidate = null;

        foreach ($regions as $region) {
            $possible = $this->candidate(
                $sourcePath,
                $region,
                'manual',
                false,
                $document,
                $paths,
                $byPath,
                $fileIndex
            );

            if ($possible === null) {
                continue;
            }

            $count = 0;

            $visit = static function (
                array $items
            ) use (&$visit, &$count): void {
                foreach ($items as $item) {
                    $count++;

                    $visit(
                        is_array($item['children'] ?? null)
                            ? $item['children']
                            : []
                    );
                }
            };

            $visit($possible['items']);

            if ($count >= 2) {
                $candidate = $possible;
                break;
            }
        }

        if ($candidate === null) {
            return new \WP_Error(
                'condixia_navigation_manual_unsafe',
                'Select a menu containing at least two source-backed links. '
                    . 'The logo, search form and other header controls will not be replaced.'
            );
        }

        $count = 0;
        $visit = static function (array $items) use (&$visit, &$count): void {
            foreach ($items as $item) {
                $count++;
                $visit(is_array($item['children'] ?? null) ? $item['children'] : []);
            }
        };
        $visit($candidate['items']);
        if ($count < 2) {
            return new \WP_Error(
                'condixia_navigation_manual_few_links',
                'Select a container containing at least two usable navigation links.'
            );
        }

        $grouped = $this->group([$candidate], 1);
        return $grouped['candidates'][0];
    }

    public function group(
        array $candidates,
        int $totalDocuments
    ): array {
        $groups = [];

        foreach ($candidates as $candidate) {
            if (
                ! is_array($candidate)
                || empty($candidate['fingerprint'])
            ) {
                continue;
            }

            $key = (string) $candidate['fingerprint']
                . ':'
                . (string) $candidate['mode'];

            if (! isset($groups[$key])) {
                $groups[$key] = [];
            }

            $groups[$key][] = $candidate;
        }

        $result = [];

        foreach ($groups as $members) {
            usort(
                $members,
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

            $representative = $members[0];

            $documents = [];
            $explicitNav = false;

            foreach ($members as $member) {
                $documents[$member['source']] = true;

                if (
                    $member['source_kind']
                    === 'nav'
                    && ! empty($member['auto_bind'])
                ) {
                    $explicitNav = true;
                }
            }

            $documentCount = count(
                $documents
            );

            $coverage = $totalDocuments > 0
                ? min(
                    1.0,
                    $documentCount
                        / $totalDocuments
                )
                : 0.0;

            if ($explicitNav) {
                $confidence = $documentCount >= 2
                    ? 0.99
                    : 0.96;
            } else {
                $confidence = $documentCount >= 2
                    ? 0.78
                    : 0.70;
            }

            $result[] = [
                'fingerprint' => $representative['fingerprint'],
                'source_kind' => $representative['source_kind'],
                'mode' => $representative['mode'],
                'representative_source'
                => $representative['source'],
                'representative_ref'
                => $representative['container_ref'],
                'representative_path'
                => $representative['container_path'],
                'container_tag'
                => $representative['container_tag'],
                'items' => $representative['items'],
                'members' => array_map(
                    static function (
                        array $member
                    ): array {
                        return [
                            'source'
                            => $member['source'],
                            'container_ref'
                            => $member['container_ref'],
                            'container_path'
                            => $member['container_path'],
                            'container_tag'
                            => $member['container_tag'],
                            'mode'
                            => $member['mode'],
                            'template_html'
                            => $member['template_html'],
                            'items'
                            => $member['items'],
                        ];
                    },
                    $members
                ),
                'document_count' => $documentCount,
                'document_coverage' => round(
                    $coverage,
                    3
                ),
                'confidence' => $confidence,
                'ambiguous' => ! $explicitNav,
                'auto_bind' => $explicitNav,
            ];
        }

        usort(
            $result,
            static function (
                array $left,
                array $right
            ): int {
                $source = strcmp(
                    $left['representative_source'],
                    $right['representative_source']
                );

                if ($source !== 0) {
                    return $source;
                }

                return strcmp(
                    $left['representative_path'],
                    $right['representative_path']
                );
            }
        );

        foreach (
            $result
            as $index => &$candidate
        ) {
            $number = $index + 1;

            $label = $number <= 26
                ? chr(
                    64 + $number
                )
                : (string) $number;

            $candidate['title'] = sprintf(
                __(
                    'Navigation %s',
                    'condixia-ai-site-to-wordpress'
                ),
                $label
            );

            $candidate['slug'] = sanitize_title(
                $candidate['title']
            );

            $candidate['source_key'] = hash(
                'sha256',
                'navigation'
                    . "\0"
                    . $candidate['fingerprint']
            );
        }

        unset($candidate);

        return [
            'candidates' => $result,
            'count' => count($result),
            'auto_bind_count' => count(
                array_filter(
                    $result,
                    static fn(
                        array $candidate
                    ): bool =>
                    ! empty($candidate['auto_bind'])
                )
            ),
            'ambiguous_count' => count(
                array_filter(
                    $result,
                    static fn(
                        array $candidate
                    ): bool =>
                    ! empty($candidate['ambiguous'])
                )
            ),
        ];
    }

    private function candidate(
        string $sourcePath,
        \DOMElement $container,
        string $sourceKind,
        bool $explicit,
        \DOMDocument $document,
        array $pathMap,
        array $domByPath,
        array $fileIndex
    ): ?array {
        $objectId = spl_object_id(
            $container
        );

        if (! isset($pathMap[$objectId])) {
            return null;
        }

        $containerPath = $pathMap[$objectId];

        if (! isset($domByPath[$containerPath])) {
            return null;
        }

        $domElement = $domByPath[$containerPath];

        $fingerprint = (string) (
            $domElement['subtree_fingerprint']
            ?? ''
        );

        $containerRef = (string) (
            $domElement['ref']
            ?? ''
        );

        if (
            $fingerprint === ''
            || $containerRef === ''
        ) {
            return null;
        }

        $listRoot = $this->listRoot(
            $container
        );

        if ($listRoot !== null) {
            $items = $this->listItems(
                $sourcePath,
                $listRoot,
                $fingerprint,
                '',
                $fileIndex
            );

            if ($items === []) {
                return null;
            }

            $mode = 'list';
        } else {
            $anchors = $this->directAnchors(
                $container
            );

            if (count($anchors) < 2) {
                return null;
            }

            $items = $this->anchorItems(
                $sourcePath,
                $anchors,
                $fingerprint,
                $fileIndex
            );

            if ($items === []) {
                return null;
            }

            $mode = 'links';
        }

        $templateHtml = $document->saveHTML(
            $container
        );

        if (! is_string($templateHtml)) {
            return null;
        }

        return [
            'source' => str_replace(
                '\\',
                '/',
                $sourcePath
            ),
            'source_kind' => $sourceKind,
            'container_tag' => strtolower(
                $container->tagName
            ),
            'container_ref' => $containerRef,
            'container_path' => $containerPath,
            'fingerprint' => $fingerprint,
            'mode' => $mode,
            'items' => $items,
            'template_html' => $templateHtml,
            'auto_bind' => $explicit,
        ];
    }

    private function listItems(
        string $sourcePath,
        \DOMElement $list,
        string $fingerprint,
        string $prefix,
        array $fileIndex
    ): array {
        $result = [];
        $position = 0;

        foreach (
            $this->directListItems($list)
            as $item
        ) {
            $anchor = $this->primaryAnchor(
                $item
            );

            if ($anchor === null) {
                continue;
            }

            $label = $this->label(
                $anchor
            );

            if ($label === '') {
                continue;
            }

            $position++;

            $positionKey = $prefix === ''
                ? (string) $position
                : $prefix
                . '.'
                . $position;

            $url = trim(
                $anchor->getAttribute(
                    'href'
                )
            );

            if (! $this->safeNavigationUrl($url)) {
                continue;
            }

            $target = $this->internalTarget(
                $sourcePath,
                $url,
                $fileIndex
            );

            $nestedList = $this->nestedList(
                $item
            );

            $children = $nestedList !== null
                ? $this->listItems(
                    $sourcePath,
                    $nestedList,
                    $fingerprint,
                    $positionKey,
                    $fileIndex
                )
                : [];

            $result[] = [
                'key' => substr(
                    hash(
                        'sha256',
                        $fingerprint
                            . "\0"
                            . $positionKey
                    ),
                    0,
                    24
                ),
                'position' => $positionKey,
                'source_label' => $label,
                'source_url' => $url,
                'target' => $target,
                'opens_in_new_tab'
                => strtolower(
                    $anchor->getAttribute(
                        'target'
                    )
                ) === '_blank',
                'rel' => sanitize_text_field(
                    $anchor->getAttribute(
                        'rel'
                    )
                ),
                'children' => $children,
            ];
        }

        return $result;
    }

    private function anchorItems(
        string $sourcePath,
        array $anchors,
        string $fingerprint,
        array $fileIndex
    ): array {
        $items = [];
        $position = 0;

        foreach ($anchors as $anchor) {
            $label = $this->label(
                $anchor
            );

            if ($label === '') {
                continue;
            }

            $url = trim(
                $anchor->getAttribute(
                    'href'
                )
            );

            if (! $this->safeNavigationUrl($url)) {
                continue;
            }

            $position++;

            $items[] = [
                'key' => substr(
                    hash(
                        'sha256',
                        $fingerprint
                            . "\0"
                            . $position
                    ),
                    0,
                    24
                ),
                'position' => (string) $position,
                'source_label' => $label,
                'source_url' => $url,
                'target' => $this->internalTarget(
                    $sourcePath,
                    $url,
                    $fileIndex
                ),
                'opens_in_new_tab'
                => strtolower(
                    $anchor->getAttribute(
                        'target'
                    )
                ) === '_blank',
                'rel' => sanitize_text_field(
                    $anchor->getAttribute(
                        'rel'
                    )
                ),
                'children' => [],
            ];
        }

        return $items;
    }

    private function internalTarget(
        string $sourcePath,
        string $url,
        array $fileIndex
    ): ?string {
        if (
            $url === ''
            || str_starts_with(
                $url,
                '#'
            )
        ) {
            return null;
        }

        $resolved = $this->resolver->resolve(
            $sourcePath,
            $url,
            $fileIndex
        );

        if (
            ($resolved['kind'] ?? '')
            !== 'local'
            || ($resolved['status'] ?? '')
            !== 'found'
        ) {
            return null;
        }

        $target = (string) (
            $resolved['target']
            ?? ''
        );

        $extension = strtolower(
            pathinfo(
                $target,
                PATHINFO_EXTENSION
            )
        );

        if (
            ! in_array(
                $extension,
                [
                    'html',
                    'htm',
                ],
                true
            )
        ) {
            return null;
        }

        return $target;
    }

    private function safeNavigationUrl(
        string $url
    ): bool {
        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '#')) {
            return true;
        }

        if (
            preg_match(
                '/^([A-Za-z][A-Za-z0-9+.-]*):/',
                $url,
                $matches
            ) === 1
        ) {
            return in_array(
                strtolower($matches[1]),
                [
                    'http',
                    'https',
                    'mailto',
                    'tel',
                ],
                true
            );
        }

        return true;
    }

    private function label(
        \DOMElement $anchor
    ): string {
        $label = wp_strip_all_tags(
            $anchor->textContent
        );

        $label = preg_replace(
            '/\s+/u',
            ' ',
            $label
        );

        return is_string($label)
            ? trim($label)
            : '';
    }

    private function listRoot(
        \DOMElement $container
    ): ?\DOMElement {
        $tag = strtolower(
            $container->tagName
        );

        if (
            in_array(
                $tag,
                [
                    'ul',
                    'ol',
                ],
                true
            )
            && $this->directListItems(
                $container
            ) !== []
        ) {
            return $container;
        }

        $queue = [];

        foreach (
            $container->childNodes
            as $child
        ) {
            if ($child instanceof \DOMElement) {
                $queue[] = $child;
            }
        }

        while ($queue !== []) {
            $element = array_shift(
                $queue
            );

            if (! $element instanceof \DOMElement) {
                continue;
            }

            $elementTag = strtolower(
                $element->tagName
            );

            if (
                in_array(
                    $elementTag,
                    [
                        'ul',
                        'ol',
                    ],
                    true
                )
                && $this->directListItems(
                    $element
                ) !== []
            ) {
                return $element;
            }

            foreach (
                $element->childNodes
                as $child
            ) {
                if ($child instanceof \DOMElement) {
                    $queue[] = $child;
                }
            }
        }

        return null;
    }

    private function directListItems(
        \DOMElement $list
    ): array {
        $items = [];

        foreach ($list->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && strtolower(
                    $child->tagName
                ) === 'li'
            ) {
                $items[] = $child;
            }
        }

        return $items;
    }

    private function directAnchors(
        \DOMElement $container
    ): array {
        $anchors = [];

        foreach (
            $container->childNodes
            as $child
        ) {
            if (
                $child instanceof \DOMElement
                && strtolower(
                    $child->tagName
                ) === 'a'
            ) {
                $anchors[] = $child;
            }
        }

        return $anchors;
    }

    private function primaryAnchor(
        \DOMElement $item
    ): ?\DOMElement {
        foreach ($item->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower(
                $child->tagName
            );

            if ($tag === 'a') {
                return $child;
            }

            if (
                in_array(
                    $tag,
                    [
                        'ul',
                        'ol',
                    ],
                    true
                )
            ) {
                continue;
            }

            $anchor = $this->firstAnchorOutsideList(
                $child
            );

            if ($anchor !== null) {
                return $anchor;
            }
        }

        return null;
    }

    private function firstAnchorOutsideList(
        \DOMElement $element
    ): ?\DOMElement {
        if (
            strtolower(
                $element->tagName
            ) === 'a'
        ) {
            return $element;
        }

        foreach (
            $element->childNodes
            as $child
        ) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower(
                $child->tagName
            );

            if (
                in_array(
                    $tag,
                    [
                        'ul',
                        'ol',
                    ],
                    true
                )
            ) {
                continue;
            }

            $anchor = $this
                ->firstAnchorOutsideList(
                    $child
                );

            if ($anchor !== null) {
                return $anchor;
            }
        }

        return null;
    }

    private function nestedList(
        \DOMElement $item
    ): ?\DOMElement {
        foreach ($item->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower(
                $child->tagName
            );

            if (
                in_array(
                    $tag,
                    [
                        'ul',
                        'ol',
                    ],
                    true
                )
            ) {
                return $child;
            }

            $nested = $this
                ->nestedListBeforeChildItem(
                    $child
                );

            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    private function nestedListBeforeChildItem(
        \DOMElement $element
    ): ?\DOMElement {
        foreach (
            $element->childNodes
            as $child
        ) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower(
                $child->tagName
            );

            if ($tag === 'li') {
                continue;
            }

            if (
                in_array(
                    $tag,
                    [
                        'ul',
                        'ol',
                    ],
                    true
                )
            ) {
                return $child;
            }

            $nested = $this
                ->nestedListBeforeChildItem(
                    $child
                );

            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    private function hasMenuMarker(\DOMElement $element): bool
    {
        $classes = preg_split(
            '/\s+/',
            strtolower(trim($element->getAttribute('class')))
        );

        if (! is_array($classes)) {
            return false;
        }

        return array_intersect($classes, [
            'navbar-nav',
            'main-navigation',
            'navigation',
            'menu',
            'nav-menu',
            'nav-list',
            'main-menu',
            'primary-menu',
            'navigation-menu',
            'menu-items',
            'menu-list',
        ]) !== [];
    }

    private function isNavigationList(\DOMElement $list): bool
    {
        $excluded = [
            'nav-tabs',
            'pagination',
            'breadcrumb',
            'product-list',
            'product-items',
            'tab-list',
        ];

        $classes = preg_split(
            '/\s+/',
            strtolower(trim($list->getAttribute('class')))
        );

        if (is_array($classes) && array_intersect($classes, $excluded) !== []) {
            return false;
        }

        if ($this->hasMenuMarker($list)) {
            return true;
        }

        $parent = $list->parentNode;

        while ($parent instanceof \DOMElement) {
            if (
                strtolower($parent->getAttribute('role')) === 'navigation'
                || $this->hasMenuMarker($parent)
            ) {
                return true;
            }

            if (in_array(strtolower($parent->tagName), ['main', 'article'], true)) {
                return false;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    private function hasAncestorTag(
        \DOMElement $element,
        string $tag
    ): bool {
        $parent = $element->parentNode;

        while (
            $parent instanceof \DOMElement
        ) {
            if (
                strtolower(
                    $parent->tagName
                ) === $tag
            ) {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    private function hasAncestorList(
        \DOMElement $element
    ): bool {
        $parent = $element->parentNode;

        while (
            $parent instanceof \DOMElement
        ) {
            if (
                in_array(
                    strtolower(
                        $parent->tagName
                    ),
                    [
                        'ul',
                        'ol',
                    ],
                    true
                )
            ) {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    private function mapPaths(
        \DOMElement $element,
        string $path,
        array &$paths
    ): void {
        $paths[spl_object_id($element)] = $path;

        $tagCounts = [];

        foreach (
            $element->childNodes
            as $child
        ) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower(
                $child->tagName
            );

            if (! isset($tagCounts[$tag])) {
                $tagCounts[$tag] = 0;
            }

            $tagCounts[$tag]++;

            $this->mapPaths(
                $child,
                $path
                    . '/'
                    . $tag
                    . '['
                    . $tagCounts[$tag]
                    . ']',
                $paths
            );
        }
    }
}
