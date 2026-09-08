# Integration — how the plugin talks to ContentiQ

This covers the wire relationship between this Craft plugin and the ContentiQ Laravel app: which side initiates requests, the four endpoints involved, how a request authenticates and resolves to a project, the read-only-export/explicit-ack contract that governs ContentiQ's own state, the shape of what comes back over the wire, and where the connection settings and per-project overrides live. It does not cover what happens to the data once it's inside Craft — see [import-pipeline.md](import-pipeline.md) for that.

Verified against code 2026-08-24.

## Pull-only — Craft fetches, ContentiQ never pushes

There is no webhook receiver, no inbound route ContentiQ calls to notify Craft of new content. Every sync is Craft-initiated: a human triggers it (CP button, CLI command, sidebar widget), Craft's plugin makes an outbound HTTP request to the ContentiQ instance, and the response is imported. ContentiQ has no way to reach into a Craft install on its own. This is why the plugin needs a queue job for full syncs (`SyncJob`) rather than a request-scoped fetch — content volume plus image downloads can exceed a single HTTP request's timeout budget, but there's no other reason a "sync" is anything more than "GET, then write."

## The four endpoints

All requests are made via `Craft::createGuzzleClient()`, never `file_get_contents()` or curl directly — this picks up `config/guzzle.php` settings and correctly handles self-signed certs on local dev domains. Every call is authenticated with `Authorization: Bearer {apiKey}` and no project id appears anywhere in a URL — the project is resolved server-side from the key itself (see below).

Client code: `src/services/ContentIQApiService.php`. Read it directly for the exact request/response shapes; the summary below is purpose and timeout behaviour only.

| Endpoint | Method | Service method | Purpose | Timeout / connect-timeout |
|---|---|---|---|---|
| `/api/v1/export` | GET | `fetchExport()` — `src/services/ContentIQApiService.php:33` | Full project batch export — every page. Used by the Sync screen (tree preview and the queue job) and the CLI. | Caller-overridable; defaults to 120s / 10s. The Sync screen's tree-preview call passes a short override (`timeout: 20, connectTimeout: 5` — `src/controllers/CpController.php:661`) so a slow ContentiQ instance doesn't hang the page load; `SyncJob` uses the defaults for the real fetch. |
| `/api/v1/globals` | GET | `fetchGlobals()` — `src/services/ContentIQApiService.php:60` | Its docblock describes it as a standalone globals-only refresh, but the only actual caller in this codebase is `CpController::actionMappings()` (`src/controllers/CpController.php:98`), which reads `data.globals.collections[]` off the response to populate the CP Mappings screen's collection dropdown. The batch export's own `globals` key (see below) is what actually feeds the globals import itself — this endpoint is not on that path. | Fixed 120s / 10s (no override parameter). |
| `/api/v1/pages/{slug}/export` | GET | Called inline (not via `ContentIQApiService`) from `CpController::actionWidgetSync()` — `src/controllers/CpController.php:1136` | Single-page export for the sidebar widget's per-entry Sync button. | 30s / 10s, set inline at the call site. |
| `/api/v1/pages/ack` | POST | `ackPages()` — `src/services/ContentIQApiService.php:94` | Acknowledges pages genuinely written this run (body: `{"page_ids": [...]}`). See the read-only-export contract below — this is the only call that mutates state on the ContentiQ side. | 15s / 10s. |

An empty `$pageIds` to `ackPages()` is a no-op — no request is made, so callers never need to guard the call themselves (`src/services/ContentIQApiService.php:96`).

Both GET helpers share one private method, `_get()` (`src/services/ContentIQApiService.php:168`), which does the actual Guzzle call and JSON decode.

## Auth: Bearer key with embedded project slug

The API key has the shape `ciq_{project-slug}_{32chars}`. The Craft side never stores a separate `projectSlug` setting — there is no such field on `Settings` (`src/models/Settings.php`) or in `config/contentiq.php`'s merged defaults (`src/services/ImportService.php:1094`). The whole key is sent as the Bearer token on every request; the ContentiQ server parses the slug out of it and resolves the project from that — this plugin doesn't need to know or send the slug anywhere in a URL.

The one place this repo parses the slug itself is purely cosmetic: `CpController` (`src/controllers/CpController.php:680-691`) strips the `ciq_` prefix and takes everything up to the last underscore, to print a "Project: {slug}" label on the Sync screen. If that parse fails (key doesn't start with `ciq_`, or has no further underscore) it just shows an empty slug — it has no effect on any request.

If an existing project config YAML still has an old `projectSlug` key from a previous plugin version, it's harmless — nothing reads it anymore.

## Read-only export + explicit ack contract

Before 2026-08-21, ContentiQ flipped a page's status (and armed structure locks) the moment its export GET was served — meaning a preview fetch (the Sync screen loading its tree, or a dry-run) could silently mutate ContentiQ's own state. That's gone: **the export GETs are now read-only** on the ContentiQ side. The only way ContentiQ learns a page was actually imported is the explicit `POST /api/v1/pages/ack` call.

On the Craft side, `ackPages()` is only ever called with the ids of pages that were genuinely written this run:

- `SyncJob` builds the ack list after both import passes complete, filtering out anything `skippedLocked`, `skippedDeselected`, or `skipped` (an unmapped content type), and anything with no `entryId` — see `src/jobs/SyncJob.php:443-467`. It sends one batched `ackPages()` call for the whole run.
- `CpController::actionWidgetSync()` sends a single-page ack immediately after a successful single-entry sync — `src/controllers/CpController.php:1231-1240`.
- The CLI (`ImportController`) and the CP file-upload path (`actionRunImport()`) **never call `ackPages()`** — neither is wired to it. Ack is specific to the two entry points that talk to the live ContentiQ API (Sync and the widget); uploading a previously-exported JSON file has no live ContentiQ page ids to acknowledge against in the same sense, and nothing in the code attempts it.

**Ack failure is non-fatal.** Both call sites treat a failed `ackPages()` (network error, non-2xx, malformed JSON) as a run warning, never an error — the pages were still imported into Craft successfully; only ContentiQ's own bookkeeping is behind. `SyncJob` surfaces this as `$ackWarning` in the run result and flips the run status to `warnings` (`src/jobs/SyncJob.php:457-467`). Because the export GET didn't mutate anything, a page that failed to ack simply stays in ContentiQ's pending-import state and gets reprocessed (ack retried) on the next sync — nothing is lost, there's no divergent state to reconcile.

## The export envelope

Every page object has the same top-level shape: `document` (slug, title, id, parent_slug, is_homepage, content_type, depth), `seo`, `blocks` — for collection children, `content` can also be present: alongside `blocks[]` when the editor explicitly marked one or more Body Text sections (holding just those marked sections, merged in page order), or standing alone in place of `blocks` when no ranges are marked up at all. See [import-pipeline.md](import-pipeline.md) for the full contract. Regular pages never carry a `content` key. The envelope also carries an optional top-level `globals` key on the batch/sync envelope. Batch exports (`/api/v1/export`) wrap pages in a top-level `pages` array; single-page exports (`/api/v1/pages/{slug}/export`) return one page object directly. The plugin detects which shape it received by checking for a top-level `pages` key (e.g. `src/jobs/SyncJob.php:128`).

This doc only gives you the shape — for exact field names and the `nodes` ProseMirror-ish rich text format, read a real export file directly, or `src/config/defaults.php`'s header comment for the handler vocabulary; block-level field detail belongs to [block-mapping.md](block-mapping.md), not here.

## Config surface

Two layers: connection settings (URL + key), and behavioural config.

**Connection** — `src/models/Settings.php`. Two plain string properties, `contentiqUrl` and `apiKey`, saved through Craft's normal plugin-settings/project-config mechanism. The CP settings form (`src/templates/_cp/settings.twig`) uses `autosuggestField` with `suggestEnvVars: true`, so an editor *can* reference an environment variable (conventionally named something like `CONTENTIQ_URL` / `CONTENTIQ_API_KEY`, though nothing in the plugin hardcodes those names — it's just a suggestion UI). What's actually stored in project config is the env var reference (e.g. `$CONTENTIQ_API_KEY`), not the secret. Every read site resolves it at call time with `craft\helpers\App::parseEnv()` — never read the raw setting directly. `Settings::validateParsedUrl()` validates the *resolved* URL, not the raw string, so a `$SOME_ENV_VAR` placeholder doesn't fail validation for looking like a non-URL.

Both `contentiqUrl`/`apiKey` and `collectionMappings` are saved through Craft's standard plugin-settings mechanism, which persists into **project config** (`config/project/`) — committed to the repo like any other project config change. Because only the env var *reference* is stored (never the resolved secret), this is safe to commit, but it also means every environment that runs this plugin needs its own matching env var defined — a project config sync alone does not carry the actual URL/key value across environments, only which variable name to look it up from.

**Behaviour** — `config/contentiq.php`, merged over `src/services/ImportService.php:1094`'s hardcoded defaults via `array_replace_recursive`. Read that defaults array directly for the current full set; the ones with non-obvious effects:

- `content_types` — collection-child routing (slug → section/entryType/contentField/headingField/blocksField). This is actually a three-layer merge (defaults.php ← CP Mappings screen settings ← this config key), and the config file always wins per-slug. See `ImportService::_getContentTypesMap()` (`src/services/ImportService.php:769`) and [import-pipeline.md](import-pipeline.md).
- `blockOverrides` — replaces a block-type mapping from `src/config/defaults.php` entirely (not merged field-by-field). See [block-mapping.md](block-mapping.md).
- `slugMap` — Craft slug → ContentiQ slug, used **only** by the sidebar widget's single-page fetch (`CpController::actionWidgetSync()` looks up `$slugMap[$slug] ?? $slug` before calling `/api/v1/pages/{slug}/export}` — `src/controllers/CpController.php:1128-1130`). It is not applied anywhere in Craft-side entry lookup (`ImportService::findExistingEntry()` never touches it) — this is a one-directional translation for outbound API calls only.
- `preserveBlockIdentity` — off by default; rewrites the Matrix save path to reuse existing nested-block element ids instead of always creating new ones. This is entirely internal to Craft's own save behaviour, not part of the ContentiQ wire contract — see [block-mapping.md](block-mapping.md#diff-aware-matrix-writes-preserveblockidentity) and [import-pipeline.md](import-pipeline.md) for what it changes.
- `assetFolderStrategy` (`'flat'` default | `'sitemap'`) and `documentVolume` (default `'documents'`) — since 1.25.0, control where page-level `assets[]`/`files[]` (and, under `'sitemap'`, every other image) get filed, and whether a drifted reuse gets relocated. See [assets.md](assets.md).

## When the API is unreachable or unconfigured

Every entry point that talks to the live API checks `contentiqUrl`/`apiKey` are both non-empty (after `App::parseEnv()`) before attempting a request, and fails with a plain "not configured" message rather than trying and getting a confusing network error. Beyond that, the two CP screens that make a live call degrade differently, not identically:

- **The Sync screen** (`CpController::actionSync()`) tries `fetchExport()` with a short timeout to build its tree preview; if the API is unconfigured or the call fails, it falls back to `_buildLocalOnlySyncTree()` — the pre-existing view built entirely from `contentiq_entry_syncs` rows, with a banner explaining the fallback (`src/controllers/CpController.php:660-678`). The screen is still usable, just without ContentiQ's live "New" rows.
- **The Mappings screen** (`actionMappings()`) falls back to whatever's already stored in `Settings::$collectionMappings` and renders with an error banner, rather than blocking the screen entirely (`src/controllers/CpController.php:92-105`).
- **The sidebar widget's single-entry Sync** and **the queued full Sync** both just fail the action outright with the API error surfaced to the user — there's no local-only fallback for an actual write, only for these two read-only preview screens.

## Malformed-response handling

Every GET goes through `_get()` (`src/services/ContentIQApiService.php:168`), which decodes the body and checks `json_last_error()`. A non-2xx response or a network failure is caught as `GuzzleException` and returned as `{success: false, error: ...}` — never thrown out to the caller. Two layers of "successfully decoded but still garbage" checks exist above that:

- `ackPages()` additionally requires the decoded body to be an array, not just valid JSON — a bare `"ok"` or `42` response would pass `json_last_error()` but fails this check and returns `'ContentiQ ack returned invalid JSON.'` (`src/services/ContentIQApiService.php:129`).
- `SyncJob` does the same check on the export response before touching it (`is_array($data)` at `src/jobs/SyncJob.php:120`) — a scalar body would otherwise reach `importPage()` and throw a `TypeError` outside the per-page try/catch, killing the whole run instead of failing gracefully. It also filters individual batch entries: any element of `pages[]` that isn't itself an array is skipped and counted, surfacing as a "malformed export entries" warning row in the sync report rather than silently dropping it (`src/jobs/SyncJob.php:131-188`).

Nothing in this plugin retries a failed request automatically — a failed `fetchExport()` fails the whole sync run immediately (`_failRun()`); the user re-runs the sync.

## ContentiQ-side pairing

Not this repo's code, but worth knowing when debugging: ContentiQ's own sync model is whole-page replace, not merge — an unlocked page's entire content is overwritten by whatever the export currently contains, never diffed field-by-field. Structure locks on the ContentiQ side are per-run (armed by the ack call above), separate from this plugin's own per-entry `contentiq_entry_syncs.locked` column, which governs whether Craft-side import even attempts to write a given entry in the first place. The two lock mechanisms serve different purposes and neither implies the other.

## Related docs

- [README.md](README.md)
- [import-pipeline.md](import-pipeline.md)
- [block-mapping.md](block-mapping.md)
- [globals.md](globals.md)
- [assets.md](assets.md)
- [cp-and-widget.md](cp-and-widget.md)
