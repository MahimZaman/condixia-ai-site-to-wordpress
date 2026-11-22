<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Contracts;

defined('ABSPATH') || exit;

interface NavigationProviderInterface
{
    public function key(): string;

    public function available(): bool;

    public function exists(int $objectId): bool;

    public function create(
        string $name,
        array $items,
        array $context = []
    ): int|\WP_Error;

    public function items(
        int $objectId
    ): array|\WP_Error;

    public function manageUrl(
        int $objectId
    ): string;
}
