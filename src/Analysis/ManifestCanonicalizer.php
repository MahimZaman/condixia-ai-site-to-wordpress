<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class ManifestCanonicalizer
{
    public function encode(array $manifest): string|\WP_Error
    {
        $normalized = $this->normalize(
            $manifest
        );

        $json = wp_json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );

        if (! is_string($json)) {
            return new \WP_Error(
                'condixia_ai_stw_analysis_manifest_json',
                __('The site manifest could not be encoded.', 'condixia-ai-site-to-wordpress')
            );
        }

        return $json;
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                [$this, 'normalize'],
                $value
            );
        }

        ksort(
            $value,
            SORT_STRING
        );

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize(
                $item
            );
        }

        return $value;
    }
}
