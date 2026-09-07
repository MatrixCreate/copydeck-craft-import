<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\fields\ContentBlock;
use craft\helpers\Db;
use craft\models\FieldLayout;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\helpers\LinkHelper;
use Throwable;
use yii\base\Component;

/**
 * Orchestrates the full import pipeline for a single ContentIQ page.
 *
 * Pipeline (per page):
 *   1. Validate and parse the JSON structure.
 *   2. Resolve section and entry type from config.
 *   3. Find existing entry by slug (or create a new one).
 *   4. Set title from document.title.
 *   5. Populate SEO fields.
 *   6. Download and import images via ImageImportService.
 *   7. Walk blocks via MatrixBuilder → set contentBlocks Matrix field.
 *   8. Save directly to the entry (no draft).
 *   9. Return a result array for the controller to render.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class ImportService extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * Resolved and merged config for the current run.
     *
     * @var array|null
     */
    private ?array $_config = null;

    /**
     * Resolved content_types routing map (defaults.php merged with project override).
     *
     * @var array<string, array>|null
     */
    private ?array $_contentTypesMap = null;

    // Public Methods
    // =========================================================================

    /**
     * Runs the full import pipeline for a single ContentIQ page export.
     *
     * Returns a result array consumed by ImportController for output rendering.
     *
     * Result shape:
     * {
     *   success:       bool,
     *   slug:          string,
     *   entryId:       int|null,
     *   entryFound:    bool,
     *   seoFieldCount: int,
     *   blocks:        [{type, fields[], skipped}],
     *   images:        [{filename, reused}],
     *   warnings:      string[],
     *   error:         string|null,
     * }
     *
     * @param array $data    Decoded top-level JSON object for a single page.
     * @param bool  $dryRun  If true, validate and report without writing anything.
     * @param bool  $verbose Unused here — verbose block logging is in the controller.
     * @return array
     */
    public function importPage(array $data, bool $dryRun = false, bool $verbose = false): array
    {
        $result = $this->_emptyResult();

        try {
            // -----------------------------------------------------------------------
            // 1. Resolve config (once per process — cached after first call).
            // -----------------------------------------------------------------------
            $config = $this->_getConfig();

            // DIFF-AWARE Matrix writes (see MatrixBuilder::build() docblock and
            // docs/block-mapping.md) — gated off by default,
            // see _getConfig()'s 'preserveBlockIdentity' default and comment.
            $preserveBlockIdentity = (bool)($config['preserveBlockIdentity'] ?? false);

            // -----------------------------------------------------------------------
            // 2. Validate JSON structure.
            //    An empty/whitespace-only slug is invalid for a normal page — a
            //    downstream ->slug('') matches Craft's *first* entry in the section
            //    (a falsy slug filter is dropped there), which would silently
            //    overwrite an arbitrary entry. Homepage is the one legitimate
            //    exception: it resolves by section (Single), not by slug, so an
            //    empty slug there is expected and allowed.
            // -----------------------------------------------------------------------
            $isHomepage = (bool)($data['document']['is_homepage'] ?? false);

            if (!$isHomepage && (!isset($data['document']['slug']) || trim((string)$data['document']['slug']) === '')) {
                return $this->_fatal($result, 'Missing or empty document.slug.');
            }

            $slug  = $data['document']['slug'] ?? '';
            $title = $data['document']['title'] ?? $slug;
            $result['slug']  = $slug;
            $result['title'] = $title;

            // Stable ContentIQ page id — threaded into _resolveCtaBlocks() below so
            // 'page'-source CTA identity can key on (page_id, block_id) instead of
            // title alone (see _resolveCtaEntry()).
            $pageId = isset($data['document']['id']) ? (int)$data['document']['id'] : null;

            // -----------------------------------------------------------------------
            // 2a. Collection children carry a content_type and a raw `content` field
            //     instead of a blocks array. Route them to their configured section
            //     and import via a separate, simpler path. (Collection parents are
            //     excluded server-side and never reach the importer.)
            // -----------------------------------------------------------------------
            $contentType = $data['document']['content_type'] ?? null;
            if ($contentType !== null) {
                return $this->_importCollectionChild($data, $contentType, $config, $dryRun, $result);
            }

            // -----------------------------------------------------------------------
            // 3. Resolve section and entry type.
            // -----------------------------------------------------------------------
            if ($isHomepage) {
                $sectionHandle   = $config['homepageSection'] ?? 'homepage';
                $entryTypeHandle = $config['homepageEntryType'] ?? 'homepage';
            } else {
                $sectionHandle   = $config['section'] ?? 'pages';
                $entryTypeHandle = $config['entryType'] ?? 'pages';
            }

            $section = Craft::$app->entries->getSectionByHandle($sectionHandle);
            if ($section === null) {
                return $this->_fatal($result, "Section '{$sectionHandle}' not found in Craft. Check config/contentiq.php.");
            }

            $entryType = Craft::$app->entries->getEntryTypeByHandle($entryTypeHandle);
            if ($entryType === null) {
                return $this->_fatal($result, "Entry type '{$entryTypeHandle}' not found in Craft. Check config/contentiq.php.");
            }

            // -----------------------------------------------------------------------
            // 4. Prepare ImageImportService (resolves volume + folder once).
            // -----------------------------------------------------------------------
            $volumeHandle = $config['assetVolume'] ?? 'images';
            $folderPath   = $config['assetFolder'] ?? 'contentiq';

            ContentIQImporter::$plugin->images->prepare($volumeHandle, $folderPath);

            // -----------------------------------------------------------------------
            // 5. Prepare MatrixBuilder (builds merged mapping once).
            // -----------------------------------------------------------------------
            ContentIQImporter::$plugin->matrixBuilder->prepare($config);

            // -----------------------------------------------------------------------
            // 6. Find existing entry (Singles always exist; Structures match by slug).
            //    Delegates to findExistingEntry() — the single resolution path also
            //    used by SyncJob's lock check, so the two never disagree about which
            //    entry a page maps to. Resolved here (moved up from its previous spot
            //    after SEO/card/hero resolution) because DIFF-AWARE block identity
            //    (step 6a, below) needs the owner entry id before MatrixBuilder runs.
            // -----------------------------------------------------------------------
            $existing = $this->findExistingEntry($data);

            if ($existing !== null) {
                $result['entryFound'] = true;
                $result['entryId']    = $existing->id;
            }

            // -----------------------------------------------------------------------
            // 6a. DIFF-AWARE Matrix writes: preload this owner's existing top-level
            //     block map (payload block id => nested element id) so build() can
            //     reuse unchanged blocks' element ids instead of always recreating
            //     them. Empty unless preserveBlockIdentity is on and the entry
            //     already exists — an empty map makes build() behave exactly as it
            //     did before this feature existed (see MatrixBuilder::build()).
            // -----------------------------------------------------------------------
            $existingBlockMap = ($preserveBlockIdentity && $existing !== null)
                ? $this->_loadBlockSyncMap($existing->id)
                : [];

            // -----------------------------------------------------------------------
            // 7. Build Matrix field data from blocks.
            //    Hero blocks are handled separately (entry.hero field, not contentBlocks).
            // -----------------------------------------------------------------------
            $blocks = $data['blocks'] ?? [];

            // Extract hero block before passing to MatrixBuilder.
            // CTA blocks pass through MatrixBuilder (it skips them with a report)
            // and are resolved separately after the entry is available.
            $heroBlock     = null;
            $ctaBlocks     = [];
            $contentBlocks = [];
            foreach ($blocks as $block) {
                $blockType = $block['type'] ?? '';
                if ($blockType === 'hero') {
                    $heroBlock = $block;
                } else {
                    if ($blockType === 'call_to_action') {
                        $ctaBlocks[] = $block;
                    }
                    $contentBlocks[] = $block;
                }
            }

            // Collect block notes for the sidebar widget.
            $noteLines = [];
            foreach ($blocks as $block) {
                $note = $block['notes'] ?? '';
                if (is_string($note) && trim($note) !== '') {
                    $blockType = $block['type'] ?? 'unknown';
                    $acronyms = ['usp' => 'USP', 'faq' => 'FAQ', 'cta' => 'CTA'];
                    $blockLabel = ucwords(str_replace('_', ' ', $blockType));
                    $blockLabel = strtr($blockLabel, array_combine(
                        array_map('ucfirst', array_keys($acronyms)),
                        array_values($acronyms),
                    ));
                    $noteLines[] = $blockLabel . "\n" . trim($note);
                }
            }
            $result['blockNotes'] = implode("\n\n", $noteLines);

            $built = ContentIQImporter::$plugin->matrixBuilder->build($contentBlocks, $dryRun, $slug, $existingBlockMap);

            $result['blocks']      = $built['blockReport'];
            $result['images']      = $built['imageReport'];
            $result['cardRefs']    = $built['cardRefs'] ?? [];  // Deferred card refs for SyncJob pass 2
            $result['warnings']    = array_merge($result['warnings'], $built['warnings'] ?? []);

            // -----------------------------------------------------------------------
            // 8. Resolve SEO field values, card fields, and hero field.
            //    SEO is only built (and later merged) when the payload actually
            //    carries SEO data — an absent `seo` key must leave an editor's
            //    hand-tuned SEO alone, never blank it out. Mirrors the
            //    collection-child path below (_importCollectionChild).
            // -----------------------------------------------------------------------
            $seoValues = [];

            if (!empty($data['seo'])) {
                $seoValues    = $this->_resolveSeoFields($data['seo'], $config, $dryRun);
                $seoPopulated = array_filter($seoValues, fn($v) => $v !== '' && $v !== null && $v !== []);
                $result['seoFieldCount'] = count($seoPopulated);
            }

            $cardValues = $this->_resolveCardFields($data['document']['card'] ?? null, $dryRun);

            // Both pages and homepage use the same hero ContentBlock field.
            $heroData = $heroBlock !== null
                ? $this->_buildHeroField($heroBlock, $dryRun)
                : null;

            // -----------------------------------------------------------------------
            // 9. Dry run — stop before any writes.
            // -----------------------------------------------------------------------
            if ($dryRun) {
                $result['success'] = true;

                return $result;
            }

            // -----------------------------------------------------------------------
            // 10. Resolve CTA blocks. Each one routes by its ContentIQ
            //     fields.source label (see _ctaSource()):
            //       'page'   → unchanged: create/update a per-page
            //                  callToActionEntry, patch its id into the
            //                  matrixData placeholder.
            //       'global' → the placeholder is dropped entirely (no inline
            //                  block); the shared entry itself is written by
            //                  _resolveGlobalCtaEntry(), gated on globals
            //                  consent — see docs/globals.md.
            //     footerCallToAction.showGlobalCallToAction is queued below as
            //     a tri-state decision (see _resolveCtaBlocks()'s return
            //     value): ON when ≥1 block routed 'global'; OFF when only
            //     'page'-routed blocks were found; untouched when there were
            //     no CTA blocks at all.
            // -----------------------------------------------------------------------
            $matrixData          = $built['matrixData'];
            $blockKeyConsumption = $built['blockKeyConsumption'] ?? [];

            $footerGlobalCtaIntent = $this->_resolveCtaBlocks(
                $matrixData,
                $blockKeyConsumption,
                $ctaBlocks,
                $result,
                $dryRun,
                $pageId,
            );

            // -----------------------------------------------------------------------
            // 11. Build the complete field values array.
            // -----------------------------------------------------------------------
            $matrixHandle = $config['matrixField'] ?? 'contentBlocks';

            $fieldValues = array_merge(
                [$matrixHandle => $matrixData],
                $heroData ?? [],
                $cardValues,
                $seoValues,
                $footerGlobalCtaIntent !== null
                    ? ($this->_buildFooterGlobalCtaField($entryType->getFieldLayout(), $config, $result, $footerGlobalCtaIntent) ?? [])
                    : [],
            );

            // -----------------------------------------------------------------------
            // 11a/11b. Save the resolved entry — wrapped in a DB transaction so a
            //          throw (or a validation failure) mid-write leaves the entry
            //          untouched rather than half-updated. Scoped tightly to this
            //          single page's own save: image downloads for SEO/hero/card/
            //          CTA fields already happened above (steps 7-10), outside the
            //          transaction, since they're slow network I/O rather than DB
            //          writes. An explicit save failure rolls back and returns the
            //          normal fatal result; an unexpected throw rolls back and
            //          rethrows to the outer catch below, which already converts
            //          any throw into that same fatal shape.
            // -----------------------------------------------------------------------
            $transaction = Craft::$app->getDb()->beginTransaction();

            try {
                // 11a. Existing entry → overwrite content directly.
                if ($existing !== null) {
                    $filteredValues = $this->_filterToValidFields($fieldValues, $existing->getFieldLayout(), $result);

                    // 2d. Empty-matrix guard: a page whose blocks array is missing/
                    // empty (or whose blocks were all unknown types) must never
                    // silently wipe existing content. Omit the matrix handle
                    // (leaving current blocks in place) when the built matrix is
                    // empty AND the existing entry actually has blocks today.
                    $matrixHandleOmitted = false;

                    if (empty($matrixData) && array_key_exists($matrixHandle, $filteredValues)) {
                        $hasExistingBlocks = $existing->getFieldValue($matrixHandle)->status(null)->exists();

                        if ($hasExistingBlocks) {
                            unset($filteredValues[$matrixHandle]);
                            $matrixHandleOmitted = true;
                            $result['warnings'][] = 'Page had no importable blocks — existing content blocks were preserved.';
                        }
                    }

                    $result['seoFieldCount'] = $this->_countSeoFields($filteredValues, $config);

                    if (!$isHomepage) {
                        $existing->title = $title;
                    }
                    $existing->setFieldValues($filteredValues);

                    if (!Craft::$app->getElements()->saveElement($existing, false)) {
                        $errors = implode(', ', $existing->getFirstErrors());
                        $transaction->rollBack();

                        return $this->_fatal($result, "Failed to save entry: {$errors}");
                    }

                    $transaction->commit();

                    // DIFF-AWARE Matrix writes: record the block map for the NEXT
                    // sync now that this save succeeded. Skipped when the
                    // empty-matrix guard above left existing blocks untouched —
                    // nothing changed, so the previously recorded map is still
                    // accurate. Bookkeeping only — a failure here can't fail this
                    // page (see _recordBlockSyncMap()).
                    if ($preserveBlockIdentity && !$matrixHandleOmitted) {
                        $this->_recordBlockSyncMap($existing, $blockKeyConsumption, $matrixHandle);
                    }

                    $result['entryId'] = $existing->id;
                    $result['success'] = true;

                    return $result;
                }

                // 11b. No existing entry → create and publish directly.
                $entry = new Entry();
                $entry->sectionId = $section->id;
                $entry->typeId    = $entryType->id;
                $entry->siteId    = Craft::$app->getSites()->getPrimarySite()->id;
                $entry->title     = $title;
                $entry->slug      = $slug;

                $filteredValues = $this->_filterToValidFields($fieldValues, $entryType->getFieldLayout(), $result);
                $result['seoFieldCount'] = $this->_countSeoFields($filteredValues, $config);

                $entry->setFieldValues($filteredValues);

                $saved = Craft::$app->getElements()->saveElement($entry, false);

                if (!$saved) {
                    $errors = implode(', ', $entry->getFirstErrors());
                    $this->_logNestedErrors($entry);
                    $transaction->rollBack();

                    return $this->_fatal($result, "Failed to save entry: {$errors}");
                }

                $transaction->commit();
            } catch (Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            // DIFF-AWARE Matrix writes: a brand-new entry has no prior block map
            // to preserve, but recording one now lets the SECOND sync reuse these
            // blocks' identities. Bookkeeping only — see _recordBlockSyncMap().
            if ($preserveBlockIdentity) {
                $this->_recordBlockSyncMap($entry, $blockKeyConsumption, $matrixHandle);
            }

            $result['entryId'] = $entry->id;
            $result['success'] = true;
        } catch (Throwable $e) {
            Craft::error("ContentIQImporter exception: " . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);

            return $this->_fatal($result, 'Exception: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Resolves the existing Craft entry a ContentIQ page maps to, if any.
     *
     * The single source of truth for "which entry does this page correspond
     * to" — used by importPage() itself (both the standard page/homepage
     * branch and the collection-child branch) and by SyncJob's lock check, so
     * a lock is never bypassed just because the lookup used to find the
     * candidate entry disagreed with the lookup import actually uses.
     *
     * Resolution order:
     *   0. document.id (the stable ContentIQ page id) → contentiq_page_id
     *      mapping in contentiq_entry_syncs. Checked first so a page whose
     *      slug was renamed (in ContentIQ or in Craft) still resolves to its
     *      existing entry instead of missing the slug lookup below and
     *      creating a duplicate. No mapping row, or a mapped element that no
     *      longer exists/was trashed, falls through to the steps below.
     *   1. document.content_type set → route via getContentTypeRoute(); section
     *      + slug lookup in the routed section. An unmapped content_type (no
     *      route) has nothing to look up — returns null (the page is skipped
     *      by _importCollectionChild() regardless).
     *   2. document.is_homepage → section-only lookup of the homepage Single
     *      (section from config['homepageSection'] ?? 'homepage'). Singles
     *      always have exactly one entry, so no slug is involved.
     *   3. Otherwise → pages section (config['section'] ?? 'pages') + slug.
     *
     * An empty/whitespace-only slug never drives steps 1 or 3 — Craft drops a
     * falsy ->slug() filter and would match the section's first entry instead
     * of "no match". Those steps return null instead (step 0's id mapping and
     * step 2's homepage lookup don't depend on slug, so they still run).
     * importPage() separately rejects an empty slug outright for non-homepage
     * pages before any write; this guard covers the other caller — SyncJob's
     * pre-import lock check — which calls findExistingEntry() directly.
     *
     * No slug translation is applied — importPage() itself does not apply
     * any slugMap/reverse-slug translation to document.slug, so this doesn't
     * either (slugMap is only used by the sidebar widget when calling the
     * ContentIQ API, not for Craft-side entry lookups).
     *
     * @param array $pageData Decoded top-level JSON object for a single page.
     * @return \craft\elements\Entry|null
     */
    public function findExistingEntry(array $pageData): ?Entry
    {
        $config      = $this->_getConfig();
        $slug        = $pageData['document']['slug'] ?? '';
        $isHomepage  = (bool)($pageData['document']['is_homepage'] ?? false);
        $contentType = $pageData['document']['content_type'] ?? null;

        // 0. Stable page id → element_id mapping (contentiq_entry_syncs).
        //    Takes priority over every slug-based lookup below.
        $pageId = isset($pageData['document']['id']) ? (int)$pageData['document']['id'] : null;

        if ($pageId !== null && $pageId > 0) {
            $mappedElementId = (new Query())
                ->select(['element_id'])
                ->from('{{%contentiq_entry_syncs}}')
                ->where(['contentiq_page_id' => $pageId])
                ->scalar();

            // Query::scalar() returns false when no row matches.
            if ($mappedElementId !== false) {
                $mapped = Entry::find()
                    ->id((int)$mappedElementId)
                    ->status(null)
                    ->one();

                // A live (non-trashed) entry wins outright. Otherwise — the
                // mapping row is stale (element hard-deleted or trashed) —
                // fall through to the content_type/homepage/slug resolution.
                if ($mapped !== null) {
                    return $mapped;
                }
            }
        }

        $slugIsBlank = trim((string)$slug) === '';

        if ($contentType !== null) {
            $route = $this->getContentTypeRoute($contentType);

            if ($route === null || $slugIsBlank) {
                return null;
            }

            return Entry::find()
                ->section($route['section'])
                ->slug($slug)
                ->status(null)
                ->one();
        }

        if ($isHomepage) {
            $sectionHandle = $config['homepageSection'] ?? 'homepage';

            return Entry::find()
                ->section($sectionHandle)
                ->status(null)
                ->one();
        }

        if ($slugIsBlank) {
            return null;
        }

        $sectionHandle = $config['section'] ?? 'pages';

        return Entry::find()
            ->section($sectionHandle)
            ->slug($slug)
            ->status(null)
            ->one();
    }

    /**
     * Resolves the Craft routing config for a ContentIQ content_type slug.
     *
     * Returns ['section' => ..., 'entryType' => ..., 'contentField' => ...] when the
     * slug is mapped, or null when the slug is null or has no mapping. Used by both
     * importPage() (routing) and SyncJob (section-aware lock check / skipping
     * structure positioning for collection children).
     *
     * @param string|null $contentType
     * @return array{section: string, entryType: string, contentField: string, headingField?: ?string, blocksField?: ?string}|null
     */
    public function getContentTypeRoute(?string $contentType): ?array
    {
        if ($contentType === null) {
            return null;
        }

        return $this->_getContentTypesMap()[$contentType] ?? null;
    }

    /**
     * Returns the full resolved content_types routing map (defaults.php merged
     * with the project config override).
     *
     * Used by the sync screen to enumerate every collection section so its
     * entries can be listed alongside Pages.
     *
     * @return array<string, array{section: string, entryType: string, contentField: string}>
     */
    public function getContentTypesMap(): array
    {
        return $this->_getContentTypesMap();
    }

    /**
     * PASS 2: Resolves deferred card references in entryCards blocks.
     *
     * After all pages in a run are imported (so the slug→entry ID map is
     * complete), each deferred ref set recorded by MatrixBuilder is applied to
     * its block:
     *   - pages (2+ refs): populate the block's `entries` relation in order.
     *   - pages (single ref, D6): write one manual `card` row with the `entry`
     *     relation + useEntryCardDetails, so Craft's single-entry auto-expansion
     *     never fires (pages mode is literal).
     *   - children (arbitrary parent): `entries` = [parent] — the template's
     *     single-entry expansion renders the parent's children. If the parent
     *     cannot be resolved (not in the batch, not in Craft), fall back to
     *     manual cards parsed from the block's own intro prose.
     *   - children (parent == host page): nothing deferred (useChildPages set
     *     in pass 1).
     *
     * Blocks are located by blockIndex — the 0-based position among the matrix
     * blocks MatrixBuilder emitted, which matches the saved contentBlocks order.
     * Nested matrix blocks are elements, so each modified block is saved
     * directly; the owner entry is never re-saved.
     *
     * Shared by every import entry point (SyncJob, CLI import, CP upload).
     *
     * @param array $allCardRefs   Deferred ref sets keyed by entryId; each value is a LIST
     *                             of {blockIndex, mode, refs?|parent?, intro?, singlePageMode?}.
     * @param array $slugToEntryId In-run slug→entry ID map from pass 1.
     * @param bool  $dryRun        If true, resolve and warn but write nothing.
     * @return array<int, string[]> Warnings keyed by owner entry ID.
     */
    public function resolveCardReferences(array $allCardRefs, array $slugToEntryId, bool $dryRun = false): array
    {
        $config        = Craft::$app->config->getConfigFromFile('contentiq');
        $sectionHandle = $config['section'] ?? 'pages';

        // Resolve a page slug to an entry ID: in-run map first, then DB fallback.
        $resolve = function (string $slug) use ($slugToEntryId, $sectionHandle): ?int {
            if (isset($slugToEntryId[$slug])) {
                return $slugToEntryId[$slug];
            }

            return Entry::find()
                ->section($sectionHandle)
                ->slug($slug)
                ->status(null)
                ->one()?->id;
        };

        $warningsByEntry = [];

        foreach ($allCardRefs as $entryId => $refSets) {
            $warn = function (string $message) use (&$warningsByEntry, $entryId): void {
                $warningsByEntry[$entryId][] = $message;
            };

            $entry = Entry::find()->id($entryId)->status(null)->one();
            if (!$entry) {
                continue;
            }

            // Ordered nested blocks — positions match MatrixBuilder's emitted order.
            $blocks = $entry->getFieldValue('contentBlocks')->status(null)->all();

            foreach ($refSets as $refSet) {
                $blockIndex = $refSet['blockIndex'] ?? null;
                $block      = $blockIndex !== null ? ($blocks[$blockIndex] ?? null) : null;

                if (!$block || $block->getType()->handle !== 'entryCards') {
                    $warn('Cards block at position '
                        . var_export($blockIndex, true) . ' not found — card references skipped.');
                    continue;
                }

                $mode  = $refSet['mode'] ?? '';
                $dirty = false;

                if ($mode === 'pages' && !empty($refSet['singlePageMode'])) {
                    // D6: one literal page — a single manual card row rendering
                    // from the referenced entry's own card fields.
                    $slug       = $refSet['refs'][0]['slug'] ?? '';
                    $resolvedId = $slug !== '' ? $resolve($slug) : null;

                    if ($resolvedId !== null) {
                        $block->setFieldValue('entryCards', [
                            'new1' => [
                                'type'   => 'card',
                                'fields' => [
                                    'entry'               => [$resolvedId],
                                    'useEntryCardDetails' => true,
                                ],
                            ],
                        ]);
                        $dirty = true;
                    } else {
                        $warn("Card reference slug '{$slug}' not found — card left empty.");
                    }
                } elseif ($mode === 'pages') {
                    $entryIds = [];

                    foreach ($refSet['refs'] ?? [] as $ref) {
                        $slug = $ref['slug'] ?? '';
                        if ($slug === '') {
                            continue;
                        }

                        $resolvedId = $resolve($slug);
                        if ($resolvedId !== null) {
                            $entryIds[] = $resolvedId;
                        } else {
                            $warn("Card reference slug '{$slug}' not found — card skipped.");
                        }
                    }

                    $block->setFieldValue('entries', $entryIds);
                    $dirty = true;
                } elseif ($mode === 'children') {
                    $slug = $refSet['parent']['slug'] ?? '';
                    if ($slug === '') {
                        // Host-page children — useChildPages was set in pass 1.
                        continue;
                    }

                    $parentEntryId = $resolve($slug);
                    if ($parentEntryId !== null) {
                        $block->setFieldValue('entries', [$parentEntryId]);
                        $dirty = true;
                    } else {
                        // Parent page isn't in this run or in Craft (not exported
                        // yet). Fall back to manual cards parsed from the block's
                        // own prose so it renders real cards instead of nothing.
                        // The next sync rebuilds the block in automatic mode, so
                        // the fallback self-heals once the parent is exported.
                        $fallback = ContentIQImporter::$plugin->matrixBuilder
                            ->buildChildrenFallbackCards($refSet['intro'] ?? []);

                        if ($fallback['cardCount'] > 0) {
                            $block->setFieldValue('cardsInThisBlock', 'manual');
                            $block->setFieldValue('entryCards', $fallback['cardRows']);
                            $block->setFieldValue('richText', $fallback['introHtml']);
                            $dirty = true;
                            $warn("Children parent slug '{$slug}' not found — created {$fallback['cardCount']} manual cards "
                                . "from the block content instead. Unlock and re-sync after '{$slug}' is exported "
                                . 'to switch to automatic child cards.');
                        } else {
                            $warn("Children parent slug '{$slug}' not found — children cards not populated.");
                        }
                    }
                }

                if ($dirty && !$dryRun) {
                    try {
                        // Nested matrix blocks are elements — saving the block
                        // persists its field changes; the owner needs no re-save.
                        if (!Craft::$app->elements->saveElement($block)) {
                            $warn('Could not save card references: '
                                . implode('; ', $block->getFirstErrors()));
                        }
                    } catch (Throwable $e) {
                        $warn('Could not save card references: ' . $e->getMessage());
                    }
                }
            }
        }

        return $warningsByEntry;
    }

    // Private Methods
    // =========================================================================

    /**
     * Loads the content_types routing map, per-slug replace at each layer:
     *
     *   defaults.php  ←  settings.collectionMappings (CP Mappings screen)  ←  config/contentiq.php 'content_types'
     *
     * The config file stays the dev escape hatch and wins over the UI. Settings
     * rows with an empty/missing `section` are filtered out before merging, so an
     * empty collectionMappings leaves the result byte-identical to the old
     * defaults ← file merge.
     *
     * Cached after the first call.
     *
     * @return array<string, array>
     */
    private function _getContentTypesMap(): array
    {
        if ($this->_contentTypesMap !== null) {
            return $this->_contentTypesMap;
        }

        $defaults    = require dirname(__DIR__) . '/config/defaults.php';
        $defaultMap  = is_array($defaults['content_types'] ?? null) ? $defaults['content_types'] : [];

        // CP-managed mappings (project config) — drop rows with no section.
        $settings    = ContentIQImporter::$plugin->getSettings();
        $settingsMap = [];

        foreach ($settings->collectionMappings as $slug => $row) {
            if (!is_array($row) || empty($row['section'])) {
                continue;
            }

            $settingsMap[$slug] = $row;
        }

        // config/contentiq.php 'content_types' — the dev escape hatch, wins over the UI.
        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('contentiq');
        $fileOverride  = (is_array($projectConfig) && is_array($projectConfig['content_types'] ?? null))
            ? $projectConfig['content_types']
            : [];

        // Per-slug replace — a later layer replaces a slug's whole definition.
        $this->_contentTypesMap = array_replace($defaultMap, $settingsMap, $fileOverride);

        return $this->_contentTypesMap;
    }

    /**
     * Imports a collection child (a page with document.content_type set).
     *
     * Collection children differ from standard pages: they carry a raw `content`
     * ProseMirror field (not a blocks array), route to their configured section,
     * never have a Craft parent (their collection parent is excluded from export),
     * and only carry SEO for portal-on collections. Content is serialised to HTML
     * and written to the configured contentField.
     *
     * When the wire also carries a non-empty `blocks[]`, the same Matrix/hero/CTA
     * machinery the page path uses runs via _buildBlockFieldValues() and is
     * merged in — routed to `blocksField` when the content_type configures one,
     * otherwise config['matrixField']. `blocks[]` can additionally carry an
     * optional `content` key: the leftover top-level ProseMirror nodes not
     * marked up as a block, omitted entirely when nothing is left over.
     *
     * §7.6/§7.6.1 (rulings O2/O4) — when blocks[] owns the page, headingField
     * is always explicitly cleared to '' (blocks own the heading —
     * extractHeading() never runs on this path). contentField depends on
     * whether there's leftover content: non-empty leftover renders wholesale
     * into contentField, otherwise contentField is explicitly cleared to ''
     * too — never left populated from a prior content-only sync (see
     * _buildCollectionChildContentFields()). Two guards fire around that
     * write: (1) the CP dry-run preview and the real sync report share the
     * same warning-generation code path, so both surface it identically; (2)
     * the first time this clears (or replaces with leftover content) a
     * previously non-empty contentField/headingField, or replaces a
     * previously non-empty Matrix, a warning is added to the result (see
     * _buildBlockOwnershipWarnings()) — but nothing is ever actually written
     * to an existing entry unless a human has unlocked it (SyncJob's
     * per-entry auto-lock, untouched by this change).
     *
     * Returns the standard result shape with `contentType`/`sectionLabel` set, or a
     * non-fatal skip (success, skipped=true, warning) when the content_type is unmapped.
     *
     * @param array  $data
     * @param string $contentType
     * @param array  $config
     * @param bool   $dryRun
     * @param array  $result Pre-populated result skeleton (slug/title already set).
     * @return array
     */
    private function _importCollectionChild(array $data, string $contentType, array $config, bool $dryRun, array $result): array
    {
        $result['contentType'] = $contentType;

        $slug  = $result['slug'];
        $title = $result['title'];

        // Resolve routing — unmapped content_type is skipped (non-fatal).
        $route = $this->getContentTypeRoute($contentType);
        if ($route === null) {
            $result['skipped']      = true;
            $result['success']      = true;
            $result['sectionLabel'] = $contentType;
            $result['warnings'][]   = "Content type '{$contentType}' has no mapping — page skipped. Map it under ContentiQ → Mappings (or add a content_types override in config/contentiq.php).";
            Craft::warning("ContentIQImporter: unmapped content_type '{$contentType}' for page '{$slug}' — skipped.", __METHOD__);

            return $result;
        }

        $sectionHandle      = $route['section'];
        $entryTypeHandle    = $route['entryType'];
        $contentFieldHandle = $route['contentField'];
        $headingFieldHandle = $route['headingField'] ?? null;
        $blocksFieldHandle  = $route['blocksField'] ?? null;

        $section = Craft::$app->entries->getSectionByHandle($sectionHandle);
        if ($section === null) {
            return $this->_fatal($result, "Section '{$sectionHandle}' (content type '{$contentType}') not found in Craft. Check config/contentiq.php.");
        }

        $entryType = Craft::$app->entries->getEntryTypeByHandle($entryTypeHandle);
        if ($entryType === null) {
            return $this->_fatal($result, "Entry type '{$entryTypeHandle}' (content type '{$contentType}') not found in Craft. Check config/contentiq.php.");
        }

        $result['sectionLabel'] = $section->name ?: $contentType;

        // Prepare image service (used by SEO og_image import).
        $volumeHandle = $config['assetVolume'] ?? 'images';
        $folderPath   = $config['assetFolder'] ?? 'contentiq';
        ContentIQImporter::$plugin->images->prepare($volumeHandle, $folderPath);

        // ContentIQ is transitioning collection children from a raw `content`
        // ProseMirror document to structured `blocks[]`. Current wire contract
        // (§7.6/§7.6.1 — rulings O2/O4): ranges marked up → `blocks[]` plus an
        // OPTIONAL `content` key holding only the leftover top-level nodes not
        // marked up as a block (omitted when nothing is left over); no ranges
        // marked up → `content` holds the full doc, no `blocks`, unchanged.
        // Older ContentIQ deployments that still only ever send blocks[] with
        // no `content` key normalise identically — $content stays [].
        $content   = $data['content'] ?? [];
        $blocks    = $data['blocks'] ?? [];
        $hasBlocks = !empty($blocks);

        // §7.6.1 — the target Matrix field for this content_type's blocks[],
        // needed both for the write below and for the guard-2 "was this
        // previously non-empty" warning check further down.
        $defaultMatrixHandle = $config['matrixField'] ?? 'contentBlocks';
        $targetMatrixHandle  = $blocksFieldHandle ?? $defaultMatrixHandle;

        $fieldValues = $this->_buildCollectionChildContentFields(
            $content,
            $hasBlocks,
            $contentFieldHandle,
            $headingFieldHandle,
        );

        // Whether the contentField write above carries non-empty leftover
        // prose — drives the "clearing" vs "replacing" wording of the guard-2
        // warning further down (_buildBlockOwnershipWarnings()). Derived from
        // the rendered value, not the raw doc, so a semantically-empty doc
        // (e.g. {type: doc, content: []}) still reads as a clear.
        $hasLeftoverContent = $hasBlocks && ($fieldValues[$contentFieldHandle] ?? '') !== '';

        // When blocks[] is present and non-empty, run the same Matrix/hero/CTA
        // machinery the page path uses. Absent or empty blocks[] leaves this
        // entirely untouched (pre-§7.1 behaviour).
        if ($hasBlocks) {
            $blockFieldValues = $this->_buildBlockFieldValues($data, $dryRun, $result, $entryType->getFieldLayout());

            // Route this content_type's blocks to its configured Matrix field
            // (defaults to config['matrixField'] — see 'blocksField' in content_types).
            if ($blocksFieldHandle !== null
                && $blocksFieldHandle !== $defaultMatrixHandle
                && array_key_exists($defaultMatrixHandle, $blockFieldValues)
            ) {
                $blockFieldValues[$blocksFieldHandle] = $blockFieldValues[$defaultMatrixHandle];
                unset($blockFieldValues[$defaultMatrixHandle]);
            }

            $fieldValues = array_merge($fieldValues, $blockFieldValues);
        }

        // SEO is only present for portal-on collections — set it only when supplied.
        if (!empty($data['seo'])) {
            $fieldValues = array_merge($fieldValues, $this->_resolveSeoFields($data['seo'], $config, $dryRun));
        }

        // Find existing entry — delegates to findExistingEntry(), the single
        // resolution path shared with importPage()'s main branch and SyncJob's
        // lock check, so the three never disagree about which entry a page maps to.
        $existing = $this->findExistingEntry($data);

        if ($existing !== null) {
            $result['entryFound'] = true;
            $result['entryId']    = $existing->id;
        }

        // §7.6.1 guard 2 / §7.7 — warn (in both the CP dry-run preview and the
        // real sync report — this check runs before the dry-run early return
        // below) the first time this write is about to clear (or replace with
        // leftover content — see $hasLeftoverContent above) a previously
        // non-empty contentField/headingField, or replace a previously
        // non-empty Matrix with new element IDs. Read-only against $existing's
        // CURRENT values; never silent, since an unlocked sync's write is
        // irreversible in place (Craft revision history is the only recovery).
        if ($hasBlocks && $existing !== null) {
            $layout = $existing->getFieldLayout();

            $contentWasNonEmpty = $layout?->getFieldByHandle($contentFieldHandle) !== null
                && $this->_isFieldValueNonEmpty($existing->getFieldValue($contentFieldHandle));

            $headingWasNonEmpty = $headingFieldHandle !== null
                && $layout?->getFieldByHandle($headingFieldHandle) !== null
                && $this->_isFieldValueNonEmpty($existing->getFieldValue($headingFieldHandle));

            $existingBlockCount = 0;
            if ($layout?->getFieldByHandle($targetMatrixHandle) !== null) {
                $matrixValue = $existing->getFieldValue($targetMatrixHandle);
                $existingBlockCount = method_exists($matrixValue, 'count') ? $matrixValue->count() : 0;
            }

            $result['warnings'] = array_merge($result['warnings'], $this->_buildBlockOwnershipWarnings(
                $contentWasNonEmpty,
                $headingWasNonEmpty,
                $existingBlockCount,
                $contentFieldHandle,
                $headingFieldHandle,
                $targetMatrixHandle,
                $hasLeftoverContent,
            ));
        }

        if ($dryRun) {
            $result['success'] = true;

            return $result;
        }

        if ($existing !== null) {
            $filtered = $this->_filterToValidFields($fieldValues, $existing->getFieldLayout(), $result);
            $result['seoFieldCount'] = $this->_countSeoFields($filtered, $config);
            $existing->title = $title;
            $existing->setFieldValues($filtered);

            if (!Craft::$app->getElements()->saveElement($existing, false)) {
                $errors = implode(', ', $existing->getFirstErrors());

                return $this->_fatal($result, "Failed to save entry: {$errors}");
            }

            $this->_refreshUri($existing, $result);

            $result['entryId'] = $existing->id;
            $result['success'] = true;

            return $result;
        }

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId    = $entryType->id;
        $entry->siteId    = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title     = $title;
        $entry->slug      = $slug;

        $filtered = $this->_filterToValidFields($fieldValues, $entryType->getFieldLayout(), $result);
        $result['seoFieldCount'] = $this->_countSeoFields($filtered, $config);
        $entry->setFieldValues($filtered);

        if (!Craft::$app->getElements()->saveElement($entry, false)) {
            $errors = implode(', ', $entry->getFirstErrors());
            $this->_logNestedErrors($entry);

            return $this->_fatal($result, "Failed to save entry: {$errors}");
        }

        $this->_refreshUri($entry, $result);

        $result['entryId'] = $entry->id;
        $result['success'] = true;

        return $result;
    }

    /**
     * Generates and persists the entry's URI after a validation-skipped save.
     *
     * The importer saves collection children with saveElement($entry, false) to
     * bypass nested-Matrix validation, but URI generation in Craft is a
     * validation-time concern (ElementUriValidator), so a skipped save leaves
     * uri = null — the entry has no front-end URL (no globe link in the CP).
     * updateElementSlugAndUri() regenerates and writes slug + URI directly
     * without re-running element validation.
     *
     * Pages get this for free via Structures::append() → afterMoveInStructure();
     * collection children are channel entries with no structure step, so the
     * importer must trigger it explicitly. No-op when the section has no URLs
     * (setElementUri just leaves uri = null).
     *
     * @param Entry $entry
     * @param array $result
     */
    private function _refreshUri(Entry $entry, array &$result): void
    {
        try {
            Craft::$app->getElements()->updateElementSlugAndUri($entry, true, false);
        } catch (\Throwable $e) {
            $result['warnings'][] = 'Could not generate a front-end URL: ' . $e->getMessage();
        }
    }

    /**
     * Returns an empty result skeleton.
     *
     * @return array
     */
    private function _emptyResult(): array
    {
        return [
            'success'       => false,
            'slug'          => '',
            'entryId'       => null,
            'entryFound'    => false,
            'seoFieldCount' => 0,
            'blocks'        => [],
            'images'        => [],
            'blockNotes'    => '',
            'routingNotes'  => '',
            'warnings'      => [],
            'error'         => null,
            'skipped'       => false,
            'contentType'   => null,
            'sectionLabel'  => null,
        ];
    }

    /**
     * Marks the result as a fatal error and returns it.
     *
     * @param array  $result
     * @param string $message
     * @return array
     */
    private function _fatal(array $result, string $message): array
    {
        Craft::error("ContentIQImporter: $message", __METHOD__);
        $result['success'] = false;
        $result['error']   = $message;

        return $result;
    }

    /**
     * Loads and merges the contentiq config (project config + defaults).
     *
     * Cached after the first call — called once per PHP process.
     *
     * @return array
     */
    private function _getConfig(): array
    {
        if ($this->_config !== null) {
            return $this->_config;
        }

        $defaults = [
            'section'        => 'pages',
            'entryType'      => 'pages',
            'assetVolume'    => 'images',
            'assetFolder'    => 'contentiq',
            'matrixField'    => 'contentBlocks',
            'seoField'       => 'seo',
            'blockOverrides' => [],
            // DIFF-AWARE Matrix writes — off by default. This rewrites the core
            // Matrix save path and cannot be integration-tested outside a live
            // Craft instance (no runtime/test harness here — see tests/). Flip
            // on per-project in config/contentiq.php only after validating the
            // live-validation checklist in docs/block-mapping.md. See MatrixBuilder::build()
            // and importPage()'s block-map load/record steps.
            'preserveBlockIdentity' => false,
            // CTA source routing (fields.source === 'global') — see
            // _resolveCtaBlocks()/_resolveGlobalCtaEntry()/_buildFooterGlobalCtaField()
            // and docs/globals.md. These are the Craft Starter's own handles;
            // override per-project in config/contentiq.php if a fork renamed them.
            'footerCtaField'           => 'footerCallToAction',      // ContentBlock field on pages/homepage
            'footerCtaShowGlobalField' => 'showGlobalCallToAction',  // Lightswitch nested inside it
            'globalContentSet'         => 'globalContent',           // Global set holding the relation
            'globalChooseCtaField'     => 'globalChooseCallToAction', // Entries field on that global set
        ];

        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('contentiq');
        $merged        = array_replace_recursive($defaults, is_array($projectConfig) ? $projectConfig : []);

        $this->_config = $merged;

        return $merged;
    }

    /**
     * Resolves ContentIQ SEO data into a SEOmatic SeoSettings field value array.
     *
     * SEOmatic stores all SEO data in a single field (handle: 'seo') as a
     * structured array. String values go in metaGlobalVars; source flags and
     * asset IDs go in metaBundleSettings.
     *
     * @param array $seo    The seo object from the ContentIQ JSON.
     * @param array $config Merged contentiq config.
     * @param bool  $dryRun
     * @return array<string, mixed>
     */
    private function _resolveSeoFields(array $seo, array $config, bool $dryRun): array
    {
        $fieldHandle = $config['seoField'] ?? 'seo';

        $metaGlobalVars = [
            'seoTitle'       => (string)($seo['title'] ?? ''),
            'seoDescription' => (string)($seo['description'] ?? ''),
            'ogTitle'        => (string)($seo['og_title'] ?? ''),
            'ogDescription'  => (string)($seo['og_description'] ?? ''),
            'canonicalUrl'   => (string)($seo['canonical'] ?? ''),
        ];

        $metaBundleSettings = [
            'seoTitleSource'       => 'fromCustom',
            'seoDescriptionSource' => 'fromCustom',
        ];

        // og_image — import as asset and register in both seoImage and ogImage slots.
        $ogImageData = $seo['og_image'] ?? null;
        if (is_array($ogImageData) && !empty($ogImageData['url'])) {
            $imageResult = ContentIQImporter::$plugin->images->importFromField($ogImageData, $dryRun);
            if ($imageResult !== null && $imageResult['id'] !== null) {
                $metaBundleSettings['seoImageSource'] = 'fromAsset';
                $metaBundleSettings['seoImageIds']    = [$imageResult['id']];
                $metaBundleSettings['ogImageSource']  = 'fromAsset';
                $metaBundleSettings['ogImageIds']     = [$imageResult['id']];
            }
        }

        return [
            $fieldHandle => [
                'metaGlobalVars'     => $metaGlobalVars,
                'metaBundleSettings' => $metaBundleSettings,
            ],
        ];
    }

    /**
     * Resolves ContentIQ card data into Craft card field values.
     *
     * Card data is optional on the page; when absent or null, returns empty array
     * (skip writing). When present, writes all three fields: cardTitle (from title),
     * cardText (from summary), and cardImage (from image via ImageImportService).
     *
     * All three fields are always written when card data is present — null/empty
     * values clear the field — so re-imports propagate removals made in ContentIQ.
     *
     * @param array|null $card    The card object from document.card, or null if absent.
     * @param bool       $dryRun
     * @return array<string, mixed>
     */
    private function _resolveCardFields(?array $card, bool $dryRun): array
    {
        // Card is absent or null — skip writing any card fields.
        if ($card === null || !is_array($card)) {
            return [];
        }

        $cardValues = [
            'cardTitle' => (string)($card['title'] ?? ''),
            'cardText'  => (string)($card['summary'] ?? ''), // ContentIQ calls this field "summary"
        ];

        // cardImage — from card.image via ImageImportService.importFromField().
        // Returns an array of asset IDs (or empty array if null/missing).
        $imageData = $card['image'] ?? null;
        if (is_array($imageData) && !empty($imageData['url'])) {
            $imageResult = ContentIQImporter::$plugin->images->importFromField($imageData, $dryRun);
            $cardValues['cardImage'] = ($imageResult !== null && $imageResult['id'] !== null)
                ? [$imageResult['id']]
                : [];
        } else {
            // No image, or image is null/empty — set empty array.
            $cardValues['cardImage'] = [];
        }

        return $cardValues;
    }

    /**
     * Filters a field values array to only handles that exist on the given field layout.
     *
     * Any handle that does not exist on the layout is dropped and a warning is added
     * to the result. This prevents "Setting unknown property" exceptions when SEO or
     * other configured field handles are not present on the entry type.
     *
     * @param array            $fieldValues
     * @param \craft\models\FieldLayout|null $fieldLayout
     * @param array            &$result     Result array, mutated to add warnings.
     * @return array
     */
    private function _filterToValidFields(array $fieldValues, ?FieldLayout $fieldLayout, array &$result): array
    {
        if ($fieldLayout === null) {
            return $fieldValues;
        }

        $validHandles = array_map(
            fn(FieldInterface $f) => $f->handle,
            $fieldLayout->getCustomFields(),
        );

        $filtered = [];

        foreach ($fieldValues as $handle => $value) {
            if (in_array($handle, $validHandles, true)) {
                $filtered[$handle] = $value;
            } else {
                $result['warnings'][] = "Field handle '{$handle}' not found on entry type field layout — skipped.";
                Craft::warning("ContentIQImporter: field '{$handle}' skipped (not in field layout).", __METHOD__);
            }
        }

        return $filtered;
    }

    /**
     * Builds the hero field value(s) from a ContentIQ hero block, in whichever
     * shape the destination entry type actually uses.
     *
     * §7.5 — two shapes are both live in production, on the same entry type
     * name, at different sites:
     *
     *   ContentBlock shape (pages/homepage in the Craft Starter, and fish's
     *   article): a single craft\fields\ContentBlock field (handle 'hero')
     *   wrapping the inner fields, alongside a sibling 'enableHero' lightswitch:
     *     [
     *       'enableHero' => true,
     *       'hero' => ['fields' => [
     *         'heading'       => '<h1>…</h1>',          // CKEditor
     *         'richText'      => '<h2>…</h2><p>…</p>',  // subheading + body
     *         'desktopImage'  => [$assetId],            // Assets
     *         'mobileImage'   => [$assetId],             // Assets (optional)
     *         'actionButtons' => [...],                  // Matrix of actionButton entries
     *         'heroStyle'     => 'textImage',            // Dropdown: textImage|textOnly
     *       ]],
     *     ]
     *
     *   Flat shape (article/caseStudy/team in the Craft Starter, groves, and
     *   lucia — verified against config/project/entryTypes/): the same fields
     *   sit directly on the entry under their own handles, no wrapper field,
     *   alongside their own 'enableHero' lightswitch:
     *     [
     *       'enableHero'      => true,
     *       'heroTitle'       => '<h1>…</h1>',
     *       'heroRichText'    => '<h2>…</h2><p>…</p>',
     *       'heroDesktopImage'=> [$assetId],
     *       'heroMobileImage' => [$assetId],
     *       'heroActionButtons' => [...],
     *     ]
     *
     *   heroStyle is ContentBlock-shape only (§7.5 flat sites predate the field and
     *   have no flat 'heroStyle' handle) — see _buildHeroInnerFields().
     *
     * Which shape to emit is decided by _detectHeroShape() probing
     * $targetFieldLayout — never assumed. The inner field VALUES are shape-
     * agnostic and built once by _buildHeroInnerFields(); only the handles
     * (and whether they're nested under 'hero') differ per shape.
     *
     * Returns null when no mappable inner fields are found.
     *
     * @param array            $heroBlock         ContentIQ hero block (the full block object).
     * @param bool             $dryRun
     * @param FieldLayout|null $targetFieldLayout The destination entry type's field layout.
     *                                             Null falls back to the ContentBlock shape.
     * @return array<string, mixed>|null
     */
    private function _buildHeroField(array $heroBlock, bool $dryRun, ?FieldLayout $targetFieldLayout = null): ?array
    {
        // The 'hero' ContentBlock field has its own (nested) field layout, distinct
        // from $targetFieldLayout (the page/homepage entry type's layout). heroStyle
        // lives on that nested layout, so it's resolved here and passed down —
        // see _buildHeroInnerFields()'s $heroInnerLayout param for why.
        $heroContentBlockField = $targetFieldLayout?->getFieldByHandle('hero');
        $heroInnerLayout = $heroContentBlockField instanceof ContentBlock
            ? $heroContentBlockField->getFieldLayout()
            : null;

        $innerFields = $this->_buildHeroInnerFields($heroBlock, $dryRun, $heroInnerLayout);

        if (empty($innerFields)) {
            return null;
        }

        if ($this->_detectHeroShape($targetFieldLayout) === 'flat') {
            // Flat handles directly on the entry — 'heading' → 'heroTitle', etc.
            $flatHandles = [
                'heading'       => 'heroTitle',
                'richText'      => 'heroRichText',
                'desktopImage'  => 'heroDesktopImage',
                'mobileImage'   => 'heroMobileImage',
                'actionButtons' => 'heroActionButtons',
            ];

            $flatValues = ['enableHero' => true];

            foreach ($flatHandles as $innerKey => $flatHandle) {
                if (array_key_exists($innerKey, $innerFields)) {
                    $flatValues[$flatHandle] = $innerFields[$innerKey];
                }
            }

            return $flatValues;
        }

        // hero is a ContentBlock field (handle override on heroContent).
        // enableHero is a separate lightswitch on the entry type.
        return [
            'enableHero' => true,
            'hero' => [
                'fields' => $innerFields,
            ],
        ];
    }

    /**
     * Builds the inner field values for a hero ContentBlock.
     *
     * Used by _buildHeroField for both pages and homepage (same ContentBlock).
     * Inner field handles:
     *   heading      → CKEditor (Page Heading H1, handle override)
     *   richText     → CKEditor (subheading + body)
     *   desktopImage → Assets
     *   actionButtons → Matrix of actionButton entries
     *   heroStyle    → Dropdown (textImage/textOnly)
     *
     * heroStyle is only added when $heroInnerLayout is null (caller couldn't resolve
     * the 'hero' ContentBlock field's own nested layout, e.g. flat-shape sites — see
     * _buildHeroField()) or that layout actually has the field. Older starter-based
     * sites predating heroStyle don't have it anywhere in their project config, and
     * setting an unrecognised handle on the nested ContentBlock element throws
     * yii\base\UnknownPropertyException, which Craft's own save path does NOT catch
     * (craft\fields\ContentBlock::_createContentBlockFromSerializedData() only
     * catches InvalidFieldException) — so this guard is load-bearing, not defensive
     * dead code.
     *
     * @param array            $heroBlock      Raw hero block from the JSON.
     * @param bool             $dryRun
     * @param FieldLayout|null $heroInnerLayout The 'hero' ContentBlock field's own
     *                                          (nested) field layout. Null skips the
     *                                          heroStyle guard (assume present).
     * @return array Inner fields array (empty if nothing to set).
     */
    private function _buildHeroInnerFields(array $heroBlock, bool $dryRun, ?FieldLayout $heroInnerLayout = null): array
    {
        $fields      = $heroBlock['fields'] ?? [];
        $innerFields = [];

        // heading → <h1> CKEditor HTML
        if (isset($fields['heading'])) {
            $heading = $fields['heading'];

            if (is_array($heading) && isset($heading['text'])) {
                $level = max(1, min(6, (int)($heading['level'] ?? 1)));
                $inner = isset($heading['content']) && is_array($heading['content'])
                    ? ContentIQImporter::$plugin->nodes->renderInlineContent($heading['content'])
                    : htmlspecialchars((string)$heading['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $innerFields['heading'] = "<h{$level}>{$inner}</h{$level}>";
            } elseif (is_string($heading) && $heading !== '') {
                $text = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $innerFields['heading'] = "<h1>{$text}</h1>";
            }
        }

        // richText → optional subheading + body text.
        $richTextParts = [];

        if (isset($fields['subheading'])) {
            $sub = $fields['subheading'];
            if (is_array($sub) && isset($sub['text']) && $sub['text'] !== '') {
                $subLevel = max(2, min(6, (int)($sub['level'] ?? 2)));
                $inner    = isset($sub['content']) && is_array($sub['content'])
                    ? ContentIQImporter::$plugin->nodes->renderInlineContent($sub['content'])
                    : htmlspecialchars((string)$sub['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $richTextParts[] = "<h{$subLevel}>{$inner}</h{$subLevel}>";
            }
        }

        if (isset($fields['body']) && is_string($fields['body']) && $fields['body'] !== '') {
            $escaped = htmlspecialchars($fields['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $richTextParts[] = "<p>{$escaped}</p>";
        }

        if (!empty($richTextParts)) {
            $innerFields['richText'] = implode('', $richTextParts);
        }

        // desktopImage — primary image.
        if (isset($fields['image']) && is_array($fields['image']) && !empty($fields['image']['url'])) {
            $imageResult = ContentIQImporter::$plugin->images->importFromField($fields['image'], $dryRun);
            $innerFields['desktopImage'] = ($imageResult !== null && $imageResult['id'] !== null)
                ? [$imageResult['id']]
                : [];
        }

        // mobileImage — optional mobile-specific hero image.
        if (isset($fields['mobile_image']) && is_array($fields['mobile_image']) && !empty($fields['mobile_image']['url'])) {
            $imageResult = ContentIQImporter::$plugin->images->importFromField($fields['mobile_image'], $dryRun);
            $innerFields['mobileImage'] = ($imageResult !== null && $imageResult['id'] !== null)
                ? [$imageResult['id']]
                : [];
        }

        // actionButtons — Matrix of actionButton entries with Hyper fields.
        $buttons = $fields['buttons'] ?? [];
        if (!empty($buttons) && is_array($buttons)) {
            $actionButtonsData = [];
            $btnCounter = 0;

            foreach ($buttons as $button) {
                // ContentIQ hero buttons use 'text' for the display label.
                $label = (string)($button['text'] ?? $button['label'] ?? '');
                $url   = (string)($button['url'] ?? '');

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
                                'linkValue' => LinkHelper::hyperInertUrl($url),
                                'linkText'  => $label,
                                'linkClass' => 'btn btn-primary',
                            ],
                        ],
                    ],
                ];
            }

            if (!empty($actionButtonsData)) {
                $innerFields['actionButtons'] = $actionButtonsData;
            }
        }

        // heroStyle — whitelist-validated Dropdown. Set explicitly whenever the hero
        // block carries other real content (whole-page-replace sync model — an
        // explicit default beats relying on Craft's own field default), unless the
        // destination layout is known and doesn't have the field. Skipped for a
        // genuinely empty hero block so _buildHeroField()'s empty($innerFields)
        // check still treats it as "nothing to set" (untouched), as before.
        if (
            !empty($innerFields) &&
            ($heroInnerLayout === null || $heroInnerLayout->getFieldByHandle('heroStyle') !== null)
        ) {
            $heroStyle = $fields['hero_style'] ?? null;
            $innerFields['heroStyle'] = in_array($heroStyle, ['textImage', 'textOnly'], true)
                ? $heroStyle
                : 'textImage';
        }

        return $innerFields;
    }

    /**
     * Logs detailed validation errors from an element's field values for debugging.
     *
     * Recursively surfaces errors from nested Matrix entries that would otherwise
     * only appear as a generic "Validation errors found in N nested entries" message.
     *
     * @param \craft\base\Element $element
     * @param string              $prefix
     * @return void
     */
    private function _logNestedErrors(\craft\base\Element $element, string $prefix = ''): void
    {
        foreach ($element->getErrors() as $attribute => $errors) {
            foreach ($errors as $error) {
                Craft::error("{$prefix}[{$attribute}] {$error}", __METHOD__);
            }
        }

        // Recurse into nested Matrix field entries via cached results.
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!$field instanceof \craft\fields\Matrix) {
                continue;
            }

            $handle = $field->handle;
            $value  = $element->getFieldValue($handle);

            if (!method_exists($value, 'getCachedResult')) {
                continue;
            }

            $innerEntries = $value->getCachedResult() ?? [];

            foreach ($innerEntries as $innerEntry) {
                if (!($innerEntry instanceof \craft\base\Element) || !$innerEntry->hasErrors()) {
                    continue;
                }

                $typeHandle = $innerEntry->getType()->handle ?? 'unknown';
                Craft::error("{$prefix}  Nested [{$handle}/{$typeHandle}]:", __METHOD__);
                $this->_logNestedErrors($innerEntry, $prefix . '    ');
            }
        }
    }

    /**
     * Counts how many SEO metaGlobalVars values are non-empty.
     *
     * @param array $fieldValues Filtered field values after layout check.
     * @param array $config      Merged contentiq config.
     * @return int
     */
    private function _countSeoFields(array $fieldValues, array $config): int
    {
        $fieldHandle = $config['seoField'] ?? 'seo';
        $seoValue    = $fieldValues[$fieldHandle] ?? null;

        if (!is_array($seoValue)) {
            return 0;
        }

        $count = 0;

        foreach ($seoValue['metaGlobalVars'] ?? [] as $value) {
            if ($value !== '' && $value !== null) {
                $count++;
            }
        }

        // Count image if present.
        if (!empty($seoValue['metaBundleSettings']['seoImageIds'])) {
            $count++;
        }

        return $count;
    }

    /**
     * Loads the existing top-level block map for an owner entry: payload
     * block `id` => nested Matrix element id, from contentiq_block_syncs.
     *
     * Only consulted when preserveBlockIdentity is on. Passed straight into
     * MatrixBuilder::build(), which reuses a mapped element id as a block's
     * Matrix key (UPDATE in place) instead of 'new*' (recreate) — see
     * MatrixBuilder::build()'s docblock. Never throws — a lookup failure
     * (e.g. the table not yet migrated) just falls back to the standard
     * 'new*' recreate behaviour for every block, same as today.
     *
     * @param int $ownerElementId
     * @return array<string, int>
     */
    private function _loadBlockSyncMap(int $ownerElementId): array
    {
        try {
            $rows = (new Query())
                ->select(['block_id', 'nested_element_id'])
                ->from('{{%contentiq_block_syncs}}')
                ->where(['owner_element_id' => $ownerElementId])
                ->all();
        } catch (Throwable $e) {
            Craft::warning("ContentIQImporter: failed to load block sync map for owner {$ownerElementId}: " . $e->getMessage(), __METHOD__);

            return [];
        }

        $map = [];

        foreach ($rows as $row) {
            $map[(string)$row['block_id']] = (int)$row['nested_element_id'];
        }

        return $map;
    }

    /**
     * Records the contentiq_block_syncs mapping for an owner entry after a
     * successful save, when preserveBlockIdentity is on.
     *
     * Zips MatrixBuilder::build()'s `blockKeyConsumption` (emitted Matrix key
     * => payload block id(s) it represents, in emission order) against the
     * owner's saved top-level blocks in the SAME order — Craft preserves the
     * order the Matrix keys were provided in — to recover each consumed
     * block id's real nested element id (a freshly-created 'new*' block gets
     * its real id here too, so the NEXT sync can match it). Upserts
     * (owner_element_id, block_id) => that id for each. Stale rows for block
     * ids no longer produced this run are pruned (block removed from the
     * payload, or the whole matrix was rebuilt from scratch because nothing
     * matched — e.g. an older-format payload with no stable ids at all).
     *
     * Bookkeeping only — must never fail the page. Every error is caught and
     * logged as a warning; the next sync simply rebuilds from scratch, which
     * is the safe (if less efficient) fallback this whole feature already
     * uses whenever identity can't be established.
     *
     * @param Entry  $owner               The just-saved owner entry (existing or newly created).
     * @param array<string|int, string[]> $blockKeyConsumption Emitted key => payload block id(s), from MatrixBuilder::build().
     * @param string $matrixHandle        The contentBlocks Matrix field handle.
     * @return void
     */
    private function _recordBlockSyncMap(Entry $owner, array $blockKeyConsumption, string $matrixHandle): void
    {
        if (!$owner->id) {
            return;
        }

        try {
            $savedBlocks  = $owner->getFieldValue($matrixHandle)->status(null)->all();
            $consumedSets = array_values($blockKeyConsumption);
            $seenBlockIds = [];
            $db           = Craft::$app->getDb();
            $now          = Db::prepareDateForDb(new \DateTime());

            foreach ($savedBlocks as $i => $savedBlock) {
                $blockIds = $consumedSets[$i] ?? [];

                if (empty($blockIds)) {
                    continue;
                }

                $nestedElementId = (int)$savedBlock->id;

                if ($nestedElementId <= 0) {
                    continue;
                }

                foreach ($blockIds as $blockId) {
                    $seenBlockIds[] = $blockId;

                    $exists = (new Query())
                        ->from('{{%contentiq_block_syncs}}')
                        ->where(['owner_element_id' => $owner->id, 'block_id' => $blockId])
                        ->exists();

                    if ($exists) {
                        $db->createCommand()->update(
                            '{{%contentiq_block_syncs}}',
                            ['nested_element_id' => $nestedElementId, 'dateUpdated' => $now],
                            ['owner_element_id' => $owner->id, 'block_id' => $blockId],
                        )->execute();
                    } else {
                        $db->createCommand()->insert('{{%contentiq_block_syncs}}', [
                            'owner_element_id'  => $owner->id,
                            'block_id'          => $blockId,
                            'nested_element_id' => $nestedElementId,
                            'dateCreated'       => $now,
                            'dateUpdated'       => $now,
                        ])->execute();
                    }
                }
            }

            // Prune stale rows: block ids mapped before that this run didn't
            // reproduce — the block was removed, or every block this run
            // recreated from scratch because none of them matched.
            if (!empty($seenBlockIds)) {
                $db->createCommand()->delete('{{%contentiq_block_syncs}}', [
                    'and',
                    ['owner_element_id' => $owner->id],
                    ['not in', 'block_id', $seenBlockIds],
                ])->execute();
            } else {
                $db->createCommand()->delete('{{%contentiq_block_syncs}}', [
                    'owner_element_id' => $owner->id,
                ])->execute();
            }
        } catch (Throwable $e) {
            Craft::warning("ContentIQImporter: failed to record block sync map for owner {$owner->id}: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Creates (or updates) a per-page callToActionEntry from a ContentIQ CTA
     * block. Only invoked for `fields.source === 'page'` blocks — the router,
     * _resolveCtaBlocks(), sends `'global'`-source blocks to
     * _resolveGlobalCtaEntry() instead (see docs/block-mapping.md#cta-entry-creation).
     *
     * Extracts title from the first heading node, renders richText from non-button
     * nodes, imports image if present, and builds actionButtons Matrix entries from
     * ctaButton nodes in the nodes array (or falls back to a flat fields.buttons array).
     *
     * Identity resolution (contentiq_cta_syncs, keyed on page id + block id):
     *   - $pageId (document.id) and the block's own `id` (stable within a page,
     *     survives edits/reorders) are both required to have a "stable identity".
     *     Either missing (older export without a page id, or a block with no id)
     *     falls back entirely to the pre-existing title-only behaviour below —
     *     no map row is read or written.
     *   - With a stable identity: look up contentiq_cta_syncs by (page_id,
     *     block_id). A live mapped element wins outright (update, done).
     *   - No mapping row (or a stale one whose element vanished): fall back to
     *     the legacy `->title($title)` lookup. A match is adopted — updated and
     *     recorded into contentiq_cta_syncs — so the *next* sync resolves it by
     *     id instead of title. No match creates a new entry and records it.
     *   - $claimedElementIds (by reference, one array per importPage() call)
     *     tracks every element a stable-identity block has already
     *     created/adopted/mapped this run, so a second block sharing the same
     *     (or blank) title doesn't also adopt the first block's entry — the
     *     bug this whole mechanism replaces.
     *
     * Blocks without a stable identity keep the original behaviour byte for
     * byte: same title collisions as before, no map reads/writes. This is
     * intentional back-compat for payloads exported before the block carried
     * a stable id.
     *
     * @param array    $ctaBlock          The raw CTA block from the JSON (its `id` is
     *                                    the stable block id when present).
     * @param array    &$result           Result array, mutated to add warnings/images.
     * @param bool     $dryRun
     * @param int|null $pageId            ContentIQ document.id for the page being imported.
     * @param array    &$claimedElementIds Element ids already claimed this run by a
     *                                    stable-identity CTA block (title-collision guard).
     * @return int|null The CTA entry ID, or null on failure.
     */
    private function _resolveCtaEntry(array $ctaBlock, array &$result, bool $dryRun, ?int $pageId, array &$claimedElementIds): ?int
    {
        $title = $this->_extractCtaTitle($ctaBlock);

        // The block's own stable id (e.g. "Call to Action-0") — unique within a
        // page, survives edits/reorders. Absent/empty on older exports.
        $blockId = isset($ctaBlock['id']) && $ctaBlock['id'] !== '' ? (string)$ctaBlock['id'] : null;
        $hasStableIdentity = $pageId !== null && $pageId > 0 && $blockId !== null;

        if ($dryRun) {
            // Idempotency check for dry run — prefer the stable (page_id, block_id)
            // map when available, else fall back to the title lookup.
            if ($hasStableIdentity) {
                $mappedElementId = (new Query())
                    ->select(['element_id'])
                    ->from('{{%contentiq_cta_syncs}}')
                    ->where(['page_id' => $pageId, 'block_id' => $blockId])
                    ->scalar();

                // Query::scalar() returns false when no row matches.
                if ($mappedElementId !== false) {
                    $mapped = Entry::find()->id((int)$mappedElementId)->status(null)->one();
                    if ($mapped !== null) {
                        return $mapped->id;
                    }
                }
            }

            $existing = Entry::find()->section('callsToAction')->title($title)->status(null)->one();

            return $existing?->id;
        }

        // Resolve section and entry type.
        $section = Craft::$app->entries->getSectionByHandle('callsToAction');
        if ($section === null) {
            $result['warnings'][] = "Section 'callsToAction' not found — CTA block skipped.";

            return null;
        }

        $entryType = Craft::$app->entries->getEntryTypeByHandle('callToActionEntry');
        if ($entryType === null) {
            $result['warnings'][] = "Entry type 'callToActionEntry' not found — CTA block skipped.";

            return null;
        }

        // Field values (richText/image/desktopBackgroundImage/actionButtons) —
        // shared with _resolveGlobalCtaEntry() so both CTA destinations map
        // ContentIQ's fields identically. See _buildCtaContentValues().
        $ctaFieldValues = $this->_buildCtaContentValues($ctaBlock, $dryRun, $result);

        // Idempotency — update an existing entry rather than creating a duplicate.
        //
        // 1. Stable identity present → look up contentiq_cta_syncs by
        //    (page_id, block_id). A live mapped element wins outright.
        $existing = null;

        if ($hasStableIdentity) {
            $mappedElementId = (new Query())
                ->select(['element_id'])
                ->from('{{%contentiq_cta_syncs}}')
                ->where(['page_id' => $pageId, 'block_id' => $blockId])
                ->scalar();

            // Query::scalar() returns false when no row matches.
            if ($mappedElementId !== false) {
                $existing = Entry::find()->id((int)$mappedElementId)->status(null)->one();
            }
        }

        // 2. No mapping row (or a stale one whose element vanished) — fall back
        //    to the legacy title lookup. Excludes an element another CTA block
        //    already claimed this run, so two same/blank-titled blocks don't
        //    both adopt the same entry.
        if ($existing === null) {
            $existing = Entry::find()->section('callsToAction')->title($title)->status(null)->one();

            if ($hasStableIdentity && $existing !== null && in_array($existing->id, $claimedElementIds, true)) {
                $existing = null;
            }
        }

        if ($existing !== null) {
            $existing->setFieldValues($ctaFieldValues);

            if (!Craft::$app->getElements()->saveElement($existing, false)) {
                $errors = implode(', ', $existing->getFirstErrors());
                Craft::warning("ContentIQImporter: CTA entry update failed for '{$title}': {$errors}", __METHOD__);
            }

            if ($hasStableIdentity) {
                // Adopts a title-matched entry into the map on first sync, or
                // simply refreshes dateUpdated when already mapped.
                $this->_upsertCtaMap($pageId, $blockId, $existing->id);
                $claimedElementIds[] = $existing->id;
            }

            return $existing->id;
        }

        // Create the CTA entry.
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId    = $entryType->id;
        $entry->siteId    = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title     = $title;

        $entry->setFieldValues($ctaFieldValues);

        $saved = Craft::$app->getElements()->saveElement($entry, false);

        if (!$saved) {
            $errors = implode(', ', $entry->getFirstErrors());
            $result['warnings'][] = "Failed to create CTA entry '{$title}': {$errors}";
            Craft::warning("ContentIQImporter: CTA entry save failed: {$errors}", __METHOD__);

            return null;
        }

        if ($hasStableIdentity) {
            $this->_upsertCtaMap($pageId, $blockId, $entry->id);
            $claimedElementIds[] = $entry->id;
        }

        return $entry->id;
    }

    /**
     * Extracts a CTA entry's title from its first heading node.
     *
     * Pure — no Craft dependency — shared by _resolveCtaEntry() (its dry-run
     * lookup and its real create/update path) and _resolveGlobalCtaEntry().
     *
     * @param array $ctaBlock The raw call_to_action block from the JSON.
     * @return string Defaults to 'Call to Action' when no heading node is present.
     */
    private function _extractCtaTitle(array $ctaBlock): string
    {
        $nodes = is_array($ctaBlock['fields']['nodes'] ?? null) ? $ctaBlock['fields']['nodes'] : [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'heading' && !empty($node['text'])) {
                return (string)$node['text'];
            }
        }

        return 'Call to Action';
    }

    /**
     * Classifies a call_to_action block's routing destination from its
     * ContentIQ `fields.source` label: 'page' (the pre-existing per-page
     * inline block) or 'global' (the shared footer CTA — see
     * _resolveCtaBlocks()/_resolveGlobalCtaEntry()).
     *
     * An absent `source` key defaults to 'global' — mirrors ContentiQ's own
     * default (app/Support/ProseMirrorBlockBuilder.php::serialiseCta()'s
     * `$source = 'global'` parameter default), not re-decided here. Pure — no
     * Craft dependency — covered directly by tests/run-transforms.php.
     *
     * @param array $ctaBlock The raw call_to_action block from the JSON.
     * @return string 'page' or 'global'.
     */
    private function _ctaSource(array $ctaBlock): string
    {
        $source = $ctaBlock['fields']['source'] ?? 'global';

        return $source === 'page' ? 'page' : 'global';
    }

    /**
     * Builds a CTA entry's field values (richText/image/desktopBackgroundImage/
     * actionButtons) from a ContentIQ call_to_action block.
     *
     * The content-mapping core shared by both CTA destinations:
     *   - the per-page callToActionEntry _resolveCtaEntry() creates/updates
     *     (fields.source === 'page', the original path).
     *   - the single shared entry _resolveGlobalCtaEntry() creates/updates
     *     (fields.source === 'global').
     *
     * See docs/block-mapping.md#cta-entry-creation.
     *
     * @param array $ctaBlock The raw call_to_action block from the JSON.
     * @param bool  $dryRun
     * @param array &$result  Result array — images appended (image downloads only).
     * @return array<string, mixed> Field values ready for setFieldValues().
     */
    private function _buildCtaContentValues(array $ctaBlock, bool $dryRun, array &$result): array
    {
        $fields = $ctaBlock['fields'] ?? [];
        $nodes  = is_array($fields['nodes'] ?? null) ? $fields['nodes'] : [];

        // Separate ctaButton nodes (→ actionButtons Matrix) from content nodes (→ richText).
        // ContentIQ sends buttons as ctaButton nodes in fields.nodes (same pattern as faq/price_list).
        $contentNodes      = [];
        $actionButtonsData = [];
        $btnCounter        = 0;

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'ctaButton') {
                $label = (string)($node['label'] ?? '');
                $url   = (string)($node['url'] ?? '');

                if ($label !== '' || $url !== '') {
                    $actionButtonsData['new' . (++$btnCounter)] = [
                        'type'   => 'actionButton',
                        'fields' => [
                            'actionButton' => [[
                                'type'      => 'verbb\\hyper\\links\\Url',
                                'handle'    => 'default-verbb-hyper-links-url',
                                'linkValue' => LinkHelper::hyperInertUrl($url),
                                'linkText'  => $label,
                                'linkClass' => 'btn btn-primary',
                            ]],
                        ],
                    ];
                }
            } else {
                $contentNodes[] = $node;
            }
        }

        // Build field values.
        $ctaFieldValues = [];

        // richText — render content nodes only (ctaButton nodes excluded above).
        $ctaFieldValues['richText'] = ContentIQImporter::$plugin->nodes->render($contentNodes);

        // image — import if present.
        $imageData = $fields['image'] ?? null;
        if (is_array($imageData) && !empty($imageData['url'])) {
            $imageResult = ContentIQImporter::$plugin->images->importFromField($imageData, $dryRun);
            if ($imageResult !== null && $imageResult['id'] !== null) {
                $ctaFieldValues['image'] = [$imageResult['id']];
                $result['images'][] = ['filename' => $imageResult['filename'], 'reused' => $imageResult['reused']];
            }
        }

        // desktopBackgroundImage — import background_image if present.
        $bgImageData = $fields['background_image'] ?? null;
        if (is_array($bgImageData) && !empty($bgImageData['url'])) {
            $bgResult = ContentIQImporter::$plugin->images->importFromField($bgImageData, $dryRun);
            if ($bgResult !== null && $bgResult['id'] !== null) {
                $ctaFieldValues['desktopBackgroundImage'] = [$bgResult['id']];
                $result['images'][] = ['filename' => $bgResult['filename'], 'reused' => $bgResult['reused']];
            }
        }

        // actionButtons — prefer ctaButton nodes; fall back to legacy flat fields.buttons array.
        if (empty($actionButtonsData)) {
            $actionButtonsData = $this->_buildActionButtons($fields['buttons'] ?? []);
        }

        if (!empty($actionButtonsData)) {
            $ctaFieldValues['actionButtons'] = $actionButtonsData;
        }

        return $ctaFieldValues;
    }

    /**
     * Resolves every CTA block referenced by a built matrixData array, routing
     * each by _ctaSource(). Shared by importPage() (ordinary pages/homepage)
     * and _buildBlockFieldValues() (collection children carrying blocks[]) so
     * every entry point routes CTA blocks identically — see
     * docs/import-pipeline.md.
     *
     * 'page'   — unchanged pre-existing behaviour: _resolveCtaEntry() creates/
     *            updates a per-page callToActionEntry and patches its id into
     *            the placeholder's chooseCallToAction field.
     * 'global' — the placeholder is dropped from $matrixData (and its
     *            $blockKeyConsumption entry, kept in lockstep so
     *            preserveBlockIdentity's _recordBlockSyncMap() zip stays
     *            aligned — see its docblock) entirely, so no inline block is
     *            written; a routingNotes line records the routing — never
     *            blockNotes, which is reserved for ContentIQ's own
     *            payload-authored block.notes and persists to
     *            contentiq_entry_syncs.notes (see SyncJob::run()/
     *            CpController::actionWidgetSync()) — the shared entry itself
     *            is created/updated by _resolveGlobalCtaEntry(), gated on
     *            globals consent (docs/globals.md).
     *
     * The caller is told, via the tri-state return value, what to do with the
     * page's own footerCallToAction.showGlobalCallToAction lightswitch: ON
     * when ≥1 block routed 'global' this run (wins even alongside 'page'
     * ones — a page can carry both); OFF when every CTA block routed 'page'
     * and none routed 'global' (the page supplies its own CTA, so the shared
     * footer one has nothing to add); untouched (null) when $ctaBlocks was
     * empty — a page with no CTA blocks at all must never touch a field an
     * editor may have set by hand. The OFF decision gets the same
     * routingNotes/warnings treatment as the ON one — one line, once per page
     * (not per block, since it's a single aggregate decision over every CTA
     * block rather than a property of any one of them) — see the loop below.
     *
     * Never called during a dry run — both callers already gate the whole CTA
     * step behind `!$dryRun` (dry-run reporting stops before any CTA writes;
     * see importPage()'s step 9) — the $dryRun === true guard below is
     * defensive only.
     *
     * @param array<string, array> $matrixData Built matrix data (byref — global-
     *                              routed placeholders are removed).
     * @param array<string|int, string[]> $blockKeyConsumption byref — MatrixBuilder::build()'s
     *                              emitted-key => payload-block-id(s) map, kept in
     *                              lockstep with $matrixData.
     * @param array $ctaBlocks      Raw call_to_action blocks, in MatrixBuilder::build() order.
     * @param array &$result        Result array — warnings/images/routingNotes appended.
     * @param bool  $dryRun
     * @param int|null $pageId
     * @return bool|null true (ON) when ≥1 CTA block routed 'global'; false (OFF)
     *                    when ≥1 routed 'page' and none routed 'global'; null
     *                    (leave the field untouched) when there were no CTA
     *                    blocks at all.
     */
    private function _resolveCtaBlocks(
        array &$matrixData,
        array &$blockKeyConsumption,
        array $ctaBlocks,
        array &$result,
        bool $dryRun,
        ?int $pageId,
    ): ?bool {
        if ($dryRun || empty($ctaBlocks)) {
            return null;
        }

        $ctaIndex = 0;

        // Per-page claim set — see _resolveCtaEntry()'s docblock for why this
        // exists. Only relevant to 'page'-routed blocks.
        $claimedCtaElementIds = [];
        $globalKeysToRemove   = [];
        $hasGlobalCta         = false;
        $hasPageCta           = false;

        foreach ($matrixData as $key => &$entry) {
            if (empty($entry['_cta'])) {
                continue;
            }

            unset($entry['_cta']);

            if (!isset($ctaBlocks[$ctaIndex])) {
                $ctaIndex++;
                continue;
            }

            $ctaBlock = $ctaBlocks[$ctaIndex];
            $ctaIndex++;

            if ($this->_ctaSource($ctaBlock) === 'global') {
                $hasGlobalCta         = true;
                $globalKeysToRemove[] = $key;

                $this->_resolveGlobalCtaEntry($ctaBlock, $result);

                // routingNotes only — deliberately NOT blockNotes (that key is
                // reserved for ContentIQ's own payload-authored block.notes,
                // which persist to contentiq_entry_syncs.notes/the sidebar
                // widget; a plugin-generated routing message must never land
                // there) and NOT warnings (this routing is expected behaviour,
                // not an actionable problem, and warnings drive run/page
                // status — SyncJob::run()/CpController's $hasWarnings both
                // gate on `!empty($result['warnings'])`, and any non-empty
                // warnings flips the whole run to 'warnings'). Absent
                // `fields.source` defaults to 'global' (_ctaSource()), so
                // pushing this into warnings previously stamped nearly every
                // legacy sync (anything not yet opted into 'page') as
                // warning-laden. Both result.twig and sync-result.twig render
                // routingNotes as a neutral info line alongside blockNotes and
                // warnings — see docs/import-pipeline.md. Genuine problems in
                // this path (globals locked, missing field/set — see
                // _resolveGlobalCtaEntry()) still push to $result['warnings']
                // and are untouched by this change.
                $note                    = 'Call to Action → global footer CTA';
                $existingNotes           = $result['routingNotes'] ?? '';
                $result['routingNotes']  = $existingNotes === '' ? $note : $existingNotes . "\n\n" . $note;

                continue;
            }

            $hasPageCta = true;

            $ctaEntryId = $this->_resolveCtaEntry($ctaBlock, $result, $dryRun, $pageId, $claimedCtaElementIds);

            if ($ctaEntryId !== null) {
                $entry['fields']['chooseCallToAction'] = [$ctaEntryId];
            }
        }
        unset($entry);

        foreach ($globalKeysToRemove as $key) {
            unset($matrixData[$key], $blockKeyConsumption[$key]);
        }

        if ($hasGlobalCta) {
            return true;
        }

        if ($hasPageCta) {
            // Single page-level note (not per-block, unlike the 'global'
            // branch above) — the lightswitch decision is an aggregate over
            // every CTA block on the page, not a property of any one block.
            // routingNotes only — same reasoning as the 'global' branch
            // above: never blockNotes (reserved for payload-authored
            // block.notes, which persist to contentiq_entry_syncs.notes),
            // and never warnings — this is the expected OFF outcome for any
            // page whose CTA blocks are all 'page'-source, not an actionable
            // problem. Pushing it into warnings previously flipped run/page
            // status to 'warnings' (see SyncJob::run()/CpController's
            // $hasWarnings, both gated on `!empty($result['warnings'])`) for
            // completely routine pages, burying real warnings in noise. See
            // docs/import-pipeline.md.
            $note                    = 'Page CTA → global footer CTA disabled';
            $existingNotes           = $result['routingNotes'] ?? '';
            $result['routingNotes']  = $existingNotes === '' ? $note : $existingNotes . "\n\n" . $note;

            return false;
        }

        return null;
    }

    /**
     * Creates or updates the SHARED global call-to-action entry from a
     * `fields.source === 'global'` CTA block, and relates it on
     * globalContent.globalChooseCallToAction (handles configurable — see
     * _getConfig()'s 'globalContentSet'/'globalChooseCtaField' keys).
     *
     * Gated on the same globals-consent lock GlobalsImportService/SyncJob use
     * (contentiq_globals_sync.locked — missing row ⇒ locked, the safe
     * default; see _globalsLocked()): locked skips both writes and adds a
     * per-page warning. The page's own footerCallToAction.showGlobalCallToAction
     * lightswitch is set by the CALLER regardless (_resolveCtaBlocks()) —
     * that write is page-scoped, already consented via the page's own unlock,
     * unlike this method's two site-wide writes.
     *
     * Identity is NOT contentiq_cta_syncs — that table means per-page
     * ownership (see docs/import-pipeline.md), which doesn't apply to a
     * single shared entry. Instead, globalContent's CURRENT
     * globalChooseCallToAction relation is read fresh on every call: if it
     * already points at a live entry, that entry's content is updated in
     * place; otherwise a new callsToAction entry is created (same
     * construction as the page path) and related. Two 'global'-labelled CTA
     * blocks in one run therefore naturally resolve to ONE entry, with the
     * last-processed block's content winning — see PROGRESS.md.
     *
     * @param array $ctaBlock The raw call_to_action block (fields.source === 'global').
     * @param array &$result  Result array — warnings/images appended.
     * @return void
     */
    private function _resolveGlobalCtaEntry(array $ctaBlock, array &$result): void
    {
        if ($this->_globalsLocked()) {
            $result['warnings'][] = 'Global CTA not applied — globals are locked.';

            return;
        }

        $config           = $this->_getConfig();
        $globalSetHandle  = $config['globalContentSet'] ?? 'globalContent';
        $ctaFieldHandle   = $config['globalChooseCtaField'] ?? 'globalChooseCallToAction';

        $globalSet = Craft::$app->getGlobals()->getSetByHandle($globalSetHandle);

        if ($globalSet === null) {
            $result['warnings'][] = "Global set '{$globalSetHandle}' not found — global CTA not applied.";

            return;
        }

        $globalLayout = $globalSet->getFieldLayout();

        if ($globalLayout?->getFieldByHandle($ctaFieldHandle) === null) {
            $result['warnings'][] = "Field '{$ctaFieldHandle}' not found on the '{$globalSetHandle}' global set — global CTA not applied.";

            return;
        }

        $title          = $this->_extractCtaTitle($ctaBlock);
        $ctaFieldValues = $this->_buildCtaContentValues($ctaBlock, false, $result);

        // Read the current relation fresh every call — see docblock above for
        // why this (not contentiq_cta_syncs) is this method's identity source.
        $entry = $globalSet->getFieldValue($ctaFieldHandle)->status(null)->one();

        if ($entry === null) {
            $section   = Craft::$app->entries->getSectionByHandle('callsToAction');
            $entryType = Craft::$app->entries->getEntryTypeByHandle('callToActionEntry');

            if ($section === null || $entryType === null) {
                $result['warnings'][] = "Section 'callsToAction' or entry type 'callToActionEntry' not found — global CTA not applied.";

                return;
            }

            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entry->typeId    = $entryType->id;
            $entry->siteId    = Craft::$app->getSites()->getPrimarySite()->id;
        }

        // Unlike _resolveCtaEntry()'s page path (which never renames an
        // already-matched entry — see its docblock), the global entry's
        // title IS part of its content update, both create and update.
        $entry->title = $title;
        $entry->setFieldValues($ctaFieldValues);

        if (!Craft::$app->getElements()->saveElement($entry, false)) {
            $errors = implode(', ', $entry->getFirstErrors());
            $result['warnings'][] = "Failed to save global CTA entry '{$title}': {$errors}";
            Craft::warning("ContentIQImporter: global CTA entry save failed: {$errors}", __METHOD__);

            return;
        }

        $globalSet->setFieldValue($ctaFieldHandle, [$entry->id]);

        if (!Craft::$app->getElements()->saveElement($globalSet, false)) {
            $errors = implode(', ', $globalSet->getFirstErrors());
            $result['warnings'][] = "Failed to relate global CTA entry to '{$globalSetHandle}': {$errors}";
            Craft::warning("ContentIQImporter: global CTA relation save failed: {$errors}", __METHOD__);
        }
    }

    /**
     * Whether the single globals-sync row is locked — mirrors
     * SyncJob::_globalsLocked() exactly (same table, same missing-row-is-
     * locked default) so the CTA global-routing gate always agrees with the
     * Sync screen's globals consent, regardless of which entry point
     * (SyncJob, CP upload, CLI, sidebar widget) is importing — see
     * docs/globals.md.
     *
     * @return bool
     */
    private function _globalsLocked(): bool
    {
        $row = (new Query())
            ->select(['locked'])
            ->from('{{%contentiq_globals_sync}}')
            ->one();

        // craft\db\Query::one() returns null when no row matches — globals
        // have never been consented to. Treat as locked (the safe default).
        if ($row === null) {
            return true;
        }

        return (bool)$row['locked'];
    }

    /**
     * Builds the footerCallToAction.showGlobalCallToAction field value for a
     * page whose CTA blocks this run resolved a lightswitch intent for — see
     * _resolveCtaBlocks()'s decision table ($showGlobal is its tri-state
     * return value narrowed to bool by the caller, which never calls this
     * method at all for the null/"no CTA blocks" case).
     *
     * Read-modify-write is free here: craft\fields\ContentBlock's own save
     * path (ContentBlock::_createContentBlockFromSerializedData()) fetches
     * the entry's EXISTING nested content-block element and only overwrites
     * the handles present in the 'fields' array returned below — every
     * sibling field (notably callToActionLayout) is left exactly as the
     * editor set it, so this deliberately omits every handle but
     * showGlobalCallToAction rather than reading/re-writing it.
     *
     * Defensively probes both layers before writing — mirrors the 1.22.0
     * staging-field lesson and _buildHeroInnerFields()'s heroStyle guard: an
     * unrecognised handle inside a ContentBlock's nested 'fields' array
     * throws yii\base\UnknownPropertyException, which Craft's own save path
     * does NOT catch (only InvalidFieldException) — so this is load-bearing,
     * not defensive dead code.
     *   1. The outer field (config['footerCtaField'], default
     *      'footerCallToAction') must exist on $ownerFieldLayout and be a
     *      ContentBlock field.
     *   2. Its own nested field layout must have the inner handle
     *      (config['footerCtaShowGlobalField'], default 'showGlobalCallToAction').
     * Either miss returns null (nothing written) — never a crash, per the
     * plugin's "absent field ⇒ skip, don't fail" doctrine
     * (docs/import-pipeline.md#field-layout-filtering-before-setfieldvalues).
     * Whether it also warns is asymmetric on $showGlobal: turning the switch
     * ON and finding nothing to turn on is worth a per-page warning (content
     * that should have routed to the footer silently didn't) — turning it
     * OFF and finding nothing is silent, because there's nothing to disable
     * and collection children (case studies/team) routinely carry
     * 'page'-source CTA blocks with no footerCallToAction field at all;
     * warning on every one of those would be spam, not signal.
     *
     * Takes $config as a parameter (the caller's already-resolved _getConfig()
     * result — same convention as _resolveSeoFields()) rather than calling
     * _getConfig() itself, so this stays testable without a Craft bootstrap
     * (see tests/run-transforms.php) — _getConfig() needs Craft::$app.
     *
     * @param \craft\models\FieldLayout|null $ownerFieldLayout The destination entry type's field layout.
     * @param array $config Merged contentiq config (defaults + project overrides).
     * @param array &$result Result array — a warning is appended only when $showGlobal
     *                        is true and the field/handle is missing (see above).
     * @param bool $showGlobal The lightswitch value to write — true (ON) when the
     *                        page carried a 'global'-source CTA block this run,
     *                        false (OFF) when it carried only 'page'-source ones.
     * @return array<string, mixed>|null
     */
    private function _buildFooterGlobalCtaField(?FieldLayout $ownerFieldLayout, array $config, array &$result, bool $showGlobal): ?array
    {
        $footerCtaHandle  = $config['footerCtaField'] ?? 'footerCallToAction';
        $showGlobalHandle = $config['footerCtaShowGlobalField'] ?? 'showGlobalCallToAction';

        $footerCtaField = $ownerFieldLayout?->getFieldByHandle($footerCtaHandle);

        if (!$footerCtaField instanceof ContentBlock) {
            if ($showGlobal) {
                $result['warnings'][] = "Field '{$footerCtaHandle}' not found on this entry type — global CTA lightswitch not set.";
            }

            return null;
        }

        $footerCtaInnerLayout = $footerCtaField->getFieldLayout();

        if ($footerCtaInnerLayout?->getFieldByHandle($showGlobalHandle) === null) {
            if ($showGlobal) {
                $result['warnings'][] = "Field '{$showGlobalHandle}' not found on '{$footerCtaHandle}' — global CTA lightswitch not set.";
            }

            return null;
        }

        return [
            $footerCtaHandle => [
                'fields' => [
                    $showGlobalHandle => $showGlobal,
                ],
            ],
        ];
    }

    /**
     * Upserts a contentiq_cta_syncs row ((page_id, block_id) → element_id).
     *
     * @param int    $pageId
     * @param string $blockId
     * @param int    $elementId
     * @return void
     */
    private function _upsertCtaMap(int $pageId, string $blockId, int $elementId): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $db  = Craft::$app->getDb();

        $exists = (new Query())
            ->from('{{%contentiq_cta_syncs}}')
            ->where(['page_id' => $pageId, 'block_id' => $blockId])
            ->exists();

        if ($exists) {
            $db->createCommand()->update(
                '{{%contentiq_cta_syncs}}',
                ['element_id' => $elementId, 'dateUpdated' => $now],
                ['page_id' => $pageId, 'block_id' => $blockId],
            )->execute();

            return;
        }

        $db->createCommand()->insert('{{%contentiq_cta_syncs}}', [
            'page_id'     => $pageId,
            'block_id'    => $blockId,
            'element_id'  => $elementId,
            'dateCreated' => $now,
            'dateUpdated' => $now,
        ])->execute();
    }

    /**
     * Builds the actionButtons Matrix field data from a ContentIQ flat buttons array.
     *
     * Each button becomes an actionButton entry with a Hyper actionButton field.
     * Buttons where both label and URL are empty are skipped.
     *
     * @param array $buttons [{label, url}, ...]
     * @return array<string, array> Matrix data keyed by 'new1', 'new2', etc.
     */
    private function _buildActionButtons(array $buttons): array
    {
        $matrixData = [];
        $counter    = 0;

        foreach ($buttons as $button) {
            if (!is_array($button)) {
                continue;
            }

            $label = (string)($button['label'] ?? '');
            $url   = (string)($button['url'] ?? '');

            if ($label === '' && $url === '') {
                continue;
            }

            $matrixData['new' . (++$counter)] = [
                'type'   => 'actionButton',
                'fields' => [
                    'actionButton' => [[
                        'type'      => 'verbb\\hyper\\links\\Url',
                        'handle'    => 'default-verbb-hyper-links-url',
                        'linkValue' => LinkHelper::hyperInertUrl($url),
                        'linkText'  => $label,
                        'linkClass' => 'btn btn-primary',
                    ]],
                ],
            ];
        }

        return $matrixData;
    }

    /**
     * Builds the block-driven portion of an entry's field values: Matrix field
     * data from `blocks`, the hero ContentBlock field, and CTA routing (see
     * _resolveCtaBlocks()) for call_to_action blocks — per-page
     * callToActionEntry creation/patching for `fields.source === 'page'`
     * blocks, footerCallToAction/global-entry routing for `'global'` ones
     * (and footerCallToAction.showGlobalCallToAction forced OFF when only
     * `'page'`-source blocks were found — see _resolveCtaBlocks()'s tri-state
     * return value).
     *
     * Shared by standard pages and by collection children that carry a
     * non-empty `blocks[]` (the routing decision — whether to call this at all
     * — is made by the caller; see _importCollectionChild()). Mutates $result
     * with blocks/images/cardRefs/warnings/blockNotes taken from the actual
     * MatrixBuilder run, so the import/sync report reflects what was really
     * written rather than the _emptyResult() skeleton's placeholders.
     * routingNotes is mutated separately, by _resolveCtaBlocks() below — see
     * its docblock for why routing messages never land in blockNotes.
     *
     * CTA routing is skipped when $dryRun is true — a dry run must not write
     * callToActionEntry rows or the global set (matches the page path's
     * previous behaviour, where the whole CTA step sat after the dry-run
     * early return).
     *
     * @param array            $data              Decoded top-level JSON object for the page (or collection child).
     * @param bool             $dryRun            If true, skips image downloads and CTA entry writes.
     * @param array            &$result           Result array, mutated with blocks/images/cardRefs/warnings/blockNotes/routingNotes.
     * @param FieldLayout|null $targetFieldLayout The destination entry type's field layout — drives the
     *                                             hero shape probe (§7.5; see _detectHeroShape()). Pages,
     *                                             homepage, and collection children each pass their own
     *                                             resolved entry type's layout; null falls back to the
     *                                             ContentBlock shape.
     * @return array<string, mixed> Field values keyed by the Matrix field handle
     *         (config['matrixField']) and, when a hero block is present, either
     *         'enableHero'/'hero' (ContentBlock shape) or 'enableHero'/'heroTitle'/etc
     *         (flat shape — see _buildHeroField()). Also carries 'showContentBlocks' => true
     *         whenever the Matrix is non-empty — harmless where the handle doesn't exist on
     *         the target field layout, since _filterToValidFields() drops unknown handles.
     */
    private function _buildBlockFieldValues(array $data, bool $dryRun, array &$result, ?FieldLayout $targetFieldLayout = null): array
    {
        $config = $this->_getConfig();
        $slug   = $result['slug'];

        ContentIQImporter::$plugin->matrixBuilder->prepare($config);

        $blocks = $data['blocks'] ?? [];

        // Extract hero block before passing to MatrixBuilder.
        // CTA blocks pass through MatrixBuilder (it skips them with a report)
        // and are resolved separately below, once it's known whether this is
        // a dry run.
        $heroBlock     = null;
        $ctaBlocks     = [];
        $contentBlocks = [];
        foreach ($blocks as $block) {
            $blockType = $block['type'] ?? '';
            if ($blockType === 'hero') {
                $heroBlock = $block;
            } else {
                if ($blockType === 'call_to_action') {
                    $ctaBlocks[] = $block;
                }
                $contentBlocks[] = $block;
            }
        }

        // Collect block notes for the sidebar widget.
        $noteLines = [];
        foreach ($blocks as $block) {
            $note = $block['notes'] ?? '';
            if (is_string($note) && trim($note) !== '') {
                $blockType = $block['type'] ?? 'unknown';
                $acronyms = ['usp' => 'USP', 'faq' => 'FAQ', 'cta' => 'CTA'];
                $blockLabel = ucwords(str_replace('_', ' ', $blockType));
                $blockLabel = strtr($blockLabel, array_combine(
                    array_map('ucfirst', array_keys($acronyms)),
                    array_values($acronyms),
                ));
                $noteLines[] = $blockLabel . "\n" . trim($note);
            }
        }
        $result['blockNotes'] = implode("\n\n", $noteLines);

        $built = ContentIQImporter::$plugin->matrixBuilder->build($contentBlocks, $dryRun, $slug);

        $result['blocks']   = $built['blockReport'];
        $result['images']   = $built['imageReport'];
        $result['cardRefs'] = $built['cardRefs'] ?? [];  // Deferred card refs for SyncJob pass 2
        $result['warnings'] = array_merge($result['warnings'], $built['warnings'] ?? []);

        // Both pages and homepage use the same hero ContentBlock field; a
        // collection child's blocks[] can carry one too. §7.5 — article/
        // caseStudy/team use a *flat* handle shape instead of the ContentBlock
        // shape on some sites (see _buildHeroField()'s shape probe).
        $heroData = $heroBlock !== null
            ? $this->_buildHeroField($heroBlock, $dryRun, $targetFieldLayout)
            : null;

        // Resolve CTA blocks — same routing as importPage() (see its step 10):
        // 'page'-source blocks create/update a per-page callToActionEntry and
        // patch the matrixData placeholder; 'global'-source blocks drop the
        // placeholder entirely and route to the shared footer CTA instead.
        // Skipped on a dry run — no writes.
        $matrixData            = $built['matrixData'];
        $blockKeyConsumption   = $built['blockKeyConsumption'] ?? [];
        $footerGlobalCtaIntent = null;

        if (!$dryRun) {
            // Stable ContentIQ page id — same contract as the page path (see
            // importPage() step 10).
            $pageId = isset($data['document']['id']) ? (int)$data['document']['id'] : null;

            $footerGlobalCtaIntent = $this->_resolveCtaBlocks(
                $matrixData,
                $blockKeyConsumption,
                $ctaBlocks,
                $result,
                $dryRun,
                $pageId,
            );
        }

        $matrixHandle = $config['matrixField'] ?? 'contentBlocks';

        $fieldValues = array_merge(
            [$matrixHandle => $matrixData],
            $heroData ?? [],
            $footerGlobalCtaIntent !== null
                ? ($this->_buildFooterGlobalCtaField($targetFieldLayout, $config, $result, $footerGlobalCtaIntent) ?? [])
                : [],
        );

        // §7.4 — article/caseStudy gate content-block rendering behind a
        // "Content Blocks" lightswitch (handle: showContentBlocks) that
        // defaults to false and is hidden in the CP behind a condition rule.
        // Without this, blocks import correctly and render nothing. team has
        // no such gate (its elementCondition is null), but writing this
        // unconditionally is harmless there too — _filterToValidFields() drops
        // handles the target field layout doesn't have.
        if (!empty($matrixData)) {
            $fieldValues['showContentBlocks'] = true;
        }

        return $fieldValues;
    }

    /**
     * §7.6.1 guard 2 / §7.7 — pure warning-message builder for the "this write
     * is about to clear/replace previously non-empty content" checks.
     *
     * Kept separate from the Craft calls that fetch $existing's current field
     * values (a couple of getFieldValue() calls in _importCollectionChild())
     * so the actual "was this destructive" judgement is unit-testable without
     * a Craft bootstrap (see tests/run-transforms.php).
     *
     * @param bool        $contentWasNonEmpty Whether contentField held content before this write.
     * @param bool        $headingWasNonEmpty Whether headingField held content before this write.
     * @param int         $existingBlockCount How many blocks the target Matrix field held before this write.
     * @param string      $contentFieldHandle
     * @param string|null $headingFieldHandle
     * @param string      $matrixHandle
     * @param bool        $contentReplacedWithLeftover Whether contentField is being written with
     *                    non-empty leftover (unmarked) ProseMirror content this run, rather than
     *                    cleared to '' — changes the contentField warning's wording only.
     * @return string[]
     */
    private function _buildBlockOwnershipWarnings(
        bool $contentWasNonEmpty,
        bool $headingWasNonEmpty,
        int $existingBlockCount,
        string $contentFieldHandle,
        ?string $headingFieldHandle,
        string $matrixHandle,
        bool $contentReplacedWithLeftover,
    ): array {
        $warnings = [];

        if ($contentWasNonEmpty) {
            $warnings[] = $contentReplacedWithLeftover
                ? "Blocks now own this page — replacing previously non-empty '{$contentFieldHandle}' field with leftover (unmarked) content."
                : "Blocks now own this page — clearing previously non-empty '{$contentFieldHandle}' field.";
        }

        if ($headingWasNonEmpty && $headingFieldHandle !== null) {
            $warnings[] = "Blocks now own this page's heading — clearing previously non-empty '{$headingFieldHandle}' field.";
        }

        if ($existingBlockCount > 0) {
            $warnings[] = "Content Blocks field '{$matrixHandle}' already holds {$existingBlockCount} block(s) not written by this importer "
                . "— this sync will replace them wholesale with new element IDs.";
        }

        return $warnings;
    }

    /**
     * §7.6/§7.6.1 (rulings O2/O4) — builds the contentField/headingField
     * portion of a collection child's field values.
     *
     * Pure decision, deliberately separated from the Craft-touching parts of
     * _importCollectionChild() so it's unit-testable without a Craft bootstrap
     * (see tests/run-transforms.php):
     *
     *   - blocks present (O2/O4): the block(s) own the page, so headingField
     *     (when configured) is set to '' EXPLICITLY — not omitted, and
     *     extractHeading() is never run here — so a prior content-only
     *     sync's stale heading can't linger and duplicate the block's own
     *     heading (two <h1>s). contentField depends on whether `$content`
     *     carries non-empty leftover prose (top-level ProseMirror nodes not
     *     marked up as a block): if so, that leftover renders wholesale
     *     (unstripped — blocks own the heading, not this branch) into
     *     contentField; otherwise contentField is set to '' EXPLICITLY too,
     *     same as before this leftover-content contract existed (this is
     *     also what happens against an older ContentiQ deployment that still
     *     only ever sends blocks[] with no `content` key at all).
     *     _filterToValidFields() makes the write harmless where a handle is
     *     absent from the target layout.
     *   - blocks absent: unchanged pre-§7.1 behaviour — optionally lift the
     *     first H1 out of the ProseMirror doc into headingField, then render
     *     the (possibly H1-stripped) doc into contentField.
     *
     * @param array       $content             The document.content ProseMirror doc, or the blocks[]
     *                                         leftover-content doc (or [] when neither applies).
     * @param bool        $hasBlocks           Whether this collection child carries non-empty blocks[].
     * @param string      $contentFieldHandle
     * @param string|null $headingFieldHandle
     * @return array<string, string>
     */
    private function _buildCollectionChildContentFields(
        array $content,
        bool $hasBlocks,
        string $contentFieldHandle,
        ?string $headingFieldHandle,
    ): array {
        if ($hasBlocks) {
            $fieldValues = [
                $contentFieldHandle => !empty($content)
                    ? ContentIQImporter::$plugin->nodes->renderDocument($content)
                    : '',
            ];

            if ($headingFieldHandle !== null) {
                $fieldValues[$headingFieldHandle] = '';
            }

            return $fieldValues;
        }

        $fieldValues = [];

        if ($headingFieldHandle !== null) {
            $extracted = ContentIQImporter::$plugin->nodes->extractHeading($content, 1);

            if (($extracted['text'] ?? '') !== '') {
                $fieldValues[$headingFieldHandle] = $extracted['text'];
                $content = $extracted['doc'];
            }
        }

        $fieldValues[$contentFieldHandle] = ContentIQImporter::$plugin->nodes->renderDocument($content);

        return $fieldValues;
    }

    /**
     * §7.5 shape probe — inspects the destination entry type's field layout to
     * decide which hero shape to emit, rather than assuming either.
     *
     * - A field handled 'hero' that is actually a craft\fields\ContentBlock
     *   instance → 'contentblock' shape.
     * - Otherwise, a field handled 'heroTitle' present anywhere on the layout
     *   → 'flat' shape (article/caseStudy/team).
     * - Neither found (or no layout given, e.g. a caller that hasn't been
     *   updated to pass one) → falls back to 'contentblock', matching this
     *   method's pre-§7.5 hardcoded behaviour. _filterToValidFields() drops
     *   the resulting handles harmlessly if the entry type truly has neither.
     *
     * @param FieldLayout|null $fieldLayout
     * @return string 'contentblock'|'flat'
     */
    private function _detectHeroShape(?FieldLayout $fieldLayout): string
    {
        if ($fieldLayout === null) {
            return 'contentblock';
        }

        if ($fieldLayout->getFieldByHandle('hero') instanceof ContentBlock) {
            return 'contentblock';
        }

        if ($fieldLayout->getFieldByHandle('heroTitle') !== null) {
            return 'flat';
        }

        return 'contentblock';
    }

    /**
     * Whether a fetched field value counts as "non-empty" for the guard-2
     * warning checks above. Handles the shapes _importCollectionChild() reads
     * off an existing entry: CKEditor field data (HtmlFieldData — stringifies
     * to raw HTML), PlainText strings, and arrays (Assets/Matrix-ish values,
     * though Matrix is counted separately via ->count()).
     *
     * Pure — no Craft dependency — unit-testable directly.
     *
     * @param mixed $value
     * @return bool
     */
    private function _isFieldValueNonEmpty(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return !empty($value);
        }

        return trim(strip_tags((string)$value)) !== '';
    }
}
