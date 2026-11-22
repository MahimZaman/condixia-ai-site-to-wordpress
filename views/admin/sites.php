<?php

use Condixia\AISiteToWordPress\Admin\AdminMenu;
use Condixia\AISiteToWordPress\Security\Capabilities;

defined('ABSPATH') || exit;
?>

<div class="wrap cdx-stw-admin">
    <div class="cdx-stw-page-header">
        <div>
            <p class="cdx-stw-eyebrow">
                <?php esc_html_e('Condixia', 'condixia-ai-site-to-wordpress'); ?>
            </p>

            <h1>
                <?php esc_html_e('Sites', 'condixia-ai-site-to-wordpress'); ?>
            </h1>

            <p class="cdx-stw-page-description">
                <?php
                esc_html_e(
                    'Manage websites imported and maintained by Condixia.',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </p>
        </div>

        <?php if (current_user_can(Capabilities::IMPORT)) : ?>
            <a
                class="button button-primary cdx-stw-primary-action"
                href="<?php
                        echo esc_url(
                            admin_url(
                                'admin.php?page=' . AdminMenu::IMPORT_SLUG
                            )
                        );
                        ?>">
                <?php esc_html_e('Import Site', 'condixia-ai-site-to-wordpress'); ?>
            </a>
        <?php endif; ?>
    </div>

    <div class="cdx-stw-empty-state">
        <div class="cdx-stw-empty-icon" aria-hidden="true">
            <span class="dashicons dashicons-admin-site-alt3"></span>
        </div>

        <h2>
            <?php esc_html_e('No imported sites yet', 'condixia-ai-site-to-wordpress'); ?>
        </h2>

        <p>
            <?php
            esc_html_e(
                'Your imported WordPress-managed sites will appear here.',
                'condixia-ai-site-to-wordpress'
            );
            ?>
        </p>

        <?php if (current_user_can(Capabilities::IMPORT)) : ?>
            <a
                class="button button-primary"
                href="<?php
                        echo esc_url(
                            admin_url(
                                'admin.php?page=' . AdminMenu::IMPORT_SLUG
                            )
                        );
                        ?>">
                <?php esc_html_e('Import your first site', 'condixia-ai-site-to-wordpress'); ?>
            </a>
        <?php endif; ?>
    </div>
</div>