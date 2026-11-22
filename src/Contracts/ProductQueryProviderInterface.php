<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Contracts;

defined('ABSPATH') || exit;

interface ProductQueryProviderInterface
{
    public function available(): bool;

    public function query(
        array $config,
        array $context = []
    ): array|\WP_Error;
}
