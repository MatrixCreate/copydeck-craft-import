<?php

namespace craft\helpers;

/**
 * Minimal stand-in for craft\helpers\Assets::prepareAssetName() — needed by
 * exactly one test in run-transforms.php: confirming
 * ImportService::_importPageAssets() actually calls
 * AssetFolderPath::withSubfolder() with its DEFAULT (real, non-injected)
 * sanitizer under the 'sitemap' strategy, rather than only ever exercising
 * the injectable-$sanitizer path (see the AssetFolderPath section earlier in
 * run-transforms.php, and AssetFolderPath::sanitizeSegment()'s own
 * docblock).
 *
 * NOT Craft's actual algorithm — this is lowercase + non-alphanumeric → a
 * single hyphen, nothing more. Craft's real `FileHelper::sanitizeFilename()`
 * (via `filenameWordSeparator`/`convertFilenamesToAscii` general config) is
 * unavailable without a booted Craft::$app; see docs/assets.md. Only ever
 * loaded by the test runner, never by the plugin itself.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.25.0
 */
class Assets
{
    /**
     * @param string $name
     * @param bool   $isFilename Ignored — the real signature's second param,
     *                           kept for call-shape compatibility.
     * @return string
     */
    public static function prepareAssetName(string $name, bool $isFilename = true): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }
}
