<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Contracts;

defined('ABSPATH') || exit;

interface BindingDataProviderInterface
{
    public function supports(
        string $source
    ): bool;

    public function resolve(
        string $source,
        array $config,
        array $context
    ): mixed;
}
