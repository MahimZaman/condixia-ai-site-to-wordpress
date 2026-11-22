<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Rendering;

use Condixia\AISiteToWordPress\Templates\TemplateResolver;

defined('ABSPATH') || exit;

final class StandaloneRenderingEngine
{
    private TemplateResolver $resolver;
    private StandaloneDocumentRenderer $renderer;

    public function __construct(
        ?TemplateResolver $resolver = null,
        ?StandaloneDocumentRenderer $renderer = null
    ) {
        $this->resolver = $resolver
            ?? new TemplateResolver();

        $this->renderer = $renderer
            ?? new StandaloneDocumentRenderer();
    }

    public function register(): void
    {
        add_filter(
            'template_include',
            [$this, 'template'],
            9999
        );
    }

    public function template(
        string $themeTemplate
    ): string {
        if (
            is_admin()
            || wp_doing_ajax()
            || (
                defined('REST_REQUEST')
                && REST_REQUEST
            )
            || is_feed()
            || is_trackback()
        ) {
            return $themeTemplate;
        }

        $resolved = $this->resolver->resolve();

        if (
            $resolved === null
            || is_wp_error($resolved)
        ) {
            return $themeTemplate;
        }

        $html = $this->renderer->render(
            $resolved
        );

        if (is_wp_error($html)) {
            if (current_user_can('manage_options')) {
                wp_die(
                    esc_html(
                        sprintf(
                            'Condixia render error [%s]: %s',
                            $html->get_error_code(),
                            $html->get_error_message()
                        )
                    ),
                    esc_html__(
                        'Condixia Rendering Diagnostic',
                        'condixia-ai-site-to-wordpress'
                    ),
                    [
                        'response' => 500,
                    ]
                );
            }

            return $themeTemplate;
        }

        StandaloneTemplateContext::set(
            $html
        );

        $template = dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . 'Templates'
            . DIRECTORY_SEPARATOR
            . 'standalone.php';

        if (
            ! is_file($template)
            || ! is_readable($template)
        ) {
            return $themeTemplate;
        }

        return $template;
    }
}
