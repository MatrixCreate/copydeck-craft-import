<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\models\FieldLayout;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\helpers\GlobalsTransforms;
use Throwable;
use yii\base\Component;

/**
 * Imports the ContentiQ globals payload (company/offices/branding/social/trust
 * signals/scripts) into the Craft offices section and the companyInfo,
 * globalContent, and siteConfig global sets.
 *
 * All writes are field-level merges against the sync-owned handle list — no
 * other field is ever touched. Every field write is filtered against the
 * target's field layout, so a handle missing on an older project is skipped
 * with a report note rather than throwing.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.3.0
 */
class GlobalsImportService extends Component
{
    // Constants
    // =========================================================================

    /**
     * Sync-owned handles on office entries. NOTHING outside this list is written.
     *
     * @var string[]
     */
    public const OFFICE_FIELDS = [
        'companyName',
        'addressLine1',
        'addressLine2',
        'addressLine3',
        'townCity',
        'county',
        'postcode',
        'country',
        'telephone',
        'email',
        'locationMapEmbedCode',
        'openingHours',
        'limitedCompanyNumber',
        'vatNumber',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Compares each exported collection's url_prefix against its mapped Craft
     * section uriFormat and returns advisory drift warnings.
     *
     * Read-only: never mutates section settings or project config. Runs on every
     * sync, even when globals are locked.
     *
     * @param array $collections ContentiQ globals.collections: [{slug, url_prefix}].
     * @return string[]
     */
    public function checkUrlPrefixDrift(array $collections): array
    {
        $warnings = [];
        $map      = ContentIQImporter::$plugin->imports->getContentTypesMap();
        $siteId   = Craft::$app->getSites()->getPrimarySite()->id;

        foreach ($collections as $collection) {
            $slug      = $collection['slug'] ?? null;
            $urlPrefix = (string)($collection['url_prefix'] ?? '');

            if ($slug === null || $urlPrefix === '') {
                continue;
            }

            $sectionHandle = $map[$slug]['section'] ?? null;

            if ($sectionHandle === null) {
                continue;
            }

            $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);

            if ($section === null) {
                continue;
            }

            $siteSettings = $section->getSiteSettings()[$siteId] ?? null;

            if ($siteSettings === null || !$siteSettings->hasUrls) {
                continue;
            }

            $uriFormat = (string)$siteSettings->uriFormat;

            if (GlobalsTransforms::urlPrefixDrifts($urlPrefix, $uriFormat)) {
                $warnings[] = "Collection `{$slug}` exports URLs under `/{$urlPrefix}/…` "
                    . "but section `{$sectionHandle}` uses `/{$uriFormat}`.";
            }
        }

        return $warnings;
    }

    /**
     * Imports the globals payload. Returns a structured report; when $dryRun is
     * true nothing is written (image imports use the dry-run path).
     *
     * @param array $globals Decoded globals object from the export envelope.
     * @param bool  $dryRun
     * @return array
     */
    public function import(array $globals, bool $dryRun = false): array
    {
        $report = [
            'locked'      => false,
            'skipped'     => null,
            'offices'     => [
                'created'   => [],
                'updated'   => [],
                'adopted'   => [],
                'deleted'   => [],
                'unmatched' => [],
            ],
            'globalSets'  => [],
            'fieldNotes'  => [],
            'warnings'    => [],
            'drift'       => [],
            'imageCount'  => 0,
            'imagesReused' => 0,
        ];

        // The four writes below (offices upserts/deletes + the three global-set
        // saves) are wrapped in a single DB transaction so a partial failure
        // rolls back instead of leaving globals half-written. Only started for
        // a real run — a dry run performs no writes, so there's nothing to
        // transact. Image downloads happen inline inside these methods (as
        // part of building each field's value ahead of its save); pulling them
        // out to sit fully outside the transaction would need a larger
        // refactor, so — per the same tradeoff made for image I/O elsewhere —
        // they're left inside here rather than adding that risk.
        $transaction = $dryRun ? null : Craft::$app->getDb()->beginTransaction();

        try {
            $this->_importOffices($globals, $dryRun, $report);
            $this->_writeCompanyInfo($globals, $dryRun, $report);
            $this->_writeGlobalContent($globals, $dryRun, $report);
            $this->_writeSiteConfig($globals, $dryRun, $report);

            $transaction?->commit();
        } catch (Throwable $e) {
            $transaction?->rollBack();
            Craft::error('ContentIQ globals import failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            $report['warnings'][] = 'Globals import error: ' . $e->getMessage();
        } finally {
            // Leave the images service in a clean state for any later callers.
            ContentIQImporter::$plugin->images->reset();
        }

        return $report;
    }

    // Private Methods
    // =========================================================================

    /**
     * Records a downloaded/reused image against the report counters.
     *
     * @param array|null $imageResult Result from ImageImportService::importFromField().
     * @param array      &$report
     * @return int|null The asset id, or null.
     */
    private function _countImage(?array $imageResult, array &$report): ?int
    {
        if ($imageResult === null) {
            return null;
        }

        $report['imageCount']++;

        if ($imageResult['reused'] ?? false) {
            $report['imagesReused']++;
        }

        // Item-level context (a Step A self-heal drop, or a relocation
        // whose physical file wasn't found) — non-fatal, but must reach the
        // globals report, not just the Craft log.
        if (!empty($imageResult['warning'])) {
            $report['warnings'][] = $imageResult['warning'];
        }

        return $imageResult['id'] ?? null;
    }

    /**
     * Resolves the free-text country name to an ISO code using Craft's country
     * repository, or null when there is no confident match.
     *
     * @param string|null $name
     * @return string|null
     */
    private function _countryCode(?string $name): ?string
    {
        $codeToName = Craft::$app->getAddresses()->getCountryRepository()->getList(Craft::$app->language);

        return GlobalsTransforms::countryCode($name, $codeToName);
    }

    /**
     * Filters a field-values array to handles present on the given layout.
     * Skipped handles are recorded as notes (never throws).
     *
     * @param array $values
     * @param \craft\models\FieldLayout|null $layout
     * @param string $context Label for report notes (e.g. 'companyInfo').
     * @param array &$report
     * @return array
     */
    private function _filterToLayout(array $values, ?FieldLayout $layout, string $context, array &$report): array
    {
        if ($layout === null) {
            $report['warnings'][] = "{$context}: no field layout available — all fields skipped.";

            return [];
        }

        $validHandles = array_map(fn($f) => $f->handle, $layout->getCustomFields());
        $filtered     = [];

        foreach ($values as $handle => $value) {
            if (in_array($handle, $validHandles, true)) {
                $filtered[$handle] = $value;
                continue;
            }

            $report['fieldNotes'][] = "{$context}: field `{$handle}` not found on layout — skipped.";
        }

        return $filtered;
    }

    /**
     * Resolves the merged globals config (defaults + config/contentiq.php 'globals').
     *
     * @return array
     */
    private function _globalsConfig(): array
    {
        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('contentiq');
        $pageConfig    = is_array($projectConfig) ? $projectConfig : [];
        $override      = is_array($pageConfig['globals'] ?? null) ? $pageConfig['globals'] : [];

        return array_replace([
            'officesSection'      => 'offices',
            'officeEntryType'     => 'office',
            'brandAssetsVolume'   => 'brandAssets',
            'imagesVolume'        => $pageConfig['assetVolume'] ?? 'images',
            'imagesFolder'        => $pageConfig['assetFolder'] ?? 'contentiq',
            'socialIconSet'       => 'socialNetworks',
            'socialIconPrefix'    => '/social-networks/',
        ], $override);
    }

    /**
     * Upserts every wire office through the id map, adopts title-matched
     * unmapped entries, deletes offices whose wire id vanished, and orders the
     * companyInfo relation + section structure primary-first then wire order.
     *
     * @param array $globals
     * @param bool  $dryRun
     * @param array &$report
     * @return void
     */
    private function _importOffices(array $globals, bool $dryRun, array &$report): void
    {
        // Only an explicit, well-formed `offices` array may drive upserts/
        // deletion. An absent key (partial envelope) or a present-but-malformed
        // value (e.g. `"offices": null`) must never be read as "delete every
        // office" — both fall through to the same no-op skip below.
        if (!array_key_exists('offices', $globals) || !is_array($globals['offices'])) {
            $report['warnings'][] = array_key_exists('offices', $globals)
                ? 'Globals payload `offices` is present but not an array — offices pipeline skipped (no upsert, deletion, or relation write).'
                : 'Globals payload has no `offices` key — offices pipeline skipped (no upsert, deletion, or relation write).';

            return;
        }

        $config          = $this->_globalsConfig();
        $sectionHandle   = $config['officesSection'];
        $entryTypeHandle = $config['officeEntryType'];

        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        $type    = Craft::$app->getEntries()->getEntryTypeByHandle($entryTypeHandle);

        if ($section === null || $type === null) {
            $report['warnings'][] = "Offices section `{$sectionHandle}` or entry type `{$entryTypeHandle}` not found — offices skipped.";
            return;
        }

        $siteId  = Craft::$app->getSites()->getPrimarySite()->id;
        $company = $globals['company'] ?? [];
        $offices = is_array($globals['offices']) ? $globals['offices'] : [];

        // Existing state: id → element map, plus every current office entry.
        $mapRows       = (new Query())->from('{{%contentiq_office_syncs}}')->all();
        $officeIdToEl  = [];
        foreach ($mapRows as $row) {
            $officeIdToEl[(int)$row['office_id']] = (int)$row['element_id'];
        }
        $mappedElementIds = array_values($officeIdToEl);

        // Per-run claim set: seeds from the map, then grows with every element a
        // wire office adopts/creates/updates. Guards against two same-titled wire
        // offices claiming (and later destroying) the SAME element.
        $claimedElementIds = $mappedElementIds;

        $existingEntries = Entry::find()
            ->section($sectionHandle)
            ->siteId($siteId)
            ->status(null)
            ->all();

        $existingById    = [];
        $existingByTitle = [];
        foreach ($existingEntries as $entry) {
            $existingById[$entry->id] = $entry;

            // Only unmapped entries are adoption candidates — a mapped entry must
            // never shadow an unmapped same-titled one (silent duplicate).
            if (!in_array($entry->id, $mappedElementIds, true)) {
                $existingByTitle[$entry->title] ??= $entry;
            }
        }

        $managedElementIds = [];
        $orderedIds        = [];
        $primaryId         = null;
        $seenOfficeIds     = [];

        foreach ($offices as $office) {
            $officeId = (int)($office['id'] ?? 0);
            $name     = (string)($office['name'] ?? '');

            // A non-positive id collides on map key 0 — skip rather than corrupt.
            if ($officeId <= 0) {
                $report['warnings'][] = "Office `{$name}` skipped — missing a valid positive id.";
                continue;
            }

            $seenOfficeIds[] = $officeId;

            $entry   = null;
            $outcome = 'created';

            if (isset($officeIdToEl[$officeId], $existingById[$officeIdToEl[$officeId]])) {
                $entry   = $existingById[$officeIdToEl[$officeId]];
                $outcome = 'updated';
            } elseif (
                $name !== ''
                && isset($existingByTitle[$name])
                && !in_array($existingByTitle[$name]->id, $claimedElementIds, true)
                && $existingByTitle[$name]->getType()->handle === $entryTypeHandle
            ) {
                // Adoption: an unmapped, unclaimed, title- AND type-matched entry.
                $entry   = $existingByTitle[$name];
                $outcome = 'adopted';
            }

            if ($entry === null) {
                $entry            = new Entry();
                $entry->sectionId = $section->id;
                $entry->typeId    = $type->id;
                $entry->siteId    = $siteId;
            }

            $entry->title = $name;
            $values = $this->_officeFieldValues($office, $company, $report);
            $filtered = $this->_filterToLayout($values, $entry->getFieldLayout(), "office `{$name}`", $report);

            if (!$dryRun) {
                $entry->setFieldValues($filtered);

                if (!Craft::$app->getElements()->saveElement($entry, false)) {
                    $report['warnings'][] = "Failed to save office `{$name}`: " . implode(', ', $entry->getFirstErrors());
                    continue;
                }

                if ($outcome !== 'updated') {
                    $this->_upsertOfficeMap($officeId, $entry->id);
                }
            }

            $report['offices'][$outcome][] = $name;
            $managedElementIds[]           = $entry->id;
            $claimedElementIds[]           = $entry->id;
            $orderedIds[]                  = $entry->id;

            if (($office['primary'] ?? false) === true) {
                $primaryId = $entry->id;
            }
        }

        // Note any managed offices that carry an entry lock (ignored by globals).
        $this->_noteLockedOffices($managedElementIds, $report);

        // Delete mapped offices whose wire id vanished; prune their map rows.
        foreach ($officeIdToEl as $officeId => $elementId) {
            if (in_array($officeId, $seenOfficeIds, true)) {
                continue;
            }

            // Belt and braces: never delete an element still claimed this run —
            // just prune the stale map row for the vanished wire id.
            if (in_array($elementId, $claimedElementIds, true)) {
                if (!$dryRun) {
                    Craft::$app->getDb()->createCommand()
                        ->delete('{{%contentiq_office_syncs}}', ['office_id' => $officeId])
                        ->execute();
                }
                continue;
            }

            $entry = $existingById[$elementId] ?? null;
            $report['offices']['deleted'][] = $entry?->title ?? "#{$elementId}";

            if (!$dryRun) {
                if ($entry !== null) {
                    Craft::$app->getElements()->deleteElement($entry);
                }
                Craft::$app->getDb()->createCommand()
                    ->delete('{{%contentiq_office_syncs}}', ['office_id' => $officeId])
                    ->execute();
            }
        }

        // Unmatched hand-made offices — left alone, surfaced in the report.
        foreach ($existingEntries as $entry) {
            if (!in_array($entry->id, $managedElementIds, true)) {
                $report['offices']['unmatched'][] = $entry->title;
            }
        }

        // Relation order: primary first, then wire order.
        $relationIds = $this->_orderedRelationIds($orderedIds, $primaryId);

        // Reorder the section structure to match (primary first, then wire order).
        if (!$dryRun && $section->structureId !== null) {
            $structures = Craft::$app->getStructures();
            foreach ($relationIds as $id) {
                $entry = Entry::find()->id($id)->siteId($siteId)->status(null)->one();
                if ($entry !== null) {
                    $structures->appendToRoot($section->structureId, $entry);
                }
            }
        }

        $report['offices']['relationIds'] = $relationIds;
    }

    /**
     * Builds a Link field value, or null for an empty url.
     *
     * @param string|null $url
     * @return array|null
     */
    private function _linkValue(?string $url): ?array
    {
        $url = trim((string)$url);

        if ($url === '') {
            return null;
        }

        return ['type' => 'url', 'value' => $url];
    }

    /**
     * Builds a wholesale-rebuild Matrix value for the given inner entry type,
     * filtering each row's fields through that type's own field layout so a
     * handle missing on an older project is skipped with a note instead of
     * throwing on normalize. Returns null (skip the matrix field entirely, do
     * not clear it) when the entry type does not exist on the project.
     *
     * @param array $wire Raw wire rows.
     * @param string $typeHandle Inner entry type handle (e.g. 'socialNetwork').
     * @param callable $buildFields fn(array $row): array — the row's field values.
     * @param array &$report
     * @return array<string, array>|null
     */
    private function _matrixRows(array $wire, string $typeHandle, callable $buildFields, array &$report): ?array
    {
        $type = Craft::$app->getEntries()->getEntryTypeByHandle($typeHandle);

        if ($type === null) {
            $report['fieldNotes'][] = "Matrix entry type `{$typeHandle}` not found — matrix skipped.";

            return null;
        }

        $layout = $type->getFieldLayout();
        $rows   = [];

        foreach (array_values($wire) as $i => $row) {
            $fields = $this->_filterToLayout($buildFields($row), $layout, $typeHandle, $report);
            $rows['new' . ($i + 1)] = ['type' => $typeHandle, 'fields' => $fields];
        }

        return $rows;
    }

    /**
     * Adds a report note for any managed office that has a locked entry-sync row.
     *
     * @param int[] $elementIds
     * @param array &$report
     * @return void
     */
    private function _noteLockedOffices(array $elementIds, array &$report): void
    {
        if (empty($elementIds)) {
            return;
        }

        $lockedIds = (new Query())
            ->select(['element_id'])
            ->from('{{%contentiq_entry_syncs}}')
            ->where(['element_id' => $elementIds, 'locked' => true])
            ->column();

        if (!empty($lockedIds)) {
            $report['fieldNotes'][] = count($lockedIds) . ' office entry lock(s) ignored — globals writes are not gated by per-entry locks.';
        }
    }

    /**
     * Builds the sync-owned office field values from a wire office row plus the
     * fanned-out company fields. Country uses the untouched-on-no-match rule.
     *
     * @param array $office
     * @param array $company
     * @param array &$report
     * @return array
     */
    private function _officeFieldValues(array $office, array $company, array &$report): array
    {
        $name    = (string)($office['name'] ?? '');
        $address = GlobalsTransforms::splitAddress($office['address'] ?? null);

        $values = [
            'addressLine1'         => $address['addressLine1'],
            'addressLine2'         => $address['addressLine2'],
            'addressLine3'         => $address['addressLine3'],
            'townCity'             => (string)($office['town_city'] ?? ''),
            'county'               => (string)($office['county'] ?? ''),
            'postcode'             => (string)($office['postcode'] ?? ''),
            'telephone'            => (string)($office['phone'] ?? ''),
            'email'                => (string)($office['email'] ?? ''),
            'locationMapEmbedCode' => (string)($office['map_embed'] ?? ''),
            'openingHours'         => $this->_storeHoursValue($office['opening_hours'] ?? []),
            'companyName'          => (string)($company['name'] ?? ''),
            'limitedCompanyNumber' => (string)($company['number'] ?? ''),
            'vatNumber'            => (string)($company['vat_number'] ?? ''),
        ];

        // Country: clear when wire is empty; on a non-empty no-match, leave the
        // field untouched (omit the key) and warn.
        $rawCountry = $office['country'] ?? null;
        if ($rawCountry === null || trim((string)$rawCountry) === '') {
            $values['country'] = null;
        } else {
            $iso = $this->_countryCode((string)$rawCountry);
            if ($iso !== null) {
                $values['country'] = $iso;
            } else {
                $report['warnings'][] = "Office `{$name}`: country `{$rawCountry}` could not be matched to an ISO code — left unchanged.";
            }
        }

        return $values;
    }

    /**
     * Orders office element ids primary-first, then original (wire) order.
     *
     * @param int[] $orderedIds
     * @param int|null $primaryId
     * @return int[]
     */
    private function _orderedRelationIds(array $orderedIds, ?int $primaryId): array
    {
        $ids = array_values(array_filter($orderedIds, fn($id) => $id !== null));

        if ($primaryId === null) {
            return $ids;
        }

        $ids = array_values(array_filter($ids, fn($id) => $id !== $primaryId));
        array_unshift($ids, $primaryId);

        return $ids;
    }

    /**
     * Builds an IconPicker value for a social network url, or null when the
     * hostname maps to no known icon.
     *
     * @param string|null $url
     * @return array|null
     */
    private function _socialIcon(?string $url): ?array
    {
        $host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));

        if ($host === '') {
            return null;
        }

        $map = [
            'facebook'  => 'facebook',
            'instagram' => 'instagram',
            'twitter'   => 'x-twitter',
            'x'         => 'x-twitter',
            'linkedin'  => 'linkedin',
            'youtube'   => 'youtube',
            'youtu'     => 'youtube',
            'tiktok'    => 'tiktok',
            'pinterest' => 'pinterest',
        ];

        // Match whole host labels (facebook.com, m.facebook.com, x.com) — a
        // substring match would hand x-twitter to any host containing an "x".
        $labels = explode('.', $host);

        $icon = null;
        foreach ($map as $needle => $name) {
            if (in_array($needle, $labels, true)) {
                $icon = $name;
                break;
            }
        }

        if ($icon === null) {
            return null;
        }

        $config = $this->_globalsConfig();

        return [
            'type'          => 'svg',
            'iconSet'       => $config['socialIconSet'],
            'iconSetHandle' => $config['socialIconSet'],
            'value'         => rtrim($config['socialIconPrefix'], '/') . '/' . $icon . '.svg',
        ];
    }

    /**
     * Converts wire opening-hours groups into the Store Hours field value shape.
     *
     * Store Hours' normalizeValue reads $value[$dayIndex][$slotHandle] and runs
     * each slot through DateTimeHelper::toDateTime(). A bare 'HH:MM' string is
     * NOT parseable there, so each non-null slot is wrapped in the array form
     * ['time' => 'HH:MM', 'timezone' => <system>], which routes through
     * DateTimeHelper's _parseTime() and avoids a UTC→system hour shift.
     *
     * @param array $groups
     * @return array<int, array{open: ?array, close: ?array}>
     */
    private function _storeHoursValue(array $groups): array
    {
        $tz    = Craft::$app->getTimeZone();
        $days  = GlobalsTransforms::openingHours($groups);
        $value = [];

        foreach ($days as $day => $slots) {
            $value[$day] = [
                'open'  => $slots['open'] !== null ? ['time' => $slots['open'], 'timezone' => $tz] : null,
                'close' => $slots['close'] !== null ? ['time' => $slots['close'], 'timezone' => $tz] : null,
            ];
        }

        return $value;
    }

    /**
     * Upserts a contentiq_office_syncs row (office_id → element_id).
     *
     * @param int $officeId
     * @param int $elementId
     * @return void
     */
    private function _upsertOfficeMap(int $officeId, int $elementId): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $db  = Craft::$app->getDb();

        $exists = (new Query())
            ->from('{{%contentiq_office_syncs}}')
            ->where(['office_id' => $officeId])
            ->exists();

        if ($exists) {
            $db->createCommand()->update(
                '{{%contentiq_office_syncs}}',
                ['element_id' => $elementId, 'dateUpdated' => $now],
                ['office_id' => $officeId],
            )->execute();
            return;
        }

        $db->createCommand()->insert('{{%contentiq_office_syncs}}', [
            'office_id'   => $officeId,
            'element_id'  => $elementId,
            'dateCreated' => $now,
            'dateUpdated' => $now,
        ])->execute();
    }

    /**
     * Writes the companyInfo global set: branding logos + favicon (brandAssets
     * volume), the offices relation, and a wholesale-rebuilt socialNetworks
     * Matrix. Field-level merge — only these handles are touched.
     *
     * @param array $globals
     * @param bool  $dryRun
     * @param array &$report
     * @return void
     */
    private function _writeCompanyInfo(array $globals, bool $dryRun, array &$report): void
    {
        $set = Craft::$app->getGlobals()->getSetByHandle('companyInfo');

        if ($set === null) {
            $report['warnings'][] = 'Global set `companyInfo` not found — skipped.';
            return;
        }

        $config   = $this->_globalsConfig();
        $branding = is_array($globals['branding'] ?? null) ? $globals['branding'] : [];

        // Branding assets import into the flat brandAssets volume. Dry-run
        // resolves read-only — no folder record is created, no throw on a
        // missing volume (noted below instead).
        $images = ContentIQImporter::$plugin->images;
        $images->reset();
        $images->prepare($config['brandAssetsVolume'], '', $dryRun);

        if ($dryRun && Craft::$app->getVolumes()->getVolumeByHandle($config['brandAssetsVolume']) === null) {
            $report['warnings'][] = "Brand assets volume `{$config['brandAssetsVolume']}` not found — branding images would be skipped.";
        }

        $values = [];

        // Each logo/favicon sub-key is written only when present in the wire
        // `branding` object (an entirely absent `branding` key behaves the
        // same, since $branding is then []) — an absent sub-key leaves the
        // existing asset untouched. A present (even null) sub-key is a real
        // edit and rebuilds that field, clearing it when there's no usable asset.
        foreach (['main_logo' => 'mainLogo', 'footer_logo' => 'footerLogo', 'email_logo' => 'emailLogo', 'favicon' => 'favicon'] as $wireKey => $handle) {
            if (!array_key_exists($wireKey, $branding)) {
                continue;
            }

            $asset = $branding[$wireKey];
            $id    = $this->_countImage($images->importFromField(is_array($asset) ? $asset : null, $dryRun), $report);
            $values[$handle] = $id !== null ? [$id] : [];
        }

        // offices relation — primary first, then wire order. Only written when
        // the offices step actually ran (avoid clearing on a config failure).
        if (isset($report['offices']['relationIds'])) {
            $values['offices'] = array_values(array_filter(
                $report['offices']['relationIds'],
                fn($id) => $id !== null,
            ));
        }

        // socialNetworks — rebuilt wholesale, but only when the payload
        // actually carries a `social_networks` key. An absent key leaves the
        // existing Matrix untouched; a present-but-empty array is a real
        // "clear all" edit. Inner row fields are filtered against the inner
        // entry type's own layout; a missing type skips the whole matrix
        // (rather than throwing on normalize).
        if (array_key_exists('social_networks', $globals)) {
            $socialWire = is_array($globals['social_networks']) ? $globals['social_networks'] : [];

            $social = $this->_matrixRows(
                $socialWire,
                'socialNetwork',
                function (array $social): array {
                    $fields = ['theUrl' => $this->_linkValue($social['url'] ?? null)];
                    $icon   = $this->_socialIcon($social['url'] ?? null);

                    if ($icon !== null) {
                        $fields['socialNetworkIcon'] = $icon;
                    }

                    return $fields;
                },
                $report,
            );

            if ($social !== null) {
                $values['socialNetworks'] = $social;
            }
        }

        $filtered = $this->_filterToLayout($values, $set->getFieldLayout(), 'companyInfo', $report);

        if (!$dryRun) {
            $set->setFieldValues($filtered);

            if (!Craft::$app->getElements()->saveElement($set, false)) {
                $report['warnings'][] = 'Failed to save global set `companyInfo`: ' . implode(', ', $set->getFirstErrors());
                $images->reset();

                return;
            }
        }

        $report['globalSets']['companyInfo'] = array_keys($filtered);
        $images->reset();
    }

    /**
     * Writes the globalContent global set: a wholesale-rebuilt trustSignals
     * Matrix. Logos import into the project's configured images volume.
     *
     * @param array $globals
     * @param bool  $dryRun
     * @param array &$report
     * @return void
     */
    private function _writeGlobalContent(array $globals, bool $dryRun, array &$report): void
    {
        $set = Craft::$app->getGlobals()->getSetByHandle('globalContent');

        if ($set === null) {
            $report['warnings'][] = 'Global set `globalContent` not found — skipped.';
            return;
        }

        $config = $this->_globalsConfig();
        $images = ContentIQImporter::$plugin->images;
        $images->reset();
        $images->prepare($config['imagesVolume'], $config['imagesFolder'], $dryRun);

        if ($dryRun && Craft::$app->getVolumes()->getVolumeByHandle($config['imagesVolume']) === null) {
            $report['warnings'][] = "Images volume `{$config['imagesVolume']}` not found — trust signal logos would be skipped.";
        }

        $values = [];

        // trustSignals — rebuilt wholesale, but only when the payload actually
        // carries a `trust_signals` key. An absent key leaves the existing
        // Matrix untouched; a present-but-empty array is a real "clear all" edit.
        if (array_key_exists('trust_signals', $globals)) {
            $trustWire = is_array($globals['trust_signals']) ? $globals['trust_signals'] : [];

            $trust = $this->_matrixRows(
                $trustWire,
                'trustSignal',
                function (array $signal) use ($images, $dryRun, &$report): array {
                    $logo = $signal['logo'] ?? null;
                    $id   = $this->_countImage($images->importFromField(is_array($logo) ? $logo : null, $dryRun), $report);

                    return [
                        'logo'   => $id !== null ? [$id] : [],
                        'theUrl' => $this->_linkValue($signal['url'] ?? null),
                    ];
                },
                $report,
            );

            if ($trust !== null) {
                $values['trustSignals'] = $trust;
            }
        }

        $filtered = $this->_filterToLayout($values, $set->getFieldLayout(), 'globalContent', $report);

        if (!$dryRun) {
            $set->setFieldValues($filtered);

            if (!Craft::$app->getElements()->saveElement($set, false)) {
                $report['warnings'][] = 'Failed to save global set `globalContent`: ' . implode(', ', $set->getFirstErrors());
                $images->reset();

                return;
            }
        }

        $report['globalSets']['globalContent'] = array_keys($filtered);
        $images->reset();
    }

    /**
     * Writes the siteConfig global set: head/body scripts and the analytics id.
     * Each sub-key is written only when present in the wire `scripts` object
     * (an entirely absent `scripts` key behaves the same, since $scripts is
     * then []) — an absent sub-key leaves the existing script/id untouched. A
     * present (including null) wire value is a real edit and clears the field.
     *
     * @param array $globals
     * @param bool  $dryRun
     * @param array &$report
     * @return void
     */
    private function _writeSiteConfig(array $globals, bool $dryRun, array &$report): void
    {
        $set = Craft::$app->getGlobals()->getSetByHandle('siteConfig');

        if ($set === null) {
            $report['warnings'][] = 'Global set `siteConfig` not found — skipped.';
            return;
        }

        $scripts = is_array($globals['scripts'] ?? null) ? $globals['scripts'] : [];
        $values  = [];

        foreach (['head' => 'headScripts', 'body' => 'bodyScripts', 'google_analytics_id' => 'googleAnalyticsId'] as $wireKey => $handle) {
            if (!array_key_exists($wireKey, $scripts)) {
                continue;
            }

            $values[$handle] = (string)$scripts[$wireKey];
        }

        $filtered = $this->_filterToLayout($values, $set->getFieldLayout(), 'siteConfig', $report);

        if (!$dryRun) {
            $set->setFieldValues($filtered);

            if (!Craft::$app->getElements()->saveElement($set, false)) {
                $report['warnings'][] = 'Failed to save global set `siteConfig`: ' . implode(', ', $set->getFirstErrors());

                return;
            }
        }

        $report['globalSets']['siteConfig'] = array_keys($filtered);
    }
}
