<?php

declare(strict_types=1);

use Condixia\AISiteToWordPress\Rendering\StandaloneTemplateContext;

defined('ABSPATH') || exit;

$html = StandaloneTemplateContext::take();

if ($html === null) {
    return;
}

// Keep the sandboxed visual editor free from extra WordPress toolbars
// and third-party frontend scripts.
$isEditorPreview = isset($_GET['condixia_stw_editor_preview'])
    && is_scalar($_GET['condixia_stw_editor_preview'])
    && (string) $_GET['condixia_stw_editor_preview'] === '1';

if ($isEditorPreview) {
    echo $html;
    return;
}

// Keep the imported design while restoring WordPress body classes.
$bodyClasses = array_values(array_unique(array_filter(array_map(
    'sanitize_html_class',
    get_body_class('cdx-stw-standalone')
))));

$decorateBody = static function (string $tag) use ($bodyClasses): string {
    if ($bodyClasses === []) {
        return $tag;
    }

    $found = 0;

    $result = preg_replace_callback(
        '~(?<![\w:-])class\s*=\s*(["\'])(.*?)\1~is',
        static function (array $match) use ($bodyClasses): string {
            $existing = preg_split(
                '/\s+/',
                trim(html_entity_decode(
                    $match[2],
                    ENT_QUOTES,
                    'UTF-8'
                ))
            );

            $classes = array_unique(array_merge(
                is_array($existing) ? $existing : [],
                $bodyClasses
            ));

            return 'class="' . esc_attr(
                implode(' ', array_filter($classes))
            ) . '"';
        },
        $tag,
        1,
        $found
    );

    if (!is_string($result)) {
        return $tag;
    }

    if ($found > 0) {
        return $result;
    }

    return substr_replace(
        $tag,
        ' class="' . esc_attr(
            implode(' ', $bodyClasses)
        ) . '"',
        -1,
        0
    );
};

// Support imported HTML fragments as well as complete documents.
if (!preg_match('~<body\b[^>]*>~i', $html)) {
    $html = '<!doctype html><html><head></head><body>'
        . $html
        . '</body></html>';
} elseif (!preg_match('~<head\b[^>]*>~i', $html)) {
    $html = preg_replace(
        '~<body\b~i',
        '<head></head><body',
        $html,
        1
    ) ?? $html;
}

if (!preg_match('~</head\s*>~i', $html)) {
    $html = preg_replace(
        '~<body\b~i',
        '</head><body',
        $html,
        1
    ) ?? $html;
}

if (!preg_match('~</body\s*>~i', $html)) {
    $html .= '</body>';
}

if (
    !preg_match('~<head\b[^>]*>~i', $html, $head, PREG_OFFSET_CAPTURE)
    || !preg_match('~<body\b[^>]*>~i', $html, $body, PREG_OFFSET_CAPTURE)
    || !preg_match('~</body\s*>~i', $html, $bodyClose, PREG_OFFSET_CAPTURE)
) {
    echo $html;
    return;
}

$headEnd = $head[0][1] + strlen($head[0][0]);
$bodyStart = $body[0][1];
$bodyEnd = $bodyStart + strlen($body[0][0]);
$closeStart = $bodyClose[0][1];

if (!($headEnd <= $bodyStart && $bodyEnd <= $closeStart)) {
    echo $html;
    return;
}

// Preserve the charset position and normally let the imported page's
// own CSS follow WordPress-enqueued styles.
$headInsert = $headEnd;

if (preg_match(
    '~<meta\b[^>]*\bcharset\s*=\s*["\']?[^\s"\'>]+["\']?[^>]*>~i',
    $html,
    $charset,
    PREG_OFFSET_CAPTURE
)) {
    $charsetEnd = $charset[0][1] + strlen($charset[0][0]);

    if ($charset[0][1] >= $headEnd && $charsetEnd < $bodyStart) {
        $headInsert = $charsetEnd;
    }
}

// Fire the template lifecycle actions without loading theme wrappers.
do_action('get_header', null, []);

echo substr($html, 0, $headInsert);

// SEO metadata, enqueued styles, head scripts and other integrations.
wp_head();

echo substr(
    $html,
    $headInsert,
    $bodyStart - $headInsert
);

echo $decorateBody($body[0][0]);

// Analytics, tag managers and plugins using the opening body hook.
wp_body_open();

echo substr(
    $html,
    $bodyEnd,
    $closeStart - $bodyEnd
);

do_action('get_footer', null, []);

// Footer scripts, WordPress admin bar and plugin integrations.
wp_footer();

echo substr($html, $closeStart);
