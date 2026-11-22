<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class DomAnalyzer
{
    private DomParser $parser;
    private DomNormalizer $normalizer;

    public function __construct(
        ?DomParser $parser = null,
        ?DomNormalizer $normalizer = null
    ) {
        $this->parser = $parser
            ?? new DomParser();

        $this->normalizer = $normalizer
            ?? new DomNormalizer();
    }

    public function analyze(
        string $sourcePath,
        string $html
    ): array|\WP_Error {
        $parsed = $this->parser->parse(
            $html
        );

        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $structure = $this->normalizer->normalize(
            $sourcePath,
            $parsed['document']
        );

        if (is_wp_error($structure)) {
            return $structure;
        }

        $structure['parse_warnings'] = $parsed['warnings'];

        return $structure;
    }
}
