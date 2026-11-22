<?php

declare(strict_types=1);

use Condixia\AISiteToWordPress\Admin\AdminMenu;
use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Security\NonceActions;
use Condixia\AISiteToWordPress\SiteManagement\SiteManagementController;

defined('ABSPATH') || exit;

$kind = ($viewData['kind'] ?? '') === 'site_part' ? 'site_part' : 'template';
$rows = is_array($viewData['artifacts'] ?? null) ? $viewData['artifacts'] : [];
$notice = is_array($viewData['notice'] ?? null) ? $viewData['notice'] : null;
$siteId = absint($viewData['site_id'] ?? 0);
$title = $kind === 'template' ? __('Templates', 'condixia-ai-site-to-wordpress') : __('Site Parts', 'condixia-ai-site-to-wordpress');
$slug = $kind === 'template' ? AdminMenu::TEMPLATES_SLUG : AdminMenu::SITE_PARTS_SLUG;
?>
<div class="wrap cdx-stw-admin">
    <h1><?php echo esc_html($title); ?></h1>
    <p>
        <?php esc_html_e('Move a template or header/footer to Trash without deleting the imported HTML. Restore it later with its previously enabled display assignments.', 'condixia-ai-site-to-wordpress'); ?>
    </p>

    <?php if ($notice !== null) : ?>
        <div class="notice notice-<?php echo esc_attr((string) ($notice['type'] ?? 'info')); ?> is-dismissible">
            <p><?php echo esc_html((string) ($notice['message'] ?? '')); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($siteId > 0) : ?>
        <p>
            <a class="button" href="<?php echo esc_url(add_query_arg(['page' => $slug], admin_url('admin.php'))); ?>">
                <?php esc_html_e('All Sites', 'condixia-ai-site-to-wordpress'); ?>
            </a>
            <a class="button" href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::SITES_SLUG, 'site_id' => $siteId], admin_url('admin.php'))); ?>">
                <?php esc_html_e('Site Dashboard', 'condixia-ai-site-to-wordpress'); ?>
            </a>
        </p>
    <?php endif; ?>

    <?php if ($rows === []) : ?>
        <p><?php esc_html_e('No imported items match this view.', 'condixia-ai-site-to-wordpress'); ?></p>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Title', 'condixia-ai-site-to-wordpress'); ?></th>
                    <th><?php esc_html_e('Imported Site', 'condixia-ai-site-to-wordpress'); ?></th>
                    <th><?php esc_html_e('Role', 'condixia-ai-site-to-wordpress'); ?></th>
                    <th><?php esc_html_e('Source HTML', 'condixia-ai-site-to-wordpress'); ?></th>
                    <th><?php esc_html_e('Status', 'condixia-ai-site-to-wordpress'); ?></th>
                    <th><?php esc_html_e('Actions', 'condixia-ai-site-to-wordpress'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $artifactId = (int) ($row['id'] ?? 0);
                    $rowSiteId = (int) ($row['site_id'] ?? 0);
                    $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
                    $trashed = is_array($metadata['cdx_trash'] ?? null);
                    $operation = $trashed ? 'restore' : 'trash';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) ($row['title'] ?? '')); ?></strong></td>
                        <td>
                            <?php if ($rowSiteId > 0 && current_user_can(Capabilities::MANAGE)) : ?>
                                <a href="<?php echo esc_url(add_query_arg([
                                                'page' => AdminMenu::SITES_SLUG,
                                                'site_id' => $rowSiteId,
                                                'section' => $kind === 'template' ? 'templates' : 'site_parts',
                                            ], admin_url('admin.php'))); ?>">
                                    <?php echo esc_html((string) ($row['site_name'] ?? '')); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html((string) ($row['site_name'] ?? '')); ?>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html((string) ($row['role'] ?? '')); ?></code></td>
                        <td><code><?php echo esc_html((string) ($row['source_path'] ?? '')); ?></code></td>
                        <td><?php echo esc_html($trashed ? __('Trashed', 'condixia-ai-site-to-wordpress') : (string) ($row['status'] ?? '')); ?></td>
                        <td>
                            <?php if ($rowSiteId > 0 && current_user_can(Capabilities::MANAGE)) : ?>
                                <a class="button button-small"
                                    href="<?php echo esc_url(add_query_arg([
                                                'page' => AdminMenu::SITES_SLUG,
                                                'site_id' => $rowSiteId,
                                                'section' => $kind === 'template' ? 'templates' : 'site_parts',
                                            ], admin_url('admin.php'))); ?>">
                                    <?php esc_html_e('Open in Site', 'condixia-ai-site-to-wordpress'); ?>
                                </a>
                            <?php endif; ?>

                            <?php if (current_user_can(Capabilities::DELETE) && $artifactId > 0 && $rowSiteId > 0) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(SiteManagementController::ARTIFACT_ACTION); ?>">
                                    <input type="hidden" name="site_id" value="<?php echo esc_attr((string) $rowSiteId); ?>">
                                    <input type="hidden" name="artifact_id" value="<?php echo esc_attr((string) $artifactId); ?>">
                                    <input type="hidden" name="artifact_kind" value="<?php echo esc_attr($kind); ?>">
                                    <input type="hidden" name="artifact_operation" value="<?php echo esc_attr($operation); ?>">
                                    <?php wp_nonce_field(NonceActions::resource(NonceActions::MANAGE_ARTIFACT, $artifactId)); ?>
                                    <?php if (! $trashed) : ?>
                                        <label>
                                            <input type="checkbox" name="confirm_trash" value="1" required>
                                            <?php esc_html_e('Confirm', 'condixia-ai-site-to-wordpress'); ?>
                                        </label>
                                    <?php endif; ?>
                                    <button type="submit" class="button button-small <?php echo $trashed ? '' : 'button-link-delete'; ?>">
                                        <?php echo esc_html($trashed ? __('Restore', 'condixia-ai-site-to-wordpress') : __('Move to Trash', 'condixia-ai-site-to-wordpress')); ?>
                                    </button>
                                </form>
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