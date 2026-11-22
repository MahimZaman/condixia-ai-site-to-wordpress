<?php

use Condixia\AISiteToWordPress\Security\NonceActions;

defined('ABSPATH') || exit;

$maximumUploadSize = isset($viewData['maximum_upload_size'])
    ? (int) $viewData['maximum_upload_size']
    : 0;

$notice = isset($viewData['notice'])
    && is_array($viewData['notice'])
    ? $viewData['notice']
    : null;
?>

<div class="wrap cdx-stw-admin">
    <div class="cdx-stw-page-header">
        <div>
            <p class="cdx-stw-eyebrow">
                <?php esc_html_e('Condixia', 'condixia-ai-site-to-wordpress'); ?>
            </p>

            <h1>
                <?php esc_html_e('Import Site', 'condixia-ai-site-to-wordpress'); ?>
            </h1>

            <p class="cdx-stw-page-description">
                <?php
                esc_html_e(
                    'Bring an existing frontend design into WordPress.',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </p>
        </div>
    </div>

    <?php if ($notice !== null) : ?>
        <div
            class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> inline cdx-stw-inline-notice">
            <p>
                <?php echo esc_html((string) $notice['message']); ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="cdx-stw-section-heading">
        <h2>
            <?php esc_html_e('Free import', 'condixia-ai-site-to-wordpress'); ?>
        </h2>

        <p>
            <?php
            esc_html_e(
                'Upload a standalone HTML document or a ZIP website package.',
                'condixia-ai-site-to-wordpress'
            );
            ?>
        </p>
    </div>

    <form
        class="cdx-stw-card cdx-stw-upload-card"
        method="post"
        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        enctype="multipart/form-data">
        <div class="cdx-stw-card-topline">
            <span class="cdx-stw-badge cdx-stw-badge-free">
                <?php esc_html_e('FREE', 'condixia-ai-site-to-wordpress'); ?>
            </span>
        </div>

        <h3>
            <?php esc_html_e('HTML / ZIP Website', 'condixia-ai-site-to-wordpress'); ?>
        </h3>

        <p>
            <?php
            esc_html_e(
                'Upload HTML, CSS, JavaScript, images, fonts, and related frontend assets as an HTML file or ZIP package.',
                'condixia-ai-site-to-wordpress'
            );
            ?>
        </p>

        <div class="cdx-stw-upload-field">
            <label for="cdx-stw-site-package">
                <?php esc_html_e('Site package', 'condixia-ai-site-to-wordpress'); ?>
            </label>

            <input
                id="cdx-stw-site-package"
                class="cdx-stw-file-input"
                type="file"
                name="site_package"
                accept=".html,.htm,.zip,text/html,application/zip"
                required>

            <div class="cdx-stw-upload-meta">
                <span>
                    <?php esc_html_e('Supported: .html, .htm, .zip', 'condixia-ai-site-to-wordpress'); ?>
                </span>

                <?php if ($maximumUploadSize > 0) : ?>
                    <span>
                        <?php
                        printf(
                            esc_html__('Maximum upload: %s', 'condixia-ai-site-to-wordpress'),
                            esc_html(size_format($maximumUploadSize))
                        );
                        ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <input
            type="hidden"
            name="action"
            value="condixia_ai_stw_upload_site">

        <?php
        wp_nonce_field(
            NonceActions::IMPORT
        );
        ?>

        <button
            type="submit"
            class="button button-primary">
            <?php esc_html_e('Upload site package', 'condixia-ai-site-to-wordpress'); ?>
        </button>
    </form>

    <div class="cdx-stw-section-heading cdx-stw-section-heading-spaced">
        <h2>
            <?php esc_html_e('More import sources', 'condixia-ai-site-to-wordpress'); ?>
        </h2>

        <p>
            <?php
            esc_html_e(
                'Additional source providers are planned for Condixia Pro.',
                'condixia-ai-site-to-wordpress'
            );
            ?>
        </p>
    </div>

    <div class="cdx-stw-grid cdx-stw-grid-three">
        <div class="cdx-stw-card cdx-stw-card-locked">
            <div class="cdx-stw-card-topline">
                <span class="cdx-stw-badge cdx-stw-badge-pro">
                    <?php esc_html_e('PRO', 'condixia-ai-site-to-wordpress'); ?>
                </span>
            </div>

            <h3>
                <?php esc_html_e('React / JSX / TSX Import', 'condixia-ai-site-to-wordpress'); ?>
            </h3>

            <p>
                <?php
                esc_html_e(
                    'Import modern React source projects through a dedicated source provider.',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </p>

            <button type="button" class="button" disabled>
                <?php esc_html_e('Pro feature', 'condixia-ai-site-to-wordpress'); ?>
            </button>
        </div>

        <div class="cdx-stw-card cdx-stw-card-locked">
            <div class="cdx-stw-card-topline">
                <span class="cdx-stw-badge cdx-stw-badge-pro">
                    <?php esc_html_e('PRO', 'condixia-ai-site-to-wordpress'); ?>
                </span>
            </div>

            <h3>
                <?php esc_html_e('Vite Project Import', 'condixia-ai-site-to-wordpress'); ?>
            </h3>

            <p>
                <?php
                esc_html_e(
                    'Import supported Vite-based source projects through a future build provider.',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </p>

            <button type="button" class="button" disabled>
                <?php esc_html_e('Pro feature', 'condixia-ai-site-to-wordpress'); ?>
            </button>
        </div>

        <div class="cdx-stw-card cdx-stw-card-locked">
            <div class="cdx-stw-card-topline">
                <span class="cdx-stw-badge cdx-stw-badge-coming">
                    <?php esc_html_e('PRO / COMING LATER', 'condixia-ai-site-to-wordpress'); ?>
                </span>
            </div>

            <h3>
                <?php esc_html_e('AI Platform Connections', 'condixia-ai-site-to-wordpress'); ?>
            </h3>

            <p>
                <?php
                esc_html_e(
                    'Future direct connections for supported AI website-generation platforms.',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </p>

            <button type="button" class="button" disabled>
                <?php esc_html_e('Coming later', 'condixia-ai-site-to-wordpress'); ?>
            </button>
        </div>
    </div>
</div>