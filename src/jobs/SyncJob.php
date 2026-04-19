<?php

namespace matrixcreate\copydeckimporter\jobs;

use Craft;
use craft\db\Query;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use matrixcreate\copydeckimporter\CopydeckImporter;

/**
 * Queue job that runs a full Copydeck API sync.
 *
 * Fetches the export from the Copydeck API, then imports each page
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
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Web requests already run from webroot, but queue workers run from
        // the project root — match the CLI behaviour.
        chdir(Craft::getAlias('@webroot'));

        $plugin = CopydeckImporter::$plugin;

        // 1. Fetch export from API.
        $apiResult = $plugin->api->fetchExport();

        if (!$apiResult['success']) {
            $this->_failRun($apiResult['error'] ?? 'API request failed.');
            return;
        }

        $data = $apiResult['data'];

        // 2. Determine pages array.
        $isBatch = isset($data['pages']) && is_array($data['pages']);
        $pages   = $isBatch ? $data['pages'] : [$data];
        $total   = count($pages);

        // 3. Import each page.
        $importService = $plugin->imports;
        $pageResults   = [];
        $totalImages   = 0;
        $hasErrors     = false;
        $hasWarnings   = false;

        // Resolve section for structure positioning.
        $config        = Craft::$app->config->getConfigFromFile('copydeck');
        $sectionHandle = $config['section'] ?? 'pages';
        $section       = Craft::$app->entries->getSectionByHandle($sectionHandle);
        $structureId   = $section?->structureId;
        $structures    = Craft::$app->getStructures();

        // Build slug → entry ID map for hierarchy resolution.
        $slugToEntryId = [];

        foreach ($pages as $i => $pageData) {
            $this->setProgress($queue, $i / $total, "Importing page " . ($i + 1) . " of {$total}");

            // Skip locked entries.
            $pageSlug = $pageData['document']['slug'] ?? '';
            $existingEntry = \craft\elements\Entry::find()
                ->section($sectionHandle)
                ->slug($pageSlug)
                ->status(null)
                ->one();

            if ($existingEntry !== null) {
                $isLocked = (new Query())
                    ->select(['locked'])
                    ->from('{{%copydeck_entry_syncs}}')
                    ->where(['element_id' => $existingEntry->id])
                    ->scalar();

                if ($isLocked) {
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
                        'blockNotes' => '',
                        'seoFieldCount' => 0,
                        'warnings' => ['Skipped — entry is locked.'],
                        'error' => null,
                    ];

                    if ($pageSlug !== '') {
                        $slugToEntryId[$pageSlug] = $existingEntry->id;
                    }
                    continue;
                }
            }

            $result = $importService->importPage($pageData, dryRun: false);

            // Handle hierarchy: always re-apply parent and position on every run.
            $parentSlug = $pageData['document']['parent_slug'] ?? null;
            $entryId    = $result['entryId'] ?? null;
            $slug       = $result['slug'] ?? '';
            $isHomepage = (bool)($pageData['document']['is_homepage'] ?? false);

            if ($entryId !== null && $structureId !== null && !$isHomepage) {
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

            // Track slug → entry ID for child lookups.
            if ($entryId !== null && $slug !== '') {
                $slugToEntryId[$slug] = $entryId;
            }

            // Attach hierarchy metadata for the report template.
            $result['title']      = $pageData['document']['title'] ?? $slug;
            $result['depth']      = $pageData['document']['depth'] ?? 0;
            $result['parentSlug'] = $pageData['document']['parent_slug'] ?? null;

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

        // 4. Determine overall status.
        if ($hasErrors) {
            $status = 'errors';
        } elseif ($hasWarnings) {
            $status = 'warnings';
        } else {
            $status = 'success';
        }

        // 5. Update the pre-created run record.
        Craft::$app->getDb()->createCommand()->update(
            '{{%copydeck_import_runs}}',
            [
                'pageCount'   => count($pageResults),
                'imageCount'  => $totalImages,
                'status'      => $status,
                'result'      => Json::encode($pageResults),
                'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
            ],
            ['id' => $this->runId],
        )->execute();

        // 6. Auto-lock all successfully synced entries.
        // After every sync, imported entries are locked so subsequent syncs
        // won't overwrite them unless the user explicitly unlocks them.
        $db  = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($pageResults as $result) {
            if (!($result['success'] ?? false) || !($result['entryId'] ?? null)) {
                continue;
            }

            $entryId = $result['entryId'];

            $exists = (new Query())
                ->from('{{%copydeck_entry_syncs}}')
                ->where(['element_id' => $entryId])
                ->exists();

            if ($exists) {
                $db->createCommand()->update('{{%copydeck_entry_syncs}}', [
                    'locked'    => true,
                    'synced_at' => $now,
                    'notes'     => $result['blockNotes'] ?? '',
                ], ['element_id' => $entryId])->execute();
            } else {
                $db->createCommand()->insert('{{%copydeck_entry_syncs}}', [
                    'element_id' => $entryId,
                    'locked'     => true,
                    'synced_at'  => $now,
                    'notes'      => $result['blockNotes'] ?? '',
                ])->execute();
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('copydeck-importer', 'Syncing content from Copydeck');
    }

    /**
     * Marks the run as failed with an error message.
     *
     * @param string $error
     */
    private function _failRun(string $error): void
    {
        Craft::$app->getDb()->createCommand()->update(
            '{{%copydeck_import_runs}}',
            [
                'status'      => 'errors',
                'result'      => Json::encode([['success' => false, 'slug' => '', 'error' => $error, 'warnings' => []]]),
                'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
            ],
            ['id' => $this->runId],
        )->execute();
    }
}
