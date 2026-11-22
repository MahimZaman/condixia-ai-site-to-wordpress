<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Contracts;

defined('ABSPATH') || exit;

interface PostQueryProviderInterface
{
    public function query(
        array $config,
        array $context = []
    ): array|\WP_Error;
}
