<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Templates;

use Condixia\AISiteToWordPress\Storage\AssignmentRepository;

defined('ABSPATH') || exit;

final class DisplayConditionResolver
{
    private AssignmentRepository $assignments;
    private DefaultAssignmentSeeder $seeder;
    private DisplayConditionMatcher $matcher;

    public function __construct(
        ?AssignmentRepository $assignments = null,
        ?DefaultAssignmentSeeder $seeder = null,
        ?DisplayConditionMatcher $matcher = null
    ) {
        $this->assignments = $assignments
            ?? new AssignmentRepository();

        $this->seeder = $seeder
            ?? new DefaultAssignmentSeeder(
                $this->assignments
            );

        $this->matcher = $matcher
            ?? new DisplayConditionMatcher();
    }

    public function resolveTemplate(
        array $context,
        int $siteId = 0
    ): array|\WP_Error|null {
        $ready = $siteId > 0
            ? $this->seeder->ensureSite(
                $siteId
            )
            : $this->seeder
            ->ensureCompletedSites();

        if (is_wp_error($ready)) {
            return $ready;
        }

        return $this->best(
            $this->assignments
                ->candidates(
                    'template',
                    '',
                    $siteId
                ),
            $context
        );
    }

    public function resolveSitePart(
        int $siteId,
        string $role,
        array $context
    ): array|\WP_Error|null {
        $ready = $this->seeder->ensureSite(
            $siteId
        );

        if (is_wp_error($ready)) {
            return $ready;
        }

        return $this->best(
            $this->assignments
                ->candidates(
                    'site_part',
                    $role,
                    $siteId
                ),
            $context
        );
    }

    private function best(
        array $candidates,
        array $context
    ): ?array {
        $byArtifact = [];

        foreach ($candidates as $candidate) {
            $specificity = $this
                ->matcher
                ->specificity(
                    (string) (
                        $candidate['context_type'] ?? ''
                    ),
                    (string) (
                        $candidate['context_key'] ?? ''
                    ),
                    $context
                );

            if ($specificity < 0) {
                continue;
            }

            $candidate['specificity']
                = $specificity;

            $artifactId = (int) $candidate['id'];

            if (
                ! isset(
                    $byArtifact[$artifactId]
                )
                || $this->better(
                    $candidate,
                    $byArtifact[$artifactId]
                )
            ) {
                $byArtifact[$artifactId] = $candidate;
            }
        }

        $matches = array_values(
            $byArtifact
        );

        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            function (
                array $left,
                array $right
            ): int {
                if (
                    $left['specificity']
                    !== $right['specificity']
                ) {
                    return $right['specificity']
                        <=>
                        $left['specificity'];
                }

                if (
                    $left['priority']
                    !== $right['priority']
                ) {
                    return $right['priority']
                        <=>
                        $left['priority'];
                }

                return $left['assignment_id'] <=> $right['assignment_id'];
            }
        );

        if (
            isset($matches[1])
            && $matches[0]['specificity']
            === $matches[1]['specificity']
            && $matches[0]['priority']
            === $matches[1]['priority']
        ) {
            return null;
        }

        $match = $matches[0];

        return [
            'artifact_id' => (int) $match['id'],
            'site_id' => (int) $match['site_id'],
            'manifest_id' => (int) $match['manifest_id'],
            'source_path' => (string) $match['source_path'],
            'kind' => (string) $match['kind'],
            'role' => (string) $match['role'],
            'resolution' => 'condition',
            'metadata' => $match['metadata'],
            'assignment' => [
                'id' => (int) $match['assignment_id'],
                'context_type' => (string) $match['context_type'],
                'context_key' => (string) $match['context_key'],
                'priority' => (int) $match['priority'],
                'specificity' => (int) $match['specificity'],
            ],
        ];
    }

    private function better(
        array $candidate,
        array $existing
    ): bool {
        if (
            $candidate['specificity']
            !== $existing['specificity']
        ) {
            return $candidate['specificity']
                > $existing['specificity'];
        }

        if (
            $candidate['priority']
            !== $existing['priority']
        ) {
            return $candidate['priority']
                > $existing['priority'];
        }

        return $candidate['assignment_id'] < $existing['assignment_id'];
    }
}
