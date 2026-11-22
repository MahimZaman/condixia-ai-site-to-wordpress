<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Bindings;

use Condixia\AISiteToWordPress\Contracts\BindingDataProviderInterface;
use Condixia\AISiteToWordPress\Integration\WooCommerce\WooCommerceBindingDataProvider;

defined('ABSPATH') || exit;

final class BindingValueResolver
{
    private array $providers;

    public function __construct(
        ?WordPressBindingDataProvider $wordpress = null
    ) {
        $this->providers = [
            $wordpress
                ?? new WordPressBindingDataProvider(),
        ];

        if (
            class_exists('WooCommerce')
            && function_exists('wc_get_product')
        ) {
            $this->providers[]
                = new WooCommerceBindingDataProvider();
        }
    }

    public function resolve(
        string $source,
        array $config = [],
        array $context = []
    ): mixed {
        if (in_array($source, ['grid.public_meta', 'grid.public_acf'], true)) {
            return (new GridPublicFieldCatalog())->resolve(
                $source,
                $config,
                $context
            );
        }

        $providers = apply_filters(
            'condixia_ai_stw_binding_data_providers',
            $this->providers
        );

        if (! is_array($providers)) {
            return null;
        }

        foreach ($providers as $provider) {
            if (
                ! $provider
                    instanceof BindingDataProviderInterface
                || ! $provider->supports(
                    $source
                )
            ) {
                continue;
            }

            $value = $provider->resolve(
                $source,
                $config,
                $context
            );

            return apply_filters(
                'condixia_ai_stw_binding_value',
                $value,
                $source,
                $config,
                $context
            );
        }

        return null;
    }
}
