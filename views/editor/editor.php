<?php

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<div class="cdx-stw-editor-shell">
    <header class="cdx-stw-editor-topbar">
        <div class="cdx-stw-editor-brand">
            <strong>
                <?php
                esc_html_e(
                    'Condixia Visual Editor',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </strong>

            <span data-cdx-editor-status>
                <?php
                esc_html_e(
                    'Select an element in the preview.',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </span>
        </div>

        <div class="cdx-stw-editor-actions">
            <button type="button" class="button cdx-stw-editor-panel-toggle"
                data-cdx-panel-toggle="navigator" aria-controls="cdx-stw-navigator-panel"
                aria-expanded="true">
                <?php esc_html_e('Navigator', 'condixia-ai-site-to-wordpress'); ?>
            </button>

            <button type="button" class="button cdx-stw-editor-panel-toggle"
                data-cdx-panel-toggle="inspector" aria-controls="cdx-stw-inspector-panel"
                aria-expanded="true">
                <?php esc_html_e('Inspector', 'condixia-ai-site-to-wordpress'); ?>
            </button>
            <div
                class="cdx-stw-editor-viewports"
                role="group"
                aria-label="<?php esc_attr_e('Preview size', 'condixia-ai-site-to-wordpress'); ?>">

                <button
                    type="button"
                    class="button is-active"
                    data-cdx-viewport="desktop">
                    <?php esc_html_e('Desktop', 'condixia-ai-site-to-wordpress'); ?>
                </button>

                <button
                    type="button"
                    class="button"
                    data-cdx-viewport="tablet">
                    <?php esc_html_e('Tablet', 'condixia-ai-site-to-wordpress'); ?>
                </button>

                <button
                    type="button"
                    class="button"
                    data-cdx-viewport="mobile">
                    <?php esc_html_e('Mobile', 'condixia-ai-site-to-wordpress'); ?>
                </button>
            </div>

            <div class="cdx-stw-editor-zoom" role="group"
                aria-label="<?php esc_attr_e('Canvas zoom', 'condixia-ai-site-to-wordpress'); ?>">
                <button type="button" class="button" data-cdx-zoom="out"
                    aria-label="<?php esc_attr_e('Zoom out', 'condixia-ai-site-to-wordpress'); ?>">−</button>
                <output data-cdx-zoom-label aria-live="polite">100%</output>
                <button type="button" class="button" data-cdx-zoom="in"
                    aria-label="<?php esc_attr_e('Zoom in', 'condixia-ai-site-to-wordpress'); ?>">+</button>
                <button type="button" class="button" data-cdx-zoom="fit">
                    <?php esc_html_e('Fit', 'condixia-ai-site-to-wordpress'); ?>
                </button>
                <button type="button" class="button" data-cdx-zoom="actual">
                    <?php esc_html_e('100%', 'condixia-ai-site-to-wordpress'); ?>
                </button>
            </div>

            <button
                type="button"
                class="button"
                data-cdx-editor-action="undo"
                disabled>
                <?php
                esc_html_e(
                    'Undo',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </button>

            <button
                type="button"
                class="button"
                data-cdx-editor-action="redo"
                disabled>
                <?php
                esc_html_e(
                    'Redo',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </button>

            <button
                type="button"
                class="button button-primary"
                data-cdx-editor-action="save"
                disabled>
                <?php
                esc_html_e(
                    'Save',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </button>

            <button
                type="button"
                class="button"
                data-cdx-editor-action="restore-element"
                disabled>
                <?php
                esc_html_e(
                    'Restore Element',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </button>

            <button
                type="button"
                class="button"
                data-cdx-editor-action="restore-page"
                disabled>
                <?php
                esc_html_e(
                    'Discard Unsaved',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </button>

            <select data-cdx-editor-revisions>
                <option value="">
                    <?php
                    esc_html_e(
                        'Saved revisions',
                        'condixia-ai-site-to-wordpress'
                    );
                    ?>
                </option>
            </select>

            <button
                type="button"
                class="button"
                data-cdx-editor-action="restore-revision"
                disabled>
                <?php
                esc_html_e(
                    'Restore Revision',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </button>

            <button
                type="button"
                class="button"
                data-cdx-editor-action="restore-original">
                <?php
                esc_html_e(
                    'Restore Original',
                    'condixia-ai-site-to-wordpress'
                );
                ?>
            </button>

            <a
                class="button"
                href="<?php echo esc_url($targetUrl); ?>">
                <?php esc_html_e('Exit Editor', 'condixia-ai-site-to-wordpress'); ?>
            </a>
        </div>
    </header>

    <div class="cdx-stw-editor-workspace">
        <aside class="cdx-stw-editor-navigator" id="cdx-stw-navigator-panel"
            aria-label="<?php esc_attr_e('Navigator', 'condixia-ai-site-to-wordpress'); ?>">
            <div class="cdx-stw-editor-panel-heading cdx-stw-navigator-handle"
                data-cdx-navigator-drag-handle tabindex="0"
                aria-label="Move Navigator with Alt and arrow keys">
                <div>
                    <span><?php esc_html_e('STRUCTURE', 'condixia-ai-site-to-wordpress'); ?></span>
                    <h2><?php esc_html_e('Navigator', 'condixia-ai-site-to-wordpress'); ?></h2>
                </div>
                <button type="button" class="cdx-stw-navigator-close"
                    data-cdx-navigator-close aria-label="Close Navigator"
                    title="Close Navigator">&times;</button>
            </div>

            <div class="cdx-stw-editor-current-selection" aria-live="polite">
                <small><?php esc_html_e('Selected element', 'condixia-ai-site-to-wordpress'); ?></small>
                <code data-cdx-navigator-element><?php esc_html_e('Nothing selected', 'condixia-ai-site-to-wordpress'); ?></code>
            </div>

            <div class="cdx-stw-layer-view-toggle" role="group"
                aria-label="<?php esc_attr_e('Navigator display', 'condixia-ai-site-to-wordpress'); ?>">
                <button type="button" data-cdx-layer-mode="smart" aria-pressed="true">
                    <?php esc_html_e('Smart Outline', 'condixia-ai-site-to-wordpress'); ?>
                </button>
                <button type="button" data-cdx-layer-mode="all" aria-pressed="false">
                    <?php esc_html_e('All Elements', 'condixia-ai-site-to-wordpress'); ?>
                </button>
            </div>
            <label class="cdx-stw-layer-search">
                <span class="screen-reader-text">
                    <?php esc_html_e('Search layers', 'condixia-ai-site-to-wordpress'); ?>
                </span>

                <input
                    type="search"
                    data-cdx-layer-search
                    placeholder="<?php esc_attr_e('Search layers…', 'condixia-ai-site-to-wordpress'); ?>"
                    autocomplete="off">
            </label>

            <nav
                class="cdx-stw-layer-breadcrumbs"
                data-cdx-layer-breadcrumbs
                aria-label="<?php esc_attr_e('Selected element hierarchy', 'condixia-ai-site-to-wordpress'); ?>">
            </nav>

            <p
                class="cdx-stw-layer-summary"
                data-cdx-layer-summary
                role="status">
                <?php esc_html_e('Loading layers…', 'condixia-ai-site-to-wordpress'); ?>
            </p>

            <ul
                class="cdx-stw-layer-tree"
                data-cdx-layer-tree>
            </ul>
        </aside>

        <aside
            class="cdx-stw-editor-panel"
            id="cdx-stw-inspector-panel"
            aria-label="<?php esc_attr_e('Element controls', 'condixia-ai-site-to-wordpress'); ?>">
            <div class="cdx-stw-editor-panel-heading">
                <span><?php esc_html_e('PROPERTIES', 'condixia-ai-site-to-wordpress'); ?></span>
                <h2><?php esc_html_e('Inspector', 'condixia-ai-site-to-wordpress'); ?></h2>
            </div>

            <div
                class="cdx-stw-editor-empty"
                data-cdx-editor-empty>
                <p>
                    <?php
                    esc_html_e(
                        'Click an editable element in the preview to inspect and change it.',
                        'condixia-ai-site-to-wordpress'
                    );
                    ?>
                </p>
            </div>

            <div
                class="cdx-stw-editor-controls"
                data-cdx-editor-controls
                hidden>

                <div class="cdx-stw-inspector-selection">
                    <strong data-cdx-inspector-element><?php esc_html_e('Element', 'condixia-ai-site-to-wordpress'); ?></strong>
                    <small data-cdx-inspector-hint></small>
                </div>

                <div class="cdx-stw-inspector-tabs" role="group"
                    aria-label="<?php esc_attr_e('Inspector sections', 'condixia-ai-site-to-wordpress'); ?>">
                    <?php foreach (
                        [
                            'content' => __('Content', 'condixia-ai-site-to-wordpress'),
                            'design' => __('Design', 'condixia-ai-site-to-wordpress'),
                            'advanced' => __('Advanced', 'condixia-ai-site-to-wordpress'),
                        ] as $tabKey => $tabLabel
                    ) : ?>
                        <button type="button" data-cdx-inspector-tab="<?php echo esc_attr($tabKey); ?>"
                            aria-pressed="false"><?php echo esc_html($tabLabel); ?></button>
                    <?php endforeach; ?>
                </div>

                <section data-cdx-inspector-panel="content" data-cdx-inspector-when="text" hidden>
                    <h2>
                        <?php esc_html_e('Content', 'condixia-ai-site-to-wordpress'); ?>
                    </h2>

                    <label data-cdx-field="text">
                        <span>
                            <?php esc_html_e('Text', 'condixia-ai-site-to-wordpress'); ?>
                        </span>

                        <textarea
                            rows="4"
                            data-cdx-property="text"></textarea>
                    </label>

                    <label data-cdx-field="linkUrl">
                        <span>
                            <?php esc_html_e('Link URL', 'condixia-ai-site-to-wordpress'); ?>
                        </span>

                        <input
                            type="url"
                            data-cdx-property="linkUrl">
                    </label>

                    <label data-cdx-field="linkTarget">
                        <span>
                            <?php esc_html_e('Link target', 'condixia-ai-site-to-wordpress'); ?>
                        </span>

                        <select data-cdx-property="linkTarget">
                            <option value="_self">
                                <?php esc_html_e('Same tab', 'condixia-ai-site-to-wordpress'); ?>
                            </option>

                            <option value="_blank">
                                <?php esc_html_e('New tab', 'condixia-ai-site-to-wordpress'); ?>
                            </option>
                        </select>
                    </label>

                    <div class="cdx-stw-richtext" data-cdx-richtext hidden>
                        <h3><?php esc_html_e('Rich-text segments', 'condixia-ai-site-to-wordpress'); ?></h3>
                        <p class="description">
                            <?php esc_html_e('Edit each text segment separately. Spans, links, emphasis, line breaks, classes and inline styling are preserved. Select a nested span to edit only that element.', 'condixia-ai-site-to-wordpress'); ?>
                        </p>
                        <div data-cdx-richtext-fields></div>
                        <p class="cdx-stw-richtext-feedback" data-cdx-richtext-feedback role="status" aria-live="polite"></p>
                    </div>

                    <p class="cdx-stw-inspector-note" data-cdx-nested-text-warning hidden>
                        <?php esc_html_e('This element contains complex or unsupported nested markup. Editing its full text is disabled to protect the imported structure; select a smaller inline element instead.', 'condixia-ai-site-to-wordpress'); ?>
                    </p>
                </section>

                <section data-cdx-inspector-panel="content" data-cdx-inspector-when="image" hidden>
                    <h2>
                        <?php esc_html_e('Media', 'condixia-ai-site-to-wordpress'); ?>
                    </h2>

                    <div class="cdx-stw-editor-media-card" data-cdx-media-card="image">
                        <div class="cdx-stw-editor-media-thumb">
                            <img data-cdx-media-thumbnail="image" alt="" hidden>
                            <span class="dashicons dashicons-format-image" data-cdx-media-placeholder="image" aria-hidden="true"></span>
                        </div>

                        <div class="cdx-stw-editor-media-content">
                            <strong><?php esc_html_e('Element image', 'condixia-ai-site-to-wordpress'); ?></strong>
                            <small><?php esc_html_e('Choose from Media Library or upload a file.', 'condixia-ai-site-to-wordpress'); ?></small>
                            <button type="button" class="button cdx-stw-editor-media-button" data-cdx-media="image">
                                <?php esc_html_e('Select / Replace', 'condixia-ai-site-to-wordpress'); ?>
                            </button>
                        </div>
                    </div>

                    <label data-cdx-field="imageAlt">
                        <span>
                            <?php esc_html_e('Alt text', 'condixia-ai-site-to-wordpress'); ?>
                        </span>

                        <input
                            type="text"
                            data-cdx-property="imageAlt">
                    </label>

                </section>

                <section data-cdx-inspector-panel="style" hidden>
                    <h2>
                        <?php esc_html_e('Style', 'condixia-ai-site-to-wordpress'); ?>
                    </h2>

                    <div class="cdx-stw-style-scope">
                        <label>
                            <?php esc_html_e('Apply color to', 'condixia-ai-site-to-wordpress'); ?>
                            <select data-cdx-style-scope>
                                <option value="element"><?php esc_html_e('This element only', 'condixia-ai-site-to-wordpress'); ?></option>
                                <option value="class"><?php esc_html_e('All matching this class (current document)', 'condixia-ai-site-to-wordpress'); ?></option>
                                <option value="tag"><?php esc_html_e('Same element type (current section)', 'condixia-ai-site-to-wordpress'); ?></option>
                            </select>
                        </label>
                        <label data-cdx-style-class-label hidden>
                            <?php esc_html_e('Matching class', 'condixia-ai-site-to-wordpress'); ?>
                            <select data-cdx-style-class></select>
                        </label>
                        <p data-cdx-style-scope-hint role="status" aria-live="polite"></p>
                        <label class="cdx-stw-style-solid">
                            <input type="checkbox" data-cdx-solid-background>
                            <?php esc_html_e('Use a solid background (hide existing image / gradient)', 'condixia-ai-site-to-wordpress'); ?>
                        </label>
                    </div>

                    <div class="cdx-stw-editor-color-group">
                        <div class="cdx-stw-editor-color-field" data-cdx-field="color">
                            <span class="cdx-stw-editor-field-title">
                                <?php esc_html_e('Text color', 'condixia-ai-site-to-wordpress'); ?>
                            </span>
                            <div class="cdx-stw-editor-color-control">
                                <label class="cdx-stw-editor-color-swatch">
                                    <span class="screen-reader-text"><?php esc_html_e('Choose text color', 'condixia-ai-site-to-wordpress'); ?></span>
                                    <input type="color" data-cdx-property="color" aria-label="<?php esc_attr_e('Choose text color', 'condixia-ai-site-to-wordpress'); ?>">
                                </label>
                                <input type="text" class="cdx-stw-editor-color-hex" data-cdx-color-hex="color"
                                    maxlength="7" spellcheck="false" placeholder="#000000"
                                    aria-label="<?php esc_attr_e('Text color hex value', 'condixia-ai-site-to-wordpress'); ?>">
                            </div>
                        </div>

                        <div class="cdx-stw-editor-color-field" data-cdx-field="backgroundColor">
                            <span class="cdx-stw-editor-field-title">
                                <?php esc_html_e('Background color', 'condixia-ai-site-to-wordpress'); ?>
                            </span>
                            <div class="cdx-stw-editor-color-control">
                                <label class="cdx-stw-editor-color-swatch">
                                    <span class="screen-reader-text"><?php esc_html_e('Choose background color', 'condixia-ai-site-to-wordpress'); ?></span>
                                    <input type="color" data-cdx-property="backgroundColor" aria-label="<?php esc_attr_e('Choose background color', 'condixia-ai-site-to-wordpress'); ?>">
                                </label>
                                <input type="text" class="cdx-stw-editor-color-hex" data-cdx-color-hex="backgroundColor"
                                    maxlength="7" spellcheck="false" placeholder="#FFFFFF"
                                    aria-label="<?php esc_attr_e('Background color hex value', 'condixia-ai-site-to-wordpress'); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="cdx-stw-editor-media-card" data-cdx-media-card="background">
                        <div class="cdx-stw-editor-media-thumb">
                            <img data-cdx-media-thumbnail="background" alt="" hidden>
                            <span class="dashicons dashicons-format-gallery" data-cdx-media-placeholder="background" aria-hidden="true"></span>
                        </div>

                        <div class="cdx-stw-editor-media-content">
                            <strong><?php esc_html_e('Background image', 'condixia-ai-site-to-wordpress'); ?></strong>
                            <small><?php esc_html_e('Choose from Media Library or upload a file.', 'condixia-ai-site-to-wordpress'); ?></small>
                            <button type="button" class="button cdx-stw-editor-media-button" data-cdx-media="background">
                                <?php esc_html_e('Select / Replace', 'condixia-ai-site-to-wordpress'); ?>
                            </button>
                        </div>
                    </div>

                    <label data-cdx-field="fontSize">
                        <span>
                            <?php esc_html_e('Font size', 'condixia-ai-site-to-wordpress'); ?>
                        </span>

                        <input
                            type="text"
                            data-cdx-property="fontSize"
                            placeholder="16px">
                    </label>

                    <label data-cdx-field="fontWeight">
                        <span>
                            <?php esc_html_e('Font weight', 'condixia-ai-site-to-wordpress'); ?>
                        </span>

                        <select data-cdx-property="fontWeight">
                            <option value="400">400</option>
                            <option value="500">500</option>
                            <option value="600">600</option>
                            <option value="700">700</option>
                            <option value="800">800</option>
                            <option value="900">900</option>
                        </select>
                    </label>

                    <label data-cdx-field="textAlign">
                        <span>
                            <?php esc_html_e('Alignment', 'condixia-ai-site-to-wordpress'); ?>
                        </span>

                        <select data-cdx-property="textAlign">
                            <option value="left">
                                <?php esc_html_e('Left', 'condixia-ai-site-to-wordpress'); ?>
                            </option>

                            <option value="center">
                                <?php esc_html_e('Center', 'condixia-ai-site-to-wordpress'); ?>
                            </option>

                            <option value="right">
                                <?php esc_html_e('Right', 'condixia-ai-site-to-wordpress'); ?>
                            </option>

                            <option value="justify">
                                <?php esc_html_e('Justify', 'condixia-ai-site-to-wordpress'); ?>
                            </option>
                        </select>
                    </label>

                </section>

                <section data-cdx-inspector-panel="layout" hidden>
                    <h2><?php esc_html_e('Spacing', 'condixia-ai-site-to-wordpress'); ?></h2>

                    <label>
                        <span>
                            <?php esc_html_e('Padding', 'condixia-ai-site-to-wordpress'); ?>
                        </span>

                        <input
                            type="text"
                            data-cdx-property="padding"
                            placeholder="12px 16px">
                    </label>

                    <label>
                        <span>
                            <?php esc_html_e('Margin', 'condixia-ai-site-to-wordpress'); ?>
                        </span>

                        <input
                            type="text"
                            data-cdx-property="margin"
                            placeholder="0 0 16px">
                    </label>

                </section>

                <section data-cdx-inspector-panel="advanced" hidden>
                    <h2><?php esc_html_e('Borders', 'condixia-ai-site-to-wordpress'); ?></h2>

                    <label>
                        <span>
                            <?php esc_html_e('Border', 'condixia-ai-site-to-wordpress'); ?>
                        </span>

                        <input
                            type="text"
                            data-cdx-property="border"
                            placeholder="1px solid #000">
                    </label>

                    <label>
                        <span>
                            <?php esc_html_e('Border radius', 'condixia-ai-site-to-wordpress'); ?>
                        </span>

                        <input
                            type="text"
                            data-cdx-property="borderRadius"
                            placeholder="8px">
                    </label>

                    <div class="cdx-stw-manual-navigation" data-cdx-manual-navigation hidden>
                        <h2><?php esc_html_e('Navigation', 'condixia-ai-site-to-wordpress'); ?></h2>
                        <button type="button" class="button" data-cdx-nav-inspect>
                            <?php esc_html_e('Use as Navigation', 'condixia-ai-site-to-wordpress'); ?>
                        </button>
                        <div data-cdx-nav-details hidden>
                            <p class="cdx-stw-inspector-note" data-cdx-nav-summary aria-live="polite"></p>
                            <button type="button" class="button button-primary" data-cdx-nav-create>
                                <?php esc_html_e('Create WordPress Menu', 'condixia-ai-site-to-wordpress'); ?>
                            </button>
                            <label class="cdx-stw-nav-connect-label">
                                <span><?php esc_html_e('Connect an existing menu', 'condixia-ai-site-to-wordpress'); ?></span>
                                <select data-cdx-nav-options>
                                    <option value=""><?php esc_html_e('Select a menu', 'condixia-ai-site-to-wordpress'); ?></option>
                                </select>
                            </label>
                            <button type="button" class="button" data-cdx-nav-connect disabled>
                                <?php esc_html_e('Connect Menu', 'condixia-ai-site-to-wordpress'); ?>
                            </button>
                            <button type="button" class="button" data-cdx-nav-detach hidden>
                                <?php esc_html_e('Detach Navigation', 'condixia-ai-site-to-wordpress'); ?>
                            </button>
                            <a data-cdx-nav-manage href="" target="_blank" rel="noopener noreferrer" hidden>
                                <?php esc_html_e('Manage Menu in WordPress', 'condixia-ai-site-to-wordpress'); ?>
                            </a>
                        </div>
                        <p class="cdx-stw-nav-feedback" data-cdx-nav-feedback role="status" aria-live="polite"></p>
                    </div>

                    <div class="cdx-stw-manual-sitepart" data-cdx-manual-sitepart hidden>
                        <h2><?php esc_html_e('Site Part', 'condixia-ai-site-to-wordpress'); ?></h2>

                        <button type="button" class="button" data-cdx-part-inspect>
                            <?php esc_html_e('Convert to Site Part', 'condixia-ai-site-to-wordpress'); ?>
                        </button>

                        <div data-cdx-part-details hidden>
                            <label>
                                <?php esc_html_e('Name', 'condixia-ai-site-to-wordpress'); ?>
                                <input
                                    type="text"
                                    maxlength="100"
                                    data-cdx-part-title
                                    placeholder="Main Header">
                            </label>

                            <label>
                                <?php esc_html_e('Role', 'condixia-ai-site-to-wordpress'); ?>
                                <select data-cdx-part-role>
                                    <option value="header">
                                        <?php esc_html_e('Header', 'condixia-ai-site-to-wordpress'); ?>
                                    </option>
                                    <option value="footer">
                                        <?php esc_html_e('Footer', 'condixia-ai-site-to-wordpress'); ?>
                                    </option>
                                </select>
                            </label>

                            <label>
                                <?php esc_html_e('Display condition', 'condixia-ai-site-to-wordpress'); ?>
                                <select data-cdx-part-scope>
                                    <option value="entire_site">
                                        <?php esc_html_e('Entire Site', 'condixia-ai-site-to-wordpress'); ?>
                                    </option>
                                    <option value="front_page">
                                        <?php esc_html_e('Front Page', 'condixia-ai-site-to-wordpress'); ?>
                                    </option>
                                    <option value="all_pages">
                                        <?php esc_html_e('All Pages', 'condixia-ai-site-to-wordpress'); ?>
                                    </option>
                                    <option value="object">
                                        <?php esc_html_e('This WordPress Page', 'condixia-ai-site-to-wordpress'); ?>
                                    </option>
                                </select>
                            </label>

                            <button
                                type="button"
                                class="button button-primary"
                                data-cdx-part-assign>
                                <?php esc_html_e('Save Site Part Assignment', 'condixia-ai-site-to-wordpress'); ?>
                            </button>

                            <label data-cdx-part-map-label>
                                <?php esc_html_e('Or map this region to an existing Site Part', 'condixia-ai-site-to-wordpress'); ?>
                                <select data-cdx-part-options>
                                    <option value="">—</option>
                                </select>
                            </label>

                            <button
                                type="button"
                                class="button"
                                data-cdx-part-map>
                                <?php esc_html_e('Use Existing Site Part', 'condixia-ai-site-to-wordpress'); ?>
                            </button>

                            <button
                                type="button"
                                class="button"
                                data-cdx-part-detach
                                hidden>
                                <?php esc_html_e('Detach Site Part', 'condixia-ai-site-to-wordpress'); ?>
                            </button>

                            <p
                                class="cdx-stw-inspector-note"
                                data-cdx-part-notice></p>
                        </div>

                        <p
                            class="cdx-stw-nav-feedback"
                            data-cdx-part-feedback
                            role="status"
                            aria-live="polite"></p>
                    </div>

                    <div class="cdx-stw-structure" data-cdx-structure hidden>
                        <h2><?php esc_html_e('Structure & Visibility', 'condixia-ai-site-to-wordpress'); ?></h2>
                        <p class="description" data-cdx-structure-hint></p>

                        <div class="cdx-stw-structure-moves" data-cdx-structure-moves hidden>
                            <p class="description">
                                <?php esc_html_e('Drag the grip handle in Navigator. Keyboard: focus the grip, hold Alt and press Up/Down.', 'condixia-ai-site-to-wordpress'); ?>
                            </p>
                            <details class="cdx-stw-structure-fallback">
                                <summary><?php esc_html_e('Alternative move buttons', 'condixia-ai-site-to-wordpress'); ?></summary>
                                <div>
                                    <button type="button" class="button" data-cdx-structure-up>
                                        <?php esc_html_e('Move Up (keyboard alternative)', 'condixia-ai-site-to-wordpress'); ?>
                                    </button>
                                    <button type="button" class="button" data-cdx-structure-down>
                                        <?php esc_html_e('Move Down (keyboard alternative)', 'condixia-ai-site-to-wordpress'); ?>
                                    </button>
                                </div>
                            </details>
                            <button type="button" class="button" data-cdx-structure-restore-position>
                                <?php esc_html_e('Restore Original Position', 'condixia-ai-site-to-wordpress'); ?>
                            </button>
                        </div>

                        <fieldset class="cdx-stw-structure-visibility">
                            <legend><?php esc_html_e('Visible on', 'condixia-ai-site-to-wordpress'); ?></legend>
                            <label><input type="checkbox" data-cdx-visible-on="desktop" checked>
                                <span class="cdx-stw-visibility-track" aria-hidden="true"></span>
                                <?php esc_html_e('Desktop', 'condixia-ai-site-to-wordpress'); ?></label>
                            <label><input type="checkbox" data-cdx-visible-on="tablet" checked>
                                <span class="cdx-stw-visibility-track" aria-hidden="true"></span>
                                <?php esc_html_e('Tablet', 'condixia-ai-site-to-wordpress'); ?></label>
                            <label><input type="checkbox" data-cdx-visible-on="mobile" checked>
                                <span class="cdx-stw-visibility-track" aria-hidden="true"></span>
                                <?php esc_html_e('Mobile', 'condixia-ai-site-to-wordpress'); ?></label>
                        </fieldset>
                        <div class="cdx-stw-structure-moves">
                            <button type="button" class="button" data-cdx-structure-hide>
                                <?php esc_html_e('Hide Everywhere', 'condixia-ai-site-to-wordpress'); ?>
                            </button>
                            <button type="button" class="button" data-cdx-structure-show>
                                <?php esc_html_e('Show Everywhere', 'condixia-ai-site-to-wordpress'); ?>
                            </button>
                            <button type="button" class="button" data-cdx-structure-restore-visibility>
                                <?php esc_html_e('Restore Visibility', 'condixia-ai-site-to-wordpress'); ?>
                            </button>
                        </div>
                        <p class="description"><?php esc_html_e('To reorder, you can also drag compatible siblings within the same parent in the Navigator. Source HTML is not altered.', 'condixia-ai-site-to-wordpress'); ?></p>
                    </div>

                    <h2><?php esc_html_e('Element information', 'condixia-ai-site-to-wordpress'); ?></h2>
                    <dl class="cdx-stw-inspector-meta">
                        <dt><?php esc_html_e('Tag', 'condixia-ai-site-to-wordpress'); ?></dt>
                        <dd><code data-cdx-inspector-tag>—</code></dd>
                        <dt><?php esc_html_e('Editor reference', 'condixia-ai-site-to-wordpress'); ?></dt>
                        <dd><code data-cdx-inspector-reference>—</code></dd>
                    </dl>
                </section>

                <section data-cdx-inspector-panel="dynamic" hidden>
                    <h2><?php esc_html_e('Dynamic bindings', 'condixia-ai-site-to-wordpress'); ?></h2>
                    <div class="cdx-stw-manual-grid" data-cdx-grid-root hidden>
                        <button type="button" class="button" data-cdx-grid-inspect>
                            <?php esc_html_e('Convert to Dynamic Grid', 'condixia-ai-site-to-wordpress'); ?>
                        </button>
                        <div data-cdx-grid-details hidden>
                            <p class="cdx-stw-inspector-note" data-cdx-grid-summary></p>
                            <label>
                                <?php esc_html_e('Data source', 'condixia-ai-site-to-wordpress'); ?>
                                <select data-cdx-grid-posttype></select>
                            </label>
                            <label>
                                <?php esc_html_e('Sample card', 'condixia-ai-site-to-wordpress'); ?>
                                <select data-cdx-grid-sample></select>
                            </label>
                            <h3><?php esc_html_e('Map card fields', 'condixia-ai-site-to-wordpress'); ?></h3>
                            <?php foreach (
                                [
                                    'title' => __('Title', 'condixia-ai-site-to-wordpress'),
                                    'image' => __('Image', 'condixia-ai-site-to-wordpress'),
                                    'link' => __('Link', 'condixia-ai-site-to-wordpress'),
                                    'excerpt' => __('Excerpt', 'condixia-ai-site-to-wordpress'),
                                    'date' => __('Date', 'condixia-ai-site-to-wordpress'),
                                    'price' => __('WooCommerce Price', 'condixia-ai-site-to-wordpress'),
                                ] as $fieldKey => $fieldLabel
                            ) : ?>
                                <label>
                                    <?php echo esc_html($fieldLabel); ?>
                                    <select data-cdx-grid-map="<?php echo esc_attr($fieldKey); ?>"></select>
                                </label>
                            <?php endforeach; ?>

                            <div class="cdx-stw-grid-extra-mapping">
                                <h3>
                                    <?php
                                    esc_html_e(
                                        'Additional native and custom fields',
                                        'condixia-ai-site-to-wordpress'
                                    );
                                    ?>
                                </h3>

                                <p class="description">
                                    <?php
                                    esc_html_e(
                                        'Connect an imported card element to a public field from the selected post type. Existing card markup is preserved.',
                                        'condixia-ai-site-to-wordpress'
                                    );
                                    ?>
                                </p>

                                <div data-cdx-grid-extra-rows></div>

                                <button
                                    type="button"
                                    class="button"
                                    data-cdx-grid-add-field>
                                    <?php
                                    esc_html_e(
                                        'Add Field Mapping',
                                        'condixia-ai-site-to-wordpress'
                                    );
                                    ?>
                                </button>

                                <p
                                    class="description"
                                    data-cdx-grid-field-hint
                                    role="status"
                                    aria-live="polite"></p>
                            </div>

                            <h3><?php esc_html_e('Query', 'condixia-ai-site-to-wordpress'); ?></h3>
                            <label>
                                <?php esc_html_e('Items per page', 'condixia-ai-site-to-wordpress'); ?>
                                <input type="number" min="1" max="100" value="6" data-cdx-grid-count>
                            </label>
                            <label>
                                <?php esc_html_e('Order by', 'condixia-ai-site-to-wordpress'); ?>
                                <select data-cdx-grid-orderby>
                                    <option value="date">Date</option>
                                    <option value="modified">Modified</option>
                                    <option value="title">Title</option>
                                    <option value="menu_order">Menu order</option>
                                    <option value="id">ID</option>
                                    <option value="rand">Random</option>
                                    <option value="comment_count">Comments</option>
                                </select>
                            </label>
                            <label>
                                <?php esc_html_e('Direction', 'condixia-ai-site-to-wordpress'); ?>
                                <select data-cdx-grid-order>
                                    <option value="DESC">Descending</option>
                                    <option value="ASC">Ascending</option>
                                </select>
                            </label>
                            <label>
                                <?php esc_html_e('Filter taxonomy', 'condixia-ai-site-to-wordpress'); ?>
                                <select data-cdx-grid-taxonomy>
                                    <option value=""><?php esc_html_e('All terms', 'condixia-ai-site-to-wordpress'); ?></option>
                                </select>
                            </label>
                            <label>
                                <?php esc_html_e('Term slugs (comma-separated)', 'condixia-ai-site-to-wordpress'); ?>
                                <input type="text" maxlength="500" data-cdx-grid-terms placeholder="news, featured">
                            </label>
                            <label class="cdx-stw-grid-check">
                                <input type="checkbox" data-cdx-grid-paginate>
                                <span><?php esc_html_e('Use current page number for query', 'condixia-ai-site-to-wordpress'); ?></span>
                            </label>
                            <label class="cdx-stw-grid-check">
                                <input type="checkbox" data-cdx-grid-main-query>
                                <span><?php esc_html_e('Follow main archive/search query when compatible', 'condixia-ai-site-to-wordpress'); ?></span>
                            </label>
                            <p class="cdx-stw-inspector-note">
                                <?php esc_html_e('Page-aware queries require pagination links from the imported page. This option does not generate navigation links.', 'condixia-ai-site-to-wordpress'); ?>
                            </p>
                            <button type="button" class="button button-primary" data-cdx-grid-save>
                                <?php esc_html_e('Save Dynamic Grid', 'condixia-ai-site-to-wordpress'); ?>
                            </button>
                            <button type="button" class="button" data-cdx-grid-revert hidden>
                                <?php esc_html_e('Revert to Static Grid', 'condixia-ai-site-to-wordpress'); ?>
                            </button>
                        </div>
                        <p class="cdx-stw-nav-feedback" data-cdx-grid-feedback role="status" aria-live="polite"></p>
                    </div>
                </section>

                <p class="description">
                    <?php
                    esc_html_e(
                        'Changes are element-specific. Save creates a revision; Restore Original removes saved overrides without modifying the imported source.',
                        'condixia-ai-site-to-wordpress'
                    );
                    ?>
                </p>
            </div>
        </aside>

        <main
            class="cdx-stw-editor-stage"
            data-cdx-editor-stage
            data-viewport="desktop">

            <div class="cdx-stw-editor-stage-heading">
                <strong><?php esc_html_e('Live Preview', 'condixia-ai-site-to-wordpress'); ?></strong>
                <span><?php esc_html_e('Imported document', 'condixia-ai-site-to-wordpress'); ?></span>
            </div>

            <div class="cdx-stw-editor-canvas" data-cdx-editor-canvas>
                <div class="cdx-stw-editor-frame-wrap">
                    <iframe
                        data-cdx-editor-frame
                        src="<?php echo esc_url($previewUrl); ?>"
                        title="<?php esc_attr_e('Condixia page preview', 'condixia-ai-site-to-wordpress'); ?>"
                        sandbox="allow-scripts"></iframe>
                </div>
            </div>
        </main>
    </div>
</div>