<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Editor;

use Condixia\AISiteToWordPress\Security\Capabilities;
use Condixia\AISiteToWordPress\Security\NonceActions;
use Condixia\AISiteToWordPress\Security\RequestGuard;
use Condixia\AISiteToWordPress\Storage\EditorStateRepository;

defined('ABSPATH') || exit;

final class EditorSaveController
{
    public const SAVE_ACTION
    = 'condixia_ai_stw_editor_save';

    public const RESTORE_ELEMENT_ACTION
    = 'condixia_ai_stw_editor_restore_element';

    public const RESTORE_ORIGINAL_ACTION
    = 'condixia_ai_stw_editor_restore_original';

    public const REVISIONS_ACTION
    = 'condixia_ai_stw_editor_revisions';

    public const RESTORE_REVISION_ACTION
    = 'condixia_ai_stw_editor_restore_revision';

    private const ELEMENT_PATTERN
    = '/^cdx-stw-[a-f0-9]{24}(?:--[1-9][0-9]*)?$/';

    private EditorStateRepository $state;
    private RequestGuard $guard;

    public function __construct(
        ?EditorStateRepository $state = null,
        ?RequestGuard $guard = null
    ) {
        $this->state = $state
            ?? new EditorStateRepository();

        $this->guard = $guard
            ?? new RequestGuard();
    }

    public function register(): void
    {
        add_action(
            'wp_ajax_' . self::SAVE_ACTION,
            [$this, 'save']
        );

        add_action(
            'wp_ajax_'
                . self::RESTORE_ELEMENT_ACTION,
            [$this, 'restoreElement']
        );

        add_action(
            'wp_ajax_'
                . self::RESTORE_ORIGINAL_ACTION,
            [$this, 'restoreOriginal']
        );

        add_action(
            'wp_ajax_'
                . self::REVISIONS_ACTION,
            [$this, 'revisions']
        );

        add_action(
            'wp_ajax_'
                . self::RESTORE_REVISION_ACTION,
            [$this, 'restoreRevision']
        );
    }

    public function save(): void
    {
        if (! $this->authorize()) {
            return;
        }

        [
            $siteId,
            $artifactId,
        ] = $this->context();

        if (
            ! $this->validContext(
                $siteId,
                $artifactId
            )
        ) {
            $this->fail(
                $this->error()
            );

            return;
        }

        $changes = $this->changes();

        if (is_wp_error($changes)) {
            $this->fail($changes);

            return;
        }

        $saved = $this->state
            ->applyChanges(
                $siteId,
                $artifactId,
                $changes,
                get_current_user_id()
            );

        if (is_wp_error($saved)) {
            $this->fail($saved);

            return;
        }

        $revision = $this->state
            ->createRevision(
                $siteId,
                $artifactId,
                'save',
                get_current_user_id()
            );

        if (is_wp_error($revision)) {
            $this->fail($revision);

            return;
        }

        wp_send_json_success(
            [
                'revision_id' => $revision,
                'revisions' => $this->state
                    ->revisions(
                        $siteId,
                        $artifactId
                    ),
            ]
        );
    }

    public function restoreElement(): void
    {
        if (! $this->authorize()) {
            return;
        }

        [
            $siteId,
            $artifactId,
        ] = $this->context();

        $elementId = $this->elementId();

        if (
            ! $this->validContext(
                $siteId,
                $artifactId
            )
            || $elementId === ''
        ) {
            $this->fail(
                $this->error()
            );

            return;
        }

        $result = $this->state
            ->clearElement(
                $siteId,
                $artifactId,
                $elementId
            );

        if (is_wp_error($result)) {
            $this->fail($result);

            return;
        }

        $this->revisionResponse(
            $siteId,
            $artifactId,
            'restore_element'
        );
    }

    public function restoreOriginal(): void
    {
        if (! $this->authorize()) {
            return;
        }

        [
            $siteId,
            $artifactId,
        ] = $this->context();

        if (
            ! $this->validContext(
                $siteId,
                $artifactId
            )
        ) {
            $this->fail(
                $this->error()
            );

            return;
        }

        $result = $this->state
            ->clearArtifact(
                $siteId,
                $artifactId
            );

        if (is_wp_error($result)) {
            $this->fail($result);

            return;
        }

        $this->revisionResponse(
            $siteId,
            $artifactId,
            'restore_original'
        );
    }

    public function revisions(): void
    {
        if (! $this->authorize()) {
            return;
        }

        [
            $siteId,
            $artifactId,
        ] = $this->context();

        if (
            ! $this->validContext(
                $siteId,
                $artifactId
            )
        ) {
            $this->fail(
                $this->error()
            );

            return;
        }

        wp_send_json_success(
            [
                'revisions' => $this->state
                    ->revisions(
                        $siteId,
                        $artifactId
                    ),
            ]
        );
    }

    public function restoreRevision(): void
    {
        if (! $this->authorize()) {
            return;
        }

        [
            $siteId,
            $artifactId,
        ] = $this->context();

        $revisionId = absint(
            $_POST['revision_id']
                ?? 0
        );

        if (
            ! $this->validContext(
                $siteId,
                $artifactId
            )
            || $revisionId < 1
        ) {
            $this->fail(
                $this->error()
            );

            return;
        }

        $snapshot = $this->state
            ->revisionOverrides(
                $siteId,
                $artifactId,
                $revisionId
            );

        if (is_wp_error($snapshot)) {
            $this->fail($snapshot);

            return;
        }

        $restored = $this->state
            ->replaceOverrides(
                $siteId,
                $artifactId,
                $snapshot,
                get_current_user_id()
            );

        if (is_wp_error($restored)) {
            $this->fail($restored);

            return;
        }

        $this->revisionResponse(
            $siteId,
            $artifactId,
            'restore_revision'
        );
    }

    private function authorize(): bool
    {
        $error = $this->guard
            ->guardAjaxAction(
                $_POST,
                Capabilities::EDIT,
                NonceActions::EDITOR_SAVE
            );

        if (is_wp_error($error)) {
            $this->fail($error);

            return false;
        }

        return true;
    }

    private function context(): array
    {
        return [
            absint(
                $_POST['site_id']
                    ?? 0
            ),
            absint(
                $_POST['artifact_id']
                    ?? 0
            ),
        ];
    }

    private function validContext(
        int $siteId,
        int $artifactId
    ): bool {
        return $siteId > 0
            && $artifactId > 0
            && $this->state
            ->artifactExists(
                $siteId,
                $artifactId
            );
    }

    private function elementId(): string
    {
        $elementId = isset(
            $_POST['element_id']
        ) && is_scalar(
            $_POST['element_id']
        )
            ? sanitize_text_field(
                wp_unslash(
                    (string)
                    $_POST['element_id']
                )
            )
            : '';

        return preg_match(
            self::ELEMENT_PATTERN,
            $elementId
        ) === 1
            ? $elementId
            : '';
    }

    private function changes(): array|\WP_Error
    {
        if (
            ! isset($_POST['changes'])
            || ! is_scalar(
                $_POST['changes']
            )
        ) {
            return $this->error();
        }

        $decoded = json_decode(
            wp_unslash(
                (string)
                $_POST['changes']
            ),
            true
        );

        if (
            ! is_array($decoded)
            || count($decoded) > 500
        ) {
            return $this->error();
        }

        $changes = [];

        foreach ($decoded as $change) {
            if (! is_array($change)) {
                return $this->error();
            }

            $sanitized = $this
                ->sanitizeChange(
                    $change
                );

            if (is_wp_error($sanitized)) {
                return $sanitized;
            }

            $changes[] = $sanitized;
        }

        return $changes;
    }

    private function sanitizeChange(
        array $change
    ): array|\WP_Error {
        $elementId = sanitize_text_field(
            (string) (
                $change['element_id']
                ?? ''
            )
        );

        $property = sanitize_key(
            (string) (
                $change['property_key']
                ?? ''
            )
        );

        $value = is_scalar(
            $change['value']
                ?? null
        )
            ? (string) $change['value']
            : '';

        $attachmentId = absint(
            $change['attachment_id']
                ?? 0
        );

        if (
            preg_match(
                self::ELEMENT_PATTERN,
                $elementId
            ) !== 1
        ) {
            return $this->error();
        }

        $valueType = match ($property) {
            'text',
            'richtext',
            'imagealt'
            => 'text',

            'childorder',
            'visibility'
            => 'structure',

            'linkurl',
            'linktarget'
            => 'attribute',

            'imagesrc',
            'backgroundimage'
            => 'media',

            'color',
            'backgroundcolor',
            'fontsize',
            'fontweight',
            'textalign',
            'padding',
            'margin',
            'border',
            'borderradius'
            => 'style',

            default => '',
        };

        if ($valueType === '') {
            return $this->error();
        }

        $value = match ($property) {
            'text'
            => sanitize_textarea_field(
                $value
            ),

            'richtext'
            => $this->sanitizeRichText($value),

            'childorder'
            => $this->sanitizeChildOrder($value),

            'visibility'
            => preg_match('/^[01]{3}$/', $value) === 1 ? $value : '',

            'imagealt'
            => sanitize_text_field(
                $value
            ),

            'linkurl'
            => $this->link($value),

            'linktarget'
            => in_array(
                $value,
                [
                    '_self',
                    '_blank',
                ],
                true
            )
                ? $value
                : '_self',

            'imagesrc',
            'backgroundimage'
            => $this->imageUrl($value),

            'color'
            => preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
                ? strtolower($value) : '',

            'backgroundcolor'
            => preg_match('/^(?:solid:)?#[0-9a-fA-F]{6}$/', $value) === 1
                ? strtolower($value) : '',

            'fontweight'
            => preg_match(
                '/^(?:[1-9]00|normal|bold)$/i',
                $value
            ) === 1
                ? strtolower($value)
                : '',

            'textalign'
            => in_array(
                $value,
                [
                    'left',
                    'center',
                    'right',
                    'justify',
                ],
                true
            )
                ? $value
                : '',

            'fontsize',
            'padding',
            'margin',
            'border',
            'borderradius'
            => $this->css($value),

            default => '',
        };

        if (in_array($property, ['richtext', 'childorder', 'visibility'], true) && $value === '') {
            return $this->error();
        }

        if (
            in_array(
                $property,
                [
                    'linkurl',
                    'imagesrc',
                    'backgroundimage',
                ],
                true
            )
            && $value === ''
        ) {
            return $this->error();
        }

        return [
            'element_id' => $elementId,
            'property_key' => $property,
            'value_type' => $valueType,
            'value' => $value,
            'attachment_id'
            => $attachmentId,
        ];
    }

    /** Accept only bounded, ordered text-node edits; never HTML from the editor. */

    /** A parent order override names exactly the same source siblings in two permutations. */
    private function sanitizeChildOrder(string $value): string
    {
        if ($value === '' || strlen($value) > 12000) return '';
        $decoded = json_decode($value, true, 8);
        if (! is_array($decoded) || ! isset($decoded['original'], $decoded['order'])) return '';
        $original = $decoded['original'];
        $order = $decoded['order'];
        if (! is_array($original) || ! array_is_list($original)
            || count($original) < 2 || count($original) > 80
            || ! is_array($order) || ! array_is_list($order)
            || count($order) !== count($original)) return '';
        foreach ([$original, $order] as $ids) {
            foreach ($ids as $id) {
                if (! is_string($id) || preg_match(self::ELEMENT_PATTERN, $id) !== 1) return '';
            }
            if (count(array_unique($ids)) !== count($ids)) return '';
        }
        if (array_diff($original, $order) || array_diff($order, $original)) return '';
        $encoded = wp_json_encode([
            'original' => $original, 'order' => $order,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) && strlen($encoded) <= 12000 ? $encoded : '';
    }

    private function sanitizeRichText(string $value): string
    {
        if ($value === '' || strlen($value) > 60000) {
            return '';
        }

        $segments = json_decode($value, true, 16);
        if (! is_array($segments) || ! array_is_list($segments)
            || $segments === [] || count($segments) > 100) {
            return '';
        }

        $result = [];
        $seen = [];
        $total = 0;
        foreach ($segments as $part) {
            if (! is_array($part) || ! isset($part['p'], $part['t'])
                || ! is_string($part['p']) || ! is_string($part['t'])
                || preg_match('/^(?:0|[1-9]\d{0,2})(?:\.(?:0|[1-9]\d{0,2})){0,11}$/', $part['p']) !== 1
                || isset($seen[$part['p']]) || $part['t'] === ''
                || strlen($part['t']) > 12000 || str_contains($part['t'], "\0")
                || wp_check_invalid_utf8($part['t']) !== $part['t']) {
                return '';
            }
            $seen[$part['p']] = true;
            $total += strlen($part['t']);
            if ($total > 45000) return '';
            $result[] = ['p' => $part['p'], 't' => $part['t']];
        }

        $encoded = wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) && strlen($encoded) <= 60000 ? $encoded : '';
    }

    private function link(
        string $value
    ): string {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (
            str_starts_with(
                $value,
                '#'
            )
            || str_starts_with(
                $value,
                '/'
            )
            || str_starts_with(
                $value,
                './'
            )
            || str_starts_with(
                $value,
                '../'
            )
        ) {
            return preg_match(
                '/[\x00-\x20\x7F<>]/',
                $value
            ) === 1
                ? ''
                : $value;
        }

        return esc_url_raw(
            $value,
            [
                'http',
                'https',
                'mailto',
                'tel',
            ]
        );
    }

    private function imageUrl(
        string $value
    ): string {
        return esc_url_raw(
            trim($value),
            [
                'http',
                'https',
            ]
        );
    }

    private function css(
        string $value
    ): string {
        $value = trim($value);

        if (
            strlen($value) > 120
            || preg_match(
                '/[;{}<>]/',
                $value
            ) === 1
        ) {
            return '';
        }

        return sanitize_text_field(
            $value
        );
    }

    private function revisionResponse(
        int $siteId,
        int $artifactId,
        string $revisionType
    ): void {
        $revision = $this->state
            ->createRevision(
                $siteId,
                $artifactId,
                $revisionType,
                get_current_user_id()
            );

        if (is_wp_error($revision)) {
            $this->fail($revision);

            return;
        }

        wp_send_json_success(
            [
                'revision_id' => $revision,
                'revisions' => $this->state
                    ->revisions(
                        $siteId,
                        $artifactId
                    ),
            ]
        );
    }

    private function fail(
        \WP_Error $error
    ): void {
        $data = $error->get_error_data();

        $status = is_array($data)
            ? (int) (
                $data['status']
                ?? 400
            )
            : 400;

        wp_send_json_error(
            [
                'code'
                => $error
                    ->get_error_code(),
                'message'
                => $error
                    ->get_error_message(),
            ],
            $status > 0
                ? $status
                : 400
        );
    }

    private function error(): \WP_Error
    {
        return new \WP_Error(
            'condixia_ai_stw_editor_request',
            __(
                'The Condixia editor request is invalid.',
                'condixia-ai-site-to-wordpress'
            ),
            [
                'status' => 400,
            ]
        );
    }
}
