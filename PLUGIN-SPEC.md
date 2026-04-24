# Copydeck Craft Importer — Plugin Spec

Craft CMS 5 plugin that imports Copydeck export JSON directly into published Craft entries. Standalone Composer package — pure Craft 5 PHP.

## Plugin identity

- Package: `matrixcreate/copydeck-craft-import`
- Handle: `copydeck-importer`
- Namespace: `matrixcreate\copydeckimporter`
- Minimum Craft: 5.0
- GitHub: https://github.com/MatrixCreate/copydeck-craft-import

## Console command

```
php craft copydeck-importer/import --file=path/to/export.json
php craft copydeck-importer/import --file=path/to/export.json --dry-run
php craft copydeck-importer/import --file=path/to/export.json --verbose
```

- `--dry-run` validates and reports without writing anything or downloading assets.
- `--verbose` logs each image as it's processed.
- Detects single vs batch from JSON shape: top-level `blocks` = single page, top-level `pages` = batch.

## Plugin structure

```
src/
  CopydeckImporter.php          # Plugin bootstrap, registers services, sidebar widget
  console/controllers/
    ImportController.php         # Main import command
    TestMatrixController.php     # Isolated API test (debug tool)
    ApplyDraftController.php     # One-off draft apply (debug tool)
  controllers/
    CpController.php             # CP routes: import, sync, history, widget, lock, notes
  jobs/
    SyncJob.php                  # Queue job for API sync
  models/
    Settings.php                 # Plugin settings model
  services/
    ImportService.php            # Pipeline orchestrator
    ImageImportService.php       # Asset download + idempotent import
    NodesRenderer.php            # Copydeck nodes → HTML
    MatrixBuilder.php            # Block mapping → Matrix data array
    CopydeckApiService.php       # Copydeck API client
  config/
    defaults.php                 # Block type mappings
  migrations/
    Install.php                  # Creates copydeck_import_runs + copydeck_entry_syncs
    m250418_000000_add_entry_syncs_table.php
    m250419_000000_add_notes_to_entry_syncs.php
    m250419_000001_add_locked_to_entry_syncs.php
  templates/_cp/
    index.twig                   # Intro screen (sync + upload options)
    history.twig                 # Import history list
    settings.twig                # Plugin settings form
    sync.twig                    # Sync screen with queue polling
    sync-result.twig             # Hierarchical sync report
composer.json
CLAUDE.md                       # Settled API patterns — loaded automatically by Claude
```

## Config

Installed at `config/copydeck.php` in the Craft project. All keys have defaults:

```php
return [
    'section'        => 'pages',
    'entryType'      => 'pages',
    'assetVolume'    => 'images',
    'assetFolder'    => 'copydeck',
    'matrixField'    => 'contentBlocks',
    'seoField'       => 'seo',
    'slugMap'        => [],   // Craft slug → Copydeck slug overrides for sidebar widget
    'blockOverrides' => [],   // Replaces entire block definitions from defaults.php
];
```

## Block mappings (defaults.php)

Two-level nested Matrix: contentBlocks (outer) → inner entry types.

| Copydeck type    | Outer entry type | Inner Matrix field     | Inner entry type      | Mode     |
|------------------|------------------|------------------------|-----------------------|----------|
| `text`           | `text`           | `textBlocks`           | `textBlock`           | single   |
| `text_and_media` | `textAndMedia`   | `textAndMediaBlocks`   | `textAndMediaBlock`   | grouped  |
| `faq`            | `faq`            | `accordionItems`       | `accordionItem`       | repeated |
| `cards`          | `copydeckCards`  | `copydeckCards`        | `copydeckCard`        | repeated |
| `price_list`     | `priceList`      | *(none)*               | —                     | outer only |
| `usp`            | `copydeckUsp`    | *(none)*               | —                     | outer only |
| `global`         | `copydeckGlobal` | *(none)*               | —                     | outer only |

Handled separately (not via defaults.php):
- `hero` — ContentBlock field (`hero`) on the page/homepage entry. Sets `enableHero = true`.
- `call_to_action` — creates a `callToActionEntry` in the `callsToAction` section, then relates it via `chooseCallToAction` on a `callToAction` contentBlock.
- `table` — skipped (no matching block type).

Field handler types: `nodes`, `image`, `heading`, `body`, `layout`, `textMediaLayout`, `tableHtml`, `hyperButton`, `faqNodes`, `buttonNodes`.

## Import pipeline — per page

1. Validate JSON structure (`document.slug` required)
2. Resolve section and entry type from config
3. Prepare ImageImportService (volume + folder)
4. Prepare MatrixBuilder (merge mappings)
5. Extract hero block; pass remaining blocks to MatrixBuilder
6. Build Matrix data; resolve SEO via SEOmatic SeoSettings field
7. Resolve CTA blocks → create/find `callToActionEntry` entries, patch IDs into Matrix placeholders
8. Find existing entry by slug (or create new)
9. Filter field values against the entry's field layout
10. Set field values directly on the entry (no draft)
11. Save with `saveElement($entry, false)` (skip validation)
12. Report result; update `copydeck_entry_syncs` row

## Key behaviours

**No drafts.** Saves directly to the canonical entry. Re-importing overwrites in place.

**Idempotent images.** Same filename in same folder = reuse existing asset, no re-download.

**SEOmatic integration.** SEO data goes into a single `SeoSettings` field (handle: `seo`) via `metaGlobalVars` and `metaBundleSettings` arrays.

**CLI webroot.** `ImportController` calls `chdir(Craft::getAlias('@webroot'))` before any asset operations — required for local filesystem volume paths to resolve.

**Homepage.** Pages with `is_homepage: true` import into the `homepage` Single section instead of `pages`. Title is not overwritten; structure positioning is skipped.

**Hierarchy.** `parent_slug` in `document` sets the parent entry via `setParentId()`. A `$slugToEntryId` map is maintained during batch runs; falls back to DB query.

**Sidebar widget.** `EVENT_DEFINE_SIDEBAR_HTML` appends a COPYDECK section to every entry edit screen. Shows sync status, last-synced timestamp, block notes, and a lock toggle.

**Queue sync.** CP sync pushes a `SyncJob` to Craft's queue. Frontend polls `sync/status?runId=N` until complete, then redirects to the sync report.

## Error handling

| Condition                     | Behaviour              |
|-------------------------------|------------------------|
| Invalid JSON                  | Fatal, exit 1          |
| Section/entry type not found  | Fatal, exit 1          |
| Asset volume not found        | Fatal, exit 1          |
| Unknown block type            | Skip, warn, continue   |
| Image download fails          | Skip field, warn, continue |
| Entry save fails              | Fatal, log errors, exit 1 |
| Field handle not in layout    | Skip field, warn, continue |
