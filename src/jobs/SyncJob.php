<?php

namespace matrixcreate\contentiqimporter\jobs;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use matrixcreate\contentiqimporter\ContentIQImporter;

/**
 * Queue job that runs a full ContentIQ API sync.
 *
 * Fetches the export from the ContentIQ API, then imports each page
 * through the existing ImportService pipeline. Saves the result to
 * the import history table for the sync report screen.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.2.0
 */
class SyncJob extends BaseJob
{
    /**
     * The import run ID (pre-created with status 'pending' by the controller).
     *
     * @var int
     */
    public int $runId;

    /**
     * Entry IDs to unlock for this run (checked = will sync). Every other
     * synced entry is (re)locked at the start of the run. Set by the
     * controller when it pushes this job — see
     * CpController::actionRunSync(). `null` means "leave lock state
     * untouched" (mirrors the controller's previous `is_array($unlockIds)`
     * guard: a malformed/missing selection touches nothing, rather than
     * locking every entry).
     *
     * @var int[]|null
     */
    public ?array $unlockIds = null;

    /**
     * Whether the user consented to importing globals for this run (the
     * Sync screen's globals checkbox). Applied as the inverse into the
     * single-row contentiq_globals_sync.locked column at the start of the
     * run — see CpController::actionRunSync().
     *
     * @var bool
     */
    public bool $unlockGlobals = false;

    /**
     * ContentIQ page ids (document.id) for "New" (never-imported) rows the
     * user unchecked on the Sync screen — these are skipped this run rather
     * than imported. Unlike $unlockIds (an existing entry's lock row), a New
     * row has no lock row to toggle, so exclusion is expressed by page id
     * instead. Only takes effect when the page still doesn't exist in Craft
     * at execute time (checked via ImportService::findExistingEntry(), the
     * same resolver the lock-check path above uses) — an id that raced to
     * become an existing entry between page-load and this run must still be
     * updated normally, never silently skipped. Set by the controller — see
     * CpController::actionRunSync(). An empty array (the default) excludes
     * nothing — a missing/malformed selection degrades to "import
     * everything", never to "skip by default".
     *
     * @var int[]
     */
    public array $excludeNewIds = [];

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Web requests already run from webroot, but queue workers run from
        // the project root — match the CLI behaviour.
        chdir(Craft::getAlias('@webroot'));

        // Wrap the whole run: any uncaught throw from here on (pass 2 card
        // reference resolution, globals import, the auto-lock loop, a DB
        // hiccup, …) must still leave the run row in a terminal state —
        // otherwise the status poller spins on 'pending' forever (see
        // CpController::actionSyncStatus()'s staleness fallback for the
        // belt-and-braces case). _failRun() sets a terminal 'errors' status
        // and relocks globals; rethrowing afterwards still lets Craft's
        // queue record the job itself as failed.
        try {
            // Apply lock/unlock state and globals consent for this run here —
            // moved from the controller (which previously wrote these at HTTP
            // request time, before the job was even queued) so a worker that
            // never picks up the job, or dies before this point, leaves every
            // lock/consent untouched instead of leaking an unlock/consent
            // across a run that never actually executed (see Fix B1). Applied
            // before pass 1 below so its per-page lock CHECK reads the
            // intended state.
            $this->_applyLockState($this->unlockIds);
            $this->_setGlobalsLock(!$this->unlockGlobals);

            $plugin = ContentIQImporter::$plugin;

            // 1. Fetch export from API.
            $apiResult = $plugin->api->fetchExport();

            if (!$apiResult['success']) {
                $this->_failRun($apiResult['error'] ?? 'API request failed.');
                return;
            }

            $data = $apiResult['data'];

            // 2. Validate the decoded export is an object before touching it — a
            //    non-array/scalar body (null, "ok", a bare number) decodes
            //    successfully (json_last_error() sees no error) but would otherwise
            //    reach importPage() as a scalar and throw a TypeError outside the
            //    per-page catch, killing the whole run.
            if (!is_array($data)) {
                $this->_failRun('Malformed export: expected an object.');
                return;
            }

            // 3. Determine pages array. In batch mode, any element that isn't
            //    itself an object is malformed — skip it (rather than passing a
            //    scalar to importPage()) and warn instead of silently dropping it.
            $isBatch          = isset($data['pages']) && is_array($data['pages']);
            $skippedMalformed = 0;

            if ($isBatch) {
                $pages = [];

                foreach ($data['pages'] as $pageData) {
                    if (is_array($pageData)) {
                        $pages[] = $pageData;
                    } else {
                        $skippedMalformed++;
                    }
                }
            } else {
                // Single-page envelope — $data itself is the page, already
                // confirmed to be an array above.
                $pages = [$data];
            }

            $total = count($pages);

            // 4. PASS 1: Import all pages and build slug → entry ID map.
            $importService = $plugin->imports;
            $pageResults   = [];
            $totalImages   = 0;
            $hasErrors     = false;
            $hasWarnings   = false;

            // Resolve section for structure positioning.
            $config        = Craft::$app->config->getConfigFromFile('contentiq');
            $sectionHandle = $config['section'] ?? 'pages';
            $section       = Craft::$app->entries->getSectionByHandle($sectionHandle);
            $structureId   = $section?->structureId;
            $structures    = Craft::$app->getStructures();

            // Build slug → entry ID map for hierarchy and card ref resolution.
            $slugToEntryId = [];
            $allCardRefs   = [];  // Collected from all pages for pass 2

            // Surface any malformed batch entries filtered out in step 3 above as
            // a visible warning row, rather than silently dropping them.
            if ($skippedMalformed > 0) {
                $hasWarnings   = true;
                $pageResults[] = [
                    'success' => true,
                    'slug' => '',
                    'entryId' => null,
                    'entryFound' => false,
                    'title' => 'Malformed export entries',
                    'depth' => 0,
                    'parentSlug' => null,
                    'blocks' => [],
                    'images' => [],
                    'blockNotes' => '',
                    'seoFieldCount' => 0,
                    'warnings' => ["{$skippedMalformed} page(s) in the export were malformed (not an object) and were skipped."],
                    'error' => null,
                    'contentType' => null,
                    'sectionLabel' => null,
                ];
            }

            foreach ($pages as $i => $pageData) {
                $this->setProgress($queue, $i / $total, "Importing page " . ($i + 1) . " of {$total}");

                // Collection children route to their configured section, not Pages.
                $contentType   = $pageData['document']['content_type'] ?? null;
                $route         = $importService->getContentTypeRoute($contentType);
                $lookupSection = $route['section'] ?? $sectionHandle;

                // Skip locked entries. Resolution goes through the same shared
                // resolver ImportService uses internally (findExistingEntry), so the
                // lock check and the import it's gating always agree on which entry
                // a page maps to — the previous inline section+slug lookup here
                // never matched the homepage Single, so a locked homepage's lock
                // was never found and it got silently overwritten on every sync.
                $pageSlug      = $pageData['document']['slug'] ?? '';
                $existingEntry = $importService->findExistingEntry($pageData);

                // 3c. Duplicate leaf slug within this same export — the second
                // occurrence would otherwise silently overwrite the first in
                // $slugToEntryId below (poisoning hierarchy/card-ref resolution)
                // with no visible trace. Surfaced as a warning only; import
                // behaviour (last one wins) is unchanged. A blank slug (legitimate
                // for a homepage page) is never flagged.
                $isDuplicateSlug = $pageSlug !== '' && isset($slugToEntryId[$pageSlug]);

                if ($existingEntry !== null) {
                    // A missing sync row means this entry has never been through a
                    // ContentIQ sync — treat it as locked (same default the Sync UI
                    // uses: `$lockedMap[$entry->id] ?? true`), so a hand-built entry
                    // that collides with a synced slug/section is never silently
                    // overwritten just because it has no row yet.
                    $syncRow = (new Query())
                        ->select(['locked', 'notes'])
                        ->from('{{%contentiq_entry_syncs}}')
                        ->where(['element_id' => $existingEntry->id])
                        ->one();

                    // craft\db\Query::one() returns null when no row matches.
                    $isLocked = $syncRow === null ? true : (bool)$syncRow['locked'];

                    if ($isLocked) {
                        // Asset filing never touches entry content, so it's
                        // exempt from the lock — file this page's
                        // assets[]/files[] (and relocate under 'sitemap')
                        // even though nothing else runs for it. See
                        // ImportService::importPageAssetsOnly().
                        $assetsOnly = $importService->importPageAssetsOnly($pageData);

                        $lockedWarnings = array_merge(['Skipped — entry is locked.'], $assetsOnly['warnings']);

                        if ($isDuplicateSlug) {
                            $lockedWarnings[] = "Duplicate slug '{$pageSlug}' — a page earlier in this export already used this slug; hierarchy and card-reference resolution may point at the wrong entry.";
                        }

                        $pageResults[] = [
                            'success' => true,
                            'slug' => $pageSlug,
                            'entryId' => $existingEntry->id,
                            'entryFound' => true,
                            'title' => $pageData['document']['title'] ?? $pageSlug,
                            'depth' => $pageData['document']['depth'] ?? 0,
                            'parentSlug' => $pageData['document']['parent_slug'] ?? null,
                            'blocks' => [],
                            'images' => [],
                            'pageAssets' => $assetsOnly['pageAssets'],
                            'pageFiles' => $assetsOnly['pageFiles'],
                            // Nothing is written this run for a locked entry, so
                            // 'blocks' stays genuinely empty. blockNotes, though,
                            // has a real stored value from the last successful sync
                            // (contentiq_entry_syncs.notes) — surface it rather than
                            // a hardcoded '' so the report doesn't imply the entry
                            // has no content. `??` is null-safe even though $syncRow
                            // may be null (the default-to-locked branch above).
                            'blockNotes' => (string)($syncRow['notes'] ?? ''),
                            'seoFieldCount' => 0,
                            'warnings' => $lockedWarnings,
                            'error' => null,
                            'contentType' => $contentType,
                            'sectionLabel' => $contentType !== null
                                ? (Craft::$app->entries->getSectionByHandle($lookupSection)?->name ?? $contentType)
                                : null,
                            // Tells the auto-lock step (below) to leave this entry's
                            // sync row untouched — it was skipped, not synced.
                            'skippedLocked' => true,
                        ];

                        if ($pageSlug !== '') {
                            $slugToEntryId[$pageSlug] = $existingEntry->id;
                        }
                        continue;
                    }
                }

                // Skip pages the user deselected on the Sync screen's "New"
                // rows. Only applies when the page genuinely doesn't exist yet
                // ($existingEntry === null, resolved via the same
                // findExistingEntry() call above) — an id that raced to
                // become an existing entry between page-load and this run
                // must still go through the normal import/lock path, never
                // be silently skipped as if it were still new.
                $contentiqPageId = isset($pageData['document']['id']) ? (int)$pageData['document']['id'] : null;
                $isDeselectedNew = $existingEntry === null
                    && $contentiqPageId !== null
                    && in_array($contentiqPageId, $this->excludeNewIds, true);

                if ($isDeselectedNew) {
                    $deselectedWarnings = ['Skipped — deselected.'];

                    if ($isDuplicateSlug) {
                        $deselectedWarnings[] = "Duplicate slug '{$pageSlug}' — a page earlier in this export already used this slug; hierarchy and card-reference resolution may point at the wrong entry.";
                    }

                    $pageResults[] = [
                        'success' => true,
                        'slug' => $pageSlug,
                        'entryId' => null,
                        'entryFound' => false,
                        'title' => $pageData['document']['title'] ?? $pageSlug,
                        'depth' => $pageData['document']['depth'] ?? 0,
                        'parentSlug' => $pageData['document']['parent_slug'] ?? null,
                        'blocks' => [],
                        'images' => [],
                        'blockNotes' => '',
                        'seoFieldCount' => 0,
                        'warnings' => $deselectedWarnings,
                        'error' => null,
                        'contentType' => $contentType,
                        'sectionLabel' => $contentType !== null
                            ? (Craft::$app->entries->getSectionByHandle($lookupSection)?->name ?? $contentType)
                            : null,
                        // Tells the ack step and auto-lock step (below) this
                        // page was never written — no entry was created, so
                        // there's nothing to acknowledge or lock.
                        'skippedDeselected' => true,
                    ];

                    // Deliberately NOT added to $slugToEntryId — this page was
                    // never created, so there's no entry id to record. Any
                    // child of this page falls through to the existing
                    // "parent slug not found" warning path below and imports
                    // at root level; the next sync (once the parent is no
                    // longer deselected) self-heals the hierarchy.
                    continue;
                }

                $result = $importService->importPage($pageData, dryRun: false);

                // Handle hierarchy: always re-apply parent and position on every run.
                $parentSlug = $pageData['document']['parent_slug'] ?? null;
                $entryId    = $result['entryId'] ?? null;
                $slug       = $result['slug'] ?? '';
                $isHomepage = (bool)($pageData['document']['is_homepage'] ?? false);

                // Collection children are routed to their own section and have no Craft
                // parent (their collection parent is excluded from export) — skip the
                // Pages structure positioning / parent resolution for them entirely.
                if ($contentType === null && $entryId !== null && $structureId !== null && !$isHomepage) {
                    $entry = \craft\elements\Entry::find()->id($entryId)->status(null)->one();

                    if ($entry !== null) {
                        try {
                            if ($parentSlug !== null && $parentSlug !== '') {
                                // Try current-batch map first, then fall back to a Craft query
                                // so re-imports correctly place children under existing parents.
                                $parentId = $slugToEntryId[$parentSlug] ?? null;

                                if ($parentId === null) {
                                    $parentEntry = \craft\elements\Entry::find()
                                        ->section($sectionHandle)
                                        ->slug($parentSlug)
                                        ->status(null)
                                        ->one();
                                    $parentId = $parentEntry?->id;
                                }

                                if ($parentId !== null) {
                                    $structures->append($structureId, $entry, $parentId);
                                } else {
                                    $structures->appendToRoot($structureId, $entry);
                                    $result['warnings'][] = "Parent slug '{$parentSlug}' not found — entry saved at root level.";
                                }
                            } else {
                                $structures->appendToRoot($structureId, $entry);
                            }
                        } catch (\Throwable $e) {
                            $result['warnings'][] = 'Could not update structure position: ' . $e->getMessage();
                        }
                    }
                }

                // 3c. Surface the duplicate-slug warning computed above (see
                // $isDuplicateSlug) on this page's own result row.
                if ($isDuplicateSlug) {
                    $result['warnings'][] = "Duplicate slug '{$pageSlug}' — a page earlier in this export already used this slug; hierarchy and card-reference resolution may point at the wrong entry.";
                }

                // Track slug → entry ID for card ref lookups in pass 2.
                if ($entryId !== null && $slug !== '') {
                    $slugToEntryId[$slug] = $entryId;
                }

                // Collect deferred card ref sets from this page for pass 2 resolution.
                // Kept as a plain LIST of ref sets — each already carries its blockIndex,
                // so a page with several Cards blocks resolves each independently.
                if ($entryId !== null && !empty($result['cardRefs'])) {
                    $allCardRefs[$entryId] = array_values($result['cardRefs']);
                }

                // Attach hierarchy metadata for the report template.
                $result['title']      = $pageData['document']['title'] ?? $slug;
                $result['depth']      = $pageData['document']['depth'] ?? 0;
                $result['parentSlug'] = $pageData['document']['parent_slug'] ?? null;

                // Thread the stable ContentIQ page id through to the auto-lock
                // step below, which persists it into contentiq_entry_syncs so
                // findExistingEntry() can resolve this page by id next sync,
                // even if its slug changes.
                $result['contentiqPageId'] = isset($pageData['document']['id'])
                    ? (int)$pageData['document']['id']
                    : null;

                $pageResults[] = $result;
                $totalImages  += count($result['images'] ?? []);

                if (!$result['success']) {
                    $hasErrors = true;
                }
                if (!empty($result['warnings'])) {
                    $hasWarnings = true;
                }
            }

            $this->setProgress($queue, 1);

            // 5. PASS 2: Resolve deferred card references via the shared resolver.
            //    Warnings land on the owning page's result row and count toward the
            //    run status (previously pass-2 warnings never flipped $hasWarnings).
            $cardWarnings = $importService->resolveCardReferences($allCardRefs, $slugToEntryId);

            foreach ($cardWarnings as $entryId => $warnings) {
                if (empty($warnings)) {
                    continue;
                }

                $hasWarnings = true;

                foreach ($pageResults as $i => $r) {
                    if (($r['entryId'] ?? null) === $entryId) {
                        array_push($pageResults[$i]['warnings'], ...$warnings);
                        break;
                    }
                }
            }

            // 5b. Acknowledge pages that were genuinely imported this run — the
            //     ContentiQ export GETs are now read-only, so this is the CMS's
            //     explicit signal that a page was actually written, letting
            //     ContentiQ retire its own pending-import state. Locked/skipped
            //     entries (skippedLocked, or ImportService's own non-fatal
            //     'skipped' for e.g. an unmapped content_type) never reach this
            //     list — nothing was written for them. Best-effort: a failure
            //     here is surfaced as a run warning, never fails the sync.
            $ackPageIds = [];

            foreach ($pageResults as $result) {
                if (($result['success'] ?? false)
                    && empty($result['skippedLocked'])
                    && empty($result['skippedDeselected'])
                    && empty($result['skipped'])
                    && ($result['entryId'] ?? null) !== null
                    && !empty($result['contentiqPageId'])
                ) {
                    $ackPageIds[] = $result['contentiqPageId'];
                }
            }

            $ackWarning = null;

            if (!empty($ackPageIds)) {
                $ackResult = $plugin->api->ackPages($ackPageIds);

                if (!$ackResult['success']) {
                    $ackWarning = 'Could not acknowledge imported pages with ContentiQ: ' . ($ackResult['error'] ?? 'unknown error');
                    $hasWarnings = true;
                    Craft::warning("ContentIQImporter: sync ack call failed — {$ackWarning}", __METHOD__);
                }
            }

            // 6. Import globals (envelope may carry a `globals` key). URL-prefix drift
            //    is advisory and runs read-only regardless of the lock; the write
            //    path only runs when the user has consented (row unlocked this run).
            //    The pages path above is completely untouched by any of this.
            $globalsReport = null;

            if (isset($data['globals']) && is_array($data['globals'])) {
                $globals = $data['globals'];
                $drift   = $plugin->globals->checkUrlPrefixDrift($globals['collections'] ?? []);

                if ($this->_globalsLocked()) {
                    $globalsReport = [
                        'locked'  => true,
                        'skipped' => 'Skipped — globals are locked.',
                        'drift'   => $drift,
                    ];
                } else {
                    $globalsReport          = $plugin->globals->import($globals, dryRun: false);
                    $globalsReport['drift'] = $drift;
                    $totalImages           += $globalsReport['imageCount'] ?? 0;

                    // Relock and stamp on success.
                    $this->_relockGlobals();
                }

                if (!empty($drift)
                    || !empty($globalsReport['warnings'] ?? [])
                    || !empty($globalsReport['fieldNotes'] ?? [])) {
                    $hasWarnings = true;
                }
            }

            // 7. Determine overall status.
            if ($hasErrors) {
                $status = 'errors';
            } elseif ($hasWarnings) {
                $status = 'warnings';
            } else {
                $status = 'success';
            }

            // 8. Update the pre-created run record. The result is a shape-tagged
            //    object ({pages, globals, ackWarning}); old runs remain flat arrays.
            Craft::$app->getDb()->createCommand()->update(
                '{{%contentiq_import_runs}}',
                [
                    'pageCount'   => count($pageResults),
                    'imageCount'  => $totalImages,
                    'status'      => $status,
                    'result'      => Json::encode(['pages' => $pageResults, 'globals' => $globalsReport, 'ackWarning' => $ackWarning]),
                    'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                ],
                ['id' => $this->runId],
            )->execute();

            // 9. Auto-lock all successfully synced entries.
            // After every sync, imported entries are locked so subsequent syncs
            // won't overwrite them unless the user explicitly unlocks them.
            $db  = Craft::$app->getDb();
            $now = Db::prepareDateForDb(new \DateTime());

            foreach ($pageResults as $result) {
                if (!($result['success'] ?? false) || !($result['entryId'] ?? null)) {
                    continue;
                }

                // Skipped-locked entries were never touched — leave their existing
                // row alone (locked stays 1, synced_at/notes stay whatever they
                // were) so the audit trail still shows "skipped" instead of being
                // overwritten to look like a fresh sync.
                if (!empty($result['skippedLocked'])) {
                    continue;
                }

                $entryId = $result['entryId'];

                $exists = (new Query())
                    ->from('{{%contentiq_entry_syncs}}')
                    ->where(['element_id' => $entryId])
                    ->exists();

                // Record the ContentIQ page id → element_id mapping (when the
                // page carried one) so findExistingEntry() can resolve this
                // entry by id on the next sync even if its slug changes.
                $syncData = [
                    'locked'    => true,
                    'synced_at' => $now,
                    'notes'     => $result['blockNotes'] ?? '',
                ];

                if (!empty($result['contentiqPageId'])) {
                    $syncData['contentiq_page_id'] = $result['contentiqPageId'];
                }

                if ($exists) {
                    $db->createCommand()->update(
                        '{{%contentiq_entry_syncs}}',
                        $syncData,
                        ['element_id' => $entryId],
                    )->execute();
                } else {
                    $db->createCommand()->insert(
                        '{{%contentiq_entry_syncs}}',
                        array_merge(['element_id' => $entryId], $syncData),
                    )->execute();
                }
            }
        } catch (\Throwable $e) {
            Craft::error(
                'ContentIQImporter sync job exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                __METHOD__,
            );
            $this->_failRun('Unexpected error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('contentiq-importer', 'Syncing content from ContentiQ');
    }

    /**
     * Applies the lock/unlock selection from the Sync screen — locks every
     * synced entry, then unlocks only the given IDs. `null` is a no-op (see
     * the $unlockIds property docblock).
     *
     * Moved here from CpController::actionRunSync() (Fix B1) — same exact
     * semantics, just applied when the job actually runs instead of when the
     * controller pushes it.
     *
     * Unlock is an upsert, not an UPDATE-only (Fix B-unlock): an entry that
     * has never been through a successful sync — the Craft homepage Single
     * is the standing example, since it pre-exists so findExistingEntry()
     * matches it, but its contentiq_entry_syncs row is only ever created by
     * the post-import auto-lock step (see "9." below) — has no row for an
     * UPDATE to match. The unlock then silently no-ops, and the per-page
     * lock check a few lines up in execute() (missing row => locked) skips
     * the entry anyway, defeating the user's explicit unlock. Rows that
     * already exist are updated in place; missing ones are inserted with
     * locked=false and everything else null — the auto-lock step fills in
     * synced_at/notes/contentiq_page_id once the entry actually imports.
     *
     * @param int[]|null $unlockIds
     * @return void
     */
    private function _applyLockState(?array $unlockIds): void
    {
        if ($unlockIds === null) {
            return;
        }

        $db = Craft::$app->getDb();

        // Lock everything first, then unlock only the selected entries. An
        // empty $unlockIds (the user selected none) still runs this block —
        // it locks every synced entry and simply has nothing to unlock, so
        // "select none" actually means "sync nothing" instead of leaving
        // previously-unlocked rows unlocked.
        $db->createCommand()
            ->update('{{%contentiq_entry_syncs}}', ['locked' => true])
            ->execute();

        if (empty($unlockIds)) {
            return;
        }

        // $unlockIds arrives from a POST body (see CpController::actionRunSync())
        // — cast to positive ints and drop junk before it ever reaches a query,
        // then filter to ids that are real Craft entries so malformed POST data
        // can't create an orphan sync row for an element that doesn't exist.
        $candidateIds = array_values(array_unique(array_filter(
            array_map('intval', $unlockIds),
            static fn (int $id): bool => $id > 0,
        )));

        if (empty($candidateIds)) {
            return;
        }

        $validIds = \craft\elements\Entry::find()->id($candidateIds)->status(null)->ids();

        if (empty($validIds)) {
            return;
        }

        foreach ($validIds as $elementId) {
            $exists = (new Query())
                ->from('{{%contentiq_entry_syncs}}')
                ->where(['element_id' => $elementId])
                ->exists();

            if ($exists) {
                $db->createCommand()
                    ->update('{{%contentiq_entry_syncs}}', ['locked' => false], ['element_id' => $elementId])
                    ->execute();
            } else {
                $db->createCommand()
                    ->insert('{{%contentiq_entry_syncs}}', ['element_id' => $elementId, 'locked' => false])
                    ->execute();
            }
        }
    }

    /**
     * Sets the single globals-sync lock row, creating it if absent.
     *
     * Moved here from CpController::_setGlobalsLock() (Fix B1) — identical
     * behaviour, just applied when the job actually runs.
     *
     * @param bool $locked
     * @return void
     */
    private function _setGlobalsLock(bool $locked): void
    {
        $db     = Craft::$app->getDb();
        $exists = (new Query())->from('{{%contentiq_globals_sync}}')->exists();

        if ($exists) {
            $db->createCommand()
                ->update('{{%contentiq_globals_sync}}', ['locked' => $locked])
                ->execute();
            return;
        }

        $db->createCommand()
            ->insert('{{%contentiq_globals_sync}}', ['locked' => $locked])
            ->execute();
    }

    /**
     * Whether the single globals-sync row is locked. A missing row is treated
     * as locked (the safe default).
     *
     * @return bool
     */
    private function _globalsLocked(): bool
    {
        $row = (new Query())
            ->select(['locked'])
            ->from('{{%contentiq_globals_sync}}')
            ->one();

        // craft\db\Query::one() returns null when no row matches. A missing
        // row means globals have never been consented to — treat as locked
        // (the safe default the globals model documents).
        if ($row === null) {
            return true;
        }

        return (bool)$row['locked'];
    }

    /**
     * Relocks the globals-sync row and stamps synced_at, creating the row if
     * absent.
     *
     * @return void
     */
    private function _relockGlobals(): void
    {
        $db  = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $exists = (new Query())->from('{{%contentiq_globals_sync}}')->exists();

        if ($exists) {
            $db->createCommand()
                ->update('{{%contentiq_globals_sync}}', ['locked' => true, 'synced_at' => $now])
                ->execute();
            return;
        }

        $db->createCommand()
            ->insert('{{%contentiq_globals_sync}}', ['locked' => true, 'synced_at' => $now])
            ->execute();
    }

    /**
     * Marks the run as failed with an error message.
     *
     * @param string $error
     */
    private function _failRun(string $error): void
    {
        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_import_runs}}',
            [
                'status'      => 'errors',
                'result'      => Json::encode([['success' => false, 'slug' => '', 'error' => $error, 'warnings' => []]]),
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ],
            ['id' => $this->runId],
        )->execute();

        // Relock globals so a persisted unlock consent does not survive a failed
        // run and silently drive the next sync. Only touch an existing row.
        $exists = (new Query())->from('{{%contentiq_globals_sync}}')->exists();

        if ($exists) {
            Craft::$app->getDb()->createCommand()
                ->update('{{%contentiq_globals_sync}}', ['locked' => true])
                ->execute();
        }
    }
}
