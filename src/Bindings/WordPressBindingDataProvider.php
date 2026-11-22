<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Bindings;

use Condixia\AISiteToWordPress\Contracts\BindingDataProviderInterface;

defined('ABSPATH') || exit;

final class WordPressBindingDataProvider implements BindingDataProviderInterface
{
    private const SOURCES = [
        'post.title',
        'post.content',
        'post.excerpt',
        'post.permalink',
        'post.featured_image',
        'post.date',
        'post.author',
        'post.taxonomies',
        'post.meta',
        'site.name',
        'site.url',
        'query.archive_title',
        'query.archive_description',
        'query.result_count',
        'query.pagination',
        'query.search_title',
        'query.search_term',
        'query.search_action',
        'query.search_parameter',
        'query.search_method',
        'term.name',
        'term.url',
    ];

    public function supports(
        string $source
    ): bool {
        return in_array(
            $source,
            self::SOURCES,
            true
        );
    }

    public function resolve(
        string $source,
        array $config,
        array $context
    ): mixed {
        if ($source === 'site.name') {
            return get_bloginfo('name');
        }

        if ($source === 'site.url') {
            return home_url('/');
        }

        if (str_starts_with($source, 'query.')) {
            return $this->queryValue(
                $source
            );
        }

        if (
            $source === 'term.name'
            || $source === 'term.url'
        ) {
            return $this->termValue(
                $source
            );
        }

        $post = $this->post(
            $context
        );

        if (! $post instanceof \WP_Post) {
            return null;
        }

        return match ($source) {
            'post.title' => get_the_title(
                $post
            ),

            'post.content' => (string) get_post_field(
                'post_content',
                $post->ID,
                'raw'
            ),

            'post.excerpt' => $this->excerpt(
                $post
            ),

            'post.permalink' => get_permalink(
                $post
            ),

            'post.featured_image' => $this
                ->featuredImage(
                    $post
                ),

            'post.date' => get_the_date(
                isset($config['format'])
                    ? sanitize_text_field(
                        (string) $config['format']
                    )
                    : '',
                $post
            ),

            'post.author' => $this->author(
                $post
            ),

            'post.taxonomies' => $this->taxonomies(
                $post,
                $config
            ),

            'post.meta' => $this->meta(
                $post,
                $config
            ),

            default => null,
        };
    }

    private function queryValue(
        string $source
    ): mixed {
        global $wp_query;

        return match ($source) {
            'query.archive_title' => get_the_archive_title(),

            'query.archive_description' => get_the_archive_description(),

            'query.result_count' => $wp_query instanceof \WP_Query
                ? max(
                    0,
                    (int) $wp_query->found_posts
                )
                : 0,

            'query.pagination' => $this->pagination(),

            'query.search_title' => sprintf(
                __(
                    'Search results for: %s',
                    'condixia-ai-site-to-wordpress'
                ),
                get_search_query(false)
            ),

            'query.search_term' => get_search_query(false),

            'query.search_action' => home_url('/'),

            'query.search_parameter' => 's',

            'query.search_method' => 'get',

            default => null,
        };
    }

    private function pagination(): string
    {
        global $wp_query;

        if (
            ! $wp_query instanceof \WP_Query
            || (int) $wp_query->max_num_pages < 2
        ) {
            return '';
        }

        $current = max(
            1,
            absint(
                get_query_var('paged')
                    ?: get_query_var('page')
                    ?: 1
            )
        );

        $links = paginate_links(
            [
                'current' => $current,
                'total' => (int) $wp_query->max_num_pages,
                'type' => 'list',
            ]
        );

        return is_string($links)
            ? $links
            : '';
    }

    private function post(
        array $context
    ): ?\WP_Post {
        $postId = isset($context['post_id'])
            ? absint($context['post_id'])
            : get_queried_object_id();

        if ($postId < 1) {
            return null;
        }

        $post = get_post(
            $postId
        );

        return $post instanceof \WP_Post
            ? $post
            : null;
    }

    private function excerpt(
        \WP_Post $post
    ): string {
        $excerpt = (string) get_post_field(
            'post_excerpt',
            $post->ID,
            'raw'
        );

        if ($excerpt !== '') {
            return $excerpt;
        }

        return wp_trim_words(
            wp_strip_all_tags(
                (string) get_post_field(
                    'post_content',
                    $post->ID,
                    'raw'
                )
            ),
            55
        );
    }

    private function featuredImage(
        \WP_Post $post
    ): ?array {
        $attachmentId = get_post_thumbnail_id(
            $post
        );

        if ($attachmentId < 1) {
            return null;
        }

        $url = wp_get_attachment_image_url(
            $attachmentId,
            'full'
        );

        if (! is_string($url)) {
            return null;
        }

        return [
            'attachment_id' => $attachmentId,
            'url' => $url,
            'alt' => (string) get_post_meta(
                $attachmentId,
                '_wp_attachment_image_alt',
                true
            ),
        ];
    }

    private function author(
        \WP_Post $post
    ): string {
        $user = get_userdata(
            (int) $post->post_author
        );

        return $user instanceof \WP_User
            ? $user->display_name
            : '';
    }

    private function taxonomies(
        \WP_Post $post,
        array $config
    ): ?string {
        $taxonomy = isset(
            $config['taxonomy']
        )
            ? sanitize_key(
                (string) $config['taxonomy']
            )
            : '';

        if (
            $taxonomy === ''
            || ! taxonomy_exists($taxonomy)
            || ! is_object_in_taxonomy(
                $post->post_type,
                $taxonomy
            )
        ) {
            return null;
        }

        $terms = get_the_terms(
            $post->ID,
            $taxonomy
        );

        if (
            $terms === false
            || is_wp_error($terms)
        ) {
            return '';
        }

        $names = [];

        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $names[] = $term->name;
            }
        }

        $separator = isset(
            $config['separator']
        )
            ? sanitize_text_field(
                (string) $config['separator']
            )
            : ', ';

        return implode(
            $separator,
            $names
        );
    }


    private function meta(
        \WP_Post $post,
        array $config
    ): mixed {
        $key = isset($config['key'])
            ? sanitize_key(
                (string) $config['key']
            )
            : '';

        if ($key === '') {
            return null;
        }

        return get_post_meta(
            $post->ID,
            $key,
            true
        );
    }

    private function termValue(
        string $source
    ): ?string {
        $term = get_queried_object();

        if (! $term instanceof \WP_Term) {
            return null;
        }

        if ($source === 'term.name') {
            return $term->name;
        }

        $url = get_term_link(
            $term
        );

        return is_wp_error($url)
            ? null
            : $url;
    }
}
