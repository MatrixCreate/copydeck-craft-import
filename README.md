# ContentiQ Craft Import

Craft CMS 5 plugin that pulls ContentiQ content into published Craft entries — primarily via a live API sync, with a manual JSON file import as the fallback path.

> [!IMPORTANT]
> ### 📚 [Jump to the full developer documentation →](docs/README.md)
> The whole plugin at a glance, one doc per subsystem, plus a walk through the life of a sync.

**Contents:** [Requirements](#requirements) · [Installation](#installation) · [Configuration](#configuration) · [Usage](#usage) · [How it fits with ContentiQ](#how-it-fits-with-contentiq) · [**Documentation**](#documentation) · [Local development](#local-development) · [Releasing](#releasing) · [Related repositories](#related-repositories)

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

```bash
composer require matrixcreate/contentiq-craft-import
php craft plugin/install contentiq-importer
```

## Configuration

Add `config/contentiq.php` to your Craft project:

```php
return [
    'section'             => 'pages',       // Entry section handle
    'entryType'           => 'pages',       // Entry type handle
    'assetVolume'         => 'images',      // Asset volume for imported images
    'assetFolder'         => 'contentiq',   // Folder within the volume
    'assetFolderStrategy' => 'flat',        // 'flat' (default) | 'sitemap' — see docs/assets.md
    'documentVolume'      => 'documents',   // Volume for non-image files[] (e.g. PDFs)
    // 'allowPrivateAssetUrls' => true,     // LOCAL DEV ONLY — bypasses the SSRF guard for a
                                             // loopback-resolving dev domain; inert unless devMode
                                             // is also true. See docs/assets.md.
    'matrixField'         => 'contentBlocks',
    'seoField'            => 'seo',
];
```

All keys are optional — the values above are the defaults. See [docs/integration.md](docs/integration.md#config-surface) for the full config surface, including `blockOverrides`, `content_types`, `slugMap`, and the diff-aware `preserveBlockIdentity` flag.

Add API credentials to `.env`:

```
CONTENTIQ_URL=https://your-contentiq-instance.com
CONTENTIQ_API_KEY=ciq_your-project_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Then reference them in the plugin settings: **CP → Settings → Plugins → ContentiQ**.

## Usage

### API sync

The **Sync** screen in the CP loads the full ContentiQ sitemap (including pages never yet imported into Craft) and lets you select what to pull. Submitting runs via Craft's queue — the screen polls for completion and shows a hierarchical report when done. See [the life of a sync](docs/README.md#the-life-of-a-sync) for the full sequence and [docs/cp-and-widget.md](docs/cp-and-widget.md) for a tour of the screen.

### Per-entry sync

Each entry edit screen shows a **ContentiQ** sidebar widget with:

- **Sync** — pulls and re-imports just this entry from the API
- **Lock** — a lightswitch that stops this entry being overwritten by a sync (a never-synced entry is locked by default)
- **Synced at** — the last sync time, with a **Reload** link that appears after a fresh sync (the entry's fields aren't re-rendered live)
- **Notes** — developer notes attached to content blocks in ContentiQ

See [docs/cp-and-widget.md](docs/cp-and-widget.md#entry-sidebar-widget) for the full behaviour.

### CP upload

Navigate to **ContentiQ** in the Craft control panel. Upload a JSON export file, review the dry-run preview, then confirm to import. See [docs/cp-and-widget.md](docs/cp-and-widget.md) for a tour of every screen.

### CLI import

```bash
php craft contentiq-importer/import --file=export.json
php craft contentiq-importer/import --file=export.json --dry-run
php craft contentiq-importer/import --file=export.json --verbose
php craft contentiq-importer/import --file=export.json --force
```

`import` is the controller's only (and default) action. It detects single-page vs. batch export format automatically from the JSON shape (a top-level `blocks` array is a single page, a top-level `pages` array is a batch) and loops the same pipeline either way.

- `--file` (`-f`) — path to the ContentiQ JSON export file. Required.
- `--dry-run` (`-n`) — validates and reports without writing anything or downloading assets.
- `--verbose` (`-v`) — logs each block and image as it's processed.
- `--force` — bypasses the entry-lock check. By default a locked entry is skipped (a missing lock row also counts as locked); `--force` overrides that so the CLI can write over it.

## How it fits with ContentiQ

This plugin is a **pull-only** consumer of ContentiQ's export API — Craft never receives a push, and every sync is initiated from this side (a CP button, the CLI, or the sidebar widget). A sync overwrites an entry's content wholesale (**whole-page replace**), not a field-by-field merge; the only defence against a re-sync clobbering hand-edits is per-entry locking. After a successful write, this plugin sends an explicit **acknowledgement** back to ContentiQ (`POST /api/v1/pages/ack`) for the pages it actually wrote — that's the only thing that mutates ContentiQ's own state. See [docs/integration.md](docs/integration.md) for the full wire contract.

## Documentation

| Doc | Covers |
|---|---|
| [integration.md](docs/integration.md) | The four API endpoints, auth, the read-only-export/explicit-ack contract, and the config surface |
| [import-pipeline.md](docs/import-pipeline.md) | The per-page pipeline: find-or-create, the no-drafts save, collection children, homepage, hierarchy, locks |
| [block-mapping.md](docs/block-mapping.md) | The declarative block mapping system, `MatrixBuilder`, `NodesRenderer`, hero, cards, CTA, `preserveBlockIdentity` |
| [globals.md](docs/globals.md) | Company info/offices/branding import, the per-run consent lock, and the URL-prefix drift check |
| [assets.md](docs/assets.md) | Image download and idempotency, SSRF/path-traversal guards, the CLI webroot requirement |
| [cp-and-widget.md](docs/cp-and-widget.md) | Every CP screen, the sync report, and the entry sidebar widget |

Start at **[docs/README.md](docs/README.md)** for the index and the life-of-a-sync narrative.

## Local development

To develop the plugin and a Craft project simultaneously, run from the Craft project root:

```bash
# Switch to local symlinked copy
composer config repositories.contentiq '{"type":"path","url":"../contentiq-craft-import","options":{"symlink":true}}' \
  && composer require matrixcreate/contentiq-craft-import:@dev

# Revert to Packagist
git checkout composer.json composer.lock && composer install
```

Never commit the path repository — the `git checkout` step ensures `composer.json` is clean before pushing. See [AGENTS.md](AGENTS.md#local-development) for the full workflow, including recovery if the path repo does get committed by accident.

## Releasing

Always pair a git tag with a GitHub release:

```bash
git tag 1.x.0
git push origin main --tags
gh release create 1.x.0 --title "1.x.0" --notes "- What changed"
```

## Related repositories

- **[contentiq](https://github.com/MatrixCreate/contentiq)** — the Laravel app content is authored, reviewed, and approved in before this plugin ever sees it. Its `docs/delivery/` covers the server side of the export/ack API contract this plugin consumes.
- **contentiq-payload** (the `payload-sync` package) — the Payload CMS equivalent of this plugin, consuming the same ContentiQ export API for Payload-based client sites.
- **craft-starter** — the Craft site starter that provisioned client sites (the ones this plugin syncs into) are built from.
