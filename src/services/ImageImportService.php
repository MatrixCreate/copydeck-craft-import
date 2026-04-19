<?php

namespace matrixcreate\copydeckimporter\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\models\Volume;
use craft\models\VolumeFolder;
use GuzzleHttp\RequestOptions;
use Throwable;
use yii\base\Component;
use yii\base\Exception;

/**
 * Handles downloading remote images and importing them as Craft assets.
 *
 * Idempotent: if an asset with the same filename already exists in the target
 * folder it is reused — the file is not downloaded again and a new asset is
 * not created. Running the import twice does NOT duplicate assets.
 *
 * Failed downloads are non-fatal: the method returns null and logs a warning.
 * Callers should treat null as "leave field empty" and continue.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class ImageImportService extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * Cached volume instance, resolved once per import run.
     *
     * @var Volume|null
     */
    private ?Volume $_volume = null;

    /**
     * Cached target folder instance, resolved once per import run.
     *
     * @var VolumeFolder|null
     */
    private ?VolumeFolder $_folder = null;

    // Public Methods
    // =========================================================================

    /**
     * Resolves the configured volume and folder, caching both for the run.
     *
     * Must be called once before any importFromUrl() calls. Throws if the
     * volume handle is not found — this is a fatal configuration error.
     *
     * @param string $volumeHandle  e.g. 'images'
     * @param string $folderPath    e.g. 'copydeck'
     * @return void
     * @throws Exception if the volume handle cannot be resolved.
     */
    public function prepare(string $volumeHandle, string $folderPath): void
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

        if ($volume === null) {
            throw new Exception("Asset volume '{$volumeHandle}' not found. Check the 'assetVolume' key in config/copydeck.php.");
        }

        $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(
            $folderPath,
            $volume,
            true, // justRecord — volume handles physical dir creation on save
        );

        $this->_volume = $volume;
        $this->_folder = $folder;
    }

    /**
     * Imports an image from a Copydeck image field value.
     *
     * Accepts the raw image object from the JSON:
     *   { "key": "path/to/file.jpg", "url": "https://...", "alt": null }
     *
     * Returns the Craft asset ID on success, null on failure or if the image
     * field is empty (no url).
     *
     * @param array|null $imageField  Raw image object from Copydeck JSON.
     * @param bool       $dryRun     If true, resolves what would happen without downloading.
     * @return array{id: int|null, filename: string, reused: bool}|null
     *   Returns null if the image field is empty or unusable.
     */
    public function importFromField(?array $imageField, bool $dryRun = false): ?array
    {
        if (empty($imageField['url'])) {
            return null;
        }

        $url      = $imageField['url'];
        $rawFilename = $this->_filenameFromKey($imageField['key'] ?? '') ?: $this->_filenameFromUrl($url);
        // Sanitize the same way Craft does on save — spaces become hyphens, etc.
        $filename = Assets::prepareAssetName($rawFilename);

        if ($filename === '') {
            Craft::warning("Could not determine filename from image field: " . json_encode($imageField), __METHOD__);

            return null;
        }

        // Idempotency check — reuse if already imported.
        $existing = Asset::find()
            ->volumeId($this->_volume->id)
            ->folderId($this->_folder->id)
            ->filename($filename)
            ->one();

        if ($existing !== null) {
            return ['id' => $existing->id, 'filename' => $filename, 'reused' => true];
        }

        if ($dryRun) {
            return ['id' => null, 'filename' => $filename, 'reused' => false];
        }

        // If the file exists on the volume but not in the DB (orphaned from a
        // previous partial import or manual DB cleanup), remove it so Craft's
        // "file already exists" validation does not block the save.
        $this->_deleteOrphanedFile($filename);

        return $this->_download($url, $filename);
    }

    /**
     * Resets cached volume and folder. Call between import runs if needed.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->_volume = null;
        $this->_folder = null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Deletes a file from the volume if it exists on the filesystem without a
     * corresponding asset DB record (orphaned).
     *
     * This can happen when asset DB records are deleted manually (e.g. during
     * testing) but the volume files are not removed. Craft's "file already exists"
     * validation would otherwise block creating a new asset with the same name.
     *
     * @param string $filename
     * @return void
     */
    private function _deleteOrphanedFile(string $filename): void
    {
        try {
            $fs         = $this->_volume->getFs();
            $folderPath = $this->_folder->path ? rtrim($this->_folder->path, '/') . '/' : '';
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
     * Downloads a remote URL to a temp file and imports it as a Craft asset.
     *
     * Returns the result array on success, null on any failure.
     *
     * @param string $url
     * @param string $filename
     * @return array{id: int, filename: string, reused: false}|null
     */
    private function _download(string $url, string $filename): ?array
    {
        $tempPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . uniqid('copydeck_') . '_' . $filename;

        try {
            Craft::createGuzzleClient()->request('GET', $url, [
                RequestOptions::SINK    => $tempPath,
                RequestOptions::TIMEOUT => 30,
            ]);

            if (!is_file($tempPath) || filesize($tempPath) === 0) {
                Craft::warning("Downloaded file is empty or missing: $url", __METHOD__);

                return null;
            }

            $asset = new Asset();
            $asset->setScenario(Asset::SCENARIO_CREATE);
            $asset->tempFilePath = $tempPath;
            // newLocation is what SCENARIO_CREATE validation requires — format: {folder:X}filename
            $asset->newLocation  = "{folder:{$this->_folder->id}}{$filename}";
            $asset->title        = pathinfo($filename, PATHINFO_FILENAME);

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
     * Extracts just the base filename from a Copydeck S3 key.
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

        // Strip any thumbnail path segment (e.g. '.thumbs/') used by Copydeck.
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
