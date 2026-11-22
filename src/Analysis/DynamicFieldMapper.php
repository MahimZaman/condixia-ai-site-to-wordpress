<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

use Condixia\AISiteToWordPress\Integration\WooCommerce\WooCommerceFieldMapper;

defined('ABSPATH') || exit;

final class DynamicFieldMapper
{
    private ?WooCommerceFieldMapper $wooCommerce;

    public function __construct(
        ?WooCommerceFieldMapper $wooCommerce = null
    ) {
        if ($wooCommerce !== null) {
            $this->wooCommerce = $wooCommerce;

            return;
        }

        $this->wooCommerce =
            class_exists('WooCommerce')
            && function_exists('wc_get_product')
            ? new WooCommerceFieldMapper()
            : null;
    }

    public function mapRegion(
        array $document,
        string $rootPath
    ): array {
        $elements = is_array(
            $document['elements']
                ?? null
        )
            ? $document['elements']
            : [];

        if (
            $elements === []
            || $rootPath === ''
        ) {
            return $this->emptyMap();
        }

        $byRef = [];

        foreach ($elements as $element) {
            if (
                is_array($element)
                && ! empty($element['ref'])
            ) {
                $byRef[(string) $element['ref']] = $element;
            }
        }

        $suggestions = [];

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $path = (string) (
                $element['path']
                ?? ''
            );

            if (! $this->inside(
                $rootPath,
                $path
            )) {
                continue;
            }

            $suggestions = array_merge(
                $suggestions,
                $this->elementSuggestions(
                    $element,
                    $rootPath,
                    $byRef
                )
            );

            if ($this->wooCommerce !== null) {
                $suggestions = array_merge(
                    $suggestions,
                    $this->wooCommerce
                        ->suggestions(
                            $element,
                            $rootPath,
                            $byRef
                        )
                );
            }
        }

        $suggestions = $this->deduplicate(
            $suggestions
        );

        usort(
            $suggestions,
            static function (
                array $left,
                array $right
            ): int {
                if (
                    $left['confidence']
                    !== $right['confidence']
                ) {
                    return $right['confidence']
                        <=>
                        $left['confidence'];
                }

                return strcmp(
                    $left['relative_path'],
                    $right['relative_path']
                );
            }
        );

        return [
            'root_path' => $rootPath,
            'suggestions' => $suggestions,
            'suggestion_count' => count(
                $suggestions
            ),
            'ambiguous_count' => count(
                array_filter(
                    $suggestions,
                    static fn(
                        array $suggestion
                    ): bool =>
                    ! empty($suggestion['ambiguous'])
                )
            ),
            'available_sources' => $this
                ->catalog(),
        ];
    }

    public function mapDocument(
        array $document
    ): array {
        $rootPath = '';

        foreach (
            $document['elements'] ?? []
            as $element
        ) {
            if (
                ! is_array($element)
                || empty($element['path'])
            ) {
                continue;
            }

            if (
                strtolower(
                    (string) (
                        $element['tag']
                        ?? ''
                    )
                ) === 'body'
            ) {
                $rootPath = (string) $element['path'];

                break;
            }

            if ($rootPath === '') {
                $rootPath = (string) $element['path'];
            }
        }

        return $this->mapRegion(
            $document,
            $rootPath
        );
    }

    public function mapHtml(
        string $sourcePath,
        string $html
    ): array {
        if (! class_exists(\DOMDocument::class)) {
            return $this->emptyMap();
        }

        $previous = libxml_use_internal_errors(
            true
        );

        libxml_clear_errors();

        $document = new \DOMDocument(
            '1.0',
            'UTF-8'
        );

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'
                . $html,
            LIBXML_NONET
                | LIBXML_COMPACT
                | LIBXML_NOERROR
                | LIBXML_NOWARNING
        );

        libxml_clear_errors();

        libxml_use_internal_errors(
            $previous
        );

        if (! $loaded) {
            return $this->emptyMap();
        }

        $root = $document->documentElement;

        if (! $root instanceof \DOMElement) {
            return $this->emptyMap();
        }

        $elements = [];

        $this->collectDomElements(
            $root,
            '/'
                . strtolower($root->tagName)
                . '[1]',
            str_replace(
                '\\',
                '/',
                $sourcePath
            ),
            null,
            $elements
        );

        $map = $this->mapDocument(
            [
                'elements' => $elements,
            ]
        );

        $map['suggestions'] = $this->deduplicate(
            array_merge(
                $map['suggestions'],
                $this->contextSuggestions(
                    $elements,
                    (string) $map['root_path']
                )
            )
        );

        usort(
            $map['suggestions'],
            static function (
                array $left,
                array $right
            ): int {
                if (
                    $left['confidence']
                    !== $right['confidence']
                ) {
                    return $right['confidence']
                        <=>
                        $left['confidence'];
                }

                return strcmp(
                    $left['relative_path'],
                    $right['relative_path']
                );
            }
        );

        $map['suggestion_count'] = count(
            $map['suggestions']
        );

        $map['ambiguous_count'] = count(
            array_filter(
                $map['suggestions'],
                static fn(
                    array $suggestion
                ): bool =>
                ! empty($suggestion['ambiguous'])
            )
        );

        $map['available_sources'] = $this->catalog();

        return $map;
    }

    private function contextSuggestions(
        array $elements,
        string $rootPath
    ): array {
        $suggestions = [];

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $tag = strtolower(
                (string) (
                    $element['tag']
                    ?? ''
                )
            );

            $attributes = is_array(
                $element['attributes']
                    ?? null
            )
                ? $element['attributes']
                : [];

            $semantic = $this->contextSemantic(
                $attributes
            );

            if (
                in_array(
                    $tag,
                    [
                        'h1',
                        'h2',
                        'h3',
                    ],
                    true
                )
                && $this->containsAny(
                    $semantic,
                    [
                        'archive-title',
                        'archive_title',
                        'page-title',
                        'category-title',
                        'tag-title',
                        'author-title',
                        'date-title',
                        'taxonomy-title',
                    ]
                )
            ) {
                $suggestions[] = $this->suggestion(
                    $element,
                    $rootPath,
                    'query.archive_title',
                    'text',
                    '',
                    0.96,
                    'archive_title_semantics'
                );
            }

            if (
                $this->containsAny(
                    $semantic,
                    [
                        'archive-description',
                        'archive_description',
                        'taxonomy-description',
                        'term-description',
                        'category-description',
                        'tag-description',
                    ]
                )
            ) {
                $suggestions[] = $this->suggestion(
                    $element,
                    $rootPath,
                    'query.archive_description',
                    'html',
                    '',
                    0.94,
                    'archive_description_semantics'
                );
            }

            if (
                $this->containsAny(
                    $semantic,
                    [
                        'result-count',
                        'results-count',
                        'search-count',
                        'post-count',
                        'found-posts',
                    ]
                )
            ) {
                $suggestions[] = $this->suggestion(
                    $element,
                    $rootPath,
                    'query.result_count',
                    'text',
                    '',
                    0.95,
                    'result_count_semantics'
                );
            }

            if (
                $this->containsAny(
                    $semantic,
                    [
                        'pagination',
                        'pager',
                        'nav-links',
                    ]
                )
                || (
                    $tag === 'nav'
                    && $this->containsAny(
                        strtolower(
                            (string) (
                                $attributes['aria-label']
                                ?? ''
                            )
                        ),
                        [
                            'pagination',
                            'pages',
                        ]
                    )
                )
            ) {
                $suggestions[] = $this->suggestion(
                    $element,
                    $rootPath,
                    'query.pagination',
                    'html',
                    '',
                    0.97,
                    'pagination_semantics'
                );
            }

            if (
                in_array(
                    $tag,
                    [
                        'h1',
                        'h2',
                        'h3',
                    ],
                    true
                )
                && $this->containsAny(
                    $semantic,
                    [
                        'search-title',
                        'search-results',
                        'search-heading',
                    ]
                )
            ) {
                $suggestions[] = $this->suggestion(
                    $element,
                    $rootPath,
                    'query.search_title',
                    'text',
                    '',
                    0.97,
                    'search_title_semantics'
                );
            }

            if (
                $tag === 'form'
                && (
                    strtolower(
                        (string) (
                            $attributes['role']
                            ?? ''
                        )
                    ) === 'search'
                    || $this->containsAny(
                        $semantic,
                        [
                            'search-form',
                            'site-search',
                        ]
                    )
                )
            ) {
                $suggestions[] = $this->suggestion(
                    $element,
                    $rootPath,
                    'query.search_action',
                    'url',
                    'action',
                    0.99,
                    'search_form_action'
                );

                $suggestions[] = $this->suggestion(
                    $element,
                    $rootPath,
                    'query.search_method',
                    'attribute',
                    'method',
                    0.99,
                    'search_form_method'
                );
            }

            $inputType = strtolower(
                (string) (
                    $attributes['type']
                    ?? ''
                )
            );

            $inputName = strtolower(
                (string) (
                    $attributes['name']
                    ?? ''
                )
            );

            if (
                $tag === 'input'
                && (
                    $inputType === 'search'
                    || $inputName === 's'
                    || $this->containsAny(
                        $semantic,
                        [
                            'search-field',
                            'search-input',
                        ]
                    )
                )
            ) {
                $suggestions[] = $this->suggestion(
                    $element,
                    $rootPath,
                    'query.search_term',
                    'attribute',
                    'value',
                    0.99,
                    'search_term_input'
                );

                $suggestions[] = $this->suggestion(
                    $element,
                    $rootPath,
                    'query.search_parameter',
                    'attribute',
                    'name',
                    0.99,
                    'search_parameter_input'
                );
            }
        }

        return $suggestions;
    }

    private function contextSemantic(
        array $attributes
    ): string {
        $classes = is_array(
            $attributes['class']
                ?? null
        )
            ? $attributes['class']
            : [];

        return strtolower(
            implode(
                ' ',
                $classes
            )
                . ' '
                . (string) (
                    $attributes['id']
                    ?? ''
                )
                . ' '
                . (string) (
                    $attributes['role']
                    ?? ''
                )
                . ' '
                . (string) (
                    $attributes['aria-label']
                    ?? ''
                )
                . ' '
                . (string) (
                    $attributes['itemprop']
                    ?? ''
                )
                . ' '
                . (string) (
                    $attributes['property']
                    ?? ''
                )
                . ' '
                . (string) (
                    $attributes['name']
                    ?? ''
                )
        );
    }

    private function collectDomElements(
        \DOMElement $element,
        string $path,
        string $sourcePath,
        ?string $parentRef,
        array &$elements
    ): void {
        $ref = 'cdx-stw-'
            . substr(
                hash(
                    'sha256',
                    $sourcePath
                        . "\0"
                        . $path
                ),
                0,
                24
            );

        $attributes = [];

        foreach ($element->attributes as $attribute) {
            if (! $attribute instanceof \DOMAttr) {
                continue;
            }

            $name = strtolower(
                $attribute->name
            );

            if ($name === 'class') {
                $classes = preg_split(
                    '/\s+/',
                    trim($attribute->value)
                );

                $attributes['class'] = is_array(
                    $classes
                )
                    ? array_values(
                        array_filter(
                            $classes,
                            static fn(
                                string $class
                            ): bool =>
                            $class !== ''
                        )
                    )
                    : [];

                continue;
            }

            $attributes[$name] = $attribute->value;
        }

        $elements[] = [
            'ref' => $ref,
            'path' => $path,
            'tag' => strtolower(
                $element->tagName
            ),
            'attributes' => $attributes,
            'parent_ref' => $parentRef,
        ];

        $counts = [];

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

            $counts[$tag] = (
                $counts[$tag]
                ?? 0
            ) + 1;

            $this->collectDomElements(
                $child,
                $path
                    . '/'
                    . $tag
                    . '['
                    . $counts[$tag]
                    . ']',
                $sourcePath,
                $ref,
                $elements
            );
        }
    }

    private function elementSuggestions(
        array $element,
        string $rootPath,
        array $byRef
    ): array {
        $tag = strtolower(
            (string) (
                $element['tag']
                ?? ''
            )
        );

        $attributes = is_array(
            $element['attributes']
                ?? null
        )
            ? $element['attributes']
            : [];

        $classes = is_array(
            $attributes['class']
                ?? null
        )
            ? $attributes['class']
            : [];

        $semantic = strtolower(
            implode(
                ' ',
                $classes
            )
                . ' '
                . (string) (
                    $attributes['itemprop']
                    ?? ''
                )
                . ' '
                . (string) (
                    $attributes['property']
                    ?? ''
                )
                . ' '
                . (string) (
                    $attributes['name']
                    ?? ''
                )
        );

        $suggestions = [];

        if (
            in_array(
                $tag,
                [
                    'h1',
                    'h2',
                    'h3',
                    'h4',
                    'h5',
                    'h6',
                ],
                true
            )
            || $this->containsAny(
                $semantic,
                [
                    'headline',
                    'title',
                    'entry-title',
                    'post-title',
                    'product-title',
                ]
            )
        ) {
            $suggestions[] = $this->suggestion(
                $element,
                $rootPath,
                'post.title',
                'text',
                '',
                $tag === 'h1'
                    || $tag === 'h2'
                    || $tag === 'h3'
                    ? 0.94
                    : 0.84,
                'title_semantics'
            );
        }

        if (
            $this->containsAny(
                $semantic,
                [
                    'articlebody',
                    'article-body',
                    'entry-content',
                    'post-content',
                    'content-body',
                ]
            )
        ) {
            $suggestions[] = $this->suggestion(
                $element,
                $rootPath,
                'post.content',
                'html',
                '',
                0.92,
                'content_semantics'
            );
        }

        if (
            $this->containsAny(
                $semantic,
                [
                    'excerpt',
                    'summary',
                    'description',
                    'intro',
                    'teaser',
                ]
            )
        ) {
            $suggestions[] = $this->suggestion(
                $element,
                $rootPath,
                'post.excerpt',
                'text',
                '',
                0.87,
                'excerpt_semantics'
            );
        }

        if ($tag === 'img') {
            $strongImage = $this->containsAny(
                $semantic,
                [
                    'image',
                    'featured',
                    'thumbnail',
                    'thumb',
                    'photo',
                ]
            );

            $suggestions[] = $this->suggestion(
                $element,
                $rootPath,
                'post.featured_image',
                'media',
                'src',
                $strongImage
                    ? 0.92
                    : 0.68,
                $strongImage
                    ? 'featured_image_semantics'
                    : 'generic_image'
            );
        }

        if (
            $tag === 'time'
            || $this->containsAny(
                $semantic,
                [
                    'datepublished',
                    'datemodified',
                    'post-date',
                    'entry-date',
                    'published',
                    'date',
                ]
            )
        ) {
            $suggestions[] = $this->suggestion(
                $element,
                $rootPath,
                'post.date',
                'text',
                '',
                $tag === 'time'
                    ? 0.94
                    : 0.84,
                'date_semantics'
            );

            if (
                $tag === 'time'
                && array_key_exists(
                    'datetime',
                    $attributes
                )
            ) {
                $attributeSuggestion = $this
                    ->suggestion(
                        $element,
                        $rootPath,
                        'post.date',
                        'attribute',
                        'datetime',
                        0.91,
                        'datetime_attribute'
                    );

                $attributeSuggestion['config'] = [
                    'format' => 'c',
                ];

                $suggestions[] = $attributeSuggestion;
            }
        }

        if (
            $this->containsAny(
                $semantic,
                [
                    'author',
                    'byline',
                    'posted-by',
                    'writer',
                ]
            )
        ) {
            $suggestions[] = $this->suggestion(
                $element,
                $rootPath,
                'post.author',
                'text',
                '',
                0.88,
                'author_semantics'
            );
        }

        if (
            $this->containsAny(
                $semantic,
                [
                    'category',
                    'categories',
                    'tag',
                    'tags',
                    'taxonomy',
                    'taxonomies',
                    'terms',
                ]
            )
        ) {
            $suggestion = $this->suggestion(
                $element,
                $rootPath,
                'post.taxonomies',
                'taxonomy',
                '',
                0.78,
                'taxonomy_semantics'
            );

            $suggestion['config_required'] = [
                'taxonomy',
            ];

            $suggestions[] = $suggestion;
        }

        if (
            $tag === 'a'
            && array_key_exists(
                'href',
                $attributes
            )
        ) {
            $parent = $this->parent(
                $element,
                $byRef
            );

            $parentTag = strtolower(
                (string) (
                    $parent['tag']
                    ?? ''
                )
            );

            $strongLink = $this->containsAny(
                $semantic,
                [
                    'permalink',
                    'read-more',
                    'readmore',
                    'button',
                    'cta',
                    'details',
                    'more-link',
                    'title-link',
                ]
            )
                || in_array(
                    $parentTag,
                    [
                        'h1',
                        'h2',
                        'h3',
                        'h4',
                        'h5',
                        'h6',
                    ],
                    true
                );

            if ($strongLink) {
                $suggestions[] = $this
                    ->suggestion(
                        $element,
                        $rootPath,
                        'post.permalink',
                        'url',
                        'href',
                        0.91,
                        'permalink_semantics'
                    );
            }
        }

        return $suggestions;
    }

    private function suggestion(
        array $element,
        string $rootPath,
        string $source,
        string $bindingType,
        string $bindingTarget,
        float $confidence,
        string $reason
    ): array {
        $path = (string) (
            $element['path']
            ?? ''
        );

        return [
            'source_ref' => (string) (
                $element['ref']
                ?? ''
            ),
            'source_path' => $path,
            'relative_path' => $this
                ->relativePath(
                    $rootPath,
                    $path
                ),
            'tag' => (string) (
                $element['tag']
                ?? ''
            ),
            'data_source' => $source,
            'binding_type' => $bindingType,
            'binding_target' => $bindingTarget,
            'config' => [],
            'confidence' => round(
                $confidence,
                3
            ),
            'ambiguous' => $confidence < 0.80,
            'reason' => $reason,
            'editable' => true,
        ];
    }

    private function parent(
        array $element,
        array $byRef
    ): array {
        $parentRef = isset(
            $element['parent_ref']
        ) && is_string(
            $element['parent_ref']
        )
            ? $element['parent_ref']
            : '';

        if (
            $parentRef === ''
            || ! isset($byRef[$parentRef])
        ) {
            return [];
        }

        return $byRef[$parentRef];
    }

    private function inside(
        string $rootPath,
        string $path
    ): bool {
        return $path === $rootPath
            || str_starts_with(
                $path,
                $rootPath . '/'
            );
    }

    private function relativePath(
        string $rootPath,
        string $path
    ): string {
        if ($path === $rootPath) {
            return '.';
        }

        return ltrim(
            substr(
                $path,
                strlen($rootPath)
            ),
            '/'
        );
    }

    private function containsAny(
        string $value,
        array $needles
    ): bool {
        foreach ($needles as $needle) {
            if (
                str_contains(
                    $value,
                    strtolower($needle)
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function deduplicate(
        array $suggestions
    ): array {
        $unique = [];

        foreach ($suggestions as $suggestion) {
            $key = $suggestion['source_ref']
                . "\0"
                . $suggestion['data_source']
                . "\0"
                . $suggestion['binding_type']
                . "\0"
                . $suggestion['binding_target'];

            if (
                ! isset($unique[$key])
                || $suggestion['confidence']
                > $unique[$key]['confidence']
            ) {
                $unique[$key] = $suggestion;
            }
        }

        return array_values(
            $unique
        );
    }

    private function catalog(): array
    {
        $catalog = [
            [
                'source' => 'post.title',
                'binding_types' => [
                    'text',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'post.content',
                'binding_types' => [
                    'html',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'post.excerpt',
                'binding_types' => [
                    'text',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'post.featured_image',
                'binding_types' => [
                    'media',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'post.date',
                'binding_types' => [
                    'text',
                    'attribute',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'post.author',
                'binding_types' => [
                    'text',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'post.taxonomies',
                'binding_types' => [
                    'taxonomy',
                ],
                'config_required' => [
                    'taxonomy',
                ],
            ],
            [
                'source' => 'post.permalink',
                'binding_types' => [
                    'url',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'post.meta',
                'binding_types' => [
                    'text',
                    'html',
                    'attribute',
                    'url',
                ],
                'config_required' => [
                    'key',
                ],
            ],
            [
                'source' => 'query.archive_title',
                'binding_types' => [
                    'text',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'query.archive_description',
                'binding_types' => [
                    'html',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'query.result_count',
                'binding_types' => [
                    'text',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'query.pagination',
                'binding_types' => [
                    'html',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'query.search_title',
                'binding_types' => [
                    'text',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'query.search_term',
                'binding_types' => [
                    'text',
                    'attribute',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'query.search_action',
                'binding_types' => [
                    'url',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'query.search_parameter',
                'binding_types' => [
                    'attribute',
                ],
                'config_required' => [],
            ],
            [
                'source' => 'query.search_method',
                'binding_types' => [
                    'attribute',
                ],
                'config_required' => [],
            ],
        ];

        if ($this->wooCommerce !== null) {
            $catalog = array_merge(
                $catalog,
                $this->wooCommerce->catalog()
            );
        }

        return $catalog;
    }

    private function emptyMap(): array
    {
        return [
            'root_path' => '',
            'suggestions' => [],
            'suggestion_count' => 0,
            'ambiguous_count' => 0,
            'available_sources' => $this
                ->catalog(),
        ];
    }
}
