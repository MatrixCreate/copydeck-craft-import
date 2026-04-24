<?php

namespace matrixcreate\copydeckimporter\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\web\Controller;
use craft\web\UploadedFile;
use craft\helpers\StringHelper;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use matrixcreate\copydeckimporter\CopydeckImporter;
use matrixcreate\copydeckimporter\jobs\SyncJob;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * CP controller for the Copydeck Importer dashboard, upload, preview, and result screens.
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
        $settings = CopydeckImporter::$plugin->getSettings();
        $apiConfigured = $settings->copydeckUrl !== ''
            && $settings->apiKey !== '';

        return $this->renderTemplate('copydeck-importer/_cp/index', [
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
            ->from('{{%copydeck_import_runs}}')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(50)
            ->all();

        return $this->renderTemplate('copydeck-importer/_cp/history', [
            'runs' => $runs,
        ]);
    }

    /**
     * Upload screen — file picker and drag-and-drop.
     *
     * @return Response
     */
    public function actionUpload(): Response
    {
        return $this->renderTemplate('copydeck-importer/_cp/upload');
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

        $uploadedFile = UploadedFile::getInstanceByName('jsonFile');

        if ($uploadedFile === null) {
            Craft::$app->getSession()->setError('No file was uploaded.');

            return $this->redirect('copydeck-importer/upload');
        }

        $json = file_get_contents($uploadedFile->tempName);

        if ($json === false) {
            Craft::$app->getSession()->setError('Could not read the uploaded file.');

            return $this->redirect('copydeck-importer/upload');
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Craft::$app->getSession()->setError('Invalid JSON: ' . json_last_error_msg());

            return $this->redirect('copydeck-importer/upload');
        }

        // Detect single vs batch.
        $isBatch = isset($data['pages']) && is_array($data['pages']);
        $pages   = $isBatch ? $data['pages'] : [$data];

        // Prepare services for dry-run.
        $importService = CopydeckImporter::$plugin->imports;

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

        // Store JSON in session for the import step.
        $tempFilename = 'copydeck-import-' . gmdate('ymd_His') . '.json';
        $tempPath     = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . $tempFilename;
        file_put_contents($tempPath, $json);

        return $this->renderTemplate('copydeck-importer/_cp/preview', [
            'pages'         => $previewResults,
            'isBatch'       => $isBatch,
            'pageCount'     => count($previewResults),
            'createCount'   => $createCount,
            'updateCount'   => $updateCount,
            'totalWarnings' => $totalWarnings,
            'tempFilename'  => $tempFilename,
            'exportDate'    => $data['exported_at'] ?? null,
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

        $tempFilename = Craft::$app->getRequest()->getRequiredBodyParam('tempFilename');
        $tempPath     = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . $tempFilename;

        if (!is_file($tempPath)) {
            Craft::$app->getSession()->setError('Import file expired. Please upload again.');

            return $this->redirect('copydeck-importer/upload');
        }

        $json = file_get_contents($tempPath);
        $data = json_decode($json, true);

        if ($data === null) {
            Craft::$app->getSession()->setError('Could not parse import file.');

            return $this->redirect('copydeck-importer/upload');
        }

        // Web requests run from webroot already — no chdir needed (unlike CLI).

        $isBatch = isset($data['pages']) && is_array($data['pages']);
        $pages   = $isBatch ? $data['pages'] : [$data];

        $importService = CopydeckImporter::$plugin->imports;
        $pageResults   = [];
        $totalImages   = 0;
        $hasErrors     = false;
        $hasWarnings   = false;
        $slugToEntryId = [];

        // Resolve section for structure positioning.
        $config        = Craft::$app->config->getConfigFromFile('copydeck');
        $sectionHandle = $config['section'] ?? 'pages';
        $section       = Craft::$app->entries->getSectionByHandle($sectionHandle);
        $structureId   = $section?->structureId;
        $structures    = Craft::$app->getStructures();

        foreach ($pages as $pageData) {
            $result = $importService->importPage($pageData, dryRun: false);

            $slug       = $result['slug'] ?? '';
            $parentSlug = $pageData['document']['parent_slug'] ?? null;
            $entryId    = $result['entryId'] ?? null;
            $isHomepage = (bool)($pageData['document']['is_homepage'] ?? false);

            if ($entryId !== null && $slug !== '') {
                $slugToEntryId[$slug] = $entryId;
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

        return $this->redirect('copydeck-importer/result/' . $runId);
    }

    /**
     * Result screen — shows the outcome of a completed import run.
     *
     * @param int $runId
     * @return Response
     */
    public function actionResult(int $runId): Response
    {
        $run = (new Query())
            ->from('{{%copydeck_import_runs}}')
            ->where(['id' => $runId])
            ->one();

        if ($run === null) {
            throw new \yii\web\NotFoundHttpException('Import run not found.');
        }

        $run['result'] = Json::decodeIfJson($run['result'] ?? '[]');

        return $this->renderTemplate('copydeck-importer/_cp/result', [
            'run' => $run,
        ]);
    }

    /**
     * Sync screen — shows API details and sync button.
     *
     * @return Response
     */
    public function actionSync(): Response
    {
        $settings = CopydeckImporter::$plugin->getSettings();

        if ($settings->copydeckUrl === '' || $settings->apiKey === '') {
            Craft::$app->getSession()->setError('Copydeck API is not configured. Set URL and API key in plugin settings.');

            return $this->redirect('copydeck-importer');
        }

        // Build tree data from existing sync records.
        $syncRecords = (new Query())
            ->select(['element_id', 'locked'])
            ->from('{{%copydeck_entry_syncs}}')
            ->all();

        $hasSyncRecords = count($syncRecords) > 0;
        $syncEntries    = [];

        if ($hasSyncRecords) {
            $elementIds = array_column($syncRecords, 'element_id');
            $lockedMap  = array_column($syncRecords, 'locked', 'element_id');

            $config        = Craft::$app->config->getConfigFromFile('copydeck');
            $sectionHandle = $config['section'] ?? 'pages';

            $entries = Entry::find()
                ->section([$sectionHandle, 'homepage'])
                ->id($elementIds)
                ->status(null)
                ->all();

            foreach ($entries as $entry) {
                $isHomepage = $entry->section->handle === 'homepage';
                $parent     = $isHomepage ? null : $entry->getParent();

                $syncEntries[] = [
                    'elementId'  => $entry->id,
                    'title'      => $entry->title,
                    'slug'       => $entry->slug,
                    'locked'     => (bool)($lockedMap[$entry->id] ?? true),
                    'parentSlug' => $parent?->slug,
                    'depth'      => $isHomepage ? 0 : ($entry->level - 1),
                    'isHomepage' => $isHomepage,
                ];
            }
        }

        // Parse the project slug from the API key (cpd_{slug}_{32chars}) for display.
        $inferredSlug = '';
        $apiKey = App::parseEnv($settings->apiKey);

        if (str_starts_with($apiKey, 'cpd_')) {
            $withoutPrefix = substr($apiKey, 4);
            $lastUnderscore = strrpos($withoutPrefix, '_');

            if ($lastUnderscore !== false) {
                $inferredSlug = substr($withoutPrefix, 0, $lastUnderscore);
            }
        }

        return $this->renderTemplate('copydeck-importer/_cp/sync', [
            'copydeckUrl'    => App::parseEnv($settings->copydeckUrl),
            'projectSlug'    => $inferredSlug,
            'hasSyncRecords' => $hasSyncRecords,
            'syncEntries'    => $syncEntries,
        ]);
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

        $settings = CopydeckImporter::$plugin->getSettings();

        if ($settings->copydeckUrl === '' || $settings->apiKey === '') {
            return $this->asJson([
                'success' => false,
                'error'   => 'Copydeck API is not configured.',
            ]);
        }

        // Apply lock/unlock state from the pre-sync tree view.
        // The browser sends the list of entry IDs the user has unlocked.
        $request   = Craft::$app->getRequest();
        $unlockIds = Json::decodeIfJson($request->getBodyParam('unlockIds', '[]'));

        if (is_array($unlockIds) && !empty($unlockIds)) {
            $db = Craft::$app->getDb();

            // Lock everything first, then unlock only the selected entries.
            $db->createCommand()
                ->update('{{%copydeck_entry_syncs}}', ['locked' => true])
                ->execute();

            $db->createCommand()
                ->update(
                    '{{%copydeck_entry_syncs}}',
                    ['locked' => false],
                    ['element_id' => $unlockIds],
                )
                ->execute();
        }

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
            'runId' => $runId,
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

        $status = (new Query())
            ->select(['status'])
            ->from('{{%copydeck_import_runs}}')
            ->where(['id' => $runId])
            ->scalar();

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
            ->from('{{%copydeck_import_runs}}')
            ->where(['id' => $runId])
            ->one();

        if ($run === null) {
            throw new \yii\web\NotFoundHttpException('Sync run not found.');
        }

        $run['result'] = Json::decodeIfJson($run['result'] ?? '[]');

        return $this->renderTemplate('copydeck-importer/_cp/sync-result', [
            'run' => $run,
        ]);
    }

    /**
     * Syncs a single entry from the Copydeck API.
     *
     * Called via AJAX from the Copydeck sidebar widget on the entry edit screen.
     * Fetches the single-page export for the entry's slug, runs it through
     * ImportService, and upserts a row in copydeck_entry_syncs on success.
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

        $request   = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $slug      = trim((string)$request->getRequiredBodyParam('slug'));

        if (!$elementId || $slug === '') {
            return $this->asJson(['success' => false, 'error' => 'elementId and slug are required.']);
        }

        // Look up entry title for user-facing messages.
        $entryTitle = Entry::find()->id($elementId)->status(null)->select(['title'])->scalar() ?: $slug;

        $settings = CopydeckImporter::$plugin->getSettings();

        if ($settings->copydeckUrl === '' || $settings->apiKey === '') {
            return $this->asJson([
                'success' => false,
                'error'   => 'Copydeck API is not configured. Set URL and API key in plugin settings.',
            ]);
        }

        // Map Craft slug to Copydeck slug if configured.
        $config       = Craft::$app->config->getConfigFromFile('copydeck');
        $slugMap      = $config['slugMap'] ?? [];
        $copydeckSlug = $slugMap[$slug] ?? $slug;

        $url      = rtrim(App::parseEnv($settings->copydeckUrl), '/');
        $endpoint = "{$url}/api/v1/pages/{$copydeckSlug}/export";

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
                return $this->asJson(['success' => false, 'error' => 'Copydeck returned invalid JSON.']);
            }
        } catch (GuzzleException $e) {
            $status = method_exists($e, 'getResponse') && $e->getResponse() !== null
                ? $e->getResponse()->getStatusCode()
                : 0;

            $message = $status === 404
                ? "'{$entryTitle}' is not ready for export in Copydeck."
                : 'API request failed: ' . $e->getMessage();

            return $this->asJson(['success' => false, 'error' => $message]);
        }

        // Run the import pipeline (no dry-run).
        $result = CopydeckImporter::$plugin->imports->importPage($data, dryRun: false);

        if (!$result['success']) {
            return $this->asJson([
                'success' => false,
                'error'   => $result['error'] ?? 'Import failed.',
            ]);
        }

        // Upsert the sync timestamp and notes.
        $now   = Db::prepareDateForDb(new \DateTime());
        $notes = $result['blockNotes'] ?? '';
        $db    = Craft::$app->getDb();

        $exists = (new Query())
            ->from('{{%copydeck_entry_syncs}}')
            ->where(['element_id' => $elementId])
            ->exists();

        $syncData = ['synced_at' => $now, 'notes' => $notes];

        if ($exists) {
            $db->createCommand()
                ->update('{{%copydeck_entry_syncs}}', $syncData, ['element_id' => $elementId])
                ->execute();
        } else {
            $db->createCommand()
                ->insert('{{%copydeck_entry_syncs}}', array_merge(['element_id' => $elementId], $syncData))
                ->execute();
        }

        $syncedAt = Craft::$app->getFormatter()->asDatetime($now, 'short');

        return $this->asJson(['success' => true, 'syncedAt' => $syncedAt, 'notes' => $notes]);
    }

    /**
     * Toggles the lock state for an entry's Copydeck sync record.
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

        $request   = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $locked    = (bool)$request->getRequiredBodyParam('locked');

        $db = Craft::$app->getDb();

        $exists = (new Query())
            ->from('{{%copydeck_entry_syncs}}')
            ->where(['element_id' => $elementId])
            ->exists();

        if ($exists) {
            $db->createCommand()
                ->update('{{%copydeck_entry_syncs}}', ['locked' => $locked], ['element_id' => $elementId])
                ->execute();
        } else {
            $db->createCommand()
                ->insert('{{%copydeck_entry_syncs}}', [
                    'element_id' => $elementId,
                    'locked'     => $locked,
                    'synced_at'  => (new \DateTime())->format('Y-m-d H:i:s'),
                ])
                ->execute();
        }

        return $this->asJson(['success' => true, 'locked' => $locked]);
    }

    /**
     * Clears the notes for an entry's Copydeck sync record.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionClearNotes(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $elementId = (int)Craft::$app->getRequest()->getRequiredBodyParam('elementId');

        Craft::$app->getDb()->createCommand()
            ->update('{{%copydeck_entry_syncs}}', ['notes' => ''], ['element_id' => $elementId])
            ->execute();

        return $this->asJson(['success' => true]);
    }

    // Private Methods
    // =========================================================================

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

        $db->createCommand()->insert('{{%copydeck_import_runs}}', [
            'importedBy'  => Craft::$app->getUser()->getId(),
            'filename'    => $filename,
            'type'        => $type,
            'pageCount'   => $pageCount,
            'imageCount'  => $imageCount,
            'status'      => $status,
            'result'      => Json::encode($result),
            'dateCreated' => (new \DateTime())->format('Y-m-d H:i:s'),
            'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
            'uid'         => \craft\helpers\StringHelper::UUID(),
        ])->execute();

        return (int)$db->getLastInsertID();
    }
}
