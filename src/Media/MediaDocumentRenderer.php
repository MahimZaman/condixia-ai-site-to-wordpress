<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Media;

defined('ABSPATH') || exit;

final class MediaDocumentRenderer
{
    public function render(
        string $html,
        array $mediaMap
    ): string|\WP_Error {
        if ($mediaMap === []) {
            return $html;
        }

        if (
            ! class_exists(
                \WP_HTML_Tag_Processor::class
            )
        ) {
            return new \WP_Error(
                'condixia_ai_stw_media_html_processor_missing',
                __(
                    'The WordPress HTML processor required for media rendering is unavailable.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $byUrl = [];

        foreach ($mediaMap as $media) {
            if (! is_array($media)) {
                continue;
            }

            $url = isset($media['url'])
                && is_string($media['url'])
                ? $media['url']
                : '';

            if (
                $url === ''
                || empty($media['apply_alt'])
            ) {
                continue;
            }

            $byUrl[$url] = (string) (
                $media['alt']
                ?? ''
            );
        }

        if ($byUrl === []) {
            return $html;
        }

        $processor = new \WP_HTML_Tag_Processor(
            $html
        );

        while ($processor->next_tag('IMG')) {
            $src = $processor->get_attribute(
                'src'
            );

            if (
                ! is_string($src)
                || ! array_key_exists(
                    $src,
                    $byUrl
                )
            ) {
                continue;
            }

            $processor->set_attribute(
                'alt',
                $byUrl[$src]
            );
        }

        return $processor->get_updated_html();
    }
}
