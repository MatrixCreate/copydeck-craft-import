<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\Db;
use craft\models\Volume;
use craft\models\VolumeFolder;
use GuzzleHttp\RequestOptions;
use matrixcreate\contentiqimporter\helpers\UrlSafety;
use Throwable;
use yii\base\Component;
use yii\base\Exception;

/**
 * Handles downloading remote images and importing them as Craft assets.
 *
 * Idempotent: if the image's key has already been mapped to a live Craft
 * asset (contentiq_asset_syncs), that asset is reused — the key is the
 * upstream asset's stable storage path and is globally unique, so this is
 * collision-proof across pages/projects that happen to share a filename —
 * UNLESS the mapping itself is untrustworthy, a leftover of the old
 * filename-collapse bug where several DIFFERENT keys ended up mapped to one
 * shared element. `_isOldestMappingOwner()` self-heals that at Step A: the
 * oldest mapping row keeps the element, every other key's row is dropped
 * and re-resolves as its own asset (with a per-item warning). When the key
 * is unmapped, an asset with the same filename in the target folder is
 * reused as a fallback, but ONLY if that asset isn't already mapped to a
 * DIFFERENT key (`_isClaimedByKeyMapping()`) — two independent ContentiQ
 * assets sharing a filename (every page having its own "hero.jpg" is the
 * common shape) are never collapsed onto one Craft element just because
 * they landed in the same folder. A claimed hit is treated as no match,
 * and a fresh asset is created instead (the key mapping is recorded for
 * next time). Otherwise the file is downloaded and a new asset created.
 * Running the import twice does NOT duplicate assets.
 *
 * Failed downloads are non-fatal: the method returns null and logs a warning.
 * Callers should treat null as "leave field empty" and continue.
 *
 * Two independent targets can be prepared per run — `prepare()` for images
 * (`importFromField()`) and `prepareDocuments()` for non-image files
 * (`importFile()`, e.g. PDFs) — each resolving its own volume/folder pair.
 * Under `assetFolderStrategy: 'sitemap'` (see ImportService/AssetFolderPath)
 * both targets also carry a legacy flat-folder Step B fallback and relocate
 * a reused asset that's drifted from the resolved folder — see
 * docs/assets.md.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class ImageImportService extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * Cached images-target volume instance, resolved once per import run.
     *
     * @var Volume|null
     */
    private ?Volume $_volume = null;

    /**
     * Cached images-target folder instance, resolved once per import run.
     *
     * @var VolumeFolder|null
     */
    private ?VolumeFolder $_folder = null;

    /**
     * Whether a reused image whose current folder differs from the resolved
     * target should be moved there — `'sitemap'` `assetFolderStrategy` only.
     * See `prepare()` and `_relocateIfNeeded()`.
     *
     * @var bool
     */
    private bool $_relocate = false;

    /**
     * Legacy (flat-strategy) base folder path to fall back to in Step B when
     * a filename isn't found in the resolved sitemap folder — where an asset
     * synced before this feature existed still lives. Null under the `'flat'`
     * strategy (the resolved target folder already IS that base folder, so a
     * separate fallback lookup would be redundant). See `prepare()`.
     *
     * @var string|null
     */
    private ?string $_legacyFolderPath = null;

    /**
     * Cached non-image ("documents") target volume instance, resolved once
     * per import run — only set when the page actually carries `files[]`.
     *
     * @var Volume|null
     */
    private ?Volume $_documentVolume = null;

    /**
     * Cached non-image ("documents") target folder instance. See `$_folder`.
     *
     * @var VolumeFolder|null
     */
    private ?VolumeFolder $_documentFolder = null;

    /**
     * Documents-volume counterpart of `$_relocate`. See `prepareDocuments()`.
     *
     * @var bool
     */
    private bool $_documentRelocate = false;

    /**
     * Documents-volume counterpart of `$_legacyFolderPath`. See `prepareDocuments()`.
     *
     * @var string|null
     */
    private ?string $_documentLegacyFolderPath = null;

    /**
     * Dev-only bypass of the SSRF public-host refusal in `_download()` —
     * `allowPrivateAssetUrls` in config/contentiq.php, set via
     * `setAllowPrivateAssetUrls()`. Re-checked against
     * `Craft::$app->getConfig()->getGeneral()->devMode` at the point of use
     * (`_privateUrlBypassActive()`), not just trusted here, so a value that
     * somehow survives into a production request is still inert. See
     * docs/assets.md.
     *
     * @var bool
     */
    private bool $_allowPrivateAssetUrls = false;

    /**
     * Whether the private-URL bypass notice has already been logged this
     * run — logged once, not once per asset. See `_logPrivateUrlBypassOnce()`.
     *
     * @var bool
     */
    private bool $_privateUrlBypassLogged = false;

    // Public Methods
    // =========================================================================

    /**
     * Resolves the configured images volume and folder, caching both for the run.
     *
     * Must be called once before any importFromField() calls. Throws if the
     * volume handle is not found — this is a fatal configuration error — UNLESS
     * $dryRun is true, in which case a missing volume or folder is left
     * unresolved (no throw, no DB writes) and importFromField() reports each
     * image as a would-be new import.
     *
     * @param string      $volumeHandle     e.g. 'images'
     * @param string      $folderPath       e.g. 'contentiq', or a per-page sitemap path.
     * @param bool        $dryRun           Read-only resolve — never creates the folder record.
     * @param bool        $relocate         Whether a Step A/B reuse whose current folder
     *                                      differs from `$folderPath` should be moved there.
     *                                      Only ever true under `assetFolderStrategy: 'sitemap'`
     *                                      — see docs/assets.md.
     * @param string|null $legacyFolderPath The flat-strategy base folder to also check in
     *                                      Step B (assets synced before the sitemap folder
     *                                      structure existed). Ignored unless `$relocate` is
     *                                      true and differs from `$folderPath`.
     * @return void
     * @throws Exception if the volume handle cannot be resolved (non-dry-run only).
     */
    public function prepare(
        string $volumeHandle,
        string $folderPath,
        bool $dryRun = false,
        bool $relocate = false,
        ?string $legacyFolderPath = null,
    ): void {
        [$this->_volume, $this->_folder] = $this->_resolveVolumeAndFolder($volumeHandle, $folderPath, $dryRun, 'assetVolume');
        $this->_relocate         = $relocate;
        $this->_legacyFolderPath = ($relocate && $legacyFolderPath !== null && $legacyFolderPath !== $folderPath)
            ? $legacyFolderPath
            : null;
    }

    /**
     * Documents-volume counterpart of `prepare()` — resolves the configured
     * non-image volume and folder for the run. Only called by a caller that
     * actually has `files[]` to import; a project with no `documentVolume`
     * configured (or no such volume in Craft) never has this called and
     * never throws.
     *
     * @param string      $volumeHandle     e.g. 'documents'
     * @param string      $folderPath       Same page folder `prepare()` was given.
     * @param bool        $dryRun           See `prepare()`.
     * @param bool        $relocate         See `prepare()`.
     * @param string|null $legacyFolderPath See `prepare()`.
     * @return void
     * @throws Exception if the volume handle cannot be resolved (non-dry-run only).
     */
    public function prepareDocuments(
        string $volumeHandle,
        string $folderPath,
        bool $dryRun = false,
        bool $relocate = false,
        ?string $legacyFolderPath = null,
    ): void {
        [$this->_documentVolume, $this->_documentFolder] = $this->_resolveVolumeAndFolder($volumeHandle, $folderPath, $dryRun, 'documentVolume');
        $this->_documentRelocate         = $relocate;
        $this->_documentLegacyFolderPath = ($relocate && $legacyFolderPath !== null && $legacyFolderPath !== $folderPath)
            ? $legacyFolderPath
            : null;
    }

    /**
     * Imports an image from a ContentIQ image field value.
     *
     * Accepts the raw image object from the JSON:
     *   { "key": "path/to/file.jpg", "url": "https://...", "alt": null }
     *
     * Returns the Craft asset ID on success, null on failure or if the image
     * field is empty (no url). Targets the volume/folder resolved by
     * `prepare()`, unless `$folderPathOverride` names a different folder
     * (within the same volume) — used only by the page-level `assets[]`
     * filing step for an item carrying its own ContentIQ per-page `folder`.
     *
     * @param array|null  $imageField         Raw image object from ContentIQ JSON.
     * @param bool        $dryRun             If true, resolves what would happen without downloading.
     * @param string|null $folderPathOverride Full folder path (within the `prepare()`d volume)
     *                                        to use instead of the cached page folder.
     * @return array{id: int|null, filename: string, reused: bool, relocated: bool, warning: string|null}|null
     *   Returns null if the image field is empty or unusable.
     */
    public function importFromField(?array $imageField, bool $dryRun = false, ?string $folderPathOverride = null): ?array
    {
        return $this->_importAsset($imageField, $dryRun, true, $folderPathOverride);
    }

    /**
     * Non-image counterpart of `importFromField()` — imports a ContentIQ
     * `files[]` item (e.g. a PDF) into the volume/folder resolved by
     * `prepareDocuments()`. Same shape, guards, and idempotency as
     * `importFromField()`, minus alt-text handling (documents have none).
     *
     * @param array|null  $fileField          Raw file object from ContentIQ JSON.
     * @param bool        $dryRun             See `importFromField()`.
     * @param string|null $folderPathOverride See `importFromField()`.
     * @return array{id: int|null, filename: string, reused: bool, relocated: bool, warning: string|null}|null
     */
    public function importFile(?array $fileField, bool $dryRun = false, ?string $folderPathOverride = null): ?array
    {
        return $this->_importAsset($fileField, $dryRun, false, $folderPathOverride);
    }

    /**
     * Enables (or disables) the dev-only SSRF-refusal bypass for this run's
     * downloads — `allowPrivateAssetUrls` in config/contentiq.php. Call
     * before any `importFromField()`/`importFile()` call; a project that
     * never calls this defaults to false, i.e. today's behaviour.
     *
     * Only ever takes effect when `Craft::$app->getConfig()->getGeneral()->devMode`
     * is ALSO true — checked again at the point of use
     * (`_privateUrlBypassActive()`), not cached here, so this can never be
     * live in production even if the config value is accidentally left on.
     * See docs/assets.md.
     *
     * @param bool $allow
     * @return void
     */
    public function setAllowPrivateAssetUrls(bool $allow): void
    {
        $this->_allowPrivateAssetUrls = $allow;
    }

    /**
     * Resets cached volume/folder/relocation/private-URL-bypass state. Call
     * between import runs if needed.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->_volume                   = null;
        $this->_folder                   = null;
        $this->_relocate                 = false;
        $this->_legacyFolderPath         = null;
        $this->_documentVolume           = null;
        $this->_documentFolder           = null;
        $this->_documentRelocate         = false;
        $this->_documentLegacyFolderPath = null;
        $this->_allowPrivateAssetUrls    = false;
        $this->_privateUrlBypassLogged   = false;
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves a volume by handle and its target folder by path — the shared
     * guts of `prepare()`/`prepareDocuments()`. Dry-run resolves read-only
     * (`findFolder()` — never creates the folder record, a not-yet-existing
     * folder resolves to null); a real run ensures the folder record exists
     * via `ensureFolderByFullPathAndVolume()`.
     *
     * @param string $volumeHandle
     * @param string $folderPath
     * @param bool   $dryRun
     * @param string $configKeyLabel The config/contentiq.php key to name in the exception
     *                                message ('assetVolume' or 'documentVolume').
     * @return array{0: Volume|null, 1: VolumeFolder|null}
     * @throws Exception if the volume handle cannot be resolved (non-dry-run only).
     */
    private function _resolveVolumeAndFolder(string $volumeHandle, string $folderPath, bool $dryRun, string $configKeyLabel): array
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

        if ($volume === null) {
            if ($dryRun) {
                // Dry-run must not throw on a misconfigured volume — leave unresolved.
                return [null, null];
            }

            throw new Exception("Asset volume '{$volumeHandle}' not found. Check the '{$configKeyLabel}' key in config/contentiq.php.");
        }

        if ($dryRun) {
            // Read-only resolve: never create the folder record on a dry run. A
            // not-yet-existing folder resolves to null (nothing to reuse).
            $folder = Craft::$app->getAssets()->findFolder([
                'volumeId' => $volume->id,
                'path'     => $folderPath === '' ? '' : rtrim($folderPath, '/') . '/',
            ]);

            return [$volume, $folder];
        }

        $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(
            $folderPath,
            $volume,
            true, // justRecord — volume handles physical dir creation on save
        );

        return [$volume, $folder];
    }

    /**
     * Resolves a VolumeFolder for an already-resolved volume by full path —
     * used for a per-item `$folderPathOverride` (a ContentIQ per-page asset
     * sub-folder) and for the legacy flat-strategy fallback folder in Step B.
     * Same dry-run/real-run split as `_resolveVolumeAndFolder()`, but never
     * throws — an unresolvable override folder just means nothing to target
     * (the caller's own null-checks handle it the same as an unprepared volume).
     *
     * @param Volume $volume
     * @param string $path
     * @param bool   $dryRun
     * @return VolumeFolder|null
     */
    private function _resolveFolderByPath(Volume $volume, string $path, bool $dryRun): ?VolumeFolder
    {
        if ($dryRun) {
            return Craft::$app->getAssets()->findFolder([
                'volumeId' => $volume->id,
                'path'     => $path === '' ? '' : rtrim($path, '/') . '/',
            ]);
        }

        return Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($path, $volume, true);
    }

    /**
     * Moves a reused asset into the target folder when it currently sits
     * somewhere else — the 'sitemap' `assetFolderStrategy`'s relocation
     * behaviour (docs/assets.md). A no-op (`relocated: false`, no move
     * attempted) when `$relocate` is off, on a dry run (relocation never
     * happens on a preview), or the asset is already in the target folder.
     *
     * `moveAsset()` → `Asset::_relocateFile()` → (same-volume move)
     * `craft\fs\Local::renameFile()` calls PHP's `@rename()` and never
     * checks its return value — a missing source file on disk makes the
     * physical move silently no-op while the DB `folderId`/`filename`
     * update (and `saveElement()`'s own success) proceed exactly as if the
     * file really had moved. No exception, no validation error — this
     * plugin has no way to detect that AFTER the fact, so the source file's
     * existence is checked BEFORE calling `moveAsset()` and surfaced as a
     * `warning` string when missing. The move is still counted `relocated`
     * either way (it genuinely did succeed, by Craft's own definition — the
     * DB now correctly reflects this asset's folder) — this is a visibility
     * fix, not a behaviour change to what "relocated" means.
     *
     * @param Asset        $asset
     * @param VolumeFolder $targetFolder
     * @param bool         $relocate
     * @param bool         $dryRun
     * @return array{relocated: bool, warning: string|null}
     */
    private function _relocateIfNeeded(Asset $asset, VolumeFolder $targetFolder, bool $relocate, bool $dryRun): array
    {
        if (!$relocate || $dryRun || (int)$asset->folderId === $targetFolder->id) {
            return ['relocated' => false, 'warning' => null];
        }

        // Checked before the move — moveAsset() below changes $asset's own
        // folderId/path properties, so this must read the CURRENT (old)
        // location first. A check failure (e.g. an exotic Fs adapter that
        // throws) must not block the move over an inability to confirm —
        // treat "can't tell" the same as "exists".
        $sourceExists = true;

        try {
            $sourceExists = $asset->getVolume()->fileExists($asset->getPath());
        } catch (Throwable $e) {
            Craft::warning("Could not confirm asset #{$asset->id}'s source file exists before relocating: " . $e->getMessage(), __METHOD__);
        }

        $moved = Craft::$app->getAssets()->moveAsset($asset, $targetFolder);

        if (!$moved) {
            Craft::warning(
                "Could not relocate asset #{$asset->id} to folder #{$targetFolder->id}: " . implode(', ', $asset->getFirstErrors()),
                __METHOD__,
            );

            return ['relocated' => false, 'warning' => null];
        }

        $warning = $sourceExists
            ? null
            : "Asset #{$asset->id} (\"{$asset->getFilename()}\") was relocated in the database, but its physical file was not found at its previous location — the file itself was not moved. Check the volume's filesystem.";

        return ['relocated' => true, 'warning' => $warning];
    }

    /**
     * Shared implementation behind `importFromField()` (images) and
     * `importFile()` (documents) — same idempotency (Step A key lookup, Step
     * B filename lookup with a sitemap-strategy legacy-folder fallback),
     * relocation, download, and key-mapping logic; only the target
     * volume/folder pair, the legacy fallback, and whether alt text applies
     * differ between the two.
     *
     * @param array|null  $item               Raw image/file object from ContentIQ JSON.
     * @param bool        $dryRun
     * @param bool        $isImage            True for `importFromField()`, false for `importFile()` —
     *                                        selects the images vs documents target/relocate/legacy state.
     * @param string|null $folderPathOverride Full folder path to target instead of the
     *                                        cached page folder (a per-item ContentIQ sub-folder).
     * @return array{id: int|null, filename: string, reused: bool, relocated: bool, warning: string|null}|null
     */
    private function _importAsset(?array $item, bool $dryRun, bool $isImage, ?string $folderPathOverride = null): ?array
    {
        if (empty($item['url'])) {
            return null;
        }

        $url      = $item['url'];
        $assetKey = (string)($item['key'] ?? '');
        // files[]/assets[] items carry an explicit `filename`; the older
        // {key, url, alt} block-image shape does not, so it still falls back
        // to deriving one from the key/url.
        $rawFilename = !empty($item['filename'])
            ? (string)$item['filename']
            : ($this->_filenameFromKey($assetKey) ?: $this->_filenameFromUrl($url));
        // Sanitize the same way Craft does on save — spaces become hyphens, etc.
        $filename = Assets::prepareAssetName($rawFilename);

        if ($filename === '') {
            Craft::warning("Could not determine filename from asset field: " . json_encode($item), __METHOD__);

            return null;
        }

        $volume     = $isImage ? $this->_volume : $this->_documentVolume;
        $relocate   = $isImage ? $this->_relocate : $this->_documentRelocate;
        $legacyPath = $isImage ? $this->_legacyFolderPath : $this->_documentLegacyFolderPath;
        $targetFolder = $isImage ? $this->_folder : $this->_documentFolder;

        // Volume/folder may be unresolved on a dry run (missing volume, or a
        // folder that does not exist yet). Nothing can be reused — report a
        // would-be new import; a non-dry-run should never reach this (prepare
        // throws), but fail safe.
        if ($volume === null) {
            return $dryRun ? ['id' => null, 'filename' => $filename, 'reused' => false, 'relocated' => false, 'warning' => null] : null;
        }

        if ($folderPathOverride !== null) {
            $targetFolder = $this->_resolveFolderByPath($volume, $folderPathOverride, $dryRun);
        }

        if ($targetFolder === null) {
            return $dryRun ? ['id' => null, 'filename' => $filename, 'reused' => false, 'relocated' => false, 'warning' => null] : null;
        }

        // Set when a Step A mapping is dropped as a self-heal (below) —
        // carried through to WHATEVER return path this call ends up taking
        // (Step B, legacy fallback, or a fresh download), since it explains
        // why a fresh/different asset is being resolved instead of the one
        // this key used to be mapped to.
        $itemWarning = null;

        // STEP A — key-first identity. The asset key is the upstream asset's
        // stable storage path ("{project_id}/{page_id}/{filename}") and is
        // globally unique, unlike the bare filename used below — two assets
        // from different pages/projects can share a filename but never a
        // key. A key already mapped to a still-live asset wins outright and
        // the download is skipped entirely — UNLESS that mapping is itself
        // untrustworthy: a product of the old filename-collapse bug means
        // several DIFFERENT keys can be mapped to the SAME element. Self-heal
        // rule: the OLDEST mapping row (lowest id) is deemed the rightful
        // owner and keeps the element (and is the only one relocation ever
        // runs for); every other key's row is dropped and falls through to
        // Step B/download as if it had never been mapped at all — see
        // _isOldestMappingOwner()/docs/assets.md.
        if ($assetKey !== '') {
            $mappingRow = (new Query())
                ->select(['id', 'element_id'])
                ->from('{{%contentiq_asset_syncs}}')
                ->where(['image_key' => $assetKey])
                ->one();

            if ($mappingRow !== null) {
                $mapped = Asset::find()->id((int)$mappingRow['element_id'])->one();

                if ($mapped !== null) {
                    $siblingRowIds = array_map('intval', (new Query())
                        ->select(['id'])
                        ->from('{{%contentiq_asset_syncs}}')
                        ->where(['element_id' => $mapped->id])
                        ->column());

                    if ($this->_isOldestMappingOwner((int)$mappingRow['id'], $siblingRowIds)) {
                        $relocation = $this->_relocateIfNeeded($mapped, $targetFolder, $relocate, $dryRun);

                        return [
                            'id'       => $mapped->id,
                            'filename' => $filename,
                            'reused'   => true,
                            'relocated' => $relocation['relocated'],
                            'warning'  => $relocation['warning'],
                        ];
                    }

                    // Not the owner — this key's mapping collapsed onto
                    // another ContentiQ asset's element. Drop it (real runs
                    // only) and fall through to Step B/download; never
                    // relocate on behalf of a non-owner.
                    if (!$dryRun) {
                        Craft::$app->getDb()->createCommand()
                            ->delete('{{%contentiq_asset_syncs}}', ['id' => $mappingRow['id']])
                            ->execute();
                    }

                    $otherKeyCount = count($siblingRowIds) - 1;
                    $itemWarning = "Key \"{$assetKey}\" shared a Craft asset with {$otherKeyCount} other ContentIQ key"
                        . ($otherKeyCount === 1 ? '' : 's') . ' — re-imported as its own asset.';

                    // Belt-and-braces: every caller now surfaces $itemWarning
                    // on its own report, but log it here too so the Craft log
                    // records the self-heal even for a caller that somehow
                    // still drops it.
                    Craft::warning("ContentIQImporter: {$itemWarning}", __METHOD__);
                } elseif (!$dryRun) {
                    // Stale mapping — the mapped asset was hard-deleted or
                    // trashed (default Asset::find() excludes trashed, so
                    // null here means exactly that). Clear it so a fresh
                    // mapping can be recorded below; a dry run must not
                    // write, so it simply falls through to the filename
                    // fallback for this preview.
                    Craft::$app->getDb()->createCommand()
                        ->delete('{{%contentiq_asset_syncs}}', ['id' => $mappingRow['id']])
                        ->execute();
                }
            }
        }

        // STEP B — filename fallback. Reuse if an asset with this filename
        // already exists in the target folder AND is not already claimed by
        // a DIFFERENT ContentIQ key (see _isClaimedByKeyMapping() — two
        // pages independently having their own, differently-keyed
        // "hero.jpg" is a common shape, not a duplicate of the same asset).
        // A claimed hit is treated as no match at all, falling through to a
        // fresh download below. This is how a key gets adopted on the first
        // post-upgrade sync too: an asset created before contentiq_asset_syncs
        // existed has no key mapping yet (unclaimed), so it's found here and
        // the mapping recorded below. Explicit trashed(false) (already the
        // query default) — a trashed element must never be reused.
        $existing = Asset::find()
            ->volumeId($volume->id)
            ->folderId($targetFolder->id)
            ->filename($filename)
            ->trashed(false)
            ->one();

        if ($existing !== null && $this->_shouldReuseFilenameMatch($this->_isClaimedByKeyMapping($existing->id))) {
            if (!$dryRun && $assetKey !== '') {
                $this->_upsertAssetMap($assetKey, $existing->id);
            }

            return ['id' => $existing->id, 'filename' => $filename, 'reused' => true, 'relocated' => false, 'warning' => $itemWarning];
        }

        // STEP B (legacy fallback) — 'sitemap' strategy only. An asset synced
        // before the sitemap folder structure existed lives in the flat base
        // folder, not the newly-resolved page folder — check there too before
        // concluding nothing exists and re-downloading a duplicate. Same
        // claimed-by-another-key guard, same explicit trashed(false).
        if ($legacyPath !== null) {
            $legacyFolder = $this->_resolveFolderByPath($volume, $legacyPath, $dryRun);

            if ($legacyFolder !== null && $legacyFolder->id !== $targetFolder->id) {
                $legacyHit = Asset::find()
                    ->volumeId($volume->id)
                    ->folderId($legacyFolder->id)
                    ->filename($filename)
                    ->trashed(false)
                    ->one();

                if ($legacyHit !== null && $this->_shouldReuseFilenameMatch($this->_isClaimedByKeyMapping($legacyHit->id))) {
                    if (!$dryRun && $assetKey !== '') {
                        $this->_upsertAssetMap($assetKey, $legacyHit->id);
                    }

                    $relocation = $this->_relocateIfNeeded($legacyHit, $targetFolder, $relocate, $dryRun);

                    // Both a Step A self-heal drop (this key's OWN prior
                    // mapping) and a relocation-time missing-file warning
                    // (the legacy hit's own physical file) can legitimately
                    // apply to the same item — join rather than drop one.
                    $warningParts = array_filter([$itemWarning, $relocation['warning']]);

                    return [
                        'id'       => $legacyHit->id,
                        'filename' => $filename,
                        'reused'   => true,
                        'relocated' => $relocation['relocated'],
                        'warning'  => $warningParts ? implode(' ', $warningParts) : null,
                    ];
                }
            }
        }

        if ($dryRun) {
            return ['id' => null, 'filename' => $filename, 'reused' => false, 'relocated' => false, 'warning' => $itemWarning];
        }

        // If the file exists on the volume but not in the DB (orphaned from a
        // previous partial import or manual DB cleanup), remove it so Craft's
        // "file already exists" validation does not block the save. A
        // filename claimed by another key is NOT an orphan (it has a live DB
        // record — just for a different asset) and is correctly left alone
        // here; _download() below handles that on-disk collision instead via
        // avoidFilenameConflicts.
        $this->_deleteOrphanedFile($volume, $targetFolder, $filename);

        // Alt text only ever applies to images — files[] items have none.
        $alt = $isImage && isset($item['alt']) && $item['alt'] !== '' ? (string)$item['alt'] : null;

        $result = $this->_download($url, $filename, $volume, $targetFolder, $alt);

        if ($result !== null && $assetKey !== '') {
            $this->_upsertAssetMap($assetKey, $result['id']);
        }

        return $result !== null ? $result + ['relocated' => false, 'warning' => $itemWarning] : null;
    }

    /**
     * Deletes a file from the volume if it exists on the filesystem without a
     * corresponding asset DB record — LIVE OR TRASHED — for that filename
     * (a genuine orphan).
     *
     * This can happen when asset DB records are hard-deleted manually (e.g.
     * during testing) but the volume files are not removed. Craft's "file
     * already exists" validation would otherwise block creating a new asset
     * with the same name.
     *
     * Trashed assets must be spared: Craft soft-deletes into a restorable
     * trash, and the asset's physical file is what a restore brings back. If
     * this only checked live (non-trashed) assets — Craft's default
     * Asset::find() behaviour — a soft-deleted asset would look like "no DB
     * record" here and its file would be permanently wiped, breaking the
     * restore. Checking trashed rows too also spares an out-of-band editor
     * upload that happens to share this filename but isn't findable via the
     * volumeId/folderId/filename lookup for some other reason (e.g. it was
     * itself just trashed) from being deleted underneath the editor.
     *
     * @param Volume       $volume
     * @param VolumeFolder $folder
     * @param string       $filename
     * @return void
     */
    private function _deleteOrphanedFile(Volume $volume, VolumeFolder $folder, string $filename): void
    {
        try {
            $hasAssetRecord = Asset::find()
                ->volumeId($volume->id)
                ->folderId($folder->id)
                ->filename($filename)
                ->trashed(null) // null = match both live and trashed rows.
                ->exists();

            if ($hasAssetRecord) {
                // Not an orphan — a DB record (live or trashed) owns this
                // file. Leave it alone.
                return;
            }

            $fs         = $volume->getFs();
            $folderPath = $folder->path ? rtrim($folder->path, '/') . '/' : '';
            $volumePath = $folderPath . $filename;

            if ($fs->fileExists($volumePath)) {
                $fs->deleteFile($volumePath);
                Craft::info("Deleted orphaned file from volume: {$volumePath}", __METHOD__);
            }
        } catch (Throwable $e) {
            Craft::warning("Could not check/delete orphaned file '{$filename}': " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Whether a Craft asset is already claimed by a `contentiq_asset_syncs`
     * row — i.e. it's a DIFFERENT ContentiQ asset that merely happens to
     * share a filename with the one currently being resolved, not the same
     * asset. A Step B (filename) match against a claimed element must be
     * treated as no match at all, or two independent ContentiQ assets that
     * happen to share a filename (every page having its own "hero.jpg" is
     * the common shape) collapse onto a single Craft element — each
     * successive page's Step B reuse (and, under 'sitemap', its relocation)
     * steals the element out from under every earlier page that also
     * resolved to it, silently emptying their own folders.
     *
     * By the time Step B runs, STEP A has already ruled out a row existing
     * for the CURRENT key itself — a hit there returns directly above, and a
     * stale/trashed hit deletes the row before falling through — so ANY row
     * found here for `$elementId`, whatever its own `image_key`, necessarily
     * belongs to a different key. A read-only check (safe on a dry run).
     *
     * @param int $elementId
     * @return bool
     */
    private function _isClaimedByKeyMapping(int $elementId): bool
    {
        return (new Query())
            ->from('{{%contentiq_asset_syncs}}')
            ->where(['element_id' => $elementId])
            ->exists();
    }

    /**
     * The pure decision behind Step B's (and the legacy fallback's) reuse:
     * a filename match may be reused only when it is NOT already claimed by
     * a different ContentIQ key. Deliberately factored out of
     * `_isClaimedByKeyMapping()`'s DB lookup — which needs a live Craft
     * install and has no coverage in this plugin's Craft-free test harness
     * (see AGENTS.md's testing section) — so the actual collision-avoidance
     * DECISION this fixes (see the class docblock) has direct, isolated
     * test coverage independent of the DB query that feeds it; both Step B
     * call sites in `_importAsset()` share this one method.
     *
     * @param bool $isClaimedByAnotherKey `_isClaimedByKeyMapping()`'s result for the candidate element.
     * @return bool Whether the candidate should be reused.
     */
    private function _shouldReuseFilenameMatch(bool $isClaimedByAnotherKey): bool
    {
        return !$isClaimedByAnotherKey;
    }

    /**
     * The pure decision behind Step A's self-heal: given every
     * `contentiq_asset_syncs` row id sharing the SAME element (including the
     * current key's own row), is the current key's row the rightful owner?
     *
     * Rule: the OLDEST row (lowest id) keeps the element. Several different
     * keys mapped to one element is the old filename-collapse bug (fixed at
     * Step B, but pre-existing rows from before that fix still carry it) —
     * treating the oldest row as canonical is an arbitrary but STABLE
     * tie-break (every subsequent sync of every non-owner key converges on
     * the same answer, rather than whichever key happened to sync last
     * "winning" and the ownership drifting sync to sync).
     *
     * Deliberately pure (no DB dependency) so this decision — not the
     * `contentiq_asset_syncs` query that gathers `$allRowIdsForElement` —
     * has direct, isolated test coverage; see docs/assets.md.
     *
     * @param int   $currentRowId        The current key's own contentiq_asset_syncs row id.
     * @param int[] $allRowIdsForElement Every row id (including `$currentRowId`) mapped to this element.
     * @return bool Whether the current key's row is the owner.
     */
    private function _isOldestMappingOwner(int $currentRowId, array $allRowIdsForElement): bool
    {
        if (empty($allRowIdsForElement)) {
            // Defensive default — a row with no siblings at all (not even
            // itself) is trivially the owner; should never actually happen
            // since the caller always includes the row's own id.
            return true;
        }

        return $currentRowId === min($allRowIdsForElement);
    }

    /**
     * Upserts a contentiq_asset_syncs row (image key → element id).
     *
     * Called after every resolution path that ends in a resolved asset id —
     * a filename-fallback reuse (STEP B) or a freshly downloaded asset — so
     * the key becomes resolvable directly next time (STEP A). Not called for
     * a STEP A key-based reuse, since that mapping is already correct.
     *
     * @param string $imageKey
     * @param int    $elementId
     * @return void
     */
    private function _upsertAssetMap(string $imageKey, int $elementId): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $db  = Craft::$app->getDb();

        $exists = (new Query())
            ->from('{{%contentiq_asset_syncs}}')
            ->where(['image_key' => $imageKey])
            ->exists();

        if ($exists) {
            $db->createCommand()->update(
                '{{%contentiq_asset_syncs}}',
                ['element_id' => $elementId, 'dateUpdated' => $now],
                ['image_key' => $imageKey],
            )->execute();
            return;
        }

        $db->createCommand()->insert('{{%contentiq_asset_syncs}}', [
            'image_key'   => $imageKey,
            'element_id'  => $elementId,
            'dateCreated' => $now,
            'dateUpdated' => $now,
        ])->execute();
    }

    /**
     * Whether the `allowPrivateAssetUrls` dev-only SSRF-refusal bypass is
     * genuinely active right now: the config value is on (set via
     * `setAllowPrivateAssetUrls()`) AND Craft's own `devMode` general
     * config is on. Re-checking `devMode` here (rather than trusting a
     * value cached at config-resolution time) is deliberate — it's what
     * keeps this bypass inert in production even if `allowPrivateAssetUrls`
     * is accidentally left `true` in a committed `config/contentiq.php`.
     * See docs/assets.md.
     *
     * @return bool
     */
    private function _privateUrlBypassActive(): bool
    {
        return $this->_allowPrivateAssetUrls
            && (bool)(Craft::$app->getConfig()->getGeneral()->devMode ?? false);
    }

    /**
     * Logs a single warning per run when `_privateUrlBypassActive()` first
     * lets a private/loopback asset URL through — not once per asset, so a
     * whole local-dev sync doesn't spam the log with dozens of identical
     * lines.
     *
     * @return void
     */
    private function _logPrivateUrlBypassOnce(): void
    {
        if ($this->_privateUrlBypassLogged) {
            return;
        }

        $this->_privateUrlBypassLogged = true;

        Craft::warning(
            "ContentIQImporter: allowPrivateAssetUrls is ACTIVE for this run (devMode only) — private/loopback asset URLs are being downloaded without the public-host SSRF check. Dev-only; never active in production.",
            __METHOD__,
        );
    }

    /**
     * Downloads a remote URL to a temp file and imports it as a Craft asset.
     *
     * Returns the result array on success, null on any failure.
     *
     * The alt text is only applied to newly created assets — existing assets
     * (reused by filename) are never touched, so editor-set alt text survives.
     *
     * `avoidFilenameConflicts` is on — a fresh download can legitimately land
     * on a filename another (differently-keyed) Craft asset already owns in
     * this same folder, now that Step B's claimed-by-another-key guard
     * refuses to reuse that element (see `_isClaimedByKeyMapping()`). Under
     * `'flat'`, where every page shares one folder, this is the normal case
     * for two pages' same-named uploads; Craft auto-suffixes the new file
     * (`hero_1.jpg`) instead of failing "a file with that name already
     * exists". The returned `filename` here stays the original, unsuffixed
     * one — harmless, since the asset's own `contentiq_asset_syncs` key
     * mapping (not the filename) is what makes it resolvable next sync.
     *
     * @param string       $url
     * @param string       $filename
     * @param Volume       $volume Target volume (images or documents).
     * @param VolumeFolder $folder Target folder within `$volume`.
     * @param string|null  $alt    Alt text from the wire image object (new assets only).
     * @return array{id: int, filename: string, reused: false}|null
     */
    private function _download(string $url, string $filename, Volume $volume, VolumeFolder $folder, ?string $alt = null): ?array
    {
        // SSRF guard — $url comes from attacker-controllable uploaded/synced
        // JSON. Refuse anything that isn't a public http(s) host before making
        // any request (cloud metadata endpoints, internal services, etc.) —
        // UNLESS the dev-only allowPrivateAssetUrls bypass is active (see
        // _privateUrlBypassActive()/docs/assets.md), e.g. a local ContentiQ
        // instance on a loopback-resolving dev domain. Redirects stay
        // disabled, and the size cap/timeout below are unaffected either way.
        if (!UrlSafety::isPublicHttpUrl($url)) {
            if (!$this->_privateUrlBypassActive()) {
                Craft::warning("Refused to download image — not a public http(s) URL: $url", __METHOD__);

                return null;
            }

            $this->_logPrivateUrlBypassOnce();
        }

        $tempPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . uniqid('contentiq_') . '_' . $filename;

        try {
            $response = Craft::createGuzzleClient()->request('GET', $url, [
                RequestOptions::SINK            => $tempPath,
                RequestOptions::TIMEOUT         => 30,
                // A public URL must not be able to 302 its way to an internal
                // one — never follow redirects on this request.
                RequestOptions::ALLOW_REDIRECTS => false,
            ]);

            // With redirects disabled, a 3xx is not an image — reject it
            // explicitly instead of importing whatever small body it sent.
            if ($response->getStatusCode() >= 300) {
                Craft::warning("Image download got HTTP {$response->getStatusCode()} (redirects are disabled): $url", __METHOD__);

                return null;
            }

            if (!is_file($tempPath) || filesize($tempPath) === 0) {
                Craft::warning("Downloaded file is empty or missing: $url", __METHOD__);

                return null;
            }

            // Sane upper bound so a misbehaving/malicious endpoint streaming an
            // unbounded body doesn't get imported as an asset.
            $maxBytes = 25 * 1024 * 1024;
            if (filesize($tempPath) > $maxBytes) {
                Craft::warning("Downloaded file exceeds the {$maxBytes}-byte limit: $url", __METHOD__);

                return null;
            }

            $asset = new Asset();
            $asset->setScenario(Asset::SCENARIO_CREATE);
            // Auto-suffix on an on-disk filename collision (see this
            // method's docblock) instead of erroring — a distinct,
            // differently-keyed asset sharing a filename with one already in
            // this folder is expected, not a validation failure.
            $asset->avoidFilenameConflicts = true;
            $asset->tempFilePath = $tempPath;
            // newLocation is what SCENARIO_CREATE validation requires — format: {folder:X}filename
            $asset->newLocation  = "{folder:{$folder->id}}{$filename}";
            $asset->title        = pathinfo($filename, PATHINFO_FILENAME);

            if ($alt !== null) {
                $asset->alt = $alt;
            }

            $saved = Craft::$app->getElements()->saveElement($asset);

            if (!$saved) {
                $errors = implode(', ', $asset->getFirstErrors());
                Craft::warning("Failed to save asset '{$filename}': {$errors}", __METHOD__);

                return null;
            }

            return ['id' => $asset->id, 'filename' => $filename, 'reused' => false];
        } catch (Throwable $e) {
            Craft::warning("Exception importing image '{$filename}': " . $e->getMessage(), __METHOD__);

            return null;
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Extracts just the base filename from a ContentIQ S3 key.
     *
     * The key format is 'project/path/to/filename.jpg'. Returns the last segment.
     *
     * @param string $key
     * @return string
     */
    private function _filenameFromKey(string $key): string
    {
        if ($key === '') {
            return '';
        }

        // Strip any thumbnail path segment (e.g. '.thumbs/') used by ContentIQ.
        $basename = basename($key);

        return $basename !== '.' ? $basename : '';
    }

    /**
     * Extracts a filename from a URL as a fallback.
     *
     * Strips query strings before extracting the basename.
     *
     * @param string $url
     * @return string
     */
    private function _filenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if ($path === null || $path === false) {
            return '';
        }

        return basename($path);
    }
}
