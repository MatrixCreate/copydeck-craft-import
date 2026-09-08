<?php

namespace matrixcreate\contentiqimporter\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\web\Controller;
use craft\web\UploadedFile;
use craft\helpers\StringHelper;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\helpers\TempFileSafety;
use matrixcreate\contentiqimporter\jobs\SyncJob;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * CP controller for the ContentIQ dashboard, upload, preview, and result screens.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.1.0
 */
class CpController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Intro screen — sync and import entry points.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $settings = ContentIQImporter::$plugin->getSettings();
        $apiConfigured = $settings->contentiqUrl !== ''
            && $settings->apiKey !== '';

        return $this->renderTemplate('contentiq-importer/_cp/index', [
            'apiConfigured' => $apiConfigured,
        ]);
    }

    /**
     * Import history — lists previous import runs.
     *
     * @return Response
     */
    public function actionHistory(): Response
    {
        $runs = (new Query())
            ->select(['id', 'importedBy', 'filename', 'type', 'pageCount', 'imageCount', 'status', 'dateCreated'])
            ->from('{{%contentiq_import_runs}}')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(50)
            ->all();

        return $this->renderTemplate('contentiq-importer/_cp/history', [
            'runs' => $runs,
        ]);
    }

    /**
     * Mappings screen — maps ContentIQ collection slugs to Craft routing.
     *
     * Renders one row per project collection (from the wire) plus any slug still
     * held in settings but no longer exported (badged "not in ContentiQ"). Rows
     * whose slug is defined in config/contentiq.php `content_types` render
     * read-only — the file wins. On API failure the page still renders from the
     * stored settings mappings with an error banner.
     *
     * @return Response
     */
    public function actionMappings(): Response
    {
        // Mappings are project config — admin-only, like Craft's own plugin
        // settings screens (viewing is allowed even when admin changes are off).
        $this->requireAdmin(false);

        $plugin         = ContentIQImporter::$plugin;
        $settings       = $plugin->getSettings();
        $storedMappings = $settings->collectionMappings;

        // Fetch collections from the wire. On failure fall back to stored slugs.
        $apiError    = null;
        $collections = [];

        if ($settings->contentiqUrl === '' || $settings->apiKey === '') {
            $apiError = Craft::t('contentiq-importer', 'ContentiQ API is not configured. Set the URL and API key in plugin settings.');
        } else {
            $response = $plugin->api->fetchGlobals();

            if ($response['success']) {
                $collections = $response['data']['globals']['collections'] ?? [];
            } else {
                $apiError = $response['error'] ?? Craft::t('contentiq-importer', 'Could not reach ContentiQ.');
            }
        }

        // Index wire collections by slug.
        $wireBySlug = [];

        foreach ($collections as $collection) {
            $slug = (string)($collection['slug'] ?? '');

            if ($slug === '') {
                continue;
            }

            $wireBySlug[$slug] = $collection;
        }

        // Raw config-file content_types — these slugs render read-only (file wins).
        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('contentiq');
        $fileSlugs     = (is_array($projectConfig) && is_array($projectConfig['content_types'] ?? null))
            ? $projectConfig['content_types']
            : [];

        // Effective merged map (defaults ← settings ← file) drives pre-selection.
        $effectiveMap = $plugin->imports->getContentTypesMap();

        // Row set = wire collection slugs ∪ stored settings slugs.
        $slugs = array_values(array_unique(array_merge(
            array_keys($wireBySlug),
            array_keys($storedMappings),
        )));
        sort($slugs);

        $rows = [];

        foreach ($slugs as $slug) {
            $rows[] = [
                'slug'           => $slug,
                'urlPrefix'      => $wireBySlug[$slug]['url_prefix'] ?? null,
                'notInContentiq' => !isset($wireBySlug[$slug]),
                'inFile'         => isset($fileSlugs[$slug]),
                'mapping'        => $effectiveMap[$slug] ?? null,
            ];
        }

        return $this->renderTemplate('contentiq-importer/_cp/mappings', [
            'rows'         => $rows,
            'sectionsData' => $this->_buildSectionsData(),
            'apiError'     => $apiError,
        ]);
    }

    /**
     * Persists the collection mappings edited on the Mappings screen.
     *
     * Read-only (config-file) rows carry no inputs, so they never post and never
     * land in settings — the file stays the escape hatch. Rows with an empty
     * section are dropped here and again by the Settings model's validation.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionSaveMappings(): Response
    {
        $this->requirePostRequest();
        // Writes project config — admin + allowAdminChanges, like Craft's own
        // plugin-settings saves (403 instead of a ProjectConfig 500 on prod).
        $this->requireAdmin();

        $posted = Craft::$app->getRequest()->getBodyParam('mappings');

        // A missing/malformed param means the form rendered no editable rows (or
        // a broken client) — treat as a no-op rather than deleting every row.
        if (!is_array($posted)) {
            $posted = [];
        }

        $str = static fn(mixed $v): string => is_scalar($v) ? trim((string)$v) : '';

        $mappings   = [];
        $incomplete = [];

        foreach ($posted as $slug => $row) {
            if (!is_array($row)) {
                continue;
            }

            $section = $str($row['section'] ?? '');

            if ($section === '') {
                continue;
            }

            $entryType    = $str($row['entryType'] ?? '');
            $contentField = $str($row['contentField'] ?? '');

            // A mapped collection needs all three — a partial row would fatal
            // per-page at import time ("Entry type '' not found").
            if ($entryType === '' || $contentField === '') {
                $incomplete[] = (string)$slug;
                continue;
            }

            $headingField = $str($row['headingField'] ?? '');
            $blocksField  = $str($row['blocksField'] ?? '');

            // blocksField is optional — unlike entryType/contentField above, a
            // row with no blocks field configured is complete and valid; it
            // just falls back to config['matrixField'] at import time.
            $mappings[(string)$slug] = [
                'section'      => $section,
                'entryType'    => $entryType,
                'contentField' => $contentField,
                'headingField' => $headingField !== '' ? $headingField : null,
                'blocksField'  => $blocksField !== '' ? $blocksField : null,
            ];
        }

        // Rows shadowed by a config-file override render read-only (no inputs),
        // so they never post — re-preserve their stored values instead of
        // silently deleting them on every unrelated save.
        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('contentiq');
        $fileSlugs     = (is_array($projectConfig) && is_array($projectConfig['content_types'] ?? null))
            ? $projectConfig['content_types']
            : [];

        foreach (ContentIQImporter::$plugin->getSettings()->collectionMappings as $slug => $row) {
            if (isset($fileSlugs[$slug]) && !isset($mappings[$slug])) {
                $mappings[$slug] = $row;
            }
        }

        // savePluginSettings() replaces the whole project-config settings node
        // with only the keys passed here (toArray(array_keys($settings))), so a
        // partial array would wipe contentiqUrl/apiKey. Always pass every key.
        $settings = ContentIQImporter::$plugin->getSettings();

        $saved = Craft::$app->getPlugins()->savePluginSettings(ContentIQImporter::$plugin, [
            'contentiqUrl'       => $settings->contentiqUrl,
            'apiKey'             => $settings->apiKey,
            'collectionMappings' => $mappings,
        ]);

        if (!$saved) {
            Craft::$app->getSession()->setError(Craft::t('contentiq-importer', 'Couldn’t save mappings.'));

            return $this->redirect('contentiq-importer/mappings');
        }

        if (!empty($incomplete)) {
            Craft::$app->getSession()->setNotice(Craft::t('contentiq-importer', 'Mappings saved. Skipped incomplete rows (need entry type + content field): {slugs}', [
                'slugs' => implode(', ', $incomplete),
            ]));
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('contentiq-importer', 'Mappings saved.'));
        }

        return $this->redirect('contentiq-importer/mappings');
    }

    /**
     * Upload screen — file picker and drag-and-drop.
     *
     * @return Response
     */
    public function actionUpload(): Response
    {
        $this->requirePermission('contentiq-importer:sync');

        return $this->renderTemplate('contentiq-importer/_cp/upload');
    }

    /**
     * Preview — receives uploaded JSON, validates, runs dry-run, shows what will happen.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('contentiq-importer:sync');

        $uploadedFile = UploadedFile::getInstanceByName('jsonFile');

        if ($uploadedFile === null) {
            Craft::$app->getSession()->setError('No file was uploaded.');

            return $this->redirect('contentiq-importer/upload');
        }

        $json = file_get_contents($uploadedFile->tempName);

        if ($json === false) {
            Craft::$app->getSession()->setError('Could not read the uploaded file.');

            return $this->redirect('contentiq-importer/upload');
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Craft::$app->getSession()->setError('Invalid JSON: ' . json_last_error_msg());

            return $this->redirect('contentiq-importer/upload');
        }

        // Detect single vs batch.
        $isBatch = isset($data['pages']) && is_array($data['pages']);
        $pages   = $isBatch ? $data['pages'] : [$data];

        // Prepare services for dry-run.
        $importService = ContentIQImporter::$plugin->imports;

        $previewResults = [];
        $totalWarnings  = 0;

        foreach ($pages as $pageData) {
            $result = $importService->importPage($pageData, dryRun: true);

            // Check if entry exists.
            $slug     = $result['slug'] ?? '';
            $existing = Entry::find()->section('pages')->slug($slug)->status(null)->one();

            $result['willCreate'] = $existing === null;
            $result['existingId'] = $existing?->id;

            $previewResults[] = $result;

            if (!empty($result['warnings'])) {
                $totalWarnings += count($result['warnings']);
            }
        }

        // Count creates vs updates.
        $createCount = count(array_filter($previewResults, fn($r) => $r['willCreate']));
        $updateCount = count($previewResults) - $createCount;

        // Dry-run the globals payload when present so the preview can summarise it.
        // The service's dryRun path writes nothing (offices, global sets, and image
        // imports are all gated on !$dryRun).
        $globalsPreview = null;

        if (isset($data['globals']) && is_array($data['globals'])) {
            $globalsPreview          = ContentIQImporter::$plugin->globals->import($data['globals'], dryRun: true);
            $globalsPreview['drift'] = ContentIQImporter::$plugin->globals
                ->checkUrlPrefixDrift($data['globals']['collections'] ?? []);
        }

        // Store JSON in session for the import step.
        $tempFilename = 'contentiq-import-' . gmdate('ymd_His') . '.json';
        $tempPath     = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . $tempFilename;
        file_put_contents($tempPath, $json);

        return $this->renderTemplate('contentiq-importer/_cp/preview', [
            'pages'          => $previewResults,
            'isBatch'        => $isBatch,
            'pageCount'      => count($previewResults),
            'createCount'    => $createCount,
            'updateCount'    => $updateCount,
            'totalWarnings'  => $totalWarnings,
            'tempFilename'   => $tempFilename,
            'exportDate'     => $data['exported_at'] ?? null,
            'globalsPreview' => $globalsPreview,
        ]);
    }

    /**
     * Runs the actual import and redirects to the result screen.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionRunImport(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('contentiq-importer:sync');

        // Sanitize before building a filesystem path — the raw POST value is
        // untrusted and a '../' would otherwise allow arbitrary file read/delete
        // via getTempPath() below (see the @unlink() further down).
        $tempFilename = TempFileSafety::sanitize(
            (string)Craft::$app->getRequest()->getRequiredBodyParam('tempFilename'),
        );

        if ($tempFilename === null) {
            Craft::$app->getSession()->setError('Import file expired. Please upload again.');

            return $this->redirect('contentiq-importer/upload');
        }

        $tempPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . $tempFilename;

        if (!is_file($tempPath)) {
            Craft::$app->getSession()->setError('Import file expired. Please upload again.');

            return $this->redirect('contentiq-importer/upload');
        }

        $json = file_get_contents($tempPath);
        $data = json_decode($json, true);

        if ($data === null) {
            Craft::$app->getSession()->setError('Could not parse import file.');

            return $this->redirect('contentiq-importer/upload');
        }

        // Web requests run from webroot already — no chdir needed (unlike CLI).

        $isBatch = isset($data['pages']) && is_array($data['pages']);
        $pages   = $isBatch ? $data['pages'] : [$data];

        $importService = ContentIQImporter::$plugin->imports;
        $pageResults   = [];
        $totalImages   = 0;
        $hasErrors     = false;
        $hasWarnings   = false;
        $slugToEntryId = [];
        $allCardRefs   = [];  // Deferred card ref sets keyed by entry ID, for pass 2

        // Resolve section for structure positioning.
        $config        = Craft::$app->config->getConfigFromFile('contentiq');
        $sectionHandle = $config['section'] ?? 'pages';
        $section       = Craft::$app->entries->getSectionByHandle($sectionHandle);
        $structureId   = $section?->structureId;
        $structures    = Craft::$app->getStructures();

        foreach ($pages as $pageData) {
            // Enforce entry locks server-side — the upload path previously
            // called importPage() with no lock check at all, so a locked entry
            // could be silently overwritten by re-uploading the same file. A
            // missing sync row for an existing entry is treated as locked (same
            // default as the Sync UI / SyncJob).
            $existingEntry = $importService->findExistingEntry($pageData);

            if ($existingEntry !== null) {
                $syncRow = (new Query())
                    ->select(['locked'])
                    ->from('{{%contentiq_entry_syncs}}')
                    ->where(['element_id' => $existingEntry->id])
                    ->one();

                // craft\db\Query::one() returns null when no row matches.
                $isLocked = $syncRow === null ? true : (bool)$syncRow['locked'];

                if ($isLocked) {
                    $lockedSlug = $pageData['document']['slug'] ?? '';

                    // Asset filing never touches entry content, so it's
                    // exempt from the lock — file this page's
                    // assets[]/files[] (and relocate under 'sitemap') even
                    // though nothing else runs for it. See
                    // ImportService::importPageAssetsOnly().
                    $assetsOnly = $importService->importPageAssetsOnly($pageData);

                    $pageResults[] = [
                        'success'       => true,
                        'slug'          => $lockedSlug,
                        'title'         => $pageData['document']['title'] ?? $lockedSlug,
                        'entryId'       => $existingEntry->id,
                        'entryFound'    => true,
                        'seoFieldCount' => 0,
                        'blocks'        => [],
                        'images'        => [],
                        'pageAssets'    => $assetsOnly['pageAssets'],
                        'pageFiles'     => $assetsOnly['pageFiles'],
                        'blockNotes'    => '',
                        'warnings'      => array_merge(['Skipped — entry is locked.'], $assetsOnly['warnings']),
                        'error'         => null,
                        'skipped'       => false,
                        'contentType'   => $pageData['document']['content_type'] ?? null,
                        'sectionLabel'  => null,
                    ];
                    $hasWarnings = true;

                    if ($lockedSlug !== '') {
                        $slugToEntryId[$lockedSlug] = $existingEntry->id;
                    }

                    continue;
                }
            }

            $result = $importService->importPage($pageData, dryRun: false);

            $slug       = $result['slug'] ?? '';
            $parentSlug = $pageData['document']['parent_slug'] ?? null;
            $entryId    = $result['entryId'] ?? null;
            $isHomepage = (bool)($pageData['document']['is_homepage'] ?? false);

            if ($entryId !== null && $slug !== '') {
                $slugToEntryId[$slug] = $entryId;
            }

            // Collect deferred card ref sets from this page for pass 2 resolution.
            if ($entryId !== null && !empty($result['cardRefs'])) {
                $allCardRefs[$entryId] = array_values($result['cardRefs']);
            }

            if ($entryId !== null && $structureId !== null && !$isHomepage) {
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

            $pageResults[] = $result;
            $totalImages  += count($result['images'] ?? []);

            if (!$result['success']) {
                $hasErrors = true;
            }
            if (!empty($result['warnings'])) {
                $hasWarnings = true;
            }
        }

        // PASS 2: resolve deferred card references now the slug map is complete.
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

        // Determine overall status.
        if ($hasErrors) {
            $status = 'errors';
        } elseif ($hasWarnings) {
            $status = 'warnings';
        } else {
            $status = 'success';
        }

        // Save to history.
        $runId = $this->_saveRun(
            filename:   basename($tempFilename),
            type:       $isBatch ? 'batch' : 'single',
            pageCount:  count($pageResults),
            imageCount: $totalImages,
            status:     $status,
            result:     $pageResults,
        );

        // Clean up temp file.
        @unlink($tempPath);

        // The upload path intentionally does not import globals — that needs the
        // lock/consent model, which only the Sync screen exposes. Flag it so the
        // editor knows the globals in the file were left untouched.
        if (isset($data['globals']) && is_array($data['globals']) && !empty($data['globals'])) {
            Craft::$app->getSession()->setNotice(
                Craft::t('contentiq-importer', 'Globals present in file — use Sync to import globals.'),
            );
        }

        return $this->redirect('contentiq-importer/result/' . $runId);
    }

    /**
     * Result screen — shows the outcome of a completed import run.
     *
     * Sync runs are redirected to the purpose-built sync-result screen (Fix
     * B-result): since the 1.22.0 sync overhaul, SyncJob stores its result as
     * a shape-tagged object (`{pages, globals, ackWarning}`, see SyncJob.php),
     * not the flat per-page array every other run type still stores. This
     * legacy template iterates `run.result` directly and was never updated
     * for that shape, so a sync run rendered here shows three garbage rows
     * (one per top-level key) instead of the actual pages.
     *
     * @param int $runId
     * @return Response
     */
    public function actionResult(int $runId): Response
    {
        $run = (new Query())
            ->from('{{%contentiq_import_runs}}')
            ->where(['id' => $runId])
            ->one();

        if ($run === null) {
            throw new \yii\web\NotFoundHttpException('Import run not found.');
        }

        if ($run['type'] === 'sync') {
            return $this->redirect('contentiq-importer/sync/result/' . $runId);
        }

        $run['result'] = Json::decodeIfJson($run['result'] ?? '[]');

        return $this->renderTemplate('contentiq-importer/_cp/result', [
            'run' => $run,
        ]);
    }

    /**
     * Sync screen — shows API details and sync button.
     *
     * On load, shows the FULL ContentiQ project tree (everything in the
     * project, not just what's already been imported) merged with local sync
     * state, matched via ImportService::findExistingEntry() (id-first, same
     * resolver the actual import/lock-check pipeline uses) — see
     * _buildFullSyncTree(). Falls back to the local-only view
     * (_buildLocalOnlySyncTree() — what this screen showed before this
     * change) whenever the API is unconfigured or unreachable, with a
     * dismissible warning banner. The screen must never 500 because
     * ContentiQ is down.
     *
     * @return Response
     */
    public function actionSync(): Response
    {
        $settings      = ContentIQImporter::$plugin->getSettings();
        $apiConfigured = $settings->contentiqUrl !== '' && $settings->apiKey !== '';

        $config        = Craft::$app->config->getConfigFromFile('contentiq');
        $sectionHandle = $config['section'] ?? 'pages';

        // Ordered list of collection sections from content_types config.
        // Pages comes first, then each distinct collection section in config order.
        $contentTypesMap    = ContentIQImporter::$plugin->imports->getContentTypesMap();
        $collectionSections = [];

        foreach ($contentTypesMap as $route) {
            $handle = $route['section'] ?? null;

            if ($handle !== null
                && $handle !== $sectionHandle
                && !in_array($handle, $collectionSections, true)) {
                $collectionSections[] = $handle;
            }
        }

        $apiWarning     = null;
        $syncGroups     = [];
        $hasSyncRecords = false;

        if ($apiConfigured) {
            // Shorter timeouts than the queue job's fetchExport() call — this
            // runs synchronously on page load, so a slow/hung ContentiQ
            // instance shouldn't hang the CP request for the job's full 120s.
            $apiResult = ContentIQImporter::$plugin->api->fetchExport(timeout: 20, connectTimeout: 5);

            if ($apiResult['success'] && is_array($apiResult['data'])) {
                $syncGroups     = $this->_buildFullSyncTree($apiResult['data'], $sectionHandle, $collectionSections);
                $hasSyncRecords = count($syncGroups) > 0;
            } else {
                $apiWarning = "Couldn't reach ContentiQ — showing imported entries only.";
            }
        } else {
            $apiWarning = 'ContentiQ API is not configured — showing imported entries only.';
        }

        // Graceful degradation: unconfigured or unreachable API falls back to
        // exactly the pre-existing local-only view (previously the only view
        // this screen ever rendered).
        if ($apiWarning !== null) {
            [$syncGroups, $hasSyncRecords] = $this->_buildLocalOnlySyncTree($sectionHandle, $collectionSections);
        }

        // Parse the project slug from the API key (ciq_{slug}_{32chars}) for display.
        $inferredSlug = '';
        $apiKey = App::parseEnv($settings->apiKey);

        if (str_starts_with($apiKey, 'ciq_')) {
            $withoutPrefix = substr($apiKey, 4);
            $lastUnderscore = strrpos($withoutPrefix, '_');

            if ($lastUnderscore !== false) {
                $inferredSlug = substr($withoutPrefix, 0, $lastUnderscore);
            }
        }

        // Globals consent — a single lightswitch above the Pages group. Missing
        // row ⇒ locked (the safe default).
        $globalsRow    = (new Query())
            ->select(['locked'])
            ->from('{{%contentiq_globals_sync}}')
            ->one();
        // craft\db\Query::one() returns null when no row matches.
        $globalsLocked = $globalsRow === null ? true : (bool)$globalsRow['locked'];

        $globalsOfficeCount = (int)(new Query())
            ->from('{{%contentiq_office_syncs}}')
            ->count();

        return $this->renderTemplate('contentiq-importer/_cp/sync', [
            'contentiqUrl'    => App::parseEnv($settings->contentiqUrl),
            'projectSlug'    => $inferredSlug,
            'hasSyncRecords' => $hasSyncRecords,
            'syncGroups'     => $syncGroups,
            'apiWarning'     => $apiWarning,
            'globalsLocked'     => $globalsLocked,
            'globalsOfficeCount' => $globalsOfficeCount,
            'globalsSetNames'    => ['companyInfo', 'globalContent', 'siteConfig'],
        ]);
    }

    /**
     * Builds the sync-screen entry tree from ONLY existing local sync records
     * (contentiq_entry_syncs) — no ContentIQ API call. This is the screen's
     * original behaviour, preserved as the fallback for when the API is
     * unconfigured or unreachable (see actionSync()).
     *
     * @param string $sectionHandle Pages section handle.
     * @param string[] $collectionSections Ordered list of collection section handles.
     * @return array{0: array, 1: bool} [$syncGroups, $hasSyncRecords]
     */
    private function _buildLocalOnlySyncTree(string $sectionHandle, array $collectionSections): array
    {
        $syncRecords = (new Query())
            ->select(['element_id', 'locked'])
            ->from('{{%contentiq_entry_syncs}}')
            ->all();

        $hasSyncRecords = count($syncRecords) > 0;
        $syncGroups     = [];

        if ($hasSyncRecords) {
            $elementIds = array_column($syncRecords, 'element_id');
            $lockedMap  = array_column($syncRecords, 'locked', 'element_id');

            // Query entries across the Pages section (+ homepage Single) and every
            // collection section, restricted to those that have a sync record.
            $entries = Entry::find()
                ->section(array_merge([$sectionHandle, 'homepage'], $collectionSections))
                ->id($elementIds)
                ->status(null)
                ->all();

            // Bucket entries by their display group. Homepage folds into Pages.
            $buckets = [];

            foreach ($entries as $entry) {
                $entrySection = $entry->section;
                $handle       = $entrySection->handle;
                $isHomepage   = $handle === 'homepage';
                $groupHandle  = $isHomepage ? $sectionHandle : $handle;
                $isStructure  = $entrySection->type === 'structure';
                $parent       = ($isStructure && !$isHomepage) ? $entry->getParent() : null;

                $buckets[$groupHandle][] = [
                    'elementId'  => $entry->id,
                    'title'      => $entry->title,
                    'slug'       => $entry->slug,
                    'locked'     => (bool)($lockedMap[$entry->id] ?? true),
                    'parentSlug' => $parent?->slug,
                    'depth'      => ($isStructure && !$isHomepage) ? max(0, $entry->level - 1) : 0,
                    'isHomepage' => $isHomepage,
                    'isNew'      => false,
                ];
            }

            // Build ordered groups: Pages first, then collection sections in config
            // order. Omit any group with no entries (e.g. a collection not yet synced).
            foreach (array_merge([$sectionHandle], $collectionSections) as $groupHandle) {
                if (empty($buckets[$groupHandle])) {
                    continue;
                }

                $section = Craft::$app->entries->getSectionByHandle($groupHandle);

                $syncGroups[] = [
                    'handle'  => $groupHandle,
                    'name'    => $section?->name ?? ucfirst($groupHandle),
                    'entries' => $buckets[$groupHandle],
                ];
            }
        }

        return [$syncGroups, $hasSyncRecords];
    }

    /**
     * Builds the sync-screen entry tree from the FULL ContentIQ project
     * export — every page/collection entry in the project, not just what's
     * already been imported.
     *
     * Each export page is matched against Craft via
     * ImportService::findExistingEntry() — the same id-first resolver
     * SyncJob's own lock check uses, so this view and an actual sync always
     * agree on what a page maps to. A match carries the entry's lock state
     * (missing sync row ⇒ locked, same default as SyncJob/the widget sync);
     * no match is a brand-new row (never locked — SyncJob's lock check only
     * runs when findExistingEntry() found something, so an unimported page
     * always imports regardless of any lock/selection state).
     *
     * Pages-group hierarchy (parent/child nesting) is built from the
     * export's own document.parent_slug — the only source that can link a
     * not-yet-imported child to an existing (or equally new) parent, since a
     * new page has no Craft structure position yet. Collection-entry rows
     * are deliberately kept flat (parentSlug forced null) — this mirrors
     * _buildLocalOnlySyncTree()'s behaviour (collection sections are
     * typically channels, which never carry a Craft parent) and avoids a
     * latent bug in the shared tree macro (sync.twig's per-group
     * childrenOf/rootSlugs build never checks whether a parentSlug is
     * actually present in the same group — a parent slug pointing outside
     * the group, e.g. a collection child's excluded collection-listing
     * parent, would make that row simply never render).
     *
     * An unmapped content_type (no content_types route) is skipped from the
     * tree entirely — mirrors ImportService::_importCollectionChild()'s own
     * non-fatal skip; there's no Craft section to display it under.
     *
     * @param array $data Decoded export payload (batch `{pages: [...]}` or single-page `{document: ...}`).
     * @param string $sectionHandle Pages section handle.
     * @param string[] $collectionSections Ordered list of collection section handles.
     * @return array Same shape as _buildLocalOnlySyncTree()'s $syncGroups.
     */
    private function _buildFullSyncTree(array $data, string $sectionHandle, array $collectionSections): array
    {
        $importService = ContentIQImporter::$plugin->imports;

        // Same batch/single-page detection SyncJob uses.
        $isBatch = isset($data['pages']) && is_array($data['pages']);
        $pages   = [];

        if ($isBatch) {
            foreach ($data['pages'] as $pageData) {
                if (is_array($pageData)) {
                    $pages[] = $pageData;
                }
            }
        } elseif (isset($data['document']) && is_array($data['document'])) {
            $pages[] = $data;
        }

        // Local lock state, keyed by element_id — same source
        // _buildLocalOnlySyncTree() reads, consulted per matched entry below.
        $lockedMap = array_column(
            (new Query())->select(['element_id', 'locked'])->from('{{%contentiq_entry_syncs}}')->all(),
            'locked',
            'element_id',
        );

        $buckets = [];

        foreach ($pages as $pageData) {
            $document    = $pageData['document'] ?? [];
            $slug        = $document['slug'] ?? '';
            $contentType = $document['content_type'] ?? null;
            $isHomepage  = (bool)($document['is_homepage'] ?? false);

            $groupHandle = $sectionHandle;

            if ($contentType !== null) {
                $route = $importService->getContentTypeRoute($contentType);

                if ($route === null) {
                    continue;
                }

                $groupHandle = $route['section'];
            }

            $existingEntry = $importService->findExistingEntry($pageData);
            $elementId     = $existingEntry?->id;
            $isNew         = $elementId === null;
            $locked        = $isNew ? false : (bool)($lockedMap[$elementId] ?? true);

            $buckets[$groupHandle][] = [
                'elementId'       => $elementId,
                'title'           => $document['title'] ?? ($slug !== '' ? $slug : '(untitled)'),
                'slug'            => $slug,
                'locked'          => $locked,
                'parentSlug'      => $contentType === null ? ($document['parent_slug'] ?? null) : null,
                'depth'           => $document['depth'] ?? 0,
                'isHomepage'      => $isHomepage,
                'isNew'           => $isNew,
                'contentiqPageId' => isset($document['id']) ? (int)$document['id'] : null,
            ];
        }

        $syncGroups = [];

        foreach (array_merge([$sectionHandle], $collectionSections) as $groupHandle) {
            if (empty($buckets[$groupHandle])) {
                continue;
            }

            $section = Craft::$app->entries->getSectionByHandle($groupHandle);

            $syncGroups[] = [
                'handle'  => $groupHandle,
                'name'    => $section?->name ?? ucfirst($groupHandle),
                'entries' => $buckets[$groupHandle],
            ];
        }

        return $syncGroups;
    }

    /**
     * Starts the sync queue job.
     *
     * Creates a pending import run, pushes the job onto the queue,
     * and returns JSON with the run ID for the frontend to poll.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionRunSync(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('contentiq-importer:sync');

        $settings = ContentIQImporter::$plugin->getSettings();

        if ($settings->contentiqUrl === '' || $settings->apiKey === '') {
            return $this->asJson([
                'success' => false,
                'error'   => 'ContentiQ API is not configured.',
            ]);
        }

        // Fix C: refuse a second concurrent sync — two near-simultaneous runs
        // would otherwise each rewrite contentiq_entry_syncs lock state and
        // clobber each other's selections.
        if ($this->_hasInFlightSync()) {
            return $this->asJson([
                'success' => false,
                'error'   => 'A sync is already in progress — wait for it to finish.',
            ]);
        }

        // Capture the lock/unlock selection from the pre-sync tree view and
        // the globals consent checkbox here, but DEFER actually writing them
        // until SyncJob::execute() runs (Fix B1) — writing them now, before
        // the job is even queued, would leak an unlock/consent if the worker
        // never picks up the job, or dies before running it. `null` preserves
        // the previous write's guard (`is_array($unlockIds)`): a missing or
        // malformed selection leaves lock state untouched instead of locking
        // everything.
        $request      = Craft::$app->getRequest();
        $unlockIdsRaw = Json::decodeIfJson($request->getBodyParam('unlockIds', '[]'));
        $unlockIds    = is_array($unlockIdsRaw) ? $unlockIdsRaw : null;

        // ContentIQ page ids for unchecked "New" rows — deliberately opt-OUT:
        // an absent/malformed param (broken JS, older cached page) means
        // nothing is excluded and every New page still imports, same as
        // before this feature existed. See SyncJob::$excludeNewIds.
        $excludeNewIdsRaw = Json::decodeIfJson($request->getBodyParam('excludeNewIds', '[]'));
        $excludeNewIds    = is_array($excludeNewIdsRaw)
            ? array_values(array_map('intval', array_filter($excludeNewIdsRaw, 'is_scalar')))
            : [];

        // The globals consent checkbox (checked = unlock for this run only).
        // SyncJob persists the inverse into contentiq_globals_sync.locked at
        // the start of the run, then relocks after a successful globals
        // import — or on any failure, see SyncJob::_failRun().
        $unlockGlobals = (bool)$request->getBodyParam('unlockGlobals', false);

        // Create a pending run record so the frontend has a run ID to poll.
        $runId = $this->_saveRun(
            filename:   'sync',
            type:       'sync',
            pageCount:  0,
            imageCount: 0,
            status:     'pending',
            result:     [],
        );

        // Push the queue job.
        Craft::$app->getQueue()->push(new SyncJob([
            'runId'         => $runId,
            'unlockIds'     => $unlockIds,
            'unlockGlobals' => $unlockGlobals,
            'excludeNewIds' => $excludeNewIds,
        ]));

        return $this->asJson([
            'success' => true,
            'runId'   => $runId,
        ]);
    }

    /**
     * Polls the status of a sync run.
     *
     * Returns JSON with the current status — the frontend polls until
     * status is no longer 'pending'.
     *
     * @return Response
     */
    public function actionSyncStatus(): Response
    {
        $this->requireAcceptsJson();

        $runId = Craft::$app->getRequest()->getRequiredQueryParam('runId');

        $run = (new Query())
            ->select(['status', 'dateCreated', 'dateUpdated'])
            ->from('{{%contentiq_import_runs}}')
            ->where(['id' => $runId])
            ->one();

        if ($run === null) {
            return $this->asJson(['status' => 'unknown']);
        }

        $status = $run['status'];

        // Fix A2: a run that's been 'pending' too long almost always means the
        // queue worker never picked up the job (or the process died before
        // SyncJob's own try/catch — Fix A1 — could mark it failed), so the
        // poller would otherwise spin on 'pending' forever. A false-positive
        // stale flag only ever surfaces an error message, so this is
        // deliberately generous: a long threshold, plus a best-effort check
        // that no queued/reserved SyncJob explains the delay.
        if ($status === 'pending'
            && $this->_isStale($run['dateUpdated'] ?: $run['dateCreated'])
            && !$this->_hasActiveSyncJob()
        ) {
            $this->_markRunErrored((int)$runId, 'Sync did not complete — the queue worker may not be running.');
            $status = 'errors';
        }

        return $this->asJson([
            'status' => $status ?: 'unknown',
        ]);
    }

    /**
     * Sync result screen — hierarchical report of a completed sync.
     *
     * @param int $runId
     * @return Response
     */
    public function actionSyncResult(int $runId): Response
    {
        $run = (new Query())
            ->from('{{%contentiq_import_runs}}')
            ->where(['id' => $runId])
            ->one();

        if ($run === null) {
            throw new \yii\web\NotFoundHttpException('Sync run not found.');
        }

        $run['result'] = Json::decodeIfJson($run['result'] ?? '[]');

        return $this->renderTemplate('contentiq-importer/_cp/sync-result', [
            'run' => $run,
        ]);
    }

    /**
     * Syncs a single entry from the ContentIQ API.
     *
     * Called via AJAX from the ContentIQ sidebar widget on the entry edit screen.
     * Fetches the single-page export for the entry's slug, runs it through
     * ImportService, and upserts a row in contentiq_entry_syncs on success.
     *
     * Request body: { elementId: int, slug: string }
     * Response:     { success: bool, syncedAt?: string, error?: string }
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionWidgetSync(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('contentiq-importer:sync');

        $request   = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $slug      = trim((string)$request->getRequiredBodyParam('slug'));

        if (!$elementId || $slug === '') {
            return $this->asJson(['success' => false, 'error' => 'elementId and slug are required.']);
        }

        // Enforce the lock server-side. The sidebar Sync button is disabled
        // client-side when locked, but that's advisory only — a stale page or a
        // direct request to this endpoint could still reach here. The widget
        // targets a specific, already-saved entry (elementId), so the lock is
        // checked against that entry directly rather than re-resolving it from
        // the fetched page data. A missing sync row is treated as locked (same
        // default as the Sync UI / SyncJob).
        $lockRow = (new Query())
            ->select(['locked'])
            ->from('{{%contentiq_entry_syncs}}')
            ->where(['element_id' => $elementId])
            ->one();

        // craft\db\Query::one() returns null when no row matches.
        if ($lockRow === null || (bool)$lockRow['locked']) {
            return $this->asJson([
                'success' => false,
                'error'   => 'Entry is locked — unlock before syncing.',
            ]);
        }

        // Look up entry title for user-facing messages.
        $entryTitle = Entry::find()->id($elementId)->status(null)->select(['title'])->scalar() ?: $slug;

        $settings = ContentIQImporter::$plugin->getSettings();

        if ($settings->contentiqUrl === '' || $settings->apiKey === '') {
            return $this->asJson([
                'success' => false,
                'error'   => 'ContentiQ API is not configured. Set URL and API key in plugin settings.',
            ]);
        }

        // Map Craft slug to ContentIQ slug if configured.
        $config       = Craft::$app->config->getConfigFromFile('contentiq');
        $slugMap      = $config['slugMap'] ?? [];
        $contentiqSlug = $slugMap[$slug] ?? $slug;

        $url      = rtrim(App::parseEnv($settings->contentiqUrl), '/');
        $endpoint = "{$url}/api/v1/pages/{$contentiqSlug}/export";

        try {
            $response = Craft::createGuzzleClient()->request('GET', $endpoint, [
                RequestOptions::HEADERS => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . App::parseEnv($settings->apiKey),
                ],
                RequestOptions::TIMEOUT         => 30,
                RequestOptions::CONNECT_TIMEOUT => 10,
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $this->asJson(['success' => false, 'error' => 'ContentiQ returned invalid JSON.']);
            }
        } catch (GuzzleException $e) {
            $status = method_exists($e, 'getResponse') && $e->getResponse() !== null
                ? $e->getResponse()->getStatusCode()
                : 0;

            $message = $status === 404
                ? "'{$entryTitle}' is not ready for export in ContentiQ."
                : 'API request failed: ' . $e->getMessage();

            return $this->asJson(['success' => false, 'error' => $message]);
        }

        // Run the import pipeline (no dry-run).
        $result = ContentIQImporter::$plugin->imports->importPage($data, dryRun: false);

        if (!$result['success']) {
            // Fix D1: importPage() above may have attempted to write content —
            // record it in the audit trail the same as every other entry point,
            // rather than leaving this a content mutation with no history row.
            $this->_saveRun(
                filename:   $slug,
                type:       'widget',
                pageCount:  0,
                imageCount: 0,
                status:     'errors',
                result:     [$result],
            );

            return $this->asJson([
                'success' => false,
                'error'   => $result['error'] ?? 'Import failed.',
            ]);
        }

        // PASS 2: resolve deferred card references (single page — DB lookups only).
        if (!empty($result['cardRefs']) && ($result['entryId'] ?? null) !== null) {
            $cardWarnings = ContentIQImporter::$plugin->imports->resolveCardReferences(
                [$result['entryId'] => array_values($result['cardRefs'])],
                [],
            );

            foreach ($cardWarnings[$result['entryId']] ?? [] as $warning) {
                Craft::warning("Widget sync card refs ({$slug}): {$warning}", __METHOD__);
            }
        }

        // Upsert the sync timestamp and notes.
        $now   = Db::prepareDateForDb(new \DateTime());
        $notes = $result['blockNotes'] ?? '';
        $db    = Craft::$app->getDb();

        $exists = (new Query())
            ->from('{{%contentiq_entry_syncs}}')
            ->where(['element_id' => $elementId])
            ->exists();

        $syncData = ['synced_at' => $now, 'notes' => $notes, 'locked' => true];

        // Record the ContentIQ page id → element_id mapping (when the fetched
        // page carried one) so findExistingEntry() can resolve this entry by
        // id on the next sync even if its slug changes.
        $pageId = isset($data['document']['id']) ? (int)$data['document']['id'] : null;

        if ($pageId !== null) {
            $syncData['contentiq_page_id'] = $pageId;
        }

        if ($exists) {
            $db->createCommand()
                ->update('{{%contentiq_entry_syncs}}', $syncData, ['element_id' => $elementId])
                ->execute();
        } else {
            $db->createCommand()
                ->insert('{{%contentiq_entry_syncs}}', array_merge(['element_id' => $elementId], $syncData))
                ->execute();
        }

        // Acknowledge this page with ContentiQ so it can retire its own
        // pending-import state — same contract SyncJob uses for batch syncs.
        // Best-effort: never fails the widget sync.
        if ($pageId !== null) {
            $ackResult = ContentIQImporter::$plugin->api->ackPages([$pageId]);

            if (!$ackResult['success']) {
                Craft::warning(
                    "ContentIQImporter: widget sync ack failed for page {$pageId} ({$slug}) — " . ($ackResult['error'] ?? 'unknown error'),
                    __METHOD__,
                );
            }
        }

        $syncedAt = Craft::$app->getFormatter()->asDatetime($now, 'short');

        // Fix D1: record this widget sync in the audit trail, same as every
        // other content-mutating entry point (upload/import, sync, console).
        $this->_saveRun(
            filename:   $slug,
            type:       'widget',
            pageCount:  1,
            imageCount: count($result['images'] ?? []),
            status:     !empty($result['warnings']) ? 'warnings' : 'success',
            result:     [$result],
        );

        return $this->asJson(['success' => true, 'syncedAt' => $syncedAt, 'notes' => $notes]);
    }

    /**
     * Toggles the lock state for an entry's ContentIQ sync record.
     *
     * Locked entries are skipped during batch/full syncs.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionToggleLock(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('contentiq-importer:sync');

        $request   = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $locked    = (bool)$request->getRequiredBodyParam('locked');

        $db = Craft::$app->getDb();

        $exists = (new Query())
            ->from('{{%contentiq_entry_syncs}}')
            ->where(['element_id' => $elementId])
            ->exists();

        if ($exists) {
            $db->createCommand()
                ->update('{{%contentiq_entry_syncs}}', ['locked' => $locked], ['element_id' => $elementId])
                ->execute();
        } else {
            $db->createCommand()
                ->insert('{{%contentiq_entry_syncs}}', [
                    'element_id' => $elementId,
                    'locked'     => $locked,
                    'synced_at'  => Db::prepareDateForDb(new \DateTime()),
                ])
                ->execute();
        }

        return $this->asJson(['success' => true, 'locked' => $locked]);
    }

    /**
     * Clears the notes for an entry's ContentIQ sync record.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionClearNotes(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('contentiq-importer:sync');

        $elementId = (int)Craft::$app->getRequest()->getRequiredBodyParam('elementId');

        Craft::$app->getDb()->createCommand()
            ->update('{{%contentiq_entry_syncs}}', ['notes' => ''], ['element_id' => $elementId])
            ->execute();

        return $this->asJson(['success' => true]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the cascade data for the Mappings screen: every section with its
     * entry types, and each entry type's CKEditor and PlainText field handles.
     *
     * Embedded as JSON so the vanilla-JS dropdowns can cascade section → entry
     * type → content field (CKEditor) / heading field (PlainText) / blocks
     * field (Matrix).
     *
     * @return array<int, array{handle: string, name: string, entryTypes: array<int, array{handle: string, name: string, ckeditorFields: array<int, array{handle: string, name: string}>, plainTextFields: array<int, array{handle: string, name: string}>, matrixFields: array<int, array{handle: string, name: string}>}>}>
     */
    private function _buildSectionsData(): array
    {
        $data = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $entryTypes = [];

            foreach ($section->getEntryTypes() as $entryType) {
                $ckeditorFields  = [];
                $plainTextFields = [];
                $matrixFields    = [];

                foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
                    // CKEditor is an optional dependency — reference it by its
                    // fully-qualified name so this works without the plugin.
                    if ($field instanceof \craft\ckeditor\Field) {
                        $ckeditorFields[] = ['handle' => $field->handle, 'name' => $field->name];
                    } elseif ($field instanceof PlainText) {
                        $plainTextFields[] = ['handle' => $field->handle, 'name' => $field->name];
                    } elseif ($field instanceof Matrix) {
                        $matrixFields[] = ['handle' => $field->handle, 'name' => $field->name];
                    }
                }

                $entryTypes[] = [
                    'handle'          => $entryType->handle,
                    'name'            => $entryType->name,
                    'ckeditorFields'  => $ckeditorFields,
                    'plainTextFields' => $plainTextFields,
                    'matrixFields'    => $matrixFields,
                ];
            }

            $data[] = [
                'handle'     => $section->handle,
                'name'       => $section->name,
                'entryTypes' => $entryTypes,
            ];
        }

        return $data;
    }

    /**
     * How long a run may sit in 'pending' before it's treated as abandoned
     * rather than genuinely in flight — e.g. a queue worker that isn't
     * running, or one that died before Fix A1's try/catch in SyncJob could
     * mark it failed. Shared by the status poller (Fix A2) and the
     * concurrency guard (Fix C). A false-positive stale flag only ever
     * surfaces an error message or briefly blocks a re-click, so this is
     * deliberately generous.
     *
     * @var int
     */
    private const STALE_SYNC_SECONDS = 600;

    /**
     * Whether a UTC timestamp (as stored by this plugin — see Fix D2) is
     * older than {@see self::STALE_SYNC_SECONDS}.
     *
     * @param string|null $timestamp
     * @return bool
     */
    private function _isStale(?string $timestamp): bool
    {
        if ($timestamp === null || $timestamp === '') {
            return false;
        }

        try {
            $updated = new \DateTime($timestamp, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return false;
        }

        $ageSeconds = (new \DateTime('now', new \DateTimeZone('UTC')))->getTimestamp() - $updated->getTimestamp();

        return $ageSeconds >= self::STALE_SYNC_SECONDS;
    }

    /**
     * Best-effort check for a queued/reserved SyncJob row, used as an extra
     * confidence signal before Fix A2 flips a stale 'pending' run to
     * 'errors'. Only the default DB-backed queue driver is cheaply
     * queryable this way — any other driver (Redis, SQS, …), or any query
     * failure (e.g. a `LIKE` on a binary column some DB engines reject), is
     * treated as "can't tell" and falls through to `false`, so this is
     * never a hard requirement, only a booster.
     *
     * @return bool
     */
    private function _hasActiveSyncJob(): bool
    {
        if (!Craft::$app->getQueue() instanceof \craft\queue\Queue) {
            return false;
        }

        try {
            return (new Query())
                ->from('{{%queue}}')
                ->where(['fail' => false])
                ->andWhere(['like', 'job', SyncJob::class])
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether a sync is already in flight — a non-stale 'pending' row in
     * contentiq_import_runs. Used by actionRunSync() (Fix C) to refuse a
     * second concurrent sync. Deliberately relies on the run row alone
     * (not {@see self::_hasActiveSyncJob()}) — a false positive here just
     * makes the user wait and retry, whereas a false negative would let two
     * syncs clobber each other's lock selections, which is the bug this
     * guards against.
     *
     * @return bool
     */
    private function _hasInFlightSync(): bool
    {
        $pending = (new Query())
            ->select(['dateCreated', 'dateUpdated'])
            ->from('{{%contentiq_import_runs}}')
            ->where(['status' => 'pending', 'type' => 'sync'])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();

        if ($pending === null) {
            return false;
        }

        return !$this->_isStale($pending['dateUpdated'] ?: $pending['dateCreated']);
    }

    /**
     * Marks a run 'errors' with a given message — used by the status
     * poller's staleness fallback (Fix A2). Mirrors SyncJob::_failRun()'s
     * result shape and globals-relock safety net: a worker killed mid-run
     * (rather than a clean throw, which SyncJob's own try/catch — Fix A1 —
     * already handles) could have left globals consent unlocked.
     *
     * @param int    $runId
     * @param string $message
     * @return void
     */
    private function _markRunErrored(int $runId, string $message): void
    {
        $db = Craft::$app->getDb();

        $db->createCommand()->update(
            '{{%contentiq_import_runs}}',
            [
                'status'      => 'errors',
                'result'      => Json::encode([['success' => false, 'slug' => '', 'error' => $message, 'warnings' => []]]),
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ],
            ['id' => $runId],
        )->execute();

        // Only touch an existing row — same guard as SyncJob::_failRun().
        $exists = (new Query())->from('{{%contentiq_globals_sync}}')->exists();

        if ($exists) {
            $db->createCommand()
                ->update('{{%contentiq_globals_sync}}', ['locked' => true])
                ->execute();
        }
    }

    /**
     * Saves an import run to the history table.
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
        $db = Craft::$app->getDb();

        $db->createCommand()->insert('{{%contentiq_import_runs}}', [
            'importedBy'  => Craft::$app->getUser()->getId(),
            'filename'    => $filename,
            'type'        => $type,
            'pageCount'   => $pageCount,
            'imageCount'  => $imageCount,
            'status'      => $status,
            'result'      => Json::encode($result),
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid'         => \craft\helpers\StringHelper::UUID(),
        ])->execute();

        return (int)$db->getLastInsertID();
    }
}
