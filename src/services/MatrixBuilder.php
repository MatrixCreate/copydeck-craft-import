<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use matrixcreate\contentiqimporter\ContentIQImporter;
use yii\base\Component;

/**
 * Builds the Matrix field data array from ContentIQ blocks.
 *
 * Each block maps to an outer Craft contentBlocks entry type that contains a nested
 * inner Matrix field. The mapping is defined in config/defaults.php and can be
 * overridden per-project via blockOverrides in config/contentiq.php.
 *
 * Structure per block mapping:
 *   outerType   — handle of the Craft contentBlocks entry type
 *   outerFields — fields set directly on the outer entry (e.g. layout dropdowns)
 *   innerMatrix — nested Matrix: outerField, innerType, mode (single|repeated|grouped), fields
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class MatrixBuilder extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * Resolved mapping (defaults merged with any blockOverrides), built once.
     *
     * @var array<string, array>|null
     */
    private ?array $_mapping = null;

    // Public Methods
    // =========================================================================

    /**
     * Initialises the builder with the merged block mapping.
     *
     * @param array $config Merged contentiq config (defaults + project overrides).
     * @return void
     */
    public function prepare(array $config): void
    {
        $defaults  = require dirname(__DIR__) . '/config/defaults.php';
        $overrides = $config['blockOverrides'] ?? [];

        // Overrides replace entire block definitions — not merged at field level.
        $this->_mapping = array_replace($defaults, $overrides);
    }

    /**
     * Builds the Matrix field data array and the block report from a ContentIQ blocks array.
     *
     * @param array[] $blocks   ContentIQ `blocks` array from the JSON.
     * @param bool    $dryRun  If true, skips image downloads.
     * @return array{
     *   matrixData: array<string, array>,
     *   blockReport: array<int, array{type: string, fields: string[], skipped: bool}>,
     *   imageReport: array<int, array{filename: string, reused: bool}>
     * }
     */
    public function build(array $blocks, bool $dryRun = false): array
    {
        $matrixData  = [];
        $blockReport = [];
        $imageReport = [];
        $counter     = 0;

        // Pre-process: group consecutive blocks that use 'grouped' mode.
        $processedBlocks = $this->_groupConsecutiveBlocks($blocks);

        foreach ($processedBlocks as $item) {
            $type = $item['type'] ?? '';

            // CTA blocks are handled by ImportService after MatrixBuilder runs.
            // We emit them as placeholders in matrixData so positioning is preserved.
            if ($type === 'call_to_action') {
                $key = 'new' . (++$counter);
                $matrixData[$key] = [
                    'type'   => 'callToAction',
                    'fields' => [],
                    '_cta'   => true,
                ];
                $blockReport[] = ['type' => $type, 'fields' => ['chooseCallToAction'], 'skipped' => false];
                continue;
            }

            if (!isset($this->_mapping[$type])) {
                Craft::warning("Unknown ContentIQ block type '{$type}' — skipping.", __METHOD__);
                $blockReport[] = ['type' => $type, 'fields' => [], 'skipped' => true];
                continue;
            }

            $mapping = $this->_mapping[$type];

            // Grouped blocks arrive as a single item with a '_groupedBlocks' array.
            if (isset($item['_groupedBlocks'])) {
                [$outerFields, $innerMatrixData, $reportedFields] = $this->_buildGroupedBlock(
                    $mapping,
                    $item['_groupedBlocks'],
                    $imageReport,
                    $dryRun,
                );
            } else {
                $sourceFields = $item['fields'] ?? [];

                [$outerFields, $innerMatrixData, $reportedFields] = $this->_buildBlock(
                    $mapping,
                    $sourceFields,
                    $imageReport,
                    $dryRun,
                );
            }

            $allFields = array_merge($outerFields, $innerMatrixData);

            // Populate contentiqNotes from the block-level notes key if present.
            // For grouped blocks, combine notes from all blocks in the group.
            $notesSources = isset($item['_groupedBlocks'])
                ? array_column($item['_groupedBlocks'], 'notes')
                : [$item['notes'] ?? ''];
            $notes = implode("\n\n", array_filter(array_map('trim', $notesSources)));
            if ($notes !== '') {
                $allFields['contentiqNotes'] = $notes;
            }

            $key              = 'new' . (++$counter);
            $matrixData[$key] = ['type' => $mapping['outerType'], 'fields' => $allFields];

            $innerCount = isset($item['_groupedBlocks']) ? count($item['_groupedBlocks']) : 1;
            $blockReport[] = [
                'type'   => $type,
                'fields' => $reportedFields,
                'skipped' => false,
                'innerCount' => $innerCount,
            ];
        }

        return [
            'matrixData'  => $matrixData,
            'blockReport' => $blockReport,
            'imageReport' => $imageReport,
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Groups consecutive blocks that use 'grouped' mode into a single entry.
     *
     * Consecutive blocks of the same type where the mapping has mode 'grouped'
     * are merged into one item with a '_groupedBlocks' array. All other blocks
     * pass through unchanged.
     *
     * @param array[] $blocks
     * @return array[]
     */
    private function _groupConsecutiveBlocks(array $blocks): array
    {
        $result     = [];
        $groupBuffer = [];
        $groupType   = null;

        foreach ($blocks as $block) {
            $type    = $block['type'] ?? '';
            $mapping = $this->_mapping[$type] ?? null;
            $mode    = $mapping['innerMatrix']['mode'] ?? null;

            if ($mode === 'grouped' && $type === $groupType) {
                // Continue the current group.
                $groupBuffer[] = $block;
                continue;
            }

            // Flush any pending group.
            if (!empty($groupBuffer)) {
                $result[] = [
                    'type'            => $groupType,
                    '_groupedBlocks'  => $groupBuffer,
                ];
                $groupBuffer = [];
                $groupType   = null;
            }

            if ($mode === 'grouped') {
                // Start a new group.
                $groupBuffer = [$block];
                $groupType   = $type;
            } else {
                $result[] = $block;
            }
        }

        // Flush final group.
        if (!empty($groupBuffer)) {
            $result[] = [
                'type'            => $groupType,
                '_groupedBlocks'  => $groupBuffer,
            ];
        }

        return $result;
    }

    /**
     * Builds outer fields and inner Matrix data for a grouped set of blocks.
     *
     * Each block in the group becomes one inner entry. Outer fields are taken
     * from the first block only (they're typically the same across the group).
     *
     * @param array   $mapping       Block mapping definition from defaults.php.
     * @param array[] $groupedBlocks Array of ContentIQ blocks to merge.
     * @param array   &$imageReport
     * @param bool    $dryRun
     * @return array{0: array, 1: array, 2: string[]}
     */
    private function _buildGroupedBlock(
        array $mapping,
        array $groupedBlocks,
        array &$imageReport,
        bool $dryRun,
    ): array {
        $outerFields    = [];
        $reportedFields = [];

        // Resolve outer fields from the first block only.
        $firstSourceFields = $groupedBlocks[0]['fields'] ?? [];
        foreach ($mapping['outerFields'] ?? [] as $contentiqKey => [$craftHandle, $handlerType]) {
            $value    = $firstSourceFields[$contentiqKey] ?? null;
            $resolved = $this->_resolveFieldByHandler($handlerType, $craftHandle, $value, $imageReport, $dryRun);

            foreach ($resolved as $handle => $fieldValue) {
                $outerFields[$handle] = $fieldValue;
                $reportedFields[]     = $handle;
            }
        }

        $innerConfig = $mapping['innerMatrix'] ?? null;

        if ($innerConfig === null) {
            return [$outerFields, [], $reportedFields];
        }

        $outerField   = $innerConfig['outerField'];
        $innerEntries = [];
        $innerCounter = 0;

        foreach ($groupedBlocks as $block) {
            $sourceFields = $block['fields'] ?? [];

            [$innerFields, $innerReportedFields] = $this->_buildSingleInnerEntry(
                $innerConfig,
                $sourceFields,
                $imageReport,
                $dryRun,
            );

            $innerKey                = 'new' . (++$innerCounter);
            $innerEntries[$innerKey] = [
                'type'   => $innerConfig['innerType'],
                'fields' => $innerFields,
            ];

            // Report field names from the first item only.
            if ($innerCounter === 1) {
                $reportedFields = array_merge($reportedFields, $innerReportedFields);
            }
        }

        $innerMatrixData = [$outerField => $innerEntries];

        return [$outerFields, $innerMatrixData, $reportedFields];
    }

    /**
     * Builds the outer fields and inner Matrix data for a single block.
     *
     * Returns a tuple of [outerFields, innerMatrixData, reportedFields].
     *
     * @param array  $mapping      Block mapping definition from defaults.php.
     * @param array  $sourceFields ContentIQ fields for this block.
     * @param array  &$imageReport Image report array, mutated by image handlers.
     * @param bool   $dryRun
     * @return array{0: array, 1: array, 2: string[]}
     */
    private function _buildBlock(
        array $mapping,
        array $sourceFields,
        array &$imageReport,
        bool $dryRun,
    ): array {
        $outerFields    = [];
        $reportedFields = [];

        // Resolve outer fields (e.g. layout dropdown on the outer entry type).
        foreach ($mapping['outerFields'] ?? [] as $contentiqKey => [$craftHandle, $handlerType]) {
            // '_block' is a special key meaning "pass the entire source fields to the handler".
            $value   = $contentiqKey === '_block' ? $sourceFields : ($sourceFields[$contentiqKey] ?? null);
            $resolved = $this->_resolveFieldByHandler($handlerType, $craftHandle, $value, $imageReport, $dryRun);

            foreach ($resolved as $handle => $fieldValue) {
                // Internal keys (prefixed _) are injected into sourceFields for
                // inner matrix resolution — they are not Craft field values.
                if (str_starts_with($handle, '_')) {
                    $sourceFields[$handle] = $fieldValue;
                    continue;
                }
                $outerFields[$handle] = $fieldValue;
                $reportedFields[]     = $handle;
            }
        }

        // Build inner Matrix data.
        $innerConfig = $mapping['innerMatrix'] ?? null;

        if ($innerConfig === null) {
            return [$outerFields, [], $reportedFields];
        }

        $outerField    = $innerConfig['outerField'];
        $mode          = $innerConfig['mode'] ?? 'single';
        $innerEntries  = [];

        if ($mode === 'single') {
            [$innerFields, $innerReportedFields] = $this->_buildSingleInnerEntry(
                $innerConfig,
                $sourceFields,
                $imageReport,
                $dryRun,
            );

            $innerEntries['new1'] = [
                'type'   => $innerConfig['innerType'],
                'fields' => $innerFields,
            ];

            $reportedFields = array_merge($reportedFields, $innerReportedFields);
        } else {
            $sourceKey   = $innerConfig['sourceKey'] ?? '';
            $items       = $sourceFields[$sourceKey] ?? [];

            // Fallback source key — used when primary is empty (e.g. FAQ
            // items may come from fields.items or from _faqItems via nodes).
            if (empty($items) && isset($innerConfig['fallbackSourceKey'])) {
                $items = $sourceFields[$innerConfig['fallbackSourceKey']] ?? [];
            }
            $innerCounter = 0;

            foreach ($items as $item) {
                [$innerFields, $innerReportedFields] = $this->_buildSingleInnerEntry(
                    $innerConfig,
                    is_array($item) ? $item : [],
                    $imageReport,
                    $dryRun,
                );

                $innerKey                  = 'new' . (++$innerCounter);
                $innerEntries[$innerKey]   = [
                    'type'   => $innerConfig['innerType'],
                    'fields' => $innerFields,
                ];

                // Only report field names from the first item — they're all the same.
                if ($innerCounter === 1) {
                    $reportedFields = array_merge($reportedFields, $innerReportedFields);
                }
            }
        }

        $innerMatrixData = [$outerField => $innerEntries];

        return [$outerFields, $innerMatrixData, $reportedFields];
    }

    /**
     * Resolves a single inner entry's fields from a source fields array.
     *
     * Used for both 'single' mode (resolves from the block's top-level fields)
     * and 'repeated' mode (resolves from each item in the source array).
     *
     * @param array  $innerConfig  The innerMatrix config.
     * @param array  $sourceFields The source data (block fields or item from repeated array).
     * @param array  &$imageReport
     * @param bool   $dryRun
     * @return array{0: array<string, mixed>, 1: string[]}
     */
    private function _buildSingleInnerEntry(
        array $innerConfig,
        array $sourceFields,
        array &$imageReport,
        bool $dryRun,
    ): array {
        $innerFields    = [];
        $reportedFields = [];

        foreach ($innerConfig['fields'] as $contentiqKey => [$craftHandle, $handlerType]) {
            $value   = $sourceFields[$contentiqKey] ?? null;
            $resolved = $this->_resolveFieldByHandler($handlerType, $craftHandle, $value, $imageReport, $dryRun);

            foreach ($resolved as $handle => $fieldValue) {
                $innerFields[$handle] = $fieldValue;
                $reportedFields[]     = $handle;
            }
        }

        return [$innerFields, $reportedFields];
    }

    /**
     * Resolves a single field value using the specified handler type.
     *
     * Returns an associative array keyed by Craft field handle(s).
     *
     * @param string     $handlerType  Handler type string from the block mapping.
     * @param string     $craftHandle  Destination Craft field handle.
     * @param mixed      $value        Raw value from the ContentIQ source data.
     * @param array      &$imageReport Image report array, mutated by image handlers.
     * @param bool       $dryRun
     * @return array<string, mixed>
     */
    private function _resolveFieldByHandler(
        string $handlerType,
        string $craftHandle,
        mixed $value,
        array &$imageReport,
        bool $dryRun,
    ): array {
        return match ($handlerType) {
            'nodes'           => $this->_handleNodes($craftHandle, $value),
            'image'           => $this->_handleImage($craftHandle, $value, $imageReport, $dryRun),
            'heading'         => $this->_handleHeading($craftHandle, $value),
            'body'            => $this->_handleBody($craftHandle, $value),
            'layout'          => $this->_handlePassThrough($craftHandle, $value),
            'textMediaLayout' => $this->_handleTextMediaLayout($craftHandle, $value),
            'tableHtml'       => $this->_handleTableHtml($craftHandle, $value),
            'hyperButton'     => $this->_handleHyperButton($craftHandle, $value),
            'faqNodes'        => $this->_handleFaqNodes($craftHandle, $value),
            'buttonNodes'     => $this->_handleButtonNodes($craftHandle, $value),
            'uspContent'      => $this->_handleUspContent($craftHandle, $value),
            default           => $this->_handlePassThrough($craftHandle, $value),
        };
    }

    /**
     * Renders a ContentIQ nodes array to an HTML string.
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, string>
     */
    private function _handleNodes(string $handle, mixed $value): array
    {
        $html = ContentIQImporter::$plugin->nodes->render(is_array($value) ? $value : []);

        return [$handle => $html];
    }

    /**
     * Builds CKEditor HTML from a USP block's fields.
     *
     * The ContentIQ USP block sends content as two separate keys:
     *   heading → {level: int, text: string} → <hN>text</hN>
     *   items   → string[]                  → <ul><li>…</li></ul>
     *
     * Falls back to rendering a 'nodes' array if present (legacy / future format).
     *
     * @param string $handle
     * @param mixed  $value  Entire block fields array (passed via '_block' contentiqKey).
     * @return array<string, string>
     */
    private function _handleUspContent(string $handle, mixed $value): array
    {
        if (!is_array($value)) {
            return [$handle => ''];
        }

        // Fallback: if a 'nodes' array is present, render it via NodesRenderer.
        if (!empty($value['nodes']) && is_array($value['nodes'])) {
            $html = ContentIQImporter::$plugin->nodes->render($value['nodes']);
            return [$handle => $html];
        }

        $html = '';

        // heading — {level, text}
        $heading = $value['heading'] ?? null;
        if (is_array($heading) && isset($heading['text']) && (string)$heading['text'] !== '') {
            $level = max(1, min(6, (int)($heading['level'] ?? 2)));
            $text  = htmlspecialchars((string)$heading['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= "<h{$level}>{$text}</h{$level}>";
        }

        // items — flat string array → <ul>
        $items = $value['items'] ?? [];
        if (!empty($items) && is_array($items)) {
            $lis = '';
            foreach ($items as $item) {
                $text  = htmlspecialchars((string)$item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $lis  .= "<li>{$text}</li>";
            }
            $html .= "<ul>{$lis}</ul>";
        }

        return [$handle => $html];
    }

    /**
     * Downloads (or dry-runs) an image and returns an asset ID array.
     *
     * Mutates $imageReport with a record of the image for output display.
     *
     * @param string $handle
     * @param mixed  $value
     * @param array  &$imageReport
     * @param bool   $dryRun
     * @return array<string, int[]>
     */
    private function _handleImage(string $handle, mixed $value, array &$imageReport, bool $dryRun): array
    {
        if (!is_array($value) || empty($value['url'])) {
            return [$handle => []];
        }

        $result = ContentIQImporter::$plugin->images->importFromField($value, $dryRun);

        if ($result !== null) {
            $imageReport[] = ['filename' => $result['filename'], 'reused' => $result['reused']];

            return [$handle => $result['id'] !== null ? [$result['id']] : []];
        }

        return [$handle => []];
    }

    /**
     * Converts a ContentIQ heading value to an HTML heading string.
     *
     * Accepts either a {level, text} object or a plain string.
     * Output is wrapped in the appropriate <hN> tag for CKEditor fields.
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, string>
     */
    private function _handleHeading(string $handle, mixed $value): array
    {
        if (is_array($value) && isset($value['text'])) {
            $level = max(2, min(6, (int)($value['level'] ?? 3)));
            $text  = htmlspecialchars((string)$value['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return [$handle => "<h{$level}>{$text}</h{$level}>"];
        }

        if (is_string($value) && $value !== '') {
            $text = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return [$handle => "<h3>{$text}</h3>"];
        }

        return [$handle => ''];
    }

    /**
     * Wraps a plain string in a paragraph tag for CKEditor rich text fields.
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, string>
     */
    private function _handleBody(string $handle, mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [$handle => ''];
        }

        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return [$handle => "<p>{$escaped}</p>"];
    }

    /**
     * Passes a value through unchanged.
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, mixed>
     */
    private function _handlePassThrough(string $handle, mixed $value): array
    {
        return [$handle => $value];
    }

    /**
     * Maps a ContentIQ text-and-media layout string to a Craft dropdown value.
     *
     * ContentIQ layout values and their Craft equivalents:
     *   'image_right' → 'text-left'  (text on left, image on right — Craft default)
     *   'image_left'  → 'image-left' (image on left, text on right)
     *
     * Unmapped values fall back to 'text-left'.
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, string>
     */
    private function _handleTextMediaLayout(string $handle, mixed $value): array
    {
        $layoutMap = [
            'image_right' => 'text-left',
            'image_left'  => 'image-left',
        ];

        $craftValue = $layoutMap[(string)$value] ?? 'text-left';

        return [$handle => $craftValue];
    }

    /**
     * Converts a ContentIQ rows array to an HTML table string for CKEditor.
     *
     * Rows format: [{isHeader: bool, cells: [string, ...]}, ...]
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, string>
     */
    private function _handleTableHtml(string $handle, mixed $value): array
    {
        if (!is_array($value) || empty($value)) {
            return [$handle => ''];
        }

        $thead = '';
        $tbody = '';

        foreach ($value as $row) {
            if (!is_array($row) || !isset($row['cells'])) {
                continue;
            }

            $cells = $row['cells'];
            $isHeader = !empty($row['isHeader']);
            $tag = $isHeader ? 'th' : 'td';

            $rowHtml = '<tr>';
            foreach ($cells as $cell) {
                $escaped = htmlspecialchars((string)$cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $rowHtml .= "<{$tag}>{$escaped}</{$tag}>";
            }
            $rowHtml .= '</tr>';

            if ($isHeader) {
                $thead .= $rowHtml;
            } else {
                $tbody .= $rowHtml;
            }
        }

        $html = '<figure class="table"><table>';
        if ($thead !== '') {
            $html .= "<thead>{$thead}</thead>";
        }
        if ($tbody !== '') {
            $html .= "<tbody>{$tbody}</tbody>";
        }
        $html .= '</table></figure>';

        return [$handle => $html];
    }

    /**
     * Converts a ContentIQ button object to a Hyper link field value.
     *
     * Expects {label, url}. Buttons without a URL are skipped (returns empty array).
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, array>
     */
    private function _handleHyperButton(string $handle, mixed $value): array
    {
        if (!is_array($value)) {
            return [$handle => []];
        }

        $label = (string)($value['label'] ?? '');
        $url   = (string)($value['url'] ?? '');

        if ($label === '' && $url === '') {
            return [$handle => [], 'showLinkAsSeparateButton' => false];
        }

        return [
            $handle => [
                [
                    'type'       => 'verbb\\hyper\\links\\Url',
                    'handle'    => 'default-verbb-hyper-links-url',
                    'linkValue' => $url !== '' ? $url : '#',
                    'linkText'  => $label,
                    'linkClass' => 'btn btn-primary',
                ],
            ],
            'showLinkAsSeparateButton' => true,
        ];
    }

    /**
     * Splits a FAQ nodes array into richText (before), accordion items, and extraRichText (after).
     *
     * The nodes array may contain headings, paragraphs, and a single faq_items node.
     * Content before faq_items → richText (rendered as HTML).
     * The faq_items node → returned under the _faqItems key for the caller to handle.
     * Content after faq_items → extraRichText (rendered as HTML).
     * ctaButton nodes are skipped.
     *
     * @param string $handle Ignored — this handler returns multiple fixed handles.
     * @param mixed  $value  The nodes array from the ContentIQ FAQ block.
     * @return array<string, mixed>
     */
    private function _handleFaqNodes(string $handle, mixed $value): array
    {
        if (!is_array($value) || empty($value)) {
            return ['richText' => '', 'extraRichText' => '', '_faqItems' => []];
        }

        $beforeNodes    = [];
        $afterNodes     = [];
        $faqItems       = [];
        $ctaButtons     = [];
        $foundFaqItems  = false;

        foreach ($value as $node) {
            $type = $node['type'] ?? '';

            if ($type === 'faq_items') {
                $faqItems      = $node['faqItems'] ?? [];
                $foundFaqItems = true;
                continue;
            }

            if ($type === 'ctaButton') {
                // Collect CTA buttons as actionButtons Matrix entries.
                $label = (string)($node['label'] ?? '');
                $url   = (string)($node['url'] ?? '');

                if ($label !== '' || $url !== '') {
                    $ctaButtons[] = [
                        'type'   => 'actionButton',
                        'fields' => [
                            'actionButton' => [
                                [
                                    'type'      => 'verbb\\hyper\\links\\Url',
                                    'handle'    => 'default-verbb-hyper-links-url',
                                    'linkValue' => $url !== '' ? $url : '#',
                                    'linkText'  => $label,
                                    'linkClass' => 'btn btn-primary',
                                ],
                            ],
                        ],
                    ];
                }
                continue;
            }

            if ($foundFaqItems) {
                $afterNodes[] = $node;
            } else {
                $beforeNodes[] = $node;
            }
        }

        $renderer = ContentIQImporter::$plugin->nodes;

        // Build actionButtons Matrix data from collected CTA buttons.
        $actionButtonsData = [];
        $btnCounter = 0;
        foreach ($ctaButtons as $btn) {
            $actionButtonsData['new' . (++$btnCounter)] = $btn;
        }

        $result = [
            'richText'      => $renderer->render($beforeNodes),
            'extraRichText' => $renderer->render($afterNodes),
            '_faqItems'     => $faqItems,
        ];

        if (!empty($actionButtonsData)) {
            $result['actionButtons'] = $actionButtonsData;
        }

        return $result;
    }

    /**
     * Converts a ContentIQ postNodes array (ContentNode[]) to an actionButtons Matrix field value.
     *
     * Only ctaButton nodes are processed — all other node types are silently ignored.
     * Nodes with no label and no URL are skipped.
     *
     * @param string $handle Craft Matrix field handle (e.g. 'actionButtons').
     * @param mixed  $value  ContentNode[] from the ContentIQ postNodes array.
     * @return array<string, array>
     */
    private function _handleButtonNodes(string $handle, mixed $value): array
    {
        if (!is_array($value) || empty($value)) {
            return [$handle => []];
        }

        $actionButtonsData = [];
        $btnCounter = 0;

        foreach ($value as $node) {
            if (($node['type'] ?? '') !== 'ctaButton') {
                continue;
            }

            $label = (string)($node['label'] ?? '');
            $url   = (string)($node['url'] ?? '');

            if ($label === '' && $url === '') {
                continue;
            }

            $actionButtonsData['new' . (++$btnCounter)] = [
                'type'   => 'actionButton',
                'fields' => [
                    'actionButton' => [
                        [
                            'type'      => 'verbb\\hyper\\links\\Url',
                            'handle'    => 'default-verbb-hyper-links-url',
                            'linkValue' => $url !== '' ? $url : '#',
                            'linkText'  => $label,
                            'linkClass' => 'btn btn-primary',
                        ],
                    ],
                ],
            ];
        }

        return [$handle => $actionButtonsData];
    }
}
