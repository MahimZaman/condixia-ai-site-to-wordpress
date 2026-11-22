<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class DomParser
{
    private const MAX_WARNINGS = 25;

    public function parse(string $html): array|\WP_Error
    {
        if (! class_exists(\DOMDocument::class)) {
            return new \WP_Error(
                'condixia_ai_stw_dom_unavailable',
                __(
                    'The PHP DOM extension is required to analyze imported HTML structure.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        if (trim($html) === '') {
            return new \WP_Error(
                'condixia_ai_stw_dom_empty',
                __(
                    'An imported HTML document is empty.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $previousErrorState = libxml_use_internal_errors(true);

        libxml_clear_errors();

        $document = new \DOMDocument(
            '1.0',
            'UTF-8'
        );

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_COMPACT
        );

        $libxmlErrors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors(
            $previousErrorState
        );

        if (
            ! $loaded
            || ! $document->documentElement instanceof \DOMElement
        ) {
            return new \WP_Error(
                'condixia_ai_stw_dom_parse_failed',
                __(
                    'An imported HTML document could not be parsed safely.',
                    'condixia-ai-site-to-wordpress'
                )
            );
        }

        $this->removeEncodingInstruction(
            $document
        );

        return [
            'document' => $document,
            'warnings' => $this->normalizeWarnings(
                $libxmlErrors
            ),
        ];
    }

    private function removeEncodingInstruction(
        \DOMDocument $document
    ): void {
        $node = $document->firstChild;

        while ($node !== null) {
            $next = $node->nextSibling;

            if (
                $node->nodeType === XML_PI_NODE
                && strtolower($node->nodeName) === 'xml'
            ) {
                $document->removeChild(
                    $node
                );
            }

            $node = $next;
        }
    }

    private function normalizeWarnings(
        array $errors
    ): array {
        $warnings = [];

        foreach (
            array_slice(
                $errors,
                0,
                self::MAX_WARNINGS
            )
            as $error
        ) {
            if (! $error instanceof \LibXMLError) {
                continue;
            }

            $warnings[] = [
                'code' => (int) $error->code,
                'level' => $this->level(
                    (int) $error->level
                ),
                'line' => max(
                    0,
                    (int) $error->line
                ),
                'column' => max(
                    0,
                    (int) $error->column
                ),
            ];
        }

        return $warnings;
    }

    private function level(int $level): string
    {
        return match ($level) {
            LIBXML_ERR_WARNING => 'warning',
            LIBXML_ERR_ERROR => 'error',
            LIBXML_ERR_FATAL => 'fatal',
            default => 'unknown',
        };
    }
}
