<?php

namespace matrixcreate\contentiqimporter\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\elements\Entry;
use matrixcreate\contentiqimporter\ContentIQImporter;
use yii\console\ExitCode;

/**
 * ContentIQ import console controller.
 *
 * Usage:
 *   php craft contentiq-importer/import --file=export.json
 *   php craft contentiq-importer/import --file=export.json --dry-run
 *   php craft contentiq-importer/import --file=export.json --verbose
 *   php craft contentiq-importer/import --file=export.json --force
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class ImportController extends Controller
{
    /**
     * @inheritdoc
     */
    public $defaultAction = 'import';

    // Public Properties
    // =========================================================================

    /**
     * @var string|null Path to the ContentIQ JSON export file.
     */
    public ?string $file = null;

    /**
     * @var bool Whether to run in dry-run mode (validates and reports, writes nothing).
     */
    public bool $dryRun = false;

    /**
     * @var bool Whether to output verbose block-by-block and image logging.
     */
    public bool $verbose = false;

    /**
     * @var bool Whether to bypass the entry-lock check. Defaults to false —
     *           locked entries are skipped unless this is explicitly set, so a
     *           CLI import can't silently clobber a locked entry.
     */
    public bool $force = false;

    /**
     * Entry ID from the most recent _runSinglePage call (used for hierarchy).
     *
     * @var int|null
     */
    private ?int $_lastEntryId = null;

    /**
     * Deferred card ref sets from the most recent _runSinglePage call
     * (collected for pass 2 resolution).
     *
     * @var array
     */
    private array $_lastCardRefs = [];

    /**
     * Per-page results accumulated across the current invocation (single
     * page or batch) — same shape ImportService::importPage() returns.
     * Written to a contentiq_import_runs record at the end of the run (see
     * Fix D1 — console imports previously left no audit trail) via
     * {@see self::_saveRunFromResults()}. Fresh for every process, so no
     * explicit reset between invocations is needed.
     *
     * @var array
     */
    private array $_runResults = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'file';
        $options[] = 'dryRun';
        $options[] = 'verbose';
        $options[] = 'force';

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        $aliases = parent::optionAliases();
        $aliases['f'] = 'file';
        $aliases['n'] = 'dryRun';
        $aliases['v'] = 'verbose';

        return $aliases;
    }

    /**
     * Import a ContentIQ JSON export into Craft CMS as draft entries.
     *
     * Detects single-page vs batch format from the JSON shape:
     * - Top-level `blocks` array = single page
     * - Top-level `pages` array = batch (loops single-page pipeline)
     *
     * @return int
     */
    public function actionImport(): int
    {
        App::maxPowerCaptain();

        if ($this->file === null) {
            $this->failure('`--file` is required. Usage: craft contentiq/import --file=export.json');

            return ExitCode::USAGE;
        }

        // Resolve the file path to absolute before chdir() changes the CWD.
        $file = realpath($this->file) ?: $this->file;

        if (!is_file($file)) {
            $this->failure("File not found: `{$this->file}`");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Local filesystem paths in project.yaml are relative to the web root
        // (e.g. "assets/cms/images"). Web requests execute with CWD = web/, so
        // paths resolve correctly. CLI runs from the project root, so we must
        // manually change to the webroot to match.
        chdir(Craft::getAlias('@webroot'));

        $json = file_get_contents($file);

        if ($json === false) {
            $this->failure("Could not read file: `$file`");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->failure('Invalid JSON: ' . json_last_error_msg());

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->dryRun) {
            $this->stdout('[DRY RUN] ' . PHP_EOL, Console::FG_YELLOW, Console::BOLD);
        }

        $importService = ContentIQImporter::$plugin->imports;

        // Batch format has a top-level 'pages' array.
        if (isset($data['pages']) && is_array($data['pages'])) {
            return $this->_runBatch($data['pages'], $importService);
        }

        // Single-page format has 'document' and 'blocks' at the top level.
        $exitCode = $this->_runSinglePage($data, $importService);

        // PASS 2: resolve deferred card references (single page — DB lookups only).
        if (!$this->dryRun && $this->_lastEntryId !== null && !empty($this->_lastCardRefs)) {
            $cardWarnings = $importService->resolveCardReferences(
                [$this->_lastEntryId => array_values($this->_lastCardRefs)],
                [],
            );
            $this->_printCardRefWarnings($cardWarnings);
            $this->_mergeCardWarnings($cardWarnings);
        }

        // Fix D1: record this console import in the audit trail, same as the
        // other content-mutating entry points (upload/import, sync, widget).
        $this->_saveRunFromResults(basename($file));

        return $exitCode;
    }

    // Private Methods
    // =========================================================================

    /**
     * Runs a batch import by looping over each page through the single-page pipeline.
     *
     * @param array[] $pages
     * @param \matrixcreate\contentiqimporter\services\ImportService $importService
     * @return int
     */
    private function _runBatch(array $pages, $importService): int
    {
        $this->stdout(sprintf("Batch import: %d pages\n", count($pages)));

        $exitCode      = ExitCode::OK;
        $slugToEntryId = [];
        $allCardRefs   = [];  // Deferred card ref sets keyed by entry ID, for pass 2

        // Resolve section for structure positioning.
        $config        = Craft::$app->config->getConfigFromFile('contentiq');
        $sectionHandle = $config['section'] ?? 'pages';
        $section       = Craft::$app->entries->getSectionByHandle($sectionHandle);
        $structureId   = $section?->structureId;
        $structures    = Craft::$app->getStructures();

        foreach ($pages as $i => $pageData) {
            $this->stdout(sprintf("\n[%d/%d] ", $i + 1, count($pages)), Console::FG_GREY);
            $result = $this->_runSinglePage($pageData, $importService);

            if ($result !== ExitCode::OK) {
                $exitCode = ExitCode::UNSPECIFIED_ERROR;
            }

            // Collect the slug map and deferred card refs for pass 2.
            if (!$this->dryRun && $this->_lastEntryId !== null) {
                $slug = $pageData['document']['slug'] ?? '';
                if ($slug !== '') {
                    $slugToEntryId[$slug] = $this->_lastEntryId;
                }
                if (!empty($this->_lastCardRefs)) {
                    $allCardRefs[$this->_lastEntryId] = array_values($this->_lastCardRefs);
                }
            }

            // Handle hierarchy after import.
            $isHomepage = (bool)($pageData['document']['is_homepage'] ?? false);

            if (!$this->dryRun && $structureId !== null && !$isHomepage) {
                $slug       = $pageData['document']['slug'] ?? '';
                $parentSlug = $pageData['document']['parent_slug'] ?? null;
                $entryId    = $this->_lastEntryId;

                if ($entryId !== null && $slug !== '') {
                    $slugToEntryId[$slug] = $entryId;
                }

                if ($entryId !== null) {
                    $entry = Entry::find()->id($entryId)->status(null)->one();

                    if ($entry !== null) {
                        try {
                            if ($parentSlug !== null && $parentSlug !== '') {
                                // Try current-batch map first, then fall back to a Craft query
                                // so re-imports correctly place children under existing parents.
                                $parentId = $slugToEntryId[$parentSlug] ?? null;

                                if ($parentId === null) {
                                    $parentEntry = Entry::find()
                                        ->section($sectionHandle)
                                        ->slug($parentSlug)
                                        ->status(null)
                                        ->one();
                                    $parentId = $parentEntry?->id;
                                }

                                if ($parentId !== null) {
                                    $structures->append($structureId, $entry, $parentId);
                                    $this->stdout("  Parent: {$parentSlug}\n", Console::FG_GREEN);
                                } else {
                                    $structures->appendToRoot($structureId, $entry);
                                    $this->stdout("  Parent: '{$parentSlug}' not found — saved at root\n", Console::FG_YELLOW);
                                }
                            } else {
                                $structures->appendToRoot($structureId, $entry);
                            }
                        } catch (\Throwable $e) {
                            $this->stdout("  Structure: failed — {$e->getMessage()}\n", Console::FG_YELLOW);
                        }
                    }
                }
            }
        }

        // PASS 2: resolve deferred card references now the slug map is complete.
        if (!$this->dryRun && !empty($allCardRefs)) {
            $this->stdout("\nResolving card references…\n", Console::BOLD);
            $cardWarnings = $importService->resolveCardReferences($allCardRefs, $slugToEntryId);
            $this->_printCardRefWarnings($cardWarnings);
            $this->_mergeCardWarnings($cardWarnings);
        }

        // Fix D1: record this console import in the audit trail, same as the
        // other content-mutating entry points (upload/import, sync, widget).
        $this->_saveRunFromResults(basename((string)$this->file));

        return $exitCode;
    }

    /**
     * Prints pass-2 card reference warnings keyed by owner entry ID.
     *
     * @param array<int, string[]> $warningsByEntry
     */
    private function _printCardRefWarnings(array $warningsByEntry): void
    {
        foreach ($warningsByEntry as $entryId => $warnings) {
            foreach ($warnings as $warning) {
                $this->stdout("  Cards (entry {$entryId}): {$warning}\n", Console::FG_YELLOW);
            }
        }
    }

    /**
     * Merges pass-2 card reference warnings into the matching $_runResults
     * entry (by entryId) — same pattern CpController/SyncJob use — so a
     * page's audit-trail row reflects pass-2 warnings too, not just pass 1.
     *
     * @param array<int, string[]> $warningsByEntry
     * @return void
     */
    private function _mergeCardWarnings(array $warningsByEntry): void
    {
        foreach ($warningsByEntry as $entryId => $warnings) {
            if (empty($warnings)) {
                continue;
            }

            foreach ($this->_runResults as $i => $r) {
                if (($r['entryId'] ?? null) === $entryId) {
                    array_push($this->_runResults[$i]['warnings'], ...$warnings);
                    break;
                }
            }
        }
    }

    /**
     * Writes a contentiq_import_runs record summarising the page results
     * accumulated in $_runResults for this invocation (Fix D1 — console
     * imports previously left no audit trail). A no-op in dry-run mode,
     * since nothing was actually written to Craft.
     *
     * @param string $filename
     * @return void
     */
    private function _saveRunFromResults(string $filename): void
    {
        if ($this->dryRun) {
            return;
        }

        $hasErrors   = false;
        $hasWarnings = false;
        $imageCount  = 0;

        foreach ($this->_runResults as $r) {
            if (!($r['success'] ?? false)) {
                $hasErrors = true;
            }
            if (!empty($r['warnings'] ?? [])) {
                $hasWarnings = true;
            }
            $imageCount += count($r['images'] ?? []);
        }

        $status = $hasErrors ? 'errors' : ($hasWarnings ? 'warnings' : 'success');

        $this->_saveRun(
            filename:   $filename,
            type:       'console',
            pageCount:  count($this->_runResults),
            imageCount: $imageCount,
            status:     $status,
            result:     $this->_runResults,
        );
    }

    /**
     * Saves an import run to the history table. Mirrors
     * CpController::_saveRun() — kept as a separate copy here since console
     * and web controllers don't share a common base.
     *
     * @param string $filename
     * @param string $type
     * @param int    $pageCount
     * @param int    $imageCount
     * @param string $status
     * @param array  $result
     * @return int The inserted row ID.
     */
    private function _saveRun(
        string $filename,
        string $type,
        int $pageCount,
        int $imageCount,
        string $status,
        array $result,
    ): int {
        $db  = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $db->createCommand()->insert('{{%contentiq_import_runs}}', [
            'importedBy'  => Craft::$app->getUser()->getId(),
            'filename'    => $filename,
            'type'        => $type,
            'pageCount'   => $pageCount,
            'imageCount'  => $imageCount,
            'status'      => $status,
            'result'      => Json::encode($result),
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid'         => StringHelper::UUID(),
        ])->execute();

        return (int)$db->getLastInsertID();
    }

    /**
     * Runs the import pipeline for a single page and renders output.
     *
     * @param array $data
     * @param \matrixcreate\contentiqimporter\services\ImportService $importService
     * @return int
     */
    private function _runSinglePage(array $data, $importService): int
    {
        // Enforce entry locks unless --force. Missing sync row for an existing
        // entry is treated as locked (same default as the Sync UI / SyncJob) —
        // a hand-built entry colliding with a synced slug/section is never
        // silently overwritten by a CLI import.
        if (!$this->force) {
            $existingEntry = $importService->findExistingEntry($data);

            if ($existingEntry !== null) {
                $syncRow = (new Query())
                    ->select(['locked'])
                    ->from('{{%contentiq_entry_syncs}}')
                    ->where(['element_id' => $existingEntry->id])
                    ->one();

                // craft\db\Query::one() returns null when no row matches.
                $isLocked = $syncRow === null ? true : (bool)$syncRow['locked'];

                if ($isLocked) {
                    $slug = $data['document']['slug'] ?? '';

                    $this->stdout("Page: {$slug}\n", Console::BOLD);
                    $this->stdout("  Skipped — entry is locked. Use --force to override.\n", Console::FG_YELLOW);

                    // Asset filing never touches entry content, so it's
                    // exempt from the lock — file this page's
                    // assets[]/files[] (and relocate under 'sitemap') even
                    // though nothing else runs for it. See
                    // ImportService::importPageAssetsOnly().
                    $assetsOnly = $importService->importPageAssetsOnly($data, $this->dryRun);

                    if ($this->_hasAnyAssetActivity($assetsOnly['pageAssets']) || $this->_hasAnyAssetActivity($assetsOnly['pageFiles'])) {
                        $this->stdout('  Page assets: ' . $this->_formatAssetCounts($assetsOnly['pageAssets']) . "\n");
                        $this->stdout('  Page files: ' . $this->_formatAssetCounts($assetsOnly['pageFiles']) . "\n");
                    }

                    foreach ($assetsOnly['warnings'] as $warning) {
                        $this->warning($warning);
                    }

                    $this->_lastEntryId  = $existingEntry->id;
                    $this->_lastCardRefs = [];

                    // Fix D1: record the skip in the run's audit trail, same as
                    // the locked-skip branches in CpController/SyncJob.
                    $this->_runResults[] = [
                        'success'       => true,
                        'slug'          => $slug,
                        'title'         => $data['document']['title'] ?? $slug,
                        'entryId'       => $existingEntry->id,
                        'entryFound'    => true,
                        'seoFieldCount' => 0,
                        'blocks'        => [],
                        'images'        => [],
                        'pageAssets'    => $assetsOnly['pageAssets'],
                        'pageFiles'     => $assetsOnly['pageFiles'],
                        'warnings'      => array_merge(['Skipped — entry is locked.'], $assetsOnly['warnings']),
                        'error'         => null,
                    ];

                    return ExitCode::OK;
                }
            }
        }

        $result = $importService->importPage($data, $this->dryRun, $this->verbose);

        // Fix D1: accumulate this page's result for the run's audit trail —
        // importPage() already returns the exact shape used everywhere else.
        $this->_runResults[] = $result;

        // Store entry ID and deferred card refs for _runBatch / pass 2.
        $this->_lastEntryId  = $result['entryId'] ?? null;
        $this->_lastCardRefs = $result['cardRefs'] ?? [];

        $prefix = $this->dryRun ? '[DRY RUN] ' : '';

        $isHomepage = (bool)($data['document']['is_homepage'] ?? false);

        $this->stdout("{$prefix}Page: {$result['slug']}", Console::BOLD);
        if ($isHomepage) {
            $this->stdout(" (homepage)", Console::FG_CYAN);
        }
        $this->stdout("\n");
        $this->stdout("  Entry: ");

        if ($result['entryFound']) {
            $this->stdout("updated (ID {$result['entryId']})\n", Console::FG_GREEN);
        } elseif ($this->dryRun) {
            $this->stdout("not found — will create\n", Console::FG_YELLOW);
        } else {
            $this->stdout("created (ID {$result['entryId']})\n", Console::FG_GREEN);
        }

        $seoLabel = $this->dryRun
            ? "{$result['seoFieldCount']} fields would populate"
            : "{$result['seoFieldCount']} fields populated";
        $this->stdout("  SEO: {$seoLabel}\n");

        $blockCount = count($result['blocks']);
        $blockLabel = $this->dryRun
            ? "{$blockCount} blocks would import"
            : "{$blockCount} blocks imported";
        $this->stdout("  Blocks: {$blockLabel}\n");

        foreach ($result['blocks'] as $block) {
            if ($block['skipped']) {
                $this->stdout("    ✗ {$block['type']} — skipped (unknown type)\n", Console::FG_YELLOW);
            } else {
                $fields     = implode(', ', $block['fields']);
                $innerCount = $block['innerCount'] ?? 1;
                $countLabel = $innerCount > 1 ? " ({$innerCount} inner)" : '';
                $this->stdout("    ✓ {$block['type']}{$countLabel} — {$fields}\n", Console::FG_GREEN);
            }
        }

        $imageCount = count($result['images']);

        if ($imageCount > 0) {
            $imageLabel = $this->dryRun
                ? "{$imageCount} would download"
                : "{$imageCount} imported";
            $this->stdout("  Images: {$imageLabel}\n");

            if ($this->verbose || $this->dryRun) {
                foreach ($result['images'] as $image) {
                    $status = $image['reused'] ? '(reused)' : ($this->dryRun ? '' : '(downloaded)');
                    $this->stdout("    - {$image['filename']} {$status}\n", Console::FG_GREY);
                }
            }
        }

        // Page-level assets[]/files[] filing — independent of any field, so
        // it's reported separately from the block/hero image count above.
        // Printed whenever there's ANY activity, including a failure alone —
        // a payload item that silently failed with no other activity must
        // still be visible, not just omitted from a total.
        $pageAssets = $result['pageAssets'] ?? null;
        $pageFiles  = $result['pageFiles'] ?? null;

        if ($pageAssets !== null && $this->_hasAnyAssetActivity($pageAssets)) {
            $this->stdout('  Page assets: ' . $this->_formatAssetCounts($pageAssets) . "\n");
        }

        if ($pageFiles !== null && $this->_hasAnyAssetActivity($pageFiles)) {
            $this->stdout('  Page files: ' . $this->_formatAssetCounts($pageFiles) . "\n");
        }

        // Bracketed placeholder nodes (e.g. "[Product grid]") dropped rather
        // than written into this page's rich text — see
        // NodesRenderer::getPlaceholderCount(). Custom blocks keep their
        // placeholders, so they never count towards this.
        $placeholdersDropped = $result['placeholdersDropped'] ?? 0;

        if ($placeholdersDropped > 0) {
            $this->stdout("  Placeholders stripped: {$placeholdersDropped}\n");
        }

        foreach ($result['warnings'] as $warning) {
            $this->warning($warning);
        }

        if (!$result['success']) {
            $this->failure($result['error'] ?? 'Import failed.');

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->dryRun) {
            $this->stdout("[DRY RUN] Nothing written.\n\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Whether a `pageAssets`/`pageFiles` count triple ({created, reused,
     * relocated, failed}) has any activity worth printing a line for —
     * including a failure alone, so a payload item that silently failed
     * doesn't just vanish from the total with no explanation.
     *
     * @param array{created: int, reused: int, relocated: int, failed: int} $counts
     * @return bool
     */
    private function _hasAnyAssetActivity(array $counts): bool
    {
        return ($counts['created'] ?? 0) > 0
            || ($counts['reused'] ?? 0) > 0
            || ($counts['failed'] ?? 0) > 0;
    }

    /**
     * Formats a `pageAssets`/`pageFiles` count triple as a one-line
     * new/reused/relocated/failed breakdown — every number always shown
     * (never hidden when zero), so a missing item is always visible instead
     * of silently absent from a total.
     *
     * @param array{created: int, reused: int, relocated: int, failed: int} $counts
     * @return string
     */
    private function _formatAssetCounts(array $counts): string
    {
        $created   = $counts['created'] ?? 0;
        $reused    = $counts['reused'] ?? 0;
        $relocated = $counts['relocated'] ?? 0;
        $failed    = $counts['failed'] ?? 0;

        return "{$created} new, {$reused} reused ({$relocated} relocated), {$failed} failed";
    }
}
