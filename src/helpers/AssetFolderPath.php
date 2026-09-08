<?php

namespace matrixcreate\contentiqimporter\helpers;

use craft\helpers\Assets;

/**
 * Derives sitemap-mirroring asset folder paths from a ContentIQ page's
 * `document.path` (ancestor titles, root → self) and the plugin's
 * configured base `assetFolder`.
 *
 * Segment sanitisation is delegated to `craft\helpers\Assets::prepareAssetName()`
 * — the exact function the Craft CP runs when a user names a folder —
 * rather than a bespoke sanitiser, so a folder this helper builds always
 * matches what the CP itself would have produced for the same name. The
 * sanitiser is injectable (`$sanitizer`) purely so the joining/fallback
 * logic here can be exercised by `tests/run-transforms.php`, which has no
 * Craft runtime to call `Assets::prepareAssetName()` against — see
 * `sanitizeSegment()`'s own docblock.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.25.0
 */
class AssetFolderPath
{
    // Public Methods
    // =========================================================================

    /**
     * Builds the per-page folder path for a ContentIQ document.
     *
     * Each segment of `$documentPath` (root → self, already disambiguated by
     * ContentIQ for same-title siblings) is sanitised the same way Craft
     * sanitises a folder name typed into the CP, then joined with `/` under
     * `$baseFolder` (which may be `''` — the volume root). A missing or
     * empty `$documentPath` — older ContentiQ exports that don't send
     * `document.path` yet, or a page ContentiQ genuinely couldn't place —
     * falls back to `$baseFolder` unchanged, i.e. the plugin's pre-existing
     * flat behaviour.
     *
     * @param string[]      $documentPath Ancestor titles root → self, e.g. `['Windows', 'Slimline', 'Reynaers SL38 Window']`.
     * @param string        $baseFolder   The configured `assetFolder` base (may be `''`).
     * @param callable|null $sanitizer    `fn(string $segment): string`, defaults to `sanitizeSegment()`. Overridable for Craft-free tests.
     * @return string
     */
    public static function forDocument(array $documentPath, string $baseFolder, ?callable $sanitizer = null): string
    {
        if (empty($documentPath)) {
            return $baseFolder;
        }

        $sanitizer = $sanitizer ?? [self::class, 'sanitizeSegment'];
        $segments  = $baseFolder !== '' ? [trim($baseFolder, '/')] : [];

        foreach ($documentPath as $title) {
            $sanitized = $sanitizer((string)$title);

            if ($sanitized !== '') {
                $segments[] = $sanitized;
            }
        }

        return implode('/', $segments);
    }

    /**
     * Appends a ContentiQ per-page asset folder (a client-made, block-named
     * folder — the `folder` key on an `assets[]`/`files[]` item) as one more
     * level under an already-resolved page folder path.
     *
     * A null/blank/unsanitisable folder name is a no-op — the caller's item
     * belongs directly in the page folder, not a sub-level.
     *
     * @param string        $pagePath  Folder path already resolved by `forDocument()` (or the flat base).
     * @param string|null   $folderName The item's raw `folder` value from the wire payload.
     * @param callable|null $sanitizer  See `forDocument()`.
     * @return string
     */
    public static function withSubfolder(string $pagePath, ?string $folderName, ?callable $sanitizer = null): string
    {
        if ($folderName === null || trim($folderName) === '') {
            return $pagePath;
        }

        $sanitizer = $sanitizer ?? [self::class, 'sanitizeSegment'];
        $sanitized = $sanitizer($folderName);

        if ($sanitized === '') {
            return $pagePath;
        }

        return $pagePath === '' ? $sanitized : $pagePath . '/' . $sanitized;
    }

    /**
     * Sanitises a single path segment exactly the way the Craft CP does when
     * a user names an asset folder — `Assets::prepareAssetName($segment, false)`,
     * NOT a bespoke sanitiser.
     *
     * Requires a booted `Craft::$app` (reads `generalConfig->filenameWordSeparator`/
     * `convertFilenamesToAscii`), so it cannot run inside `tests/run-transforms.php`'s
     * Craft-free harness — `forDocument()`/`withSubfolder()` accept an
     * injected `$sanitizer` callable specifically so their joining/fallback
     * behaviour can still be covered there without this method ever being
     * called in that context.
     *
     * @param string $segment
     * @return string
     */
    public static function sanitizeSegment(string $segment): string
    {
        return Assets::prepareAssetName($segment, false);
    }
}
