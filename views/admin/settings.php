<?php

defined('ABSPATH') || exit;
?>

<div class="wrap cdx-stw-admin">
    <div class="cdx-stw-page-header">
        <div>
            <p class="cdx-stw-eyebrow">
                <?php esc_html_e('Condixia', 'condixia-ai-site-to-wordpress'); ?>
            </p>

            <h1>
                <?php esc_html_e('Settings', 'condixia-ai-site-to-wordpress'); ?>
            </h1>

            <p class="cdx-stw-page-description">
                <?php
                esc_html_e(
                    'Configure global Condixia behavior as configurable options become available.',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </p>
        </div>
    </div>

    <div class="cdx-stw-grid cdx-stw-grid-two">
        <div class="cdx-stw-card">
            <h3>
                <?php esc_html_e('General', 'condixia-ai-site-to-wordpress'); ?>
            </h3>

            <p>
                <?php
                esc_html_e(
                    'Global site-management preferences will live here.',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </p>

            <span class="cdx-stw-muted">
                <?php esc_html_e('No configurable options yet.', 'condixia-ai-site-to-wordpress'); ?>
            </span>
        </div>

        <div class="cdx-stw-card">
            <h3>
                <?php esc_html_e('Import policy', 'condixia-ai-site-to-wordpress'); ?>
            </h3>

            <p>
                <?php
                esc_html_e(
                    'Import-related policies will be exposed here only when user configuration is required.',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </p>

            <span class="cdx-stw-muted">
                <?php esc_html_e('Using secure defaults.', 'condixia-ai-site-to-wordpress'); ?>
            </span>
        </div>
    </div>
</div>