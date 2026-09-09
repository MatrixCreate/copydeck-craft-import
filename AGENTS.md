# ContentiQ Craft Import — Project reference

## What it is

A Craft CMS 5 plugin (`matrixcreate/contentiq-craft-import`, handle
`contentiq-importer`) installed on individual client sites. It pulls a
ContentiQ project's exported content — pages, blocks, images, globals — and
writes it into that site's Craft entries. Pull-only, whole-page replace: the
plugin never pushes anything back to ContentiQ except an explicit
acknowledgement of what it wrote. One plugin codebase, many independent
client installs, distributed via Packagist.

---

## Stack — settled

| Layer | Choice |
|---|---|
| Platform | Craft CMS 5.0+, PHP 8.2+ |
| Block mapping | Declarative — `src/config/defaults.php`, interpreted by `MatrixBuilder` |
| HTTP client | `Craft::createGuzzleClient()` everywhere — never `file_get_contents()`/curl directly |
| Composer dependencies | `craftcms/cms` only. `verbb/hyper` and `nystudio107/craft-seomatic` field/entry data is written, but neither is a composer requirement of this plugin — they're assumed already installed in the *target* Craft project, so this plugin never pins a version that could conflict with the site's own |
| Testing | Two zero-dependency, Craft-free PHP scripts under `tests/` (no PHPUnit, no Craft runtime harness) |

---

## Architectural principles

**Pull-only, explicit ack.** Craft never receives a push from ContentiQ. Every sync is Craft-initiated (CP button, CLI, sidebar widget); ContentiQ's export GETs are read-only, and the only thing that mutates ContentiQ's own state is an explicit `POST /api/v1/pages/ack` for pages genuinely written this run. See [docs/integration.md](docs/integration.md).

**Whole-page replace, locks are the editor's defence.** An unlocked sync overwrites an entry's content wholesale — never a field-by-field merge. The only thing that stops a re-sync clobbering hand-edits is `contentiq_entry_syncs.locked` (missing row ⇒ locked, the safe default). This is deliberate, not a gap: ContentiQ is the source of truth for synced content, and the lock is the sole opt-out.

**No drafts, ever.** Every save is `Craft::$app->getElements()->saveElement($entry, false)` directly on the canonical entry — never `getDrafts()->createDraft()`. Drafts were tried early and silently wrote images the CP couldn't see (it renders canonical, not drafts). The `false` (skip validation) is required to dodge `MatrixBlockAnchorField` uniqueness failures on nested Matrix saves, but it has a cost: URI generation is validation-time in Craft, so every write path must handle regenerating the entry's URI itself (structure positioning does this for free for pages; collection children call `_refreshUri()` explicitly). See [docs/import-pipeline.md](docs/import-pipeline.md).

**Declarative mapping — data over code.** `src/config/defaults.php` is the single source of truth for ContentiQ block type → Craft entry/field shape. Adding a block type is a config edit, not a `MatrixBuilder` change. Per-project overrides (`blockOverrides`, `content_types`, `slugMap`) replace a definition wholesale — never merged field-by-field. See [docs/block-mapping.md](docs/block-mapping.md).

**Idempotency via sync tables keyed on ContentiQ ids.** Every write path that can run twice has a table mapping a ContentiQ id to the Craft element it produced (`contentiq_entry_syncs`, `contentiq_office_syncs`, `contentiq_asset_syncs`, `contentiq_cta_syncs`, `contentiq_block_syncs`). Re-running a sync updates the same Craft element instead of duplicating it — this is what makes "just re-sync" a safe answer to almost every support question.

**Per-page error isolation.** `ImportService::importPage()` wraps its entire body in one `try`/`catch`, converting any exception into a fatal *result*, never letting it propagate. One bad page in a batch sync never aborts the other 99. See [docs/import-pipeline.md](docs/import-pipeline.md#per-page-error-isolation).

---

## Subsystem docs — read before you touch

Every area below has a doc with the binding traps and non-obvious behaviour. Read the relevant one before writing code there — this file is a router and a rulebook, not a substitute.

| Working on… | Read first | Watch out for |
|---|---|---|
| API sync, auth, the ack contract | [docs/integration.md](docs/integration.md) | Export GETs are read-only; only `POST /api/v1/pages/ack` flips ContentiQ's own state, and only for pages actually written this run |
| The page-level pipeline: find-or-create, saves, collection children, locks | [docs/import-pipeline.md](docs/import-pipeline.md) | `saveElement($entry, false)` skips URI generation — collection children need an explicit `_refreshUri()` call, pages get it for free from structure positioning |
| Block → Matrix mapping, hero, cards, CTA, `preserveBlockIdentity` | [docs/block-mapping.md](docs/block-mapping.md) | A mode that can legitimately produce zero inner entries must still emit the empty array (`['textBlocks' => []]`) — an omitted key leaves stale blocks behind |
| Globals/offices import, the consent lock | [docs/globals.md](docs/globals.md) | Consent is per-run only — never make it persist across syncs; office writes ignore per-entry locks entirely |
| Image download, idempotency, SSRF/path-traversal guards | [docs/assets.md](docs/assets.md) | Sanitize the filename *before* the idempotency lookup, not after, or every sync duplicates the asset |
| CP screens, the sync report, the sidebar widget | [docs/cp-and-widget.md](docs/cp-and-widget.md) | Sidebar content only works via `Entry::EVENT_DEFINE_SIDEBAR_HTML` — the field-layout-designer `BaseUiElement` approach silently doesn't reach the sidebar |

---

## Hard limits — never do these

- Save via `getDrafts()->createDraft()` — always the canonical entry, `saveElement($entry, false)`
- Skip `chdir(Craft::getAlias('@webroot'))` before asset operations in `ImportController` — CLI's working directory otherwise breaks every local-filesystem volume path
- Sanitize a filename *after* the asset idempotency lookup instead of before — the lookup must match what Craft actually saved the file as
- Use `BaseUiElement`/`EVENT_DEFINE_UI_ELEMENTS` for sidebar content — only `Entry::EVENT_DEFINE_SIDEBAR_HTML` reaches the entry sidebar
- Omit an inner-Matrix field key when a mode produces zero entries — emit the empty array, or a previous sync's blocks survive as phantoms
- Flip `preserveBlockIdentity` to `true` for a project without running the live-validation checklist in [docs/block-mapping.md](docs/block-mapping.md#diff-aware-matrix-writes-preserveblockidentity) on a real Craft instance — it rewrites the core Matrix save path and has no integration test coverage
- Call `ackPages()` for a page that wasn't genuinely written this run — the ack contract is what lets ContentiQ trust the pull actually happened
- Treat a missing `contentiq_entry_syncs` row as unlocked — the safe default everywhere it's checked (Sync screen, `SyncJob`, the sidebar widget) is locked
- Apply Sync-screen lock/unlock or globals-consent selections at controller-request time — `SyncJob` defers both to job execution, so a queue worker that never runs never "leaks" an unlock against a sync that didn't happen
- Assume `NodesRenderer::render()` and `renderDocument()` take the same node shapes — they're two independent paths (ContentiQ's own serialised blocks vs. raw ProseMirror for collection children) and conflating them silently mis-renders content
- Commit the local path-repo state (`composer.json`/`composer.lock` pointed at a symlinked path) — see "Local development" below
- Tag a release without a matching GitHub release with human-readable notes

---

## How to work

### Releasing

Always pair a git tag with a GitHub release — never tag without one.

```bash
git tag 1.x.0
git push origin main --tags
gh release create 1.x.0 --title "1.x.0" --notes "- What changed"
```

Release notes are a plain-language bullet list of what changed, one bullet per logical change — not commit messages verbatim.

### Local development

The plugin installs from Packagist by default. To develop it alongside a Craft project simultaneously, run from the Craft project's root:

```bash
# Switch to a local symlinked copy
composer config repositories.contentiq '{"type":"path","url":"../contentiq-craft-import","options":{"symlink":true}}' \
  && composer require matrixcreate/contentiq-craft-import:@dev

# Revert to Packagist
git checkout composer.json composer.lock && composer install
```

With `symlink: true`, edits in this plugin's directory are instantly live in the Craft project. **Never commit the path-repo state** — the `git checkout` revert step only works if `composer.json`/`composer.lock` were never committed while pointed at the local path. If that happens by accident, `git checkout` restores a broken HEAD; the fix is to manually strip the `repositories` entry and `@dev` constraint from `composer.json`, run `composer update matrixcreate/contentiq-craft-import`, then commit both files clean.

Optional `~/.zshrc` functions (aliases break on the nested quoting) wrap the two commands above as `cdp-local`/`cdp-packagist`.

### Testing

No PHPUnit harness or live Craft install exists in this repo — coverage is two zero-dependency standalone scripts:

```bash
php tests/run-transforms.php   # GlobalsTransforms, plus MatrixBuilder/NodesRenderer/ImportService against a hand-stubbed Craft/Yii harness (tests/fixtures/craft-stubs.php) — not a real Craft install
php tests/run-security.php     # UrlSafety (SSRF guard) + TempFileSafety (path-traversal guard)
```

Run whichever script covers the code you changed. `craft-stubs.php`'s docblock spells out exactly how far the stubbed Craft/Yii surface extends; anything beyond it still needs a real Craft install to verify (see the `preserveBlockIdentity` live-validation checklist as the sharpest example of this gap).

### PROGRESS.md doctrine

`PROGRESS.md` is a capped rolling log, not an archive — keep entries short (what changed, what docs were updated), and roll older entries off verbatim to `docs/_archive/` as the file grows rather than letting it accumulate indefinitely. Durable knowledge belongs in `docs/` (update the relevant subsystem doc in the same change) — never leave the only record of *how something works* sitting in a dated PROGRESS.md entry.

---

## Further reading

[docs/README.md](docs/README.md) is the documentation index — start there for the "life of a sync" narrative and the full doc list. `docs/_archive/` holds superseded snapshots (the pre-restructure `AGENTS.md`, the old `PLUGIN-SPEC.md`, and rolled-off `PROGRESS.md` entries) — useful for history, not current behaviour.
