<?php

use Condixia\AISiteToWordPress\Analysis\TemplateRoles;
use Condixia\AISiteToWordPress\Security\NonceActions;

defined('ABSPATH') || exit;

$structure = $viewData['structure'];
$site = $structure['site'];
$pages = $structure['pages'];
$templates = $structure['templates'];
$siteParts = $structure['site_parts'];
$warnings = $structure['warnings'];
$notice = $viewData['notice'];

$manifest = is_array($structure['manifest'] ?? null)
    ? $structure['manifest']
    : [];

$summary = is_array($manifest['summary'] ?? null)
    ? $manifest['summary']
    : [];

$ignoredFiles = is_array($manifest['ignored_files'] ?? null)
    ? $manifest['ignored_files']
    : [];

$editable = ! in_array(
    $site['status'],
    ['pages_created'],
    true
);

?>

<div class="wrap cdx-stw-admin">
    <div class="cdx-stw-page-header">
        <div>
            <p class="cdx-stw-eyebrow">
                <?php esc_html_e('Condixia Import', 'condixia-ai-site-to-wordpress'); ?>
            </p>

            <h1>
                <?php esc_html_e('Review Your Website', 'condixia-ai-site-to-wordpress'); ?>
            </h1>

            <p class="cdx-stw-page-description">
                <?php
                echo esc_html(
                    sprintf(
                        __(
                            'Review %s before creating its WordPress pages.',
                            'condixia-ai-site-to-wordpress'
                        ),
                        (string) $site['name']
                    )
                );
                ?>
            </p>
        </div>

        <span class="cdx-stw-status-badge">
            <?php echo esc_html((string) $site['status']); ?>
        </span>
    </div>

    <?php if ($notice !== null) : ?>
        <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> inline cdx-stw-inline-notice">
            <p><?php echo esc_html((string) $notice['message']); ?></p>

            <?php if (! empty($notice['details'])) : ?>
                <ul class="cdx-stw-notice-list">
                    <?php foreach ($notice['details'] as $detail) : ?>
                        <li><?php echo esc_html((string) $detail); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="cdx-stw-summary-grid">
        <div class="cdx-stw-summary-card">
            <strong><?php echo esc_html((string) count($pages)); ?></strong>
            <span><?php esc_html_e('Pages detected', 'condixia-ai-site-to-wordpress'); ?></span>
        </div>

        <div class="cdx-stw-summary-card">
            <strong><?php echo esc_html((string) count($templates)); ?></strong>
            <span><?php esc_html_e('Templates detected', 'condixia-ai-site-to-wordpress'); ?></span>
        </div>

        <div class="cdx-stw-summary-card">
            <strong><?php echo esc_html((string) count($siteParts)); ?></strong>
            <span><?php esc_html_e('Site Parts detected', 'condixia-ai-site-to-wordpress'); ?></span>
        </div>

        <div class="cdx-stw-summary-card">
            <strong><?php echo esc_html((string) ($summary['files'] ?? 0)); ?></strong>
            <span><?php esc_html_e('Website files imported', 'condixia-ai-site-to-wordpress'); ?></span>
        </div>
    </div>

    <section class="cdx-stw-structure-section">
        <div class="cdx-stw-section-heading">
            <h2><?php esc_html_e('Archive report', 'condixia-ai-site-to-wordpress'); ?></h2>
        </div>

        <div class="cdx-stw-card">
            <p>
                <strong>
                    <?php
                    echo esc_html(
                        sprintf(
                            __('%d website files imported', 'condixia-ai-site-to-wordpress'),
                            (int) ($summary['files'] ?? 0)
                        )
                    );
                    ?>
                </strong>
            </p>

            <p>
                <?php
                echo esc_html(
                    sprintf(
                        __('%d unnecessary or unsupported files safely ignored', 'condixia-ai-site-to-wordpress'),
                        count($ignoredFiles)
                    )
                );
                ?>
            </p>

            <?php if ($ignoredFiles !== []) : ?>
                <details>
                    <summary>
                        <?php esc_html_e('View ignored files', 'condixia-ai-site-to-wordpress'); ?>
                    </summary>

                    <ul>
                        <?php foreach ($ignoredFiles as $ignoredFile) : ?>
                            <li>
                                <code><?php echo esc_html((string) ($ignoredFile['path'] ?? '')); ?></code>
                                —
                                <?php echo esc_html((string) ($ignoredFile['reason'] ?? 'unsupported')); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($editable) : ?>
        <form method="post"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            class="cdx-stw-structure-form">

            <input type="hidden" name="action" value="condixia_ai_stw_confirm_structure">
            <input type="hidden" name="site_id" value="<?php echo esc_attr((string) $site['id']); ?>">

            <?php
            wp_nonce_field(
                NonceActions::resource(
                    NonceActions::CONFIRM_STRUCTURE,
                    (int) $site['id']
                )
            );
            ?>

            <section class="cdx-stw-structure-section">
                <div class="cdx-stw-section-heading">
                    <h2><?php esc_html_e('Pages', 'condixia-ai-site-to-wordpress'); ?></h2>
                    <p><?php esc_html_e('Keep the detected pages, or correct a title or URL before creation.', 'condixia-ai-site-to-wordpress'); ?></p>
                </div>

                <div class="cdx-stw-table-wrap">
                    <table class="widefat striped cdx-stw-structure-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Keep', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('Home', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('Title', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('URL slug', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('Source', 'condixia-ai-site-to-wordpress'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pages as $page) : ?>
                                <?php
                                $kept = $page['status'] !== 'ignored';
                                $home = ! empty($page['metadata']['confirmed_home'])
                                    || (
                                        ! isset($page['metadata']['confirmed_home'])
                                        && ! empty($page['metadata']['home_candidate'])
                                    );
                                ?>
                                <tr>
                                    <td>
                                        <input type="hidden"
                                            name="pages[<?php echo esc_attr((string) $page['id']); ?>][include]"
                                            value="0">
                                        <input type="checkbox"
                                            name="pages[<?php echo esc_attr((string) $page['id']); ?>][include]"
                                            value="1"
                                            <?php checked($kept); ?>>
                                    </td>
                                    <td>
                                        <input type="radio"
                                            name="home_page"
                                            value="<?php echo esc_attr((string) $page['id']); ?>"
                                            <?php checked($home); ?>>
                                    </td>
                                    <td>
                                        <input type="text"
                                            name="pages[<?php echo esc_attr((string) $page['id']); ?>][title]"
                                            value="<?php echo esc_attr((string) $page['title']); ?>"
                                            required>
                                    </td>
                                    <td>
                                        <input type="text"
                                            name="pages[<?php echo esc_attr((string) $page['id']); ?>][slug]"
                                            value="<?php echo esc_attr((string) $page['slug']); ?>"
                                            required>
                                    </td>
                                    <td>
                                        <code><?php echo esc_html((string) $page['source_path']); ?></code>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <label class="cdx-stw-no-home">
                    <input type="radio" name="home_page" value="0">
                    <?php esc_html_e('Keep current WordPress homepage', 'condixia-ai-site-to-wordpress'); ?>
                </label>
            </section>

            <section class="cdx-stw-structure-section">
                <div class="cdx-stw-section-heading">
                    <h2><?php esc_html_e('Templates', 'condixia-ai-site-to-wordpress'); ?></h2>
                    <p><?php esc_html_e('Correct important template roles. Dynamic field mappings can be configured later.', 'condixia-ai-site-to-wordpress'); ?></p>
                </div>

                <div class="cdx-stw-table-wrap">
                    <table class="widefat striped cdx-stw-structure-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Keep', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('Role', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('Target', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('Source', 'condixia-ai-site-to-wordpress'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $template) : ?>
                                <tr>
                                    <td>
                                        <input type="hidden"
                                            name="templates[<?php echo esc_attr((string) $template['id']); ?>][include]"
                                            value="0">
                                        <input type="checkbox"
                                            name="templates[<?php echo esc_attr((string) $template['id']); ?>][include]"
                                            value="1"
                                            <?php
                                            checked(
                                                $template['status'] === 'confirmed'
                                                    || (
                                                        $template['status'] !== 'ignored'
                                                        && empty($template['metadata']['ambiguous'])
                                                        && (
                                                            ! TemplateRoles::requiresWooCommerce((string) $template['role'])
                                                            || ! empty($structure['woocommerce_active'])
                                                        )
                                                    )
                                            );
                                            ?>
                                            </td>
                                    <td>
                                        <select name="templates[<?php echo esc_attr((string) $template['id']); ?>][role]">
                                            <?php foreach (TemplateRoles::labels() as $role => $label) : ?>
                                                <option value="<?php echo esc_attr($role); ?>"
                                                    <?php selected($template['role'], $role); ?>>
                                                    <?php echo esc_html($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text"
                                            name="templates[<?php echo esc_attr((string) $template['id']); ?>][target]"
                                            value="<?php echo esc_attr((string) ($template['metadata']['target'] ?? '')); ?>"
                                            placeholder="<?php esc_attr_e('Optional unless required by role', 'condixia-ai-site-to-wordpress'); ?>">
                                    </td>
                                    <td>
                                        <code><?php echo esc_html((string) $template['source_path']); ?></code>
                                        <?php if (! empty($template['metadata']['ambiguous'])) : ?>
                                            <span class="cdx-stw-warning-label">
                                                <?php esc_html_e('Review classification', 'condixia-ai-site-to-wordpress'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="cdx-stw-structure-section">
                <div class="cdx-stw-section-heading">
                    <h2><?php esc_html_e('Detected Site Parts', 'condixia-ai-site-to-wordpress'); ?></h2>
                    <p><?php esc_html_e('These are suggestions. You can correct missed headers and footers in the editor later.', 'condixia-ai-site-to-wordpress'); ?></p>
                </div>

                <div class="cdx-stw-table-wrap">
                    <table class="widefat striped cdx-stw-structure-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Keep', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('Role', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('Name', 'condixia-ai-site-to-wordpress'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($siteParts as $part) : ?>
                                <tr>
                                    <td>
                                        <input type="hidden"
                                            name="site_parts[<?php echo esc_attr((string) $part['id']); ?>][include]"
                                            value="0">
                                        <input type="checkbox"
                                            name="site_parts[<?php echo esc_attr((string) $part['id']); ?>][include]"
                                            value="1"
                                            <?php checked($part['status'] !== 'ignored'); ?>>
                                    </td>
                                    <td>
                                        <select name="site_parts[<?php echo esc_attr((string) $part['id']); ?>][role]">
                                            <option value="header" <?php selected($part['role'], 'header'); ?>>
                                                <?php esc_html_e('Header', 'condixia-ai-site-to-wordpress'); ?>
                                            </option>
                                            <option value="footer" <?php selected($part['role'], 'footer'); ?>>
                                                <?php esc_html_e('Footer', 'condixia-ai-site-to-wordpress'); ?>
                                            </option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text"
                                            name="site_parts[<?php echo esc_attr((string) $part['id']); ?>][title]"
                                            value="<?php echo esc_attr((string) $part['title']); ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="cdx-stw-card">
                <h2><?php esc_html_e('Automatic suggestions', 'condixia-ai-site-to-wordpress'); ?></h2>
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            __('%1$d navigation candidates and %2$d repeated regions were detected.', 'condixia-ai-site-to-wordpress'),
                            count($structure['navigation_candidates']),
                            count($structure['dynamic_grid_candidates'])
                        )
                    );
                    ?>
                </p>
                <p><?php esc_html_e('Repeated regions remain static. Configure dynamic data in the visual editor after importing.', 'condixia-ai-site-to-wordpress'); ?></p>
            </div>

            <?php if ($structure['woocommerce_required'] && ! $structure['woocommerce_active']) : ?>
                <div class="notice notice-warning inline cdx-stw-inline-notice">
                    <p><?php esc_html_e('WooCommerce templates were detected, but WooCommerce is not active. Activate WooCommerce or exclude the dependent templates.', 'condixia-ai-site-to-wordpress'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($warnings !== []) : ?>
                <details class="cdx-stw-card">
                    <summary>
                        <?php
                        echo esc_html(
                            sprintf(
                                __('Import warnings (%d)', 'condixia-ai-site-to-wordpress'),
                                count($warnings)
                            )
                        );
                        ?>
                    </summary>
                    <ul>
                        <?php foreach ($warnings as $warning) : ?>
                            <li><?php echo esc_html((string) ($warning['message'] ?? $warning['code'] ?? 'Warning')); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <div class="cdx-stw-confirm-actions">
                <button type="submit" class="button button-primary button-hero">
                    <?php esc_html_e('Confirm Website Structure', 'condixia-ai-site-to-wordpress'); ?>
                </button>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($site['status'] === 'confirmed') : ?>
        <div class="cdx-stw-next-step-card">
            <div>
                <h2><?php esc_html_e('Structure confirmed', 'condixia-ai-site-to-wordpress'); ?></h2>
                <p><?php esc_html_e('The source structure has been saved. Create the WordPress pages to continue.', 'condixia-ai-site-to-wordpress'); ?></p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="condixia_ai_stw_create_pages">
                <input type="hidden" name="site_id" value="<?php echo esc_attr((string) $site['id']); ?>">

                <?php
                wp_nonce_field(
                    NonceActions::resource(
                        NonceActions::CREATE_PAGES,
                        (int) $site['id']
                    )
                );
                ?>

                <button type="submit" class="button button-primary button-hero">
                    <?php esc_html_e('Create WordPress Pages', 'condixia-ai-site-to-wordpress'); ?>
                </button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($site['status'] === 'pages_created') : ?>
        <div class="cdx-stw-next-step-card">
            <div>
                <h2><?php esc_html_e('WordPress pages created', 'condixia-ai-site-to-wordpress'); ?></h2>
                <p><?php esc_html_e('Your imported pages and their WordPress routes are available.', 'condixia-ai-site-to-wordpress'); ?></p>
            </div>
        </div>
    <?php endif; ?>
</div>