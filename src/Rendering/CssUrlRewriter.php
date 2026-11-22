<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Rendering;

defined('ABSPATH') || exit;

final class CssUrlRewriter
{
    private AssetResolver $resolver;

    public function __construct(
        ?AssetResolver $resolver = null
    ) {
        $this->resolver = $resolver
            ?? new AssetResolver();
    }

    public function rewrite(
        string $sourcePath,
        string $css,
        array $assetMap,
        array $fileIndex
    ): string {
        $rewritten = preg_replace_callback(
            '/url\(\s*(?:(["\'])(.*?)\1|([^)\s][^)]*?))\s*\)/is',
            function (array $match) use (
                $sourcePath,
                $assetMap,
                $fileIndex
            ): string {
                $reference = isset($match[2])
                    && $match[2] !== ''
                    ? $match[2]
                    : trim((string) ($match[3] ?? ''));

                if ($reference === '') {
                    return $match[0];
                }

                $url = $this->resolver->assetUrl(
                    $sourcePath,
                    $reference,
                    $assetMap,
                    $fileIndex
                );

                if ($url === null) {
                    return $match[0];
                }

                return 'url("'
                    . $this->escapeCssString($url)
                    . '")';
            },
            $css
        );

        if (! is_string($rewritten)) {
            return $css;
        }

        $rewrittenImports = preg_replace_callback(
            '/@import(\s+)(["\'])(.*?)\2/is',
            function (array $match) use (
                $sourcePath,
                $assetMap,
                $fileIndex
            ): string {
                $reference = (string) $match[3];

                $url = $this->resolver->assetUrl(
                    $sourcePath,
                    $reference,
                    $assetMap,
                    $fileIndex
                );

                if ($url === null) {
                    return $match[0];
                }

                return '@import'
                    . $match[1]
                    . '"'
                    . $this->escapeCssString($url)
                    . '"';
            },
            $rewritten
        );

        return is_string($rewrittenImports)
            ? $rewrittenImports
            : $rewritten;
    }

    private function escapeCssString(
        string $value
    ): string {
        return str_replace(
            [
                '\\',
                '"',
                "\r",
                "\n",
                "\f",
            ],
            [
                '\\\\',
                '\\"',
                '',
                '\A ',
                '',
            ],
            $value
        );
    }
}
