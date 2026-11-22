<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Storage;

defined('ABSPATH') || exit;

final class TableNames
{
    private string $prefix;

    public function __construct(?string $wordpressPrefix = null)
    {
        global $wpdb;

        $this->prefix = ($wordpressPrefix ?? $wpdb->prefix)
            . 'condixia_ai_stw_';
    }

    public function sites(): string
    {
        return $this->prefix . 'sites';
    }

    public function manifests(): string
    {
        return $this->prefix . 'manifests';
    }

    public function artifacts(): string
    {
        return $this->prefix . 'artifacts';
    }

    public function assignments(): string
    {
        return $this->prefix . 'assignments';
    }

    public function bindings(): string
    {
        return $this->prefix . 'bindings';
    }

    public function overrides(): string
    {
        return $this->prefix . 'overrides';
    }

    public function revisions(): string
    {
        return $this->prefix . 'revisions';
    }

    public function files(): string
    {
        return $this->prefix . 'files';
    }

    public function all(): array
    {
        return [
            $this->sites(),
            $this->manifests(),
            $this->artifacts(),
            $this->assignments(),
            $this->bindings(),
            $this->overrides(),
            $this->revisions(),
            $this->files(),
        ];
    }
}
