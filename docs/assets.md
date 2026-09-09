# Image & asset import

What this covers: how `ImageImportService` downloads a ContentiQ image
reference and turns it into (or reuses) a Craft asset — the idempotency
rules, the SSRF/temp-file safety net around the download, the CLI webroot
quirk, how the multi-image "custom"/"image_gallery" block fields fit in,
the
`assetFolderStrategy: 'sitemap'` folder-filing/relocation behaviour added
in 1.25.0, and Image Gallery "Choose Folder" mode (the `gallerySource`/
`assetFolder` handlers and the R9 relocation guard that protects a
gallery-folder image also used by a block elsewhere on the page).

Verified against code 2026-09-09.

---

## Mental model

Every image ContentiQ exports arrives as a small JSON object:
`{ "key": "path/to/file.jpg", "url": "https://...", "alt": "..." }` (block/
hero/SEO/card images) — page-level `assets[]`/`files[]` items (below) carry
a few more keys but the same `key`/`url` core. `key` is the upstream asset's
stable storage path; `url` is a signed, rotating download link.
`ImageImportService::importFromField()` (images) and `::importFile()`
(non-images, `src/services/ImageImportService.php`) are the entry points
every caller — `MatrixBuilder`, `GlobalsImportService`, `ImportService`'s
hero/CTA/page-asset handling — goes through to turn that object into a
Craft asset ID. A caller must call `prepare($volumeHandle, $folderPath, ...)`
(images) and, only if it has files to import, `prepareDocuments(...)`
once per run before any `importFromField()`/`importFile()` calls; each
resolves and caches its own target volume and folder.

Running the same import twice must **not** duplicate assets. Two
independent idempotency checks make that true, tried in order.

---

## Page-level `assets[]`/`files[]` and the `'sitemap'` folder strategy

Since 1.25.0, `ImportService::importPage()`/`_importCollectionChild()` file
every page's exported `assets[]` (images) and `files[]` (non-images, e.g.
PDFs) through `ImageImportService` as a step independent of any Matrix/hero/
SEO/card field — see `ImportService::_importPageAssets()`. Nothing here
attaches to an entry field; it only creates/reuses/relocates Craft assets
and records their key mapping, same idempotency as every other image path.
This runs for a locked entry too (`ImportService::importPageAssetsOnly()`) —
asset filing never touches entry content, so it's exempt from the lock.

**`assetFolderStrategy`** (`config/contentiq.php`) controls where a page's
images/files land:

- `'flat'` (default) — byte-identical to pre-1.25.0 behaviour. Every image
  lands in the single configured `assetFolder` folder; no relocation, no
  legacy-folder fallback.
- `'sitemap'` — each page gets its own folder mirroring `document.path`
  (the ancestor-title chain ContentiQ sends, already disambiguated
  for same-title siblings), sanitised segment-by-segment with
  `craft\helpers\Assets::prepareAssetName($segment, false)` — the exact
  function the Craft CP itself runs when a user names a folder — and joined
  under the `assetFolder` base (`src/helpers/AssetFolderPath.php`). A
  missing/empty `document.path` (older ContentiQ, or a page ContentiQ
  couldn't place) falls back to that same flat base folder. Block/hero/
  card/SEO/CTA images land in the page folder automatically, because they
  already go through `importFromField()` after `ImportService::_preparePageAssetTargets()`
  has called `prepare()` with it — no separate wiring needed.
  A `files[]` item goes to `documentVolume` (default `'documents'`) under the
  SAME page folder path (only resolved when the page actually has `files[]`).
  A misconfigured/missing `documentVolume` never fails the page — see
  "A missing documents volume" below.

**Per-item sub-folder** (`'sitemap'` only) — an `assets[]`/`files[]` item's
own `folder` (a ContentiQ client-made, block-named per-page folder) adds one
more level under the page folder (`{pagePath}/{Folder-Name}`), via
`AssetFolderPath::withSubfolder()` and `importFromField()`/`importFile()`'s
`$folderPathOverride` parameter. An item with no `folder` goes straight
into the page folder — same as every block image. Under `'flat'` an item's
`folder` is ignored outright (`ImportService::_importPageAssets()` gates the
override on `$isSitemap`) — applying it there would put every other client
site's uploads one folder deeper than `assetFolder` the moment they upgrade,
which is exactly what "byte-identical" above promises won't happen.

**A missing documents volume never fails the page.** If a page carries
`files[]` but the configured `documentVolume` doesn't exist in Craft,
`ImportService::_preparePageAssetTargets()` catches `ImageImportService::prepareDocuments()`'s
exception, adds a page-level warning ("Documents volume 'x' not found — N
file(s) skipped."), and tells `_importPageAssets()` to skip the `files[]`
loop entirely for that page — `assets[]` (images) still import normally.

**Relocation** (`'sitemap'` only) — after a Step A or Step B reuse, if the
resolved asset's current folder differs from the freshly-computed target
folder, `Craft::$app->getAssets()->moveAsset()` moves it there and the
result is flagged `relocated`. This is deliberate on every sync (not just
the first one after enabling `'sitemap'`) — it also self-heals any manual
folder tidying a client does in Craft, and undoing that is an accepted
trade-off. Never happens on a dry run, and never under `'flat'` (there the
target folder never changes, so relocation would just be moving root-volume
images into the configured `assetFolder` on a project that never asked for
that). Because a Step B/legacy match claimed by another key is never
reused (above), and Step A's self-heal above only relocates on behalf of a
mapping's rightful owner, relocation only ever moves an element that was
resolved as uniquely owned by the current key — it can no longer steal a
different page's element out from under it just because they share a
filename or a stale shared mapping.

**A missing physical file at relocation time is a DB-only "success", and a
visible warning, not silently swallowed.** `moveAsset()` → Craft's own
`Asset::_relocateFile()` (a same-volume move) calls
`craft\fs\Local::renameFile()`, which runs `@rename()` and never checks its
return value — if the source file doesn't exist on disk (e.g. a local dev
Craft install whose DB was restored from a snapshot but whose asset files
were never synced down), the physical move silently no-ops while Craft's
own save still succeeds and the DB `folderId` updates exactly as if it had
really moved. There is no exception, no validation error — nothing this
plugin could otherwise detect after the fact. `_relocateIfNeeded()` checks
the source file's existence itself, BEFORE calling `moveAsset()`, and — if
missing — still lets the DB-only move go ahead (it's still `relocated:
true`; Craft's own definition of success is DB state, and that part is
genuinely correct) but attaches a `warning` string surfaced on the page
result, so the DB/disk mismatch is visible instead of indistinguishable
from a real move. This `warning` (same for the Step A self-heal above) is
wired through EVERY `importFromField()`/`importFile()` caller, not just the
page-level `assets[]`/`files[]` step — hero desktop/mobile, SEO `og_image`,
card image, CTA image/background, the Custom and Image Gallery blocks'
multi-image fields, and globals (branding/office images, via `_countImage()`) all push a
non-empty `warning` into their own result's `warnings` list.

**Legacy-folder Step B fallback** (`'sitemap'` only) — an asset synced
before the sitemap folder structure existed (or before this project's
`assetFolderStrategy` was flipped to `'sitemap'`) still lives in the flat
base folder, not the newly-computed page folder. Step B checks the resolved
page folder first, then that flat base folder, before concluding nothing
exists and downloading a fresh duplicate; a legacy-folder hit is reused,
key-mapped, and relocated into the page folder like any other reuse.

`pageAssets`/`pageFiles` — each a `{created, reused, relocated, failed}`
count quadruple — land on every page's result array
(`ImportService::_emptyAssetCounts()`) and render in the Sync Report and the
legacy upload/CLI result screen; see `docs/cp-and-widget.md`. `failed` is
incremented (with a matching page-level warning) whenever an item couldn't
be imported at all — a thrown exception, or `ImageImportService` returning
`null` (a download failure, an unrecognisable item) — so a payload item is
never silently absent from the count with no explanation.

**Dry run never creates a folder record.** `_preparePageAssetTargets()`
threads `$dryRun` straight into `prepare()`/`prepareDocuments()`, which
resolve read-only (`findFolder()`) on a dry run instead of
`ensureFolderByFullPathAndVolume()` — this matters under both strategies,
but especially `'sitemap'`: without it, previewing an import (CLI
`--dry-run`, or the CP upload Preview screen, which has no lock check and so
walks every page) would silently create the entire per-page `VolumeFolder`
tree just from clicking Preview, never actually importing anything into it.

---

## Idempotency, step by step

**Step A — key-first identity (`contentiq_asset_syncs`).** If the image
object's `key` is non-empty, the service looks it up in
`contentiq_asset_syncs` (`image_key → element_id`). A hit against a still-live
asset wins outright — no download happens at all. This is the primary path
once a project has synced at least once: the key is globally unique (it's
the upstream storage path), so it's collision-proof even when two different
pages or projects happen to share a bare filename. If the mapped asset was
hard-deleted or trashed, the stale map row is cleared (real runs only) and
resolution falls through to Step B.

**Step A self-heal — a mapping shared by several keys is untrustworthy.**
Before Step B existed, several DIFFERENT keys could end up mapped to the
SAME element (the pre-fix filename collapse — see the "Behaviour change"
note below). A Step A hit whose element ALSO has `contentiq_asset_syncs`
rows for OTHER keys is a leftover of that: `_isOldestMappingOwner()` treats
the OLDEST row (lowest `id`) as the rightful owner — it keeps the element
and is the only one relocation ever runs for. Every other key's row is
deleted (real runs only) and that key falls through to Step B/download as
if it had never been mapped at all, with a page-level warning ("key X
shared a Craft asset with N other ContentiQ keys — re-imported as its own
asset"). This makes ownership converge on a stable answer sync over sync,
rather than drifting to whichever key happened to sync last.

**Step B — filename fallback, in the target folder, but ONLY when the
match isn't already claimed by a different key.** If there's no key mapping
(first sync since the table was added, or the image has no `key`), the
service looks for an existing asset with the same filename in the resolved
volume+folder — but a match is only reused when `_isClaimedByKeyMapping()`
finds NO `contentiq_asset_syncs` row for it. A hit that DOES have a row is
a different ContentiQ asset that merely happens to share a filename (every
page having its own `hero.jpg` is the common shape ContentiQ actually
sends) — Step B treats that as no match at all and falls through to a fresh
download, exactly as if the filename lookup had found nothing. On an
unclaimed hit, it's reused and — on a real run, when the image has a key —
the key mapping is created here so Step A resolves it directly next time.
This is also how a Craft install with pre-`contentiq_asset_syncs` assets
adopts a key mapping without duplicating anything. The same guard applies
to the legacy-folder fallback below — both share one method,
`_shouldReuseFilenameMatch()`.

**Behaviour change (2026-09, correctness fix, both strategies):** before
this guard existed, Step B collapsed same-named files from DIFFERENT
ContentiQ pages onto a single Craft element under `'flat'` mode too — every
page's own `hero.jpg` all resolved to whichever element the FIRST synced
page created, silently misattributing every other page's hero image to it.
`'sitemap'` mode's relocation made this externally visible (each
successive page's sync moved the shared element into its own folder, so an
earlier page's folder ended up with zero assets and its `contentiq_asset_syncs`
rows pointed at an element sitting in a completely different page's
folder), but the collapse itself was already happening under `'flat'` — it
was just invisible there, since nothing ever moved the element. A fresh
download that lands on a filename another (differently-keyed) asset already
owns in the same folder — routine under `'flat'`, where every page shares
one folder — is not an error: `_download()` sets `Asset::$avoidFilenameConflicts
= true`, so Craft auto-suffixes the new file (`hero_1.jpg`) instead of
rejecting the save. The suffixing is harmless for idempotency: it's the
`contentiq_asset_syncs` key mapping, not the filename, that makes the asset
resolvable on the next sync.

Only when neither step finds an unclaimed match does the service actually
download the file and create a new asset.

**The hazard — filename sanitization must happen before the Step B lookup,
not after.** The candidate filename is built with
`craft\helpers\Assets::prepareAssetName()` — the exact function Craft itself
runs on an asset's filename at save time — **before** the Step B existence
query, not after. Craft's own save path turns spaces into hyphens and
strips other characters (e.g. `Styles - Luxury - Card Image.jpg` becomes
`Styles-Luxury-Card-Image.jpg` on disk). If the idempotency lookup queried
the raw, unsanitized name, it would never find the asset Craft actually
saved under the sanitized name, and every sync would create a fresh
duplicate. Any change to how the target filename is derived must sanitize
before checking existence, not the other way round.

Alt text is only applied to freshly downloaded assets — a Step A/B reuse
never touches `alt`, so an editor's hand-set alt text in Craft survives
every re-sync.

---

## The download itself

Downloads go through `Craft::createGuzzleClient()`, not `file_get_contents()`
— this is what makes self-signed dev-domain certs (e.g. `contentiq.test`)
work, and it respects any project `config/guzzle.php` overrides. Failures
are non-fatal by design: `_download()` returns `null` on any exception, HTTP
status ≥ 300, an empty/missing temp file, or a body over the 25MB cap;
`importFromField()` propagates that `null` up, and every caller treats a
`null` result as "leave this field empty and continue" rather than aborting
the whole page/block/globals import.

Two safety checks run before and during every outbound fetch:

- **SSRF guard** (`UrlSafety::isPublicHttpUrl()`, `src/helpers/UrlSafety.php`)
  — the `url` in a synced/uploaded JSON payload is attacker-controllable, so
  before any request is made the target must be `http`/`https`, resolve
  (directly or via DNS) to a non-private/non-loopback/non-link-local
  address, and redirects are disabled on the request itself (`ALLOW_REDIRECTS
  => false`) so a public URL can't 302 its way to an internal one after the
  check has already passed. This file is pure PHP (native
  `gethostbyname`/`dns_get_record`, no Craft dependency) — see
  `tests/run-security.php` for its standalone coverage. The same file also
  holds `safeHref()`, an unrelated scheme-allowlist helper used by
  `NodesRenderer`/`LinkHelper` when rendering stored HTML — grouped here
  only because both are "is this URL safe to act on" checks with no Craft
  runtime dependency, not because `safeHref()` is part of the asset
  pipeline.

  **`allowPrivateAssetUrls` — LOCAL DEV ONLY, bypasses this refusal.**
  `config/contentiq.php`'s `allowPrivateAssetUrls` (default `false`) lets
  `_download()` skip the public-host check for a private/loopback URL —
  e.g. a local ContentiQ instance on a dev domain that resolves to
  `127.0.0.1` (`contentiq.test`), which the SSRF guard would otherwise
  refuse outright, making local end-to-end asset-download testing
  impossible. `ALLOW_REDIRECTS => false`, the 25MB size cap, and the 30s
  timeout are all unaffected — only the public-host refusal is skipped.
  Only ever takes effect when `Craft::$app->getConfig()->getGeneral()->devMode`
  is ALSO `true` — `ImageImportService::_privateUrlBypassActive()` checks
  this itself at the point of use, every time, so a config value that
  somehow survives into a production deploy stays inert. Logs one
  `Craft::warning()` per run (not once per asset) the first time the bypass
  actually lets a URL through, so an active bypass is never silent.
- **Orphaned-file cleanup** (`_deleteOrphanedFile()`) — before saving a new
  asset, the service checks whether a file with the target name already
  exists on the volume filesystem with no corresponding DB row (live *or*
  trashed). If so it deletes the stray file first, since Craft's own "file
  already exists" validation would otherwise block the save. Trashed rows
  are deliberately treated as "not an orphan" — Craft's soft-delete restore
  depends on that physical file still being there.

**Creating the `Asset` element itself has two non-obvious requirements.**
`Asset::setScenario(Asset::SCENARIO_CREATE)` must be set before save, and
`$asset->newLocation` must be the `"{folder:{$folderId}}{$filename}"` string
form — `SCENARIO_CREATE`'s own validation requires `newLocation` in that
exact shape, not a bare filename or path. `$asset->tempFilePath` is set to
the downloaded file's path so Craft knows what to move into the volume.
Skipping either of the first two silently fails Craft's own validation
rather than this service's.

---

## CLI webroot requirement

`php craft contentiq-importer/import --file=export.json` must `chdir()` to
`Craft::getAlias('@webroot')` before any asset operation runs — see
`ImportController::actionImport()`. **What breaks without it:** local
filesystem volume paths in `project.yaml` (e.g. `assets/cms/images`) are
relative to the web root, which is the working directory for every normal
web request. A CLI process starts with the project root as its working
directory instead, so without the explicit `chdir()` those relative paths
resolve to the wrong place (or nowhere) and asset saves fail. The file path
argument is resolved to an absolute path *before* the `chdir()` call, since
changing the working directory would otherwise break a relative `--file`
argument.

---

## Multi-image blocks

The Custom block's `images` field (Craft handle `contentiqImages`) and the
Image Gallery block's `images` field (Craft handle `images`, images-mode
only — see [block-mapping.md](block-mapping.md)) both accept an array of
`{key, url, alt}` objects rather than a single image, and both are wired to
the same `'images'` handler in `src/config/defaults.php`.
`MatrixBuilder::_handleImages()` iterates the array, calling
`importFromField()` per entry through the same idempotency path described
above; a single bad entry (missing `url`, or a download failure) is skipped
with a warning and does not fail the rest of the block. Any cap is entirely
a property of the target Craft field's config (`maxEntries`/`maxRelations`)
— `_handleImages()` itself enforces none — so check the Assets field
definition in the target project's `config/project/fields/` if you need the
current cap for a given block. Custom's field is conventionally capped at
10; Image Gallery's is uncapped (`maxRelations: null`) by design. In Image
Gallery folder mode (below), the wire's `images[]` is always `[]` — the
handler still runs and correctly emits an empty array (never omitted; see
`AGENTS.md`'s "empty array, not an omitted key" rule) — the gallery's
pictures instead flow through the page's own `assets[]` into the folder
`assetFolder` (the field handler, below) names.

---

## Image Gallery "Choose Folder" mode

Two more `image_gallery` handlers, on top of `images`/`nodes` above: the
wire's `source` (`'images'` | `'folder'`) and `folder` (a raw ContentiQ
folder name, or `null`) keys. **Don't confuse `folder` the field handler
here with `assetFolder` the config key** (`config/contentiq.php`'s base
volume folder, `docs/assets.md` above) — same word, unrelated: one names a
Craft field handle (`AssetFolderField`), the other a config value.

- **`gallerySource`** (`MatrixBuilder::_handleGallerySource()`) — maps
  ContentiQ's neutral wire vocabulary to Craft's `imageSource` dropdown:
  `'images'` → `'images'`, `'folder'` → `'folders'` (note Craft's is
  **plural**). Anything else defaults to `'images'`.
- **`assetFolder`** (`MatrixBuilder::_handleAssetFolder()`) — resolves the
  wire's raw folder name to the UID string `AssetFolderField` expects
  (`$folder->uid`; `AssetFolderField::serializeValue()`/`normalizeValue()`
  in the target Craft project — see the field's own source, not this repo).
  A `null`/blank wire value (images mode) is a no-op → field stays `null`.

**Requires `assetFolderStrategy: 'sitemap'`.** Under `'flat'` every page
shares one asset folder (see above), so a folder-mode gallery's folder would
hold every OTHER page's images too — `_handleAssetFolder()` warns
("… folder mode needs assetFolderStrategy 'sitemap' …") and leaves the field
`null` rather than writing a folder that silently over-shares.

**The page's own folder path, exposed.** `_resolveFieldByHandler()` is
never given the page's resolved asset folder — nothing in `MatrixBuilder`
knows it. `ImageImportService` does (it's what `_preparePageAssetTargets()`
calls `prepare()` with), so `_handleAssetFolder()` reads it via
`ImageImportService::getPreparedFolderPath()`, a plain accessor for the
exact string `prepare()` was called with. **Deliberately not derived from
`$_folder->path`** — Craft's `VolumeFolder::$path` carries a trailing slash,
which would need an `rtrim()` at every call site; stashing the raw
`$folderPath` string once, in `prepare()`, avoids that trap entirely.
`AssetFolderPath::withSubfolder($pageFolderPath, $folderName)` then appends
the gallery's own folder name as one more level — the exact same call shape
`_importPageAssets()` already uses for an `assets[]` item's own `folder`
(above), so a folder-mode gallery names a folder that ITS OWN `assets[]`
entries (same ContentiQ folder name) are already filing into; nothing new
gets created data-wise, only named.

**The dry-run split is enforced in `ImageImportService::resolveFolderByPath()`**
— a public wrapper around the same `_resolveFolderByPath()` every other
per-item folder override uses, scoped to the run's already-`prepare()`d
images volume: `findFolder()` (read-only, never creates a record) on
`$dryRun` — which covers CLI `--dry-run` **and** the CP Preview screen, both
already `$dryRun` throughout this plugin — and
`ensureFolderByFullPathAndVolume()` only on a real run. This is the same
split `_resolveVolumeAndFolder()`/`_resolveFolderByPath()` already enforce
elsewhere (see "Dry run never creates a folder record" above); reusing it
here rather than a fresh lookup is what keeps a folder-mode gallery from
regressing that 2026-09-08 fix. **On a dry run, a not-yet-existing folder
resolves to `null` — silently, no warning.** That's the expected Preview
shape (nothing has synced yet to have created the folder), not a failure; a
real run reaching a `null` result instead warns, since it means the images
volume itself is unresolved.

**No validation on the Craft side, so this handler is the only guard.**
`AssetFolderField` performs none of its own — a bad/foreign UID normalises
to `null` silently (`normalizeValue()`, `getFolderByUid()`). Before trusting
a resolved folder's UID, `_handleAssetFolder()` checks it against
`ImageImportService::getPreparedVolume()` (the run's own resolved
`assetVolume`) and warns + leaves the field `null` on a mismatch, rather
than ever writing a folder outside the volume the field is meant to point
into.

### R9 — protecting a shared image from sitemap relocation

**The trap.** A Craft asset lives in exactly one folder, and a folder-mode
gallery's Craft template renders by folder membership
(`craft.assets().folderId(block.assetFolder.id)…`), not an explicit
relation. So if an image inside a ContentiQ gallery folder is ALSO used as a
block image elsewhere on the same page (hero, a card, text-and-media), that
image is (a) subtracted from `assets[]` by ContentiQ itself (the same
block-referenced-key subtraction ContentiQ already applies for the Custom
block's `images[]`, so it doesn't export twice) and imported into the page
folder ROOT via the block path instead, then (b) **actively relocated OUT of the
gallery folder** by the ordinary `'sitemap'` relocation behaviour (above) —
`_relocateIfNeeded()` moves unconditionally whenever the asset's current
folder differs from the target. The gallery then silently renders short by
exactly the images it shares with a block.

**The fix — a protected-folder set, threaded through per page.**
`ImportService::_resolveProtectedGalleryFolderIds()` scans the page's
`blocks[]` for `image_gallery` blocks with `source === 'folder'`, resolves
each one's folder name to a Craft folder id the same way
`_handleAssetFolder()` does (`AssetFolderPath::withSubfolder()` +
`ImageImportService::resolveFolderByPath()`), and returns the set. Both
`ImportService::importPage()` and `_importCollectionChild()`
(`→ _buildBlockFieldValues()`) call
`ImageImportService::setProtectedFolderIds()` with that set **after**
`_importPageAssets()` (so a folder-mode gallery's own `assets[]` images have
already created its folder) and **before** `MatrixBuilder::build()` (so
every block image resolved by it — hero/card/text-and-media — honours the
protection). `_relocateIfNeeded()` gains one additional early return: an
asset whose CURRENT folder id is in the protected set is never moved,
full stop — everything else (legacy root relocation, the ordinary
ContentiQ-folder sub-level move) behaves exactly as it did before R9.

The protected set is reset to empty on every `ImageImportService::prepare()`
call (once per page, at the very start of asset handling) so a previous
page's protection can never leak into the next page's `assets[]` filing
step — which runs BEFORE this page's own protected set is known, and where
relocation must still behave completely normally (filing an item into its
OWN designated folder, including a gallery's, is the intended function, not
the R9 bug).

**Two residual limits, by design, not bugs to chase:**
- **No-op under `'flat'`.** `_resolveProtectedGalleryFolderIds()` returns
  `[]` immediately when `$isSitemap` is false — consistent with folder mode
  not resolving at all under `'flat'` (above), so there's nothing to
  protect there in the first place.
- **Per-page only.** The protected set is built from THIS page's own
  `blocks[]`. An asset physically sitting in ANOTHER page's gallery folder
  (e.g. a shared stock image referenced as a hero on page B, but living in
  page A's gallery folder) is still relocated when page B syncs — breaking
  page A's gallery. Each ContentiQ asset key resolves to its own Craft
  element after the asset-folders self-heal (1.25.0+), so cross-page sharing
  of a single element should be rare, but it is the one gap R9 doesn't
  close. Worth checking first if a gallery mysteriously loses an image after
  an unrelated page's sync.

---

## Temp-file handling

Two small, Craft-free helpers guard file paths used elsewhere in the import
flow (neither is part of `ImageImportService`'s own download temp file,
which is a `uniqid()`-based path under Craft's temp directory, cleaned up in
a `finally` block regardless of outcome):

- **`helpers/TempFileSafety.php`** — validates the `tempFilename` hidden
  field the CP upload flow round-trips between the preview and run-import
  requests (`CpController::actionPreview()` / `actionRunImport()`). Without
  this, an attacker-controlled value reaching `getTempPath() . '/' .
  $tempFilename` would allow path traversal to read or delete arbitrary
  files; `sanitize()` strips any directory component and confirms what's
  left matches the server-generated pattern
  (`contentiq-import-{word chars}.json`).
- **`helpers/UrlSafety.php`** — see the SSRF guard above.

Both are pure PHP with no Craft dependency, exercised by
`tests/run-security.php` (a zero-dependency runner in the same style as
`tests/run-transforms.php` — see `docs/globals.md`).

---

## How assets are tracked

`contentiq_asset_syncs` (created by `m260814_000000_add_asset_syncs_table`)
is the sole persistence for image-key idempotency: `image_key` (unique
index) → `element_id`, upserted by `_upsertAssetMap()` after every
resolution path that ends in a resolved id (a Step B reuse or a fresh
download — never a Step A hit, since that mapping is already correct).
Deleting a row here doesn't delete the Craft asset; it just means the next
sync falls back to the Step B filename lookup for that image.

`element_id` is NOT unique on this table — several rows CAN legitimately
point at the same element, and that's exactly what the Step A self-heal
(above) watches for: it's the signal that a mapping is a leftover of the
pre-fix filename collapse rather than a genuine one-key-one-asset pairing.
`id` (the table's own auto-increment primary key, not `image_key`) is what
`_isOldestMappingOwner()` compares to pick a stable owner.

---

## Related docs

- [globals.md](globals.md) — branding/trust-signal logos and office images go through this exact same service.
- [block-mapping.md](block-mapping.md) — which content-block fields carry image data and how they're wired to `_handleImages`/`importFromField`.
- [import-pipeline.md](import-pipeline.md) — where in the page-import run images get downloaded relative to entry saves.
- [README.md](README.md) — plugin overview and where each doc fits.
- [integration.md](integration.md) — the ContentiQ API/export contract that supplies the `{key, url, alt}` shape.
