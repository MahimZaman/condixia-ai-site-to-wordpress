<?php

declare(strict_types=1);

namespace Condixia\AISiteToWordPress\Analysis;

defined('ABSPATH') || exit;

final class SiteAnalyzer
{
    private const MAX_REFERENCE_SCAN_BYTES = 16777216;
    private const MAX_DOM_SCAN_BYTES = 16777216;

    private SourceScanner $scanner;
    private ReferenceExtractor $extractor;
    private ReferenceResolver $resolver;
    private ManifestCanonicalizer $canonicalizer;
    private DomAnalyzer $domAnalyzer;
    private PageDetector $pageDetector;
    private SitePartDetector $sitePartDetector;
    private TemplateTypeDetector $templateTypeDetector;
    private NavigationDetector $navigationDetector;
    private RepeatedGridDetector $repeatedGridDetector;
    private DynamicFieldMapper $dynamicFieldMapper;

    public function __construct(
        ?SourceScanner $scanner = null,
        ?ReferenceExtractor $extractor = null,
        ?ReferenceResolver $resolver = null,
        ?ManifestCanonicalizer $canonicalizer = null,
        ?DomAnalyzer $domAnalyzer = null,
        ?PageDetector $pageDetector = null,
        ?SitePartDetector $sitePartDetector = null,
        ?TemplateTypeDetector $templateTypeDetector = null,
        ?NavigationDetector $navigationDetector = null,
        ?RepeatedGridDetector $repeatedGridDetector = null,
        ?DynamicFieldMapper $dynamicFieldMapper = null
    ) {
        $this->scanner = $scanner
            ?? new SourceScanner();

        $this->extractor = $extractor
            ?? new ReferenceExtractor();

        $this->resolver = $resolver
            ?? new ReferenceResolver();

        $this->canonicalizer = $canonicalizer
            ?? new ManifestCanonicalizer();

        $this->domAnalyzer = $domAnalyzer
            ?? new DomAnalyzer();

        $this->pageDetector = $pageDetector
            ?? new PageDetector();

        $this->sitePartDetector = $sitePartDetector
            ?? new SitePartDetector();

        $this->templateTypeDetector = $templateTypeDetector
            ?? new TemplateTypeDetector();

        $this->navigationDetector = $navigationDetector
            ?? new NavigationDetector(
                null,
                $this->resolver
            );

        $this->repeatedGridDetector = $repeatedGridDetector
            ?? new RepeatedGridDetector();

        $this->dynamicFieldMapper = $dynamicFieldMapper
            ?? new DynamicFieldMapper();
    }

    public function analyze(array $prepared): array|\WP_Error
    {
        $files = $this->scanner->scan(
            $prepared
        );

        if (is_wp_error($files)) {
            return $files;
        }

        $fileIndex = [];

        foreach ($files as $file) {
            $fileIndex[$file['path']] = true;
        }

        $relationships = [];
        $externalReferences = [];
        $missingReferences = [];
        $warnings = [];
        $ignoredFiles = is_array($prepared['ignored_files'] ?? null)
            ? $prepared['ignored_files']
            : [];

        $ignoredPaths = [];

        foreach ($ignoredFiles as $ignoredFile) {
            if (! is_array($ignoredFile)) {
                continue;
            }

            $ignoredPath = (string) ($ignoredFile['path'] ?? '');

            if ($ignoredPath !== '') {
                $ignoredPaths[strtolower($ignoredPath)] = true;
            }
        }

        $domDocuments = [];
        $pageCandidates = [];
        $deferredHtmlDocuments = [];
        $templateCandidates = [];
        $navigationCandidates = [];

        foreach ($files as $file) {
            if (
                ! in_array(
                    $file['type'],
                    ['html', 'css', 'js'],
                    true
                )
            ) {
                continue;
            }

            if (
                $file['size']
                > self::MAX_REFERENCE_SCAN_BYTES
            ) {
                $warnings[] = [
                    'code' => 'reference_scan_skipped_large_file',
                    'source' => $file['path'],
                    'message' => __(
                        'Reference scanning was skipped because this text file exceeds the safe analysis size.',
                        'condixia-ai-site-to-wordpress'
                    ),
                ];

                continue;
            }

            $content = file_get_contents(
                $file['physical_path']
            );

            if (! is_string($content)) {
                return new \WP_Error(
                    'condixia_ai_stw_analysis_read_failed',
                    __(
                        'A source file could not be read during site analysis.',
                        'condixia-ai-site-to-wordpress'
                    )
                );
            }

            foreach (
                $this->extractor->extract(
                    $file['type'],
                    $content
                )
                as $reference
            ) {
                $resolved = $this->resolver->resolve(
                    $file['path'],
                    $reference['reference'],
                    $fileIndex
                );

                if ($resolved['kind'] === 'ignore') {
                    continue;
                }

                if ($resolved['kind'] === 'external') {
                    $externalReferences[] = [
                        'source' => $file['path'],
                        'context' => $reference['context'],
                        'reference' => $reference['reference'],
                    ];

                    continue;
                }

                if ($resolved['kind'] === 'unsafe') {
                    $warnings[] = [
                        'code' => 'unsafe_reference',
                        'source' => $file['path'],
                        'reference' => $reference['reference'],
                        'message' => __(
                            'A source reference could not be resolved safely.',
                            'condixia-ai-site-to-wordpress'
                        ),
                    ];

                    continue;
                }

                if (
                    $resolved['kind'] === 'local'
                    && $resolved['status'] === 'found'
                ) {
                    $relationships[] = [
                        'source' => $file['path'],
                        'target' => $resolved['target'],
                        'context' => $reference['context'],
                        'reference' => $reference['reference'],
                    ];

                    continue;
                }

                if (
                    $resolved['kind'] === 'local'
                    && $resolved['status'] === 'missing'
                ) {
                    $missingReferences[] = [
                        'source' => $file['path'],
                        'target' => $resolved['target'],
                        'context' => $reference['context'],
                        'reference' => $reference['reference'],
                    ];

                    if (
                        isset(
                            $ignoredPaths[strtolower((string) $resolved['target'])]
                        )
                    ) {
                        $warnings[] = [
                            'code' => 'excluded_file_reference',
                            'source' => $file['path'],
                            'target' => $resolved['target'],
                            'message' => sprintf(
                                __(
                                    'The file "%1$s" references "%2$s", which was excluded for safety or compatibility. Some functionality may be affected.',
                                    'condixia-ai-site-to-wordpress'
                                ),
                                $file['path'],
                                $resolved['target']
                            ),
                        ];
                    }
                }
            }

            if ($file['type'] !== 'html') {
                continue;
            }

            if (
                $file['size']
                > self::MAX_DOM_SCAN_BYTES
            ) {
                return new \WP_Error(
                    'condixia_ai_stw_dom_document_too_large',
                    sprintf(
                        __(
                            'The HTML document "%s" is too large for safe structural analysis.',
                            'condixia-ai-site-to-wordpress'
                        ),
                        $file['path']
                    )
                );
            }

            $dom = $this->domAnalyzer->analyze(
                $file['path'],
                $content
            );

            if (is_wp_error($dom)) {
                return $dom;
            }

            $domDocuments[$file['path']] = $dom;

            $detectedNavigation = $this
                ->navigationDetector
                ->detectDocument(
                    $file['path'],
                    $content,
                    $dom,
                    $fileIndex
                );

            if (is_wp_error($detectedNavigation)) {
                return $detectedNavigation;
            }

            $navigationCandidates = array_merge(
                $navigationCandidates,
                $detectedNavigation
            );

            $pageDetection = $this->pageDetector->detect(
                $file['path'],
                $content
            );

            if (is_wp_error($pageDetection)) {
                return $pageDetection;
            }

            if (
                ($pageDetection['classification'] ?? '')
                === 'page'
            ) {
                $pageCandidates[] = $pageDetection['candidate'];
            } elseif (
                ($pageDetection['classification'] ?? '')
                === 'deferred'
            ) {
                $deferredHtmlDocuments[] = $pageDetection['deferred'];
            }

            $templateCandidate = $this
                ->templateTypeDetector
                ->detect(
                    $file['path'],
                    $pageDetection,
                    $content
                );

            if ($templateCandidate !== null) {
                $templateCandidates[] = $templateCandidate;
            }

            if ($dom['parse_warnings'] !== []) {
                $warnings[] = [
                    'code' => 'html_parse_recovery',
                    'source' => $file['path'],
                    'count' => count(
                        $dom['parse_warnings']
                    ),
                    'message' => __(
                        'The HTML parser recovered from source markup issues while building the structural model. The original source was not modified.',
                        'condixia-ai-site-to-wordpress'
                    ),
                ];
            }
        }

        ksort(
            $domDocuments,
            SORT_STRING
        );

        $pageCandidates = $this->uniqueSorted(
            $pageCandidates
        );

        $deferredHtmlDocuments = $this->uniqueSorted(
            $deferredHtmlDocuments
        );

        $templateCandidates = $this->uniqueSorted(
            $templateCandidates
        );

        $sitePartDetection = $this
            ->sitePartDetector
            ->detect(
                $domDocuments
            );

        $navigationDetection = $this
            ->navigationDetector
            ->group(
                $navigationCandidates,
                count($domDocuments)
            );

        $repeatedGridDetection = $this
            ->repeatedGridDetector
            ->detect(
                $domDocuments
            );

        $mappedFieldCount = 0;

        foreach (
            $repeatedGridDetection['candidates']
            as &$gridCandidate
        ) {
            $source = (string) (
                $gridCandidate['source']
                ?? ''
            );

            $rootPath = (string) (
                $gridCandidate['sample_item_path']
                ?? ''
            );

            $fieldMap = isset(
                $domDocuments[$source]
            )
                ? $this->dynamicFieldMapper
                ->mapRegion(
                    $domDocuments[$source],
                    $rootPath
                )
                : [
                    'root_path' => '',
                    'suggestions' => [],
                    'suggestion_count' => 0,
                    'ambiguous_count' => 0,
                    'available_sources' => [],
                ];

            $gridCandidate['field_mapping']
                = $fieldMap;

            $mappedFieldCount += (int) (
                $fieldMap['suggestion_count']
                ?? 0
            );
        }

        unset($gridCandidate);

        $repeatedGridDetection['mapped_field_count'] = $mappedFieldCount;

        $relationships = $this->uniqueSorted(
            $relationships
        );

        $externalReferences = $this->uniqueSorted(
            $externalReferences
        );

        $missingReferences = $this->uniqueSorted(
            $missingReferences
        );

        $ignoredCount = max(
            0,
            (int) ($prepared['ignored_file_count'] ?? 0)
        );

        if ($ignoredCount > 0) {
            $warnings[] = [
                'code' => 'excluded_package_files',
                'count' => $ignoredCount,
                'message' => sprintf(
                    _n(
                        '%d unnecessary or unsupported package file was safely excluded.',
                        '%d unnecessary or unsupported package files were safely excluded.',
                        $ignoredCount,
                        'condixia-ai-site-to-wordpress'
                    ),
                    $ignoredCount
                ),
            ];
        }

        if ($missingReferences !== []) {
            $warnings[] = [
                'code' => 'missing_local_references',
                'count' => count($missingReferences),
                'message' => sprintf(
                    _n(
                        '%d local source reference could not be found.',
                        '%d local source references could not be found.',
                        count($missingReferences),
                        'condixia-ai-site-to-wordpress'
                    ),
                    count($missingReferences)
                ),
            ];
        }

        $warnings = $this->uniqueSorted(
            $warnings
        );

        $manifestFiles = [];

        $counts = [
            'html' => 0,
            'css' => 0,
            'js' => 0,
            'image' => 0,
            'font' => 0,
            'svg' => 0,
            'other' => 0,
        ];

        foreach ($files as $file) {
            $manifestFiles[] = [
                'path' => $file['path'],
                'type' => $file['type'],
                'mime_type' => $file['mime_type'],
                'size' => $file['size'],
                'checksum' => $file['checksum'],
            ];

            $counts[$file['type']]++;
        }

        $domElementCount = 0;
        $domWarningCount = 0;

        foreach ($domDocuments as $document) {
            $domElementCount += (int) $document['element_count'];

            $domWarningCount += count(
                $document['parse_warnings']
            );
        }

        $manifest = [
            'format_version' => 2,
            'source' => [
                'type' => (string) $prepared['source_type'],
                'name' => (string) $prepared['original_name'],
                'package_checksum' => (string) $prepared['package_checksum'],
                'ignored_file_paths' => is_array(
                    $prepared['ignored_file_paths'] ?? null
                )
                    ? $prepared['ignored_file_paths']
                    : [],
            ],
            'summary' => [
                'files' => count($manifestFiles),
                'imported_files' => count($manifestFiles),
                'ignored_files' => count($ignoredFiles),
                'types' => $counts,
                'local_relationships' => count($relationships),
                'external_references' => count($externalReferences),
                'missing_references' => count($missingReferences),
                'warnings' => count($warnings),
                'dom_documents' => count($domDocuments),
                'dom_elements' => $domElementCount,
                'dom_parse_warnings' => $domWarningCount,
                'page_candidates' => count(
                    $pageCandidates
                ),
                'deferred_html_documents' => count(
                    $deferredHtmlDocuments
                ),
                'site_part_candidates' => count(
                    $sitePartDetection['candidates']
                ),
                'header_variants' => (int) $sitePartDetection['header_variants'],
                'footer_variants' => (int) $sitePartDetection['footer_variants'],
                'ambiguous_site_parts' => (int) $sitePartDetection['ambiguous'],
                'template_candidates' => count(
                    $templateCandidates
                ),
                'ambiguous_templates' => count(
                    array_filter(
                        $templateCandidates,
                        static fn(array $candidate): bool =>
                        ! empty($candidate['ambiguous'])
                    )
                ),
                'navigation_candidates' => (int) $navigationDetection['count'],
                'auto_bind_navigation' => (int) $navigationDetection['auto_bind_count'],
                'ambiguous_navigation' => (int) $navigationDetection['ambiguous_count'],
                'dynamic_grid_candidates' => (int) $repeatedGridDetection['count'],
                'ambiguous_dynamic_grids' => (int) $repeatedGridDetection['ambiguous_count'],
                'dynamic_field_suggestions' => (int) $repeatedGridDetection['mapped_field_count'],
            ],
            'files' => $manifestFiles,
            'ignored_files' => $ignoredFiles,
            'relationships' => $relationships,
            'external_references' => $externalReferences,
            'missing_references' => $missingReferences,
            'page_detection' => [
                'candidates' => $pageCandidates,
                'deferred' => $deferredHtmlDocuments,
            ],
            'site_part_detection' => $sitePartDetection,
            'template_detection' => [
                'candidates' => $templateCandidates,
            ],
            'navigation_detection' => $navigationDetection,
            'repeated_grid_detection' => $repeatedGridDetection,
            'dom' => [
                'documents' => $domDocuments,
            ],
            'warnings' => $warnings,
        ];

        $json = $this->canonicalizer->encode(
            $manifest
        );

        if (is_wp_error($json)) {
            return $json;
        }

        return [
            'manifest' => $manifest,
            'manifest_json' => $json,
            'checksum' => hash(
                'sha256',
                $json
            ),
            'files' => $files,
        ];
    }

    private function uniqueSorted(array $records): array
    {
        $unique = [];

        foreach ($records as $record) {
            $encoded = wp_json_encode(
                $record,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );

            if (! is_string($encoded)) {
                continue;
            }

            $unique[$encoded] = $record;
        }

        ksort(
            $unique,
            SORT_STRING
        );

        return array_values(
            $unique
        );
    }
}
