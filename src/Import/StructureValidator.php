<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Import;

use Condixia\AISiteToWordPress\Analysis\TemplateRoles;

defined('ABSPATH') || exit;

final class StructureValidator
{
    public function validate(
        array $request,
        array $structure
    ): array|\WP_Error {
        $pages = $this->pages(
            $request['pages'] ?? [],
            $structure['pages'] ?? [],
            isset($request['home_page'])
                ? (int) $request['home_page']
                : 0
        );

        if (is_wp_error($pages)) {
            return $pages;
        }

        $templates = $this->templates(
            $request['templates'] ?? [],
            $structure['templates'] ?? [],
            ! empty($structure['woocommerce_active'])
        );

        if (is_wp_error($templates)) {
            return $templates;
        }

        $siteParts = $this->siteParts(
            $request['site_parts'] ?? [],
            $structure['site_parts'] ?? []
        );

        if (is_wp_error($siteParts)) {
            return $siteParts;
        }

        $dynamicGrids = [];

        foreach (
            $structure['dynamic_grid_candidates'] ?? []
            as $candidate
        ) {
            if (
                ! is_array($candidate)
                || empty($candidate['id'])
            ) {
                continue;
            }

            $id = (int) $candidate['id'];
            $metadata = is_array($candidate['metadata'] ?? null)
                ? $candidate['metadata']
                : [];

            $dynamicGrids[$id] = [
                'id' => $id,
                'include' => false,
                'query' => [],
                'mappings' => [],
            ];
        }

        return [
            'pages' => $pages,
            'templates' => $templates,
            'site_parts' => $siteParts,
            'dynamic_grids' => $dynamicGrids,
        ];
    }

    private function pages(
        mixed $requestPages,
        array $artifacts,
        int $homePageId
    ): array|\WP_Error {
        if (! is_array($requestPages)) {
            return $this->error(
                __(
                    'The page confirmation data is invalid.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $allowed = $this->indexById(
            $artifacts
        );

        $pages = [];
        $slugs = [];
        $included = 0;

        foreach ($allowed as $artifactId => $artifact) {
            $input = isset(
                $requestPages[$artifactId]
            ) && is_array($requestPages[$artifactId])
                ? $requestPages[$artifactId]
                : [];

            $include = ! empty($input['include']);

            $title = sanitize_text_field(
                (string) (
                    $input['title']
                    ?? $artifact['title']
                    ?? ''
                )
            );

            $slug = sanitize_title(
                (string) (
                    $input['slug']
                    ?? $artifact['slug']
                    ?? ''
                )
            );

            if ($include) {
                $included++;

                if ($title === '') {
                    return $this->error(
                        __(
                            'Every included page must have a title.',
                            'condixia-ai-site-to-wordpress'
                        )
                    );
                }

                if ($slug === '') {
                    return $this->error(
                        __(
                            'Every included page must have a valid slug.',
                            'condixia-ai-site-to-wordpress'
                        )
                    );
                }

                $slugKey = strtolower(
                    $slug
                );

                if (isset($slugs[$slugKey])) {
                    return $this->error(
                        sprintf(
                            __(
                                'Two confirmed pages use the slug "%s". Give each page a unique slug before continuing.',
                                'condixia-ai-site-to-wordpress'
                            ),
                            $slug
                        )
                    );
                }

                $slugs[$slugKey] = true;
            }

            $pages[$artifactId] = [
                'id' => $artifactId,
                'include' => $include,
                'title' => $title,
                'slug' => $slug,
                'home' => $include
                    && $homePageId === $artifactId,
            ];
        }

        if ($included < 1) {
            return $this->error(
                __(
                    'Keep at least one page in the confirmed import structure.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        if (
            $homePageId > 0
            && (
                ! isset($pages[$homePageId])
                || ! $pages[$homePageId]['include']
            )
        ) {
            return $this->error(
                __(
                    'The selected homepage must be an included page.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        return $pages;
    }

    private function templates(
        mixed $requestTemplates,
        array $artifacts,
        bool $woocommerceActive
    ): array|\WP_Error {
        if (! is_array($requestTemplates)) {
            return $this->error(
                __(
                    'The template confirmation data is invalid.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $allowed = $this->indexById(
            $artifacts
        );

        $templates = [];
        $keys = [];

        foreach ($allowed as $artifactId => $artifact) {
            $metadata = is_array(
                $artifact['metadata'] ?? null
            )
                ? $artifact['metadata']
                : [];

            $input = isset(
                $requestTemplates[$artifactId]
            ) && is_array($requestTemplates[$artifactId])
                ? $requestTemplates[$artifactId]
                : [];

            $include = ! empty($input['include']);

            $role = sanitize_key(
                (string) (
                    $input['role']
                    ?? $artifact['role']
                    ?? ''
                )
            );

            $target = sanitize_key(
                (string) (
                    $input['target']
                    ?? $metadata['target']
                    ?? ''
                )
            );

            $mappings = $this->mappingSelections(
                $input['mappings'] ?? [],
                $artifact
            );

            if (is_wp_error($mappings)) {
                return $mappings;
            }

            if ($include) {
                if (! TemplateRoles::valid($role)) {
                    return $this->error(
                        __(
                            'One of the selected template roles is invalid.',
                            'condixia-ai-site-to-wordpress'
                        )
                    );
                }

                if (
                    TemplateRoles::requiresTarget($role)
                    && $target === ''
                ) {
                    return $this->error(
                        sprintf(
                            __(
                                'The template "%s" requires a post type or taxonomy target.',
                                'condixia-ai-site-to-wordpress'
                            ),
                            (string) $artifact['title']
                        )
                    );
                }

                if (
                    TemplateRoles::requiresWooCommerce($role)
                    && ! $woocommerceActive
                ) {
                    return $this->error(
                        __(
                            'WooCommerce templates are selected, but WooCommerce is not active. Exclude those templates or activate WooCommerce before confirming.',
                            'condixia-ai-site-to-wordpress'
                        )
                    );
                }

                $uniqueKey = TemplateRoles::uniqueKey(
                    $role,
                    $target
                );

                if (isset($keys[$uniqueKey])) {
                    return $this->error(
                        sprintf(
                            __(
                                'More than one confirmed template resolves to "%s". Keep one candidate or change its role/target.',
                                'condixia-ai-site-to-wordpress'
                            ),
                            $uniqueKey
                        )
                    );
                }

                $keys[$uniqueKey] = true;
            }

            $templates[$artifactId] = [
                'id' => $artifactId,
                'include' => $include,
                'role' => $role,
                'target' => $target,
                'mappings' => $mappings,
            ];
        }

        return $templates;
    }

    private function dynamicGrids(
        mixed $requestGrids,
        array $artifacts
    ): array|\WP_Error {
        if (! is_array($requestGrids)) {
            return $this->error(
                __(
                    'The dynamic-grid confirmation data is invalid.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $allowed = $this->indexById(
            $artifacts
        );

        $grids = [];
        $regions = [];

        foreach ($allowed as $artifactId => $artifact) {
            $metadata = is_array(
                $artifact['metadata'] ?? null
            )
                ? $artifact['metadata']
                : [];

            $existingQuery = is_array(
                $metadata['query'] ?? null
            )
                ? $metadata['query']
                : [];

            $input = isset(
                $requestGrids[$artifactId]
            ) && is_array(
                $requestGrids[$artifactId]
            )
                ? $requestGrids[$artifactId]
                : [];

            $include = ! empty($input['include']);

            $role = sanitize_key(
                (string) (
                    $input['role']
                    ?? $artifact['role']
                    ?? ''
                )
            );

            $postsPerPage = absint(
                $input['posts_per_page']
                    ?? $existingQuery['posts_per_page']
                    ?? $metadata['repeat_count']
                    ?? 6
            );

            $postsPerPage = max(
                1,
                min(
                    100,
                    $postsPerPage
                )
            );

            $orderby = sanitize_key(
                (string) (
                    $input['orderby']
                    ?? $existingQuery['orderby']
                    ?? 'date'
                )
            );

            if (
                ! in_array(
                    $orderby,
                    [
                        'date',
                        'modified',
                        'title',
                        'menu_order',
                        'id',
                        'rand',
                        'comment_count',
                    ],
                    true
                )
            ) {
                return $this->error(
                    __(
                        'A dynamic grid has an invalid order-by value.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            $order = strtoupper(
                (string) (
                    $input['order']
                    ?? $existingQuery['order']
                    ?? 'DESC'
                )
            );

            if (
                ! in_array(
                    $order,
                    [
                        'ASC',
                        'DESC',
                    ],
                    true
                )
            ) {
                return $this->error(
                    __(
                        'A dynamic grid has an invalid sort direction.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            $taxonomy = sanitize_key(
                (string) (
                    $input['taxonomy']
                    ?? $existingQuery['taxonomy']
                    ?? ''
                )
            );

            $terms = $this->termSlugs(
                $input['terms']
                    ?? $existingQuery['terms']
                    ?? []
            );

            $mappings = $this->mappingSelections(
                $input['mappings'] ?? [],
                $artifact
            );

            if (is_wp_error($mappings)) {
                return $mappings;
            }

            if ($include) {
                $postTypeObject = get_post_type_object(
                    $postType
                );

                if (
                    ! $postTypeObject instanceof \WP_Post_Type
                    || ! $postTypeObject->public
                    || $postType === 'attachment'
                ) {
                    return $this->error(
                        __(
                            'A dynamic grid must use a registered public post type.',
                            'condixia-ai-site-to-wordpress'
                        )
                    );
                }

                if (
                    $postType === 'product'
                    && (
                        ! class_exists('WooCommerce')
                        || ! function_exists(
                            'wc_get_products'
                        )
                        || ! function_exists(
                            'wc_get_product'
                        )
                    )
                ) {
                    return $this->error(
                        __(
                            'WooCommerce must be active before a product grid can be confirmed.',
                            'condixia-ai-site-to-wordpress'
                        )
                    );
                }

                if (
                    $postType === 'product'
                    && ! in_array(
                        $orderby,
                        [
                            'date',
                            'modified',
                            'title',
                            'id',
                            'rand',
                        ],
                        true
                    )
                ) {
                    return $this->error(
                        __(
                            'WooCommerce product grids support date, modified, title, ID, or random ordering.',
                            'condixia-ai-site-to-wordpress'
                        )
                    );
                }

                if (
                    $postType === 'product'
                    && $taxonomy !== ''
                    && ! in_array(
                        $taxonomy,
                        [
                            'product_cat',
                            'product_tag',
                        ],
                        true
                    )
                ) {
                    return $this->error(
                        __(
                            'Custom WooCommerce product grids support product categories and product tags only.',
                            'condixia-ai-site-to-wordpress'
                        )
                    );
                }

                if ($taxonomy !== '') {
                    $taxonomyObject = get_taxonomy(
                        $taxonomy
                    );

                    if (
                        ! $taxonomyObject instanceof \WP_Taxonomy
                        || ! $taxonomyObject->public
                        || ! in_array(
                            $postType,
                            $taxonomyObject->object_type,
                            true
                        )
                    ) {
                        return $this->error(
                            __(
                                'The selected taxonomy is not valid for the dynamic grid post type.',
                                'condixia-ai-site-to-wordpress'
                            )
                        );
                    }

                    if ($terms === []) {
                        return $this->error(
                            __(
                                'A taxonomy-filtered dynamic grid must include at least one term slug.',
                                'condixia-ai-site-to-wordpress'
                            )
                        );
                    }

                    foreach ($terms as $termSlug) {
                        if (
                            get_term_by(
                                'slug',
                                $termSlug,
                                $taxonomy
                            ) === false
                        ) {
                            return $this->error(
                                sprintf(
                                    __(
                                        'The term slug "%s" does not exist in the selected taxonomy.',
                                        'condixia-ai-site-to-wordpress'
                                    ),
                                    $termSlug
                                )
                            );
                        }
                    }
                } elseif ($terms !== []) {
                    return $this->error(
                        __(
                            'Choose a taxonomy before entering term filters for a dynamic grid.',
                            'condixia-ai-site-to-wordpress'
                        )
                    );
                }

                if ($mappings === []) {
                    return $this->error(
                        sprintf(
                            __(
                                'The dynamic grid "%s" needs at least one confirmed field mapping.',
                                'condixia-ai-site-to-wordpress'
                            ),
                            (string) $artifact['title']
                        )
                    );
                }

                $regionKey = (string) (
                    $artifact['source_path']
                    ?? ''
                )
                    . "\0"
                    . (string) (
                        $metadata['container_ref']
                        ?? ''
                    );

                if (isset($regions[$regionKey])) {
                    return $this->error(
                        __(
                            'Two enabled dynamic grids target the same imported region.',
                            'condixia-ai-site-to-wordpress'
                        )
                    );
                }

                $regions[$regionKey] = true;
            }

            $grids[$artifactId] = [
                'id' => $artifactId,
                'include' => $include,
                'query' => [
                    'post_type' => $postType,
                    'posts_per_page' => $postsPerPage,
                    'orderby' => $orderby,
                    'order' => $order,
                    'taxonomy' => $taxonomy,
                    'terms' => $terms,
                    'paginate' => ! empty($input['paginate']),
                    'use_main_query' => ! empty($input['use_main_query']),
                ],
                'mappings' => $mappings,
            ];
        }

        return $grids;
    }

    private function siteParts(
        mixed $requestParts,
        array $artifacts
    ): array|\WP_Error {
        if (! is_array($requestParts)) {
            return $this->error(
                __(
                    'The site-part confirmation data is invalid.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $allowed = $this->indexById(
            $artifacts
        );

        $parts = [];

        foreach ($allowed as $artifactId => $artifact) {
            $input = isset(
                $requestParts[$artifactId]
            ) && is_array($requestParts[$artifactId])
                ? $requestParts[$artifactId]
                : [];

            $include = ! empty($input['include']);

            $role = sanitize_key(
                (string) (
                    $input['role']
                    ?? $artifact['role']
                    ?? ''
                )
            );

            $title = sanitize_text_field(
                (string) (
                    $input['title']
                    ?? $artifact['title']
                    ?? ''
                )
            );

            if (
                $include
                && ! in_array(
                    $role,
                    [
                        'header',
                        'footer',
                    ],
                    true
                )
            ) {
                return $this->error(
                    __(
                        'A confirmed site part has an invalid role.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            if (
                $include
                && $title === ''
            ) {
                return $this->error(
                    __(
                        'Every included site part must have a title.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            $parts[$artifactId] = [
                'id' => $artifactId,
                'include' => $include,
                'role' => $role,
                'title' => $title,
            ];
        }

        return $parts;
    }

    private function mappingSelections(
        mixed $requestMappings,
        array $artifact
    ): array|\WP_Error {
        if (! is_array($requestMappings)) {
            return $this->error(
                __(
                    'The dynamic field-mapping data is invalid.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $metadata = is_array(
            $artifact['metadata'] ?? null
        )
            ? $artifact['metadata']
            : [];

        $fieldMap = is_array(
            $metadata['field_mapping'] ?? null
        )
            ? $metadata['field_mapping']
            : [];

        $suggestions = is_array(
            $fieldMap['suggestions'] ?? null
        )
            ? $fieldMap['suggestions']
            : [];

        $selected = [];

        foreach ($suggestions as $index => $suggestion) {
            if (! is_array($suggestion)) {
                continue;
            }

            $input = isset(
                $requestMappings[$index]
            ) && is_array(
                $requestMappings[$index]
            )
                ? $requestMappings[$index]
                : [];

            if (empty($input['include'])) {
                continue;
            }

            $config = is_array(
                $suggestion['config'] ?? null
            )
                ? $suggestion['config']
                : [];

            foreach (
                $suggestion['config_required']
                    ?? []
                as $required
            ) {
                $required = sanitize_key(
                    (string) $required
                );

                $value = sanitize_text_field(
                    (string) (
                        $input['config'][$required]
                        ?? ''
                    )
                );

                if ($required === 'taxonomy') {
                    $value = sanitize_key($value);

                    if (
                        $value === ''
                        || ! taxonomy_exists($value)
                    ) {
                        return $this->error(
                            __(
                                'A selected taxonomy field mapping needs a valid taxonomy slug.',
                                'condixia-ai-site-to-wordpress'
                            )
                        );
                    }
                }

                if ($required === 'key') {
                    $value = sanitize_key($value);

                    if ($value === '') {
                        return $this->error(
                            __(
                                'A selected custom-field mapping needs a valid meta key.',
                                'condixia-ai-site-to-wordpress'
                            )
                        );
                    }
                }

                $config[$required] = $value;
            }

            $selected[] = [
                'element_id' => (string) (
                    $suggestion['source_ref']
                    ?? ''
                ),
                'binding_type' => (string) (
                    $suggestion['binding_type']
                    ?? ''
                ),
                'binding_target' => (string) (
                    $suggestion['binding_target']
                    ?? ''
                ),
                'data_source' => (string) (
                    $suggestion['data_source']
                    ?? ''
                ),
                'config' => $config,
            ];
        }

        return $selected;
    }

    private function termSlugs(
        mixed $value
    ): array {
        if (is_array($value)) {
            $values = $value;
        } else {
            $values = preg_split(
                '/[\s,]+/',
                (string) $value
            );
        }

        if (! is_array($values)) {
            return [];
        }

        $terms = [];

        foreach ($values as $term) {
            $slug = sanitize_title(
                (string) $term
            );

            if ($slug !== '') {
                $terms[$slug] = true;
            }
        }

        return array_keys($terms);
    }

    private function indexById(array $artifacts): array
    {
        $indexed = [];

        foreach ($artifacts as $artifact) {
            if (
                ! is_array($artifact)
                || empty($artifact['id'])
            ) {
                continue;
            }

            $indexed[(int) $artifact['id']] = $artifact;
        }

        return $indexed;
    }

    private function error(string $message): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_structure_conflict',
            $message
        );
    }
}
