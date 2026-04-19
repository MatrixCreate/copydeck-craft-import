# Copydeck Importer — Progress

## Version 1.2.0 (in progress)

### Completed

- **Plugin settings** — Settings model with `copydeckUrl`, `apiKey`, `projectSlug`. Saved via Craft's plugin settings mechanism. Settings screen accessible from CP Settings > Plugins.

- **Intro screen** — Replaced history list as the plugin home. Shows "Sync from Copydeck" (primary) and "Import JSON File" (secondary) options. If API not configured, sync button links to settings instead.

- **History view** — Previous import history list moved to `copydeck-importer/history`, linked from the intro screen. Supports new `sync` type indicator alongside `batch` and `single`.

- **Copydeck API service** — `CopydeckApiService` fetches project export via `GET {url}/api/v1/projects/{slug}/export` with Bearer auth. Uses `Craft::createGuzzleClient()`.

- **Sync queue job** — `SyncJob` extends `craft\queue\BaseJob`. Controller creates a `pending` run record, pushes job to queue. Frontend polls `sync/status?runId=N` for completion, then redirects to sync report. Calls `Craft.postActionRequest('queue/run')` to kick the queue immediately.

- **Per-entry sidebar widget** — `EVENT_DEFINE_SIDEBAR_HTML` appends a COPYDECK section to every entry edit screen with Sync button and last-synced timestamp. Calls single-page API endpoint. Stored in `copydeck_entry_syncs` table.

- **Sync report** — Dedicated template showing hierarchical page tree with indentation from `depth`, created/updated indicators, edit/view links, inline warnings. Summary line with page/image/warning counts.

- **Hierarchy handling** — All import paths (CLI batch, CP JSON import, sync queue job) support `parent_slug` in the document object. Uses `Structures::append()` / `appendToRoot()` for correct sibling ordering. Maintains `$slugToEntryId` map with DB fallback for parent lookups.

- **Homepage import** — `is_homepage: true` routes to the `homepage` Single section. Same `hero` ContentBlock field as pages. Skips title overwrite and structure positioning.

- **Hero ContentBlock** — Both pages and homepage use `heroContent` ContentBlock field (handle override `hero`). Imports `heading`, `richText` (subheading + body), `desktopImage`, and `actionButtons`. Sets `enableHero = true`.

- **Hero subheading** — Optional `{level, text}` subheading rendered as `<hN>` prepended to body in `richText`.

- **Hero action buttons** — `buttons[]` array from Copydeck imported into `actionButtons` Matrix field inside the hero ContentBlock.

- **Copydeck Cards staging block** — Cards import to `copydeckCards` (not `contentCards`). Editors migrate to the appropriate final card block type with proper entry links.

- **Cards intro field** — `intro` ContentNode[] on cards blocks imported to outer `richText` CKEditor field above the card grid.

- **Cards structured body** — Card `body` changed from plain string to `ContentNode[]` array, processed through `NodesRenderer`. Supports paragraphs, lists, and embedded FAQ items.

- **FAQ nodes handler** — `faqNodes` handler splits the `nodes` array at the `faq_items` boundary: content before → `richText`, items → inner accordion entries, content after → `extraRichText`, CTA buttons → `actionButtons` Matrix. Supports both `fields.items` (primary) and `nodes.faq_items` (fallback) as FAQ item sources.

- **USP block** — `usp` type maps to `copydeckUsp` with `uspText` (richText with list support).

- **Global block** — `global` type maps to `copydeckGlobal` with `copydeckNotes` for developer staging.

- **Action button support** — `hyperButton` handler in MatrixBuilder converts `{label, url}` to Hyper field data. Sets `showLinkAsSeparateButton` when button present.

- **NodesRenderer upgrades** — Added `list` node type (with `ordered` boolean), `faq_items` node type (nested Q&A list). Supports `heading`, `paragraph`, `list`, `ordered_list`, `unordered_list`, `faq_items`.

- **Asset filename sanitization** — `Assets::prepareAssetName()` applied before idempotency lookup. Prevents mismatch when Craft sanitizes filenames on save (spaces → hyphens).

- **Image downloads via Guzzle** — Replaced `file_get_contents()` with `Craft::createGuzzleClient()` for SSL compatibility with dev domains.

- **Slug mapping** — `config/copydeck.php` `slugMap` translates Craft slugs to Copydeck slugs for the sidebar widget sync.

- **CLI default action** — `ImportController::$defaultAction = 'import'` so `copydeck-importer/import` works without repeating `import`.

- **CP nav icon** — Uses Craft's built-in `copyright` system icon.

### Craft Starter template changes

- **Hero template rewrite** — `hero.twig` rewritten as single file (~100 lines) reading from `entry.hero` ContentBlock. Deleted `hero.slide.twig` and `hero.slide.image.twig`. Removed carousel CSS. Parent image inheritance and global fallback preserved.

- **New content block templates** — `copydeckCards.twig`, `copydeckUsp.twig`, `copydeckGlobal.twig`, `priceList.twig`.

### New files (plugin)

```
src/
├── jobs/
│   └── SyncJob.php              # Queue job for API sync
├── models/
│   └── Settings.php             # Plugin settings model
├── services/
│   └── CopydeckApiService.php   # Copydeck API client
└── templates/_cp/
    ├── history.twig             # Import history (moved from index)
    ├── settings.twig            # Plugin settings form
    ├── sync.twig                # Sync screen with polling
    └── sync-result.twig         # Hierarchical sync report
```

### Modified files (plugin)

```
src/
├── CopydeckImporter.php         # Settings, routes, sidebar widget, icon
├── controllers/CpController.php # Intro, history, sync, widget-sync, hierarchy
├── console/controllers/ImportController.php  # defaultAction, hierarchy
├── services/ImportService.php   # Homepage, hero ContentBlock, hierarchy
├── services/MatrixBuilder.php   # hyperButton, faqNodes handlers, internal keys
├── services/NodesRenderer.php   # list, faq_items node types
├── services/ImageImportService.php # Guzzle downloads, filename sanitization
├── config/defaults.php          # All block mappings updated
└── templates/_cp/index.twig     # Now intro screen
```
