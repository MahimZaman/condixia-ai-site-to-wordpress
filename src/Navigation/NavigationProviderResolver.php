<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Navigation;

use Condixia\AISiteToWordPress\Contracts\NavigationProviderInterface;

defined('ABSPATH') || exit;

final class NavigationProviderResolver
{
    private ClassicNavigationProvider $classic;
    private BlockNavigationProvider $block;

    public function __construct(
        ?ClassicNavigationProvider $classic = null,
        ?BlockNavigationProvider $block = null
    ) {
        $this->classic = $classic
            ?? new ClassicNavigationProvider();

        $this->block = $block
            ?? new BlockNavigationProvider();
    }

    public function current(): NavigationProviderInterface|\WP_Error
    {
        if (
            function_exists(
                'wp_is_block_theme'
            )
            && wp_is_block_theme()
            && $this->block->available()
        ) {
            return $this->block;
        }

        if ($this->classic->available()) {
            return $this->classic;
        }

        if ($this->block->available()) {
            return $this->block;
        }

        return new \WP_Error(
            'condixia_ai_stw_navigation_provider_missing',
            __(
                'No supported WordPress navigation provider is available.',
                'condixia-ai-site-to-wordpress'
            )
        );
    }

    public function byKey(
        string $key
    ): ?NavigationProviderInterface {
        return match ($key) {
            'classic' => $this->classic,
            'block' => $this->block,
            default => null,
        };
    }
}
