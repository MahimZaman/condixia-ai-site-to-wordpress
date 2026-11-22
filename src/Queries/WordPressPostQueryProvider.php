<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Queries;

use Condixia\AISiteToWordPress\Contracts\PostQueryProviderInterface;

defined('ABSPATH') || exit;

final class WordPressPostQueryProvider implements PostQueryProviderInterface
{
    private const ORDER_BY = [
        'date' => 'date',
        'modified' => 'modified',
        'title' => 'title',
        'menu_order' => 'menu_order',
        'id' => 'ID',
        'rand' => 'rand',
        'comment_count' => 'comment_count',
    ];

    public function query(
        array $config,
        array $context = []
    ): array|\WP_Error {
        $postType = sanitize_key(
            (string) (
                $config['post_type']
                ?? 'post'
            )
        );

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
                    'The selected post type is not available for a public Condixia query.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $useMainQuery = ! empty($config['use_main_query']);

        if (
            $useMainQuery
            && $this->mainQueryCompatible(
                $postType
            )
        ) {
            global $wp_query;

            if ($wp_query instanceof \WP_Query) {
                return $this->result(
                    $wp_query,
                    true
                );
            }
        }

        $postsPerPage = absint(
            $config['posts_per_page']
                ?? 10
        );

        $postsPerPage = max(
            1,
            min(
                100,
                $postsPerPage
            )
        );

        $order = strtoupper(
            (string) (
                $config['order']
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
            $order = 'DESC';
        }

        $orderByKey = sanitize_key(
            (string) (
                $config['orderby']
                ?? 'date'
            )
        );

        $orderBy = self::ORDER_BY[$orderByKey] ?? 'date';

        $paginate = ! empty($config['paginate']);

        $paged = $paginate
            ? max(
                1,
                absint(
                    get_query_var('paged')
                        ?: get_query_var('page')
                )
            )
            : 1;

        $args = [
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => $postsPerPage,
            'orderby' => $orderBy,
            'order' => $order,
            'paged' => $paged,
            'ignore_sticky_posts' => true,
            'no_found_rows' => ! $paginate,
        ];

        $taxonomy = sanitize_key(
            (string) (
                $config['taxonomy']
                ?? ''
            )
        );

        $terms = is_array(
            $config['terms']
                ?? null
        )
            ? array_values(
                array_filter(
                    array_unique(
                        array_map(
                            'sanitize_title',
                            $config['terms']
                        )
                    )
                )
            )
            : [];

        if (
            $taxonomy !== ''
            && $terms !== []
            && taxonomy_exists($taxonomy)
            && is_object_in_taxonomy(
                $postType,
                $taxonomy
            )
        ) {
            $args['tax_query'] = [
                [
                    'taxonomy' => $taxonomy,
                    'field' => 'slug',
                    'terms' => $terms,
                ],
            ];
        }

        $query = new \WP_Query(
            $args
        );

        return $this->result(
            $query,
            false
        );
    }

    private function mainQueryCompatible(
        string $postType
    ): bool {
        if (is_search()) {
            return true;
        }

        if (
            is_home()
            || is_category()
            || is_tag()
            || is_author()
            || is_date()
        ) {
            return $postType === 'post';
        }

        if (is_post_type_archive()) {
            $object = get_queried_object();

            if ($object instanceof \WP_Post_Type) {
                return $object->name
                    === $postType;
            }

            return is_post_type_archive(
                $postType
            );
        }

        if (is_tax()) {
            $term = get_queried_object();

            if (! $term instanceof \WP_Term) {
                return false;
            }

            $taxonomy = get_taxonomy(
                $term->taxonomy
            );

            return $taxonomy instanceof \WP_Taxonomy
                && in_array(
                    $postType,
                    $taxonomy->object_type,
                    true
                );
        }

        return false;
    }

    private function result(
        \WP_Query $query,
        bool $mainQuery
    ): array {
        $posts = array_values(
            array_filter(
                $query->posts,
                static fn(
                    mixed $post
                ): bool =>
                $post instanceof \WP_Post
            )
        );

        return [
            'posts' => $posts,
            'uses_main_query' => $mainQuery,
            'current_page' => max(
                1,
                (int) (
                    $query->get('paged')
                    ?: get_query_var('paged')
                    ?: get_query_var('page')
                    ?: 1
                )
            ),
            'max_num_pages' => max(
                0,
                (int) $query->max_num_pages
            ),
            'found_posts' => max(
                0,
                (int) $query->found_posts
            ),
        ];
    }

    private function error(
        string $message
    ): \WP_Error {
        return new \WP_Error(
            'condixia_ai_stw_post_query',
            $message
        );
    }
}
