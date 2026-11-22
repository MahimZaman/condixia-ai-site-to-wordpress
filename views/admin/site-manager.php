<?php

declare(strict_types=1);

use Condixia\AISiteToWordPress\Admin\AdminMenu;
use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Security\NonceActions;
use Condixia\AISiteToWordPress\SiteManagement\SiteManagementController;

defined('ABSPATH') || exit;

$sites = is_array(
    $viewData['sites']
        ?? null
)
    ? $viewData['sites']
    : [];

$selected = is_array(
    $viewData['selected_site']
        ?? null
)
    ? $viewData['selected_site']
    : null;

$notice = is_array(
    $viewData['notice']
        ?? null
)
    ? $viewData['notice']
    : null;

$artifactLabels = [
    'pages' => __(
        'Pages',
        'condixia-ai-site-to-wordpress'
    ),
    'templates' => __(
        'Templates',
        'condixia-ai-site-to-wordpress'
    ),
    'site_parts' => __(
        'Site Parts',
        'condixia-ai-site-to-wordpress'
    ),
    'navigation' => __(
        'Navigation',
        'condixia-ai-site-to-wordpress'
    ),
    'dynamic_grids' => __(
        'Dynamic Grids',
        'condixia-ai-site-to-wordpress'
    ),
];
?>
<div class="wrap cdx-stw-admin">
    <h1>
        <?php
        esc_html_e(
            'Imported Sites',
            'condixia-ai-site-to-wordpress'
        );
        ?>
    </h1>

    <?php if ($notice !== null) : ?>
        <div
            class="notice notice-<?php echo esc_attr((string) ($notice['type'] ?? 'info')); ?> is-dismissible">
            <p>
                <?php
                echo esc_html(
                    (string) (
                        $notice['message']
                        ?? ''
                    )
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($selected !== null) : ?>
        <?php
        $site = is_array(
            $selected['site']
                ?? null
        )
            ? $selected['site']
            : [];

        $manifest = is_array(
            $selected['manifest']
                ?? null
        )
            ? $selected['manifest']
            : [];

        $artifacts = is_array(
            $selected['artifacts']
                ?? null
        )
            ? $selected['artifacts']
            : [];

        $sourceFiles = is_array($selected['source_files'] ?? null)
            ? $selected['source_files']
            : [];

        $importHistory = is_array($selected['import_history'] ?? null)
            ? $selected['import_history']
            : [];

        $sections = [
            'overview' => __('Overview', 'condixia-ai-site-to-wordpress'),
            'pages' => __('Pages', 'condixia-ai-site-to-wordpress'),
            'templates' => __('Templates', 'condixia-ai-site-to-wordpress'),
            'site_parts' => __('Site Parts', 'condixia-ai-site-to-wordpress'),
            'navigation' => __('Navigation', 'condixia-ai-site-to-wordpress'),
            'dynamic_grids' => __('Dynamic Regions', 'condixia-ai-site-to-wordpress'),
            'files' => __('Files', 'condixia-ai-site-to-wordpress'),
            'history' => __('Import History', 'condixia-ai-site-to-wordpress'),
        ];

        $activeSection = isset($_GET['section']) && is_scalar($_GET['section'])
            ? sanitize_key(wp_unslash((string) $_GET['section']))
            : 'overview';

        if (! isset($sections[$activeSection])) {
            $activeSection = 'overview';
        }
        ?>

        <p>
            <a
                class="button"
                href="<?php echo esc_url(
                            add_query_arg(
                                [
                                    'page'
                                    => AdminMenu::SITES_SLUG,
                                ],
                                admin_url(
                                    'admin.php'
                                )
                            )
                        ); ?>">
                <?php
                esc_html_e(
                    '← Back to Sites',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </a>
        </p>

        <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Site dashboard sections', 'condixia-ai-site-to-wordpress'); ?>">
            <?php foreach ($sections as $sectionKey => $sectionLabel) : ?>
                <a class="nav-tab <?php echo $activeSection === $sectionKey ? 'nav-tab-active' : ''; ?>"
                    href="<?php echo esc_url(add_query_arg([
                                'page' => AdminMenu::SITES_SLUG,
                                'site_id' => (int) ($site['id'] ?? 0),
                                'section' => $sectionKey,
                            ], admin_url('admin.php'))); ?>"
                    <?php if ($activeSection === $sectionKey) : ?>aria-current="page" <?php endif; ?>>
                    <?php echo esc_html($sectionLabel); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <p class="description">
            <?php echo esc_html(sprintf(
                __('%1$d Pages · %2$d Templates · %3$d Site Parts · %4$d Files', 'condixia-ai-site-to-wordpress'),
                count($artifacts['pages'] ?? []),
                count($artifacts['templates'] ?? []),
                count($artifacts['site_parts'] ?? []),
                (int) ($selected['file_count'] ?? 0)
            )); ?>
        </p>

        <?php if ($activeSection === 'overview') : ?>
            <div class="card">
                <h2>
                    <?php
                    echo esc_html(
                        (string) (
                            $site['name']
                            ?? ''
                        )
                    );
                    ?>
                </h2>

                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <th>
                                <?php esc_html_e('Status', 'condixia-ai-site-to-wordpress'); ?>
                            </th>
                            <td>
                                <code><?php echo esc_html((string) ($site['status'] ?? '')); ?></code>
                            </td>
                        </tr>

                        <tr>
                            <th>
                                <?php esc_html_e('Source', 'condixia-ai-site-to-wordpress'); ?>
                            </th>
                            <td>
                                <?php echo esc_html((string) ($site['source_label'] ?? '')); ?>
                            </td>
                        </tr>

                        <tr>
                            <th>
                                <?php esc_html_e('Manifest version', 'condixia-ai-site-to-wordpress'); ?>
                            </th>
                            <td>
                                <?php echo esc_html((string) ((int) ($manifest['version'] ?? 0))); ?>
                            </td>
                        </tr>

                        <tr>
                            <th>
                                <?php esc_html_e('Source files', 'condixia-ai-site-to-wordpress'); ?>
                            </th>
                            <td>
                                <?php echo esc_html((string) ((int) ($selected['file_count'] ?? 0))); ?>
                            </td>
                        </tr>

                        <tr>
                            <th>
                                <?php esc_html_e('Updated', 'condixia-ai-site-to-wordpress'); ?>
                            </th>
                            <td>
                                <?php echo esc_html((string) ($site['updated_at'] ?? '')); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p>
                <a class="button" href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::TEMPLATES_SLUG, 'site_id' => (int) ($site['id'] ?? 0)], admin_url('admin.php'))); ?>">
                    <?php esc_html_e('Manage Templates', 'condixia-ai-site-to-wordpress'); ?>
                </a>
                <a class="button" href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::SITE_PARTS_SLUG, 'site_id' => (int) ($site['id'] ?? 0)], admin_url('admin.php'))); ?>">
                    <?php esc_html_e('Manage Headers & Footers', 'condixia-ai-site-to-wordpress'); ?>
                </a>
            </p>
        <?php endif; ?>

        <?php foreach ($artifactLabels as $key => $label) : ?>
            <?php
            if ($activeSection !== $key) {
                continue;
            }

            $rows = is_array(
                $artifacts[$key]
                    ?? null
            )
                ? $artifacts[$key]
                : [];
            ?>

            <div class="card">
                <h2>
                    <?php echo esc_html($label); ?>
                    <span class="count">
                        (<?php echo esc_html((string) count($rows)); ?>)
                    </span>
                </h2>

                <?php if ($rows === []) : ?>
                    <p>
                        <?php
                        esc_html_e(
                            'None in the current manifest.',
                            'condixia-ai-site-to-wordpress'
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>
                                    <?php esc_html_e('Title', 'condixia-ai-site-to-wordpress'); ?>
                                </th>
                                <th>
                                    <?php esc_html_e('Role', 'condixia-ai-site-to-wordpress'); ?>
                                </th>
                                <th>
                                    <?php esc_html_e('Source', 'condixia-ai-site-to-wordpress'); ?>
                                </th>
                                <th>
                                    <?php esc_html_e('Status', 'condixia-ai-site-to-wordpress'); ?>
                                </th>
                                <th>
                                    <?php esc_html_e('WordPress object', 'condixia-ai-site-to-wordpress'); ?>
                                </th>
                                <th><?php esc_html_e('Actions', 'condixia-ai-site-to-wordpress'); ?></th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <tr>
                                    <td>
                                        <?php echo esc_html((string) ($row['title'] ?? '')); ?>
                                    </td>

                                    <td>
                                        <code><?php echo esc_html((string) ($row['role'] ?? '')); ?></code>
                                    </td>

                                    <td>
                                        <code><?php echo esc_html((string) ($row['source_path'] ?? '')); ?></code>
                                    </td>

                                    <td>
                                        <code><?php echo esc_html(is_array($row['metadata']['cdx_trash'] ?? null) ? 'trashed' : (string) ($row['status'] ?? '')); ?></code>
                                    </td>

                                    <td>
                                        <?php
                                        $objectId = (int) (
                                            $row['wp_object_id']
                                            ?? 0
                                        );

                                        echo $objectId > 0
                                            ? esc_html((string) $objectId)
                                            : '—';
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($key === 'pages' && $objectId > 0) : ?>
                                            <?php $permalink = get_permalink($objectId); ?>
                                            <?php if (is_string($permalink) && $permalink !== '') : ?>
                                                <a href="<?php echo esc_url($permalink); ?>" target="_blank" rel="noopener noreferrer">
                                                    <?php esc_html_e('View', 'condixia-ai-site-to-wordpress'); ?>
                                                </a>
                                                <?php if (current_user_can(Capabilities::EDIT)) : ?>
                                                    | <a href="<?php echo esc_url(add_query_arg([
                                                                    'page' => \Condixia\AISiteToWordPress\Editor\EditorApplication::PAGE_SLUG,
                                                                    'target' => $permalink,
                                                                ], admin_url('admin.php'))); ?>">
                                                        <?php esc_html_e('Edit in Condixia', 'condixia-ai-site-to-wordpress'); ?>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php elseif ($key === 'templates' || $key === 'site_parts') : ?>
                                            <a href="<?php echo esc_url(add_query_arg([
                                                            'page' => $key === 'templates' ? AdminMenu::TEMPLATES_SLUG : AdminMenu::SITE_PARTS_SLUG,
                                                            'site_id' => (int) ($site['id'] ?? 0),
                                                        ], admin_url('admin.php'))); ?>">
                                                <?php esc_html_e('Manage', 'condixia-ai-site-to-wordpress'); ?>
                                            </a>
                                        <?php else : ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($activeSection === 'files') : ?>
            <div class="card">
                <h2><?php esc_html_e('Source Files', 'condixia-ai-site-to-wordpress'); ?></h2>
                <p><?php echo esc_html(sprintf(
                        __('%d active files belong to this import.', 'condixia-ai-site-to-wordpress'),
                        (int) ($selected['file_count'] ?? 0)
                    )); ?></p>

                <?php if ($sourceFiles === []) : ?>
                    <p><?php esc_html_e('No active files found.', 'condixia-ai-site-to-wordpress'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Path', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('Type', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('Size', 'condixia-ai-site-to-wordpress'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sourceFiles as $sourceFile) : ?>
                                <tr>
                                    <td><code><?php echo esc_html((string) ($sourceFile['relative_path'] ?? '')); ?></code></td>
                                    <td><?php echo esc_html((string) ($sourceFile['file_type'] ?? '')); ?></td>
                                    <td><?php echo esc_html(size_format(max(0, (int) ($sourceFile['size_bytes'] ?? 0)))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ((int) ($selected['file_count'] ?? 0) > count($sourceFiles)) : ?>
                        <p class="description"><?php esc_html_e('Showing the first 500 files, sorted by path.', 'condixia-ai-site-to-wordpress'); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($activeSection === 'history') : ?>
            <div class="card">
                <h2><?php esc_html_e('Manifest History', 'condixia-ai-site-to-wordpress'); ?></h2>
                <p><?php esc_html_e('Versions saved for this imported site. Separately imported replacement packages appear as other sites in the Sites list.', 'condixia-ai-site-to-wordpress'); ?></p>

                <?php if ($importHistory === []) : ?>
                    <p><?php esc_html_e('No manifest records found.', 'condixia-ai-site-to-wordpress'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Version', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('Created', 'condixia-ai-site-to-wordpress'); ?></th>
                                <th><?php esc_html_e('Status', 'condixia-ai-site-to-wordpress'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($importHistory as $version) : ?>
                                <tr>
                                    <td><?php echo esc_html((string) ((int) ($version['version'] ?? 0))); ?></td>
                                    <td><?php echo esc_html((string) ($version['created_at'] ?? '')); ?></td>
                                    <td><?php echo (int) ($version['id'] ?? 0) === (int) ($manifest['id'] ?? 0)
                                            ? esc_html__('Current', 'condixia-ai-site-to-wordpress')
                                            : esc_html__('Previous', 'condixia-ai-site-to-wordpress'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($activeSection === 'overview') : ?>
            <?php if (! empty($selected['warnings'])) : ?>
                <div class="card">
                    <h2>
                        <?php
                        esc_html_e(
                            'Import Warnings',
                            'condixia-ai-site-to-wordpress'
                        );
                        ?>
                    </h2>

                    <ul>
                        <?php foreach ($selected['warnings'] as $warning) : ?>
                            <li>
                                <?php
                                echo esc_html(
                                    is_scalar($warning)
                                        ? (string) $warning
                                        : (string) wp_json_encode(
                                            $warning
                                        )
                                );
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card">
                <h2>
                    <?php
                    esc_html_e(
                        'Re-import / Update',
                        'condixia-ai-site-to-wordpress'
                    );
                    ?>
                </h2>

                <p>
                    <?php
                    esc_html_e(
                        'The replacement package is imported as a fresh analyzed Condixia site first. The current site stays intact until you explicitly delete it, so a failed replacement cannot destroy the live import.',
                        'condixia-ai-site-to-wordpress'
                    );
                    ?>
                </p>

                <?php if (current_user_can(Capabilities::IMPORT)) : ?>
                    <form
                        method="post"
                        enctype="multipart/form-data"
                        action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                        <input
                            type="hidden"
                            name="action"
                            value="condixia_ai_stw_upload_site">

                        <?php
                        wp_nonce_field(
                            NonceActions::IMPORT
                        );
                        ?>

                        <p>
                            <input
                                type="file"
                                name="site_package"
                                accept=".html,.htm,.zip"
                                required>
                        </p>

                        <p class="description">
                            <?php
                            echo esc_html(
                                sprintf(
                                    __(
                                        'Maximum upload size: %s',
                                        'condixia-ai-site-to-wordpress'
                                    ),
                                    size_format(
                                        wp_max_upload_size()
                                    )
                                )
                            );
                            ?>
                        </p>

                        <p>
                            <button
                                type="submit"
                                class="button button-primary">
                                <?php
                                esc_html_e(
                                    'Import Replacement Package',
                                    'condixia-ai-site-to-wordpress'
                                );
                                ?>
                            </button>
                        </p>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>
                    <?php
                    esc_html_e(
                        'Export Current Package / State',
                        'condixia-ai-site-to-wordpress'
                    );
                    ?>
                </h2>

                <p>
                    <?php
                    esc_html_e(
                        'Exports the current imported source files plus Condixia state metadata as a ZIP archive.',
                        'condixia-ai-site-to-wordpress'
                    );
                    ?>
                </p>

                <?php if (current_user_can(Capabilities::MANAGE_FILES)) : ?>
                    <form
                        method="post"
                        action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                        <input
                            type="hidden"
                            name="action"
                            value="<?php echo esc_attr(SiteManagementController::EXPORT_ACTION); ?>">

                        <input
                            type="hidden"
                            name="site_id"
                            value="<?php echo esc_attr((string) ((int) ($site['id'] ?? 0))); ?>">

                        <?php
                        wp_nonce_field(
                            NonceActions::resource(
                                NonceActions::FILE_OPERATION,
                                (int) (
                                    $site['id']
                                    ?? 0
                                )
                            )
                        );
                        ?>

                        <button
                            type="submit"
                            class="button">
                            <?php
                            esc_html_e(
                                'Download Export',
                                'condixia-ai-site-to-wordpress'
                            );
                            ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>
                    <?php
                    esc_html_e(
                        'Delete Imported Site',
                        'condixia-ai-site-to-wordpress'
                    );
                    ?>
                </h2>

                <p>
                    <?php
                    esc_html_e(
                        'This permanently removes Condixia-owned Pages, Condixia-owned navigation objects, stored source/public files, plugin database state, and only imported Media Library attachments that Condixia can prove are safe to remove.',
                        'condixia-ai-site-to-wordpress'
                    );
                    ?>
                </p>

                <?php if (current_user_can(Capabilities::DELETE)) : ?>
                    <form
                        method="post"
                        action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                        <input
                            type="hidden"
                            name="action"
                            value="<?php echo esc_attr(SiteManagementController::DELETE_ACTION); ?>">

                        <input
                            type="hidden"
                            name="site_id"
                            value="<?php echo esc_attr((string) ((int) ($site['id'] ?? 0))); ?>">

                        <?php
                        wp_nonce_field(
                            NonceActions::resource(
                                NonceActions::DELETE_SITE,
                                (int) (
                                    $site['id']
                                    ?? 0
                                )
                            )
                        );
                        ?>

                        <p>
                            <label>
                                <input
                                    type="checkbox"
                                    name="confirm_delete"
                                    value="1"
                                    required>

                                <?php
                                esc_html_e(
                                    'I understand this permanently deletes this Condixia import and its verified plugin-owned WordPress objects.',
                                    'condixia-ai-site-to-wordpress'
                                );
                                ?>
                            </label>
                        </p>

                        <button
                            type="submit"
                            class="button button-link-delete">
                            <?php
                            esc_html_e(
                                'Delete Site Permanently',
                                'condixia-ai-site-to-wordpress'
                            );
                            ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else : ?>
        <?php if ($sites === []) : ?>
            <div class="card">
                <p>
                    <?php
                    esc_html_e(
                        'No Condixia sites have been imported yet.',
                        'condixia-ai-site-to-wordpress'
                    );
                    ?>
                </p>

                <a
                    class="button button-primary"
                    href="<?php echo esc_url(
                                add_query_arg(
                                    [
                                        'page'
                                        => AdminMenu::IMPORT_SLUG,
                                    ],
                                    admin_url(
                                        'admin.php'
                                    )
                                )
                            ); ?>">
                    <?php
                    esc_html_e(
                        'Import Site',
                        'condixia-ai-site-to-wordpress'
                    );
                    ?>
                </a>
            </div>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Site', 'condixia-ai-site-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Status', 'condixia-ai-site-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Pages', 'condixia-ai-site-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Templates', 'condixia-ai-site-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Site Parts', 'condixia-ai-site-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Files', 'condixia-ai-site-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Updated', 'condixia-ai-site-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Actions', 'condixia-ai-site-to-wordpress'); ?></th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($sites as $site) : ?>
                        <tr>
                            <td>
                                <strong>
                                    <?php echo esc_html((string) ($site['name'] ?? '')); ?>
                                </strong>

                                <br>

                                <small>
                                    <?php echo esc_html((string) ($site['source_label'] ?? '')); ?>
                                </small>
                            </td>

                            <td>
                                <code><?php echo esc_html((string) ($site['status'] ?? '')); ?></code>
                            </td>

                            <td>
                                <?php echo esc_html((string) ((int) ($site['page_count'] ?? 0))); ?>
                            </td>

                            <td>
                                <?php echo esc_html((string) ((int) ($site['template_count'] ?? 0))); ?>
                            </td>

                            <td>
                                <?php echo esc_html((string) ((int) ($site['site_part_count'] ?? 0))); ?>
                            </td>

                            <td>
                                <?php echo esc_html((string) ((int) ($site['file_count'] ?? 0))); ?>
                            </td>

                            <td>
                                <?php echo esc_html((string) ($site['updated_at'] ?? '')); ?>
                            </td>

                            <td>
                                <a
                                    class="button button-small"
                                    href="<?php echo esc_url(
                                                add_query_arg(
                                                    [
                                                        'page'
                                                        => AdminMenu::SITES_SLUG,
                                                        'site_id'
                                                        => (int) (
                                                            $site['id']
                                                            ?? 0
                                                        ),
                                                    ],
                                                    admin_url(
                                                        'admin.php'
                                                    )
                                                )
                                            ); ?>">
                                    <?php
                                    esc_html_e(
                                        'Manage',
                                        'condixia-ai-site-to-wordpress'
                                    );
                                    ?>
                                </a>

                                <?php
                                $homePageId = (int) (
                                    $site['home_page_id']
                                    ?? 0
                                );

                                $permalink = $homePageId > 0
                                    ? get_permalink(
                                        $homePageId
                                    )
                                    : '';
                                ?>

                                <?php if (is_string($permalink) && $permalink !== '') : ?>
                                    <a
                                        class="button button-small"
                                        href="<?php echo esc_url($permalink); ?>"
                                        target="_blank"
                                        rel="noopener noreferrer">
                                        <?php
                                        esc_html_e(
                                            'View Site',
                                            'condixia-ai-site-to-wordpress'
                                        );
                                        ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>