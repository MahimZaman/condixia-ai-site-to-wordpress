<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Rendering;

use Condixia\AISiteToWordPress\Analysis\ReferenceResolver;

defined('ABSPATH') || exit;

final class AssetResolver
{
    private ReferenceResolver $referenceResolver;

    public function __construct(
        ?ReferenceResolver $referenceResolver = null
    ) {
        $this->referenceResolver = $referenceResolver
            ?? new ReferenceResolver();
    }

    public function assetUrl(
        string $sourcePath,
        string $reference,
        array $assetMap,
        array $fileIndex
    ): ?string {
        $resolved = $this->referenceResolver->resolve(
            $sourcePath,
            $reference,
            $fileIndex
        );

        if (
            ($resolved['kind'] ?? '') !== 'local'
            || ($resolved['status'] ?? '') !== 'found'
        ) {
            return null;
        }

        $target = (string) ($resolved['target'] ?? '');

        if (
            $target === ''
            || ! isset($assetMap[$target])
        ) {
            return null;
        }

        return $assetMap[$target]
            . $this->referenceSuffix($reference);
    }

    public function pageUrl(
        string $sourcePath,
        string $reference,
        array $fileIndex,
        array $routeMap
    ): ?string {
        $target = $this->internalHtmlTarget(
            $sourcePath,
            $reference,
            $fileIndex
        );

        if (
            $target === null
            || ! isset($routeMap[$target])
            || ! is_string($routeMap[$target])
            || $routeMap[$target] === ''
        ) {
            return null;
        }

        return $routeMap[$target]
            . $this->referenceSuffix(
                $reference
            );
    }

    public function internalHtmlTarget(
        string $sourcePath,
        string $reference,
        array $fileIndex
    ): ?string {
        $resolved = $this->referenceResolver->resolve(
            $sourcePath,
            $reference,
            $fileIndex
        );

        if (
            ($resolved['kind'] ?? '') !== 'local'
            || ($resolved['status'] ?? '') !== 'found'
        ) {
            return null;
        }

        $target = (string) ($resolved['target'] ?? '');

        $extension = strtolower(
            pathinfo(
                $target,
                PATHINFO_EXTENSION
            )
        );

        if (
            ! in_array(
                $extension,
                ['html', 'htm'],
                true
            )
        ) {
            return null;
        }

        return $target;
    }

    private function referenceSuffix(
        string $reference
    ): string {
        $queryPosition = strpos(
            $reference,
            '?'
        );

        $fragmentPosition = strpos(
            $reference,
            '#'
        );

        $positions = [];

        if ($queryPosition !== false) {
            $positions[] = $queryPosition;
        }

        if ($fragmentPosition !== false) {
            $positions[] = $fragmentPosition;
        }

        if ($positions === []) {
            return '';
        }

        return substr(
            $reference,
            min($positions)
        );
    }
}
