# Block mapping — from ContentiQ blocks to Craft Matrix

What this doc covers: how a ContentiQ page's `blocks[]` array becomes a Craft
`contentBlocks` Matrix field (and the handful of things that live outside
that Matrix — hero, cards, call-to-action, SEO). The declarative mapping
table, the builder that walks it, the ProseMirror-to-HTML renderer, and the
non-obvious rules for text columns, grouping, cards resolution, and the
diff-aware write path. Verified against code 2026-09-04.

---

## Mental model

Every block in a ContentiQ export is a `{type, fields, notes, id}` object.
`MatrixBuilder` turns each one into a Craft "outer" contentBlocks entry
(`text`, `textAndMedia`, `faq`, `cards`, …), most of which wrap a nested
"inner" Matrix field (`textBlocks`, `accordionItems`, `card`, …). The whole
shape — which Craft entry type a ContentiQ block type becomes, which fields
it carries, and how its inner Matrix is populated — is data, not code: it
lives in `src/config/defaults.php`. `MatrixBuilder` (`src/services/
MatrixBuilder.php`) is a generic interpreter for that data; adding a new
block type is a defaults.php edit, not a MatrixBuilder change. `ImportService`
(`src/services/ImportService.php`) orchestrates the whole page — it calls
MatrixBuilder for ordinary blocks, then handles the handful of things that
don't fit the Matrix shape (hero, call-to-action, cards cross-page
references, SEOmatic) itself, and does the actual Craft save.

Rich text everywhere here is ContentiQ's own `ContentNode[]` JSON, not raw
ProseMirror — `NodesRenderer` (`src/services/NodesRenderer.php`) is the one
place that turns those nodes into the HTML string CKEditor fields expect. A
second, unrelated node shape — the raw ProseMirror `content` AST ContentiQ
stores for collection children — is rendered by the same class through a
parallel set of methods; see "NodesRenderer" below.

---

## The declarative mapping system

`src/config/defaults.php` is the single source of truth for block → Craft
shape. Read its header comment directly — it documents every mode, handler
type, and special key precisely; this doc gives you the shape and the traps,
not a re-typed copy (it will drift). Per block type, a mapping is: `outerType`
(the Craft contentBlocks entry type handle), `outerFields`
(`[contentiqKey => [craftHandle, handlerType]]`, set on the outer entry),
and `innerMatrix` — either `null` or `{outerField, innerType, mode, fields}`
describing the nested Matrix.

**Modes**, in one line each:
- `single` — one inner entry, built straight from the block's own fields.
- `repeated` — one inner entry per item in a `sourceKey` array (FAQ items, cards).
- `grouped` — MatrixBuilder pre-merges *consecutive* blocks of the same type
  into one outer entry with one inner entry per source block (`text_and_media`
  is the only current user — see "Text & Media grouping").
- `text_columns` — a bespoke split between the outer entry's own rich text
  field and its inner Matrix; `text` is the only user (see "Text blocks").

**The `'_block'` special key.** When a mapping's `contentiqKey` is `'_block'`
(in `outerFields` or `innerMatrix.fields`), the handler receives the block's
entire `fields` array instead of one value — used wherever the Craft output
depends on more than one source key at once (USP's heading+items, text-and-
media's mediaType+image routing).

**Handler types** are a fixed vocabulary dispatched by
`MatrixBuilder::_resolveFieldByHandler()` — read that match arm for the
exhaustive list. Most are one-line converters (plain string → `<p>`, `{level,
text}` → `<hN>`); the ones with real behaviour (`mediaNodes`, `textMediaMedia`,
`faqNodes`, `collectionSection`) are covered in their own sections below.

**Bracketed placeholders, and the one block exempt from the strip.** ContentiQ
authors mark where the CMS will render something else with a standalone
bracketed paragraph, heading or list item — `[Client quote]`,
`[Product category grid]`, `[Blog Listing]`. Those nodes are dropped before
richText render, whole-node only, so brackets inside a sentence survive; the
rule and its counter live in `NodesRenderer` (see its class docblock, which is
the authority). The exception is the **Custom** block: its content is free
markup typed by hand in ContentiQ's markup editor, so bracketed text there is
content the editor meant and is written verbatim. That is why `custom` maps its
`nodes` key to the `customNodes` handler rather than `nodes` — the only
difference between the two. Nothing is counted for Custom blocks, so a page of
hand-written Custom markup reports zero placeholders stripped.

**Per-project overrides.** `config/contentiq.php`'s `'blockOverrides'` key
replaces a block's *entire* definition — not merged field-by-field.
`MatrixBuilder::prepare()` does `array_replace($defaults, $overrides)` once
per run; there is no partial-override mechanism.

**`content_types` is not a block mapping.** It's a separate table in the
same file, routing collection-child `content_type` slugs (blog, case
studies, team, …) to a Craft section/entry type/content field —
`MatrixBuilder::prepare()` explicitly `unset()`s it before merging. See
docs/import-pipeline.md for the collection-child path.

---

## MatrixBuilder — prepare() / build()

`prepare(array $config)` merges defaults + overrides into `$this->_mapping`
once per run; every other public method reads that cached mapping.

`build(array $blocks, ...)` does, in order:
1. `_groupConsecutiveBlocks()` — pre-merges consecutive `mode: grouped`
   blocks of the same type into one `_groupedBlocks` item; everything else
   passes through unchanged.
2. Walks the (possibly grouped) list. Unknown block types are skipped with a
   warning. `call_to_action` blocks are emitted as bare `['type' =>
   'callToAction', '_cta' => true]` placeholders — ImportService resolves
   the real CTA entry and patches `chooseCallToAction` into these
   placeholders afterwards (build() itself never touches the CTA tables).
3. For every other type, resolves outer fields, then inner Matrix data
   according to the mapping's `mode`.

**Matrix key format.** Every emitted key is either `'new{N}'` (create) or an
integer (update the existing nested element in place) — this is Craft's own
rule for nested Matrix save data, not something this plugin invented. By
default every key MatrixBuilder emits is `'new{N}'`; the only way an integer
key appears is the diff-aware `preserveBlockIdentity` path (its own section
below).

**⚠️ Hazard — deliberately empty inner-Matrix arrays.** When a block's inner
Matrix has nothing to write (e.g. a `text_columns` single-column block with
no second column), MatrixBuilder still emits the outer field with an *empty*
array (`['textBlocks' => []]`), not an omitted key. **This is what tells
Craft to delete inner blocks a previous import left behind.** Writing the
key only when there's content would leave a page that used to have a second
column, and no longer does, rendering the stale one forever — Craft only
prunes a Matrix field's nested elements when told the full current set; an
omitted key means "leave it alone." Same rule wherever a mode can
legitimately produce zero inner entries.

---

## NodesRenderer — ContentNode[] and raw ProseMirror, two different inputs

`NodesRenderer` has two independent rendering paths that happen to share a
class and a mark-rendering helper. Do not conflate them.

**`render(array $nodes)`** — the everyday path, used by nearly every handler
in MatrixBuilder. Consumes ContentiQ's own serialised `ContentNode[]` shape
(`{type: 'heading', level, text, content?}`, `{type: 'list', items,
itemContents}`, etc. — a flat, block-shaped array, not a ProseMirror doc).
`_renderNode()`'s `match` is the exhaustive list of what's handled
(`heading`, `paragraph`, `blockquote`, `list`/`ordered_list`/`unordered_list`,
`faq_items`, `table`, `ctaButton`); unknown types render to `''` silently.
Every node type prefers its `content` array (inline marks, via
`renderInlineContent()`/`_wrapMark()`) and falls back to plain `text` when
`content` isn't present.

**`renderDocument(?array $doc)`** — used only for collection children, which
carry a raw ProseMirror `content` AST (`pages.content` from the ContentiQ
DB) instead of ContentiQ's serialised blocks. Accepts either a doc node
(`{type:'doc', content:[...]}`) or a bare content array. `_renderDocNode()`
is a *separate* match arm handling ProseMirror's own type names (`heading`
via `attrs.level`, `paragraph`, `blockquote`, `bulletList`/`orderedList`/
`listItem`, `horizontalRule`, `hardBreak`) — not aliases of the `render()`
node types; the two paths take genuinely different input shapes for nodes
that share a type name (`paragraph`, `heading`, `blockquote`).

**Blockquote — two independent implementations (2026-08-20).**
`_renderBlockquote()` (the `render()` path) treats blockquote as a flat node
carrying inline `content`/`text`, wrapping it in `<blockquote><p>…</p>
</blockquote>` (CKEditor's Block Quote feature expects a paragraph inside).
`_renderDocBlockquote()` (the `renderDocument()` path) treats it as the
nested ProseMirror shape — block children, not inline text — and wraps
`<blockquote>` around each child rendered through the block-node recursion,
so a list inside a quote keeps its own `<ul>`/`<li>` markup. Both return
`''` for an empty blockquote — check which shape your caller actually has
before reusing either.

**`extractHeading(?array $doc, int $level = 1)`** — pulls the first heading
of a given level out of a raw ProseMirror doc, returning its plain text
(marks stripped) plus the doc with that node removed. Used by collection-
child routing (`content_types[...]['headingField']`) to lift an H1 into a
dedicated title-ish field so it isn't rendered twice in the body. No match ⇒
`text: null`, doc returned unchanged.

`renderInlineContent()` is public so MatrixBuilder/ImportService can render
a heading's inline marks directly without a full node array — used by hero
heading/subheading rendering.

---

## Text blocks — the column split

The Craft Starter's `text` entry type carries the **first** column's rich
text on the outer entry itself (`richText`); the `textBlocks` inner Matrix
holds at most one *further* column. `text_columns` mode
(`MatrixBuilder::_buildBlock()`'s `text_columns` branch) implements this:

- `columns: 'singleColumn'` → everything goes to the outer `richText`; inner
  Matrix is empty.
- `columns: 'twoColumns'`, a heading found in the nodes → nodes up to and
  including the first heading go to outer `richText`; the remainder becomes
  one inner `textBlock`.
- `columns: 'twoColumns'`, no heading found → same as singleColumn (nothing
  to split on).

**The pre-split-layout fallback.** Lifting the first column onto the outer
entry only happens when `_entryTypeHasField()` (memoized per entry-type +
field-handle pair) confirms the outer entry type actually has the
`firstColumnField` (`richText`) the mapping names. A Starter fork that
predates this split has no such field — writing to an unrecognised handle
inside nested Matrix save data is **silently swallowed by Craft**
(`Matrix::_createEntriesFromSerializedData()` catches
`InvalidFieldException`), so without the probe the column would vanish with
no error. When the probe comes back negative, the importer falls back to the
pre-split shape (every column as an inner `textBlock`) and raises one page
warning naming the entry type and missing field — the same guard pattern
the hero `heroStyle` check uses. Drop `firstColumnField` entirely in a
`blockOverrides` entry to opt a project back into the pre-split behaviour
with no warning at all.

**Existing content still has the old shape until migrated.**
`m260821_113000_lift_text_block_rich_text` is a **content migration that
lives in `craft-starter`**, not this plugin's repo — it moves a page's
first inner block's Rich Text (and any CKEditor chips it owns) up onto the
outer block, then soft-deletes the now-redundant inner block. A target
project that hasn't run it still has the pre-split shape in its database
even after this plugin starts writing the new shape on the next sync.

---

## Text & Media grouping

`_groupConsecutiveBlocks()` merges consecutive `text_and_media` blocks in
the JSON into one `textAndMedia` outer entry with one `textAndMediaBlock`
inner entry per source block. A non-`text_and_media` block breaks the run
and starts a new group (or a plain block). The outer entry's `blockLayout`
(left/right position) is set once, from the **first** block in the group
only — a group with alternating layouts in ContentiQ still gets a single
position on the Craft side; the template alternates image sides visually,
not the data.

**Media type is per inner block**, resolved by the `textMediaMedia` handler
(receives the whole inner block via `'_block'`) from that block's own
`layout` value:
- `image_left` / `image_right` → `mediaType: image`; image → the `image`
  field. Left/right position comes from the outer `blockLayout`, not this
  handler.
- `background` → `mediaType: backgroundImage`; the block's `image` becomes
  `desktopBackgroundImage`, and the optional `mobile_image` becomes
  `mobileBackgroundImage` — **falling back to the desktop image when no
  mobile image was supplied**, so mobile never drops to the template's
  global placeholder. This fallback is specific to text-and-media's
  background media; it does not apply to the hero block (see "Hero" below).

**CTA buttons are lifted, not rendered inline.** The `mediaNodes` handler
(used for this block's `richText`) filters `ctaButton` nodes out of the
rendered HTML and emits them as `actionButtons` Matrix entries instead of
raw `<a>` tags — the same treatment `faqNodes`/`buttonNodes` give FAQ and
price-list buttons.

---

## Cards block

Cards import directly to `entryCards` (inner type `card`). ContentiQ's
`fields.mode` selects one of three paths, all resolved in
`MatrixBuilder::_buildBlock()`'s cards special-case and
`_buildCardsByMode()`:

- **`detected`** — inline card items in the payload; imported as ordinary
  `repeated`-mode manual cards (`cardsInThisBlock: manual`). No deferral.
- **`pages`** — the block references specific pages by slug.
- **`children`** — the block references a parent page whose children should
  render as cards.

**`pages`/`children` defer to pass 2.** These two modes carry no card items
in the block itself — only refs (slugs/ids). MatrixBuilder records a
deferred ref set per block (`result['cardRefs'][$blockIndex]`, in memory
only) and returns without building an inner Matrix at all.
`ImportService::resolveCardReferences(array $allCardRefs, array
$slugToEntryId, ...)` runs this pass **after** the whole run's slug → entry
ID map is complete, from every entry point (SyncJob, CLI batch/single
import, CP upload, sidebar widget sync) — a referenced page's Craft entry
may not exist yet (or may be created later in the same batch) until the
whole run has processed. Resolved blocks are located by `blockIndex` in the
owner's saved `contentBlocks` and **saved directly as elements** — the owner
page entry is never re-saved for this.

**Single-ref `pages` mode (D6 caveat).** A `pages`-mode block with exactly
one card becomes a manual card row (`entry: [$id], useEntryCardDetails:
true`) instead of automatic mode — automatic mode with a single entry
triggers Craft's own auto-expansion-to-children behaviour, which is not what
a genuine single-page card selection means here.

**`children` mode, parent == host page** → `useChildPages: true`, resolved
entirely in pass 1 (a live query, nothing to defer). **Arbitrary parent** →
deferred; pass 2 sets `entries: [$parentId]` and the template expands to its
children.

**Children-mode fallback when the parent never resolves** (parent page not
yet exported/in Craft). Rather than leave the block empty,
`MatrixBuilder::buildChildrenFallbackCards()` parses the block's own
retained `intro` nodes back into manual cards, using the same pattern
ContentiQ's own detected-mode serialisers use: the card heading level is the
most prominent heading level ≥2 that appears 2+ times. Nodes before the
first such heading become the true intro (rewritten into `richText` so card
content isn't duplicated as prose above the grid); each heading afterwards
starts one card (body = following paragraphs/lists, CTA button if present —
last one wins). No repeating pattern ⇒ no fallback, block stays empty. The
fallback self-heals: once the parent is exported and resolvable, the next
sync rebuilds the block in automatic mode and replaces these rows.

---

## Hero

Hero is not a `contentBlocks` entry — it writes to a dedicated field on the
page/homepage entry, built by `ImportService::_buildHeroField()` /
`_buildHeroInnerFields()`.

**Two live shapes, probed rather than assumed.** `_detectHeroShape()`
inspects the *destination* entry type's field layout: a field handled
`'hero'` that's a `craft\fields\ContentBlock` instance ⇒ ContentBlock shape
(pages/homepage in the Starter — inner fields nested under `hero.fields`,
handle `heroContent`); otherwise a `heroTitle` handle present anywhere on
the layout ⇒ flat shape (article/caseStudy/team — the same fields sit
directly on the entry as `heroTitle`/`heroRichText`/`heroDesktopImage`/etc,
no wrapper). Neither found falls back to ContentBlock shape. The inner field
*values* are built once, shape-agnostic, by `_buildHeroInnerFields()`; only
the handles (and the wrapper) differ.

**The `heroStyle` field-layout probe is load-bearing, not defensive.** The
`hero` ContentBlock field has its own nested field layout, separate from the
page entry type's layout. Older Starter-based sites predate the `heroStyle`
Dropdown and don't have it there. Setting an unrecognised handle inside a
ContentBlock's nested fields throws `yii\base\UnknownPropertyException` —
and unlike the top-level Matrix save path, Craft's own
`ContentBlock::_createContentBlockFromSerializedData()` does **not** catch
that exception, so an unguarded write would hard-fail the whole save.
`_buildHeroInnerFields()` only adds `heroStyle` when the field's own layout
is unknown (null — assume present) or genuinely has the field; otherwise
skipped silently.

`heroStyle` is whitelist-validated (`textImage`/`textOnly`) and, when
eligible, **always set explicitly** whenever the hero block carries any
other content — an explicit default beats relying on Craft's own field
default, consistent with the whole-page-replace sync model everywhere else
in this plugin. A genuinely empty hero block skips it too, so
`_buildHeroField()`'s "nothing to write" check still treats it as untouched.

**Mobile image has no desktop fallback here.** `mobileImage` is set only
when `fields.mobile_image` is present with a URL; if absent, the key simply
isn't written. This differs from text-and-media's `backgroundImage`
mediaType, which does fall back to the desktop image when no mobile image
is supplied — separate code paths, not one rule applied twice.

---

## DIFF-AWARE Matrix writes (`preserveBlockIdentity`)

Off by default (`config/contentiq.php`'s `'preserveBlockIdentity' => true`
opts a project in). Every prior sync recreated the entire `contentBlocks`
Matrix with `'new*'` keys on every save — cheap to reason about, but it
means Craft deletes and recreates every nested block element each time,
which cascades to destroy any editor **provisional draft** attached to an
unchanged block. This flag lets MatrixBuilder reuse an unchanged top-level
block's existing nested element id instead.

**Scope — top-level, non-grouped blocks only.** `_resolveTopLevelKey()`
emits the mapped element id (an int, from `contentiq_block_syncs`) as the
Matrix key only when the block has a stable payload `id` *and* a live
mapping row *and* that mapped id isn't already claimed by another block this
run (a bad map value or a same-run collision falls back to `'new{N}'` with a
warning — a wrong-id reuse is corruption, a missed-optimisation recreate is
merely safe). Inner/nested blocks always use `'new*'` — only the outer
element's identity is preserved. **Grouped blocks (text_and_media) always
recreate** — the grouping shape (which source blocks merged together) can
change between syncs, making group-level identity ambiguous; a wrong reuse
would corrupt an unrelated block, so a full recreate is the safe choice.

The mapping is loaded (`_loadBlockSyncMap`) before `MatrixBuilder::build()`
runs and recorded (`_recordBlockSyncMap`) after a successful save, by
zipping `build()`'s returned `blockKeyConsumption` (emitted key → payload
block id(s), in emission order) against the owner's saved top-level blocks
in the same order — Craft preserves Matrix key emission order. Recording is
bookkeeping only, wrapped in its own try/catch; a failure there cannot fail
the page.

**Why it defaults off**: this rewrites the core Matrix save path and cannot
be integration-tested outside a live Craft instance (no Craft runtime/test
harness here, only standalone pure-PHP scripts). Documented residual risk:
reuse does not verify the mapped element is still the same Craft entry
*type* the block would produce today (e.g. a block's type changed at the
same payload position between syncs) — no cheap way to check a live
element's type without an extra query per top-level block.

**Live-validation checklist — run on a real Craft instance before flipping
`preserveBlockIdentity` on for a project**, since none of this is
integration-tested:

- Unchanged block on re-sync keeps its element id **and** any editor
  provisional draft attached to it.
- Editing a block's content updates the same nested entry in place (no new
  row in `elements`/`entries`).
- Adding a new block gets a `'new*'` key this sync, and a real id recorded
  for the next one.
- Removing a block deletes it, and its `contentiq_block_syncs` row is
  pruned.
- A grouped `text_and_media` run round-trips correctly (still recreates
  every sync — confirm that's acceptable, not a regression).
- Reordering blocks in the payload re-syncs correctly (position isn't part
  of identity — only `id` is).
- Changing a block's type at the same payload position doesn't corrupt the
  previously-mapped element (the residual risk above) — confirm Craft
  either rejects the mismatched save cleanly, or that this scenario doesn't
  occur in practice for the project's content.

---

## CTA entry creation

Call-to-action blocks pass through MatrixBuilder as bare placeholders (see
"MatrixBuilder" above); `ImportService::_resolveCtaBlocks()` routes each one
by its ContentiQ `fields.source` label before either path below runs — see
"Source routing" below. `_resolveCtaEntry()` does the per-page work, creating/
updating a `callToActionEntry` in the `callsToAction` channel section and
patching its id into `chooseCallToAction` on the placeholder.

**Identity resolution has two tiers, not just title matching.** When both
`document.id` (the page) and the CTA block's own `id` are present — a
"stable identity" — resolution looks up `contentiq_cta_syncs` by
`(page_id, block_id)` first; a live mapped element wins outright. Only when
there's no stable identity (older exports predating block ids, or no
mapping row/a stale row whose element vanished) does it fall back to the
legacy title-only lookup — a match there is adopted and recorded into
`contentiq_cta_syncs` so the *next* sync resolves it by id instead.
`$claimedElementIds` (one set per page import) stops two stable-identity
blocks sharing a title (e.g. two blank "Call to Action" blocks) from both
adopting the first one's entry. Title/richText/buttons are extracted the
same way regardless of identity tier: title from the first heading node
(default `'Call to Action'`), richText from the non-button nodes, buttons
from `ctaButton` nodes in `fields.nodes` (falling back to a flat
`fields.buttons` array for legacy payloads). The title/content-mapping logic
itself (`_extractCtaTitle()`/`_buildCtaContentValues()`) is shared with the
global path below rather than duplicated.

### Source routing (`fields.source`: `'page'` | `'global'`)

Every ContentiQ `call_to_action` block carries `fields.source`, which an
absent key defaults to `'global'` — ContentiQ's own default
(`app/Support/ProseMirrorBlockBuilder.php::serialiseCta()`), mirrored
verbatim by the pure `ImportService::_ctaSource()` classifier rather than
re-decided. `_resolveCtaBlocks()` (shared by `importPage()` and
`_buildBlockFieldValues()` — see [import-pipeline.md](import-pipeline.md), so
every entry point routes identically) walks the built `matrixData` and splits
on this label:

- **`'page'`** — exactly the pre-existing behaviour described above: an
  inline `callToAction` Matrix block relating to a per-page entry, tracked in
  `contentiq_cta_syncs`.
- **`'global'`** — no inline Matrix block at all. The placeholder (and its
  `blockKeyConsumption` entry, so `preserveBlockIdentity`'s
  `_recordBlockSyncMap()` zip stays aligned — see its docblock) is dropped
  from `matrixData` before the page saves. `_resolveGlobalCtaEntry()` writes
  the single SHARED `callToActionEntry` and relates it on the `globalContent`
  global set's `globalChooseCallToAction` field (handles:
  `config['globalContentSet']`/`config['globalChooseCtaField']`) — gated on
  globals consent, see [globals.md](globals.md). Identity is **not**
  `contentiq_cta_syncs` (that table means per-page ownership) —
  `globalChooseCallToAction`'s current relation is read fresh every call: a
  live related entry is updated in place (title included, unlike the page
  path's update branch, which never renames an already-matched entry); no
  relation creates a new entry (same construction as the page path) and
  relates it. Two `'global'`-labelled CTA blocks in one run therefore resolve
  to **one** entry, last-processed wins.

**The `footerCallToAction.showGlobalCallToAction` lightswitch is a single
per-page decision** (handles: `config['footerCtaField']`/
`config['footerCtaShowGlobalField']`, default
`footerCallToAction`/`showGlobalCallToAction`), made once `_resolveCtaBlocks()`
has classified every CTA block on the page — it's an aggregate over the whole
page, not a property of any one block, and follows this table:

| CTA blocks on the page this run                                | Lightswitch |
|------------------------------------------------------------------|-------------|
| ≥1 `'global'`-source (any `'page'`-source blocks alongside don't change it) | **ON** (`true`) — global wins when both are present |
| 0 `'global'`-source, ≥1 `'page'`-source                          | **OFF** (`false`) — the page supplies its own CTA, so the shared footer one is disabled |
| none at all                                                       | **untouched** — absent payload data never deletes |

`_buildFooterGlobalCtaField()` writes the resolved value — read-modify-write
is free (Craft's own `ContentBlock::_createContentBlockFromSerializedData()`
fetches the entry's existing nested content-block and only overwrites handles
present in the `'fields'` array, so `callToActionLayout` and every other
sibling field is left untouched — only `showGlobalCallToAction` is ever
included). Defensively probes both the outer field and its own nested field
layout before writing (mirrors `_buildHeroInnerFields()`'s `heroStyle` guard
— an unrecognised handle inside a `ContentBlock`'s nested `'fields'` array
throws `yii\base\UnknownPropertyException`, which Craft's save path does
**not** catch): either layer missing skips the write, never a crash — but the
warning is asymmetric. Turning the switch **ON** and finding no field to turn
on is worth a per-page warning (content that should route to the footer
silently can't). Turning it **OFF** and finding no field is **silent** —
there's nothing to disable, and collection children (case studies/team)
routinely carry `'page'`-source CTA blocks with no `footerCallToAction` field
at all; warning on every one of them would be spam, not signal.

A `routingNotes` line records the routing on the page's result row either way:
`'Call to Action → global footer CTA'` once per `'global'`-source block
resolved (inside the loop, alongside the entry write), `'Page CTA → global
footer CTA disabled'` once when the aggregate decision lands OFF (after the
loop, since it's a page-level decision). Both notes are `routingNotes`-only,
deliberately **not** `blockNotes` and **not** `warnings`. Never `blockNotes`:
that key holds only ContentIQ's own payload-authored `block.notes` text, and
`SyncJob`'s post-import auto-lock step / `CpController::actionWidgetSync()`
persist it verbatim into `contentiq_entry_syncs.notes` — the "ContentIQ
Notes" field the entry sidebar widget displays — which must never contain
plugin-generated text. `routingNotes` is a separate, same-shape (`"\n\n"`-joined
string) result key that nothing persists — it exists only in the run's result
JSON. Never `warnings` either — routing is expected behaviour, not an
actionable problem, and `warnings` drives run/page status (any non-empty
`warnings` flips the run to `warnings`); since absent `fields.source` defaults
to `'global'`, pushing the ON note into `warnings` too would stamp nearly
every legacy sync as warning-laden. `result.twig` and `sync-result.twig` both
render `routingNotes` (alongside `blockNotes`) as a neutral info line on each
page's row (muted styling, separate from `.warning`), so the routing is still
visible on every screen without ever reaching the persisted notes column.

---

## SEOmatic and Hyper — field shapes worth knowing

**SEOmatic.** A single `SeoSettings` field (handle from `config['seoField']`,
default `'seo'`) — not one Craft field per meta tag.
`ImportService::_resolveSeoFields()` writes plain strings into
`metaGlobalVars` (`seoTitle`, `seoDescription`, `ogTitle`, `ogDescription`,
`canonicalUrl`) and source flags/asset ids into `metaBundleSettings`. SEO is
only built and merged when the payload carries a `seo` key — absent means
"leave whatever the editor already set alone," never blanked. `og_image` is
imported once and registered into **both** `seoImageIds` and `ogImageIds` —
ContentiQ exports one OG image, SEOmatic wants it in two slots.

**Hyper link fields.** Every action button (hero, FAQ, price list,
text-and-media, cards, CTA) writes the same Verbb Hyper shape (`type:
'verbb\hyper\links\Url'`, `handle: 'default-verbb-hyper-links-url'`,
`linkValue`, `linkText`, `linkClass: 'btn btn-primary'`), funnelled through
`LinkHelper::hyperInertUrl()` (`src/helpers/LinkHelper.php`). It collapses
every "no real destination yet" marker — `null`, `''`, `'#'`, a bare
`'https://'`/`'http://'` scheme, or anything `UrlSafety::safeHref()` would
itself neuter (disallowed schemes like `javascript:`) — to a single
`'https://'` placeholder Hyper accepts, rather than rejecting `'#'` outright
or carrying a stored-XSS payload through.

---

## Setting field values — data shapes and exploring a target project

**Matrix field-value data shapes.** Every write in this plugin goes through
Craft's own nested Matrix save format (`setFieldValue($handle, [...])` then
`saveElement($entry, false)`) — the same rules apply everywhere a mapping
produces a value: a key must be `'new{N}'` or an int (see "Matrix key
format" above); an Assets field value is `[int]` — never a bare int or
string, and `[]` clears the field; a CKEditor field value is an HTML
string; there's no nesting depth limit, since Craft's own
`afterElementPropagate` chain is recursive.

**Common field types and the shape `setFieldValue()` expects:**

| Field type | Craft class | Data shape |
|---|---|---|
| CKEditor | `craft\ckeditor\Field` | HTML string |
| Assets | `craft\fields\Assets` | `[int]` array of asset IDs |
| Entries | `craft\fields\Entries` | `[int]` array of entry IDs |
| Matrix | `craft\fields\Matrix` | `['new1' => ['type' => '...', 'fields' => [...]]]` |
| Dropdown | `craft\fields\Dropdown` | string matching one of `options[].value` |
| Lightswitch | `craft\fields\Lightswitch` | `true` / `false` |
| Hyper | `verbb\hyper\fields\HyperField` | array of link objects (see above) |
| ColourSwatches | (colour swatches plugin) | left at its default — this importer never sets colours |

**Exploring a target Craft project's field layout.** Adding or debugging a
block mapping means finding out what Craft field a given handle actually
is in the *target* project — its `config/project/` YAML is the source of
truth, not this plugin's own code:

- `config/project/fields/{handle}--{uid}.yaml` — field definitions
  (`type:` is the Craft field class; `settings.options[]` for a Dropdown's
  values).
- `config/project/entryTypes/{handle}--{uid}.yaml` — an entry type's field
  layout lives under `fieldLayouts.{uid}.tabs[].elements[]`; each
  `CustomField` element's `fieldUid` cross-references a file under
  `fields/`, and its own `handle`/`label` (if not `null`) overrides the
  field's native handle/label for that entry type only — the same field can
  appear under different handles on different entry types.
- `config/project/sections/{handle}--{uid}.yaml` — lists the entry type
  UIDs a section allows.
- A Matrix field's own YAML lists its allowed entry types under
  `settings.entryTypes[]`.

Practical order: start from the Twig template
(`templates/_content-blocks/{blockType}.twig`) to see what handles the
front end actually expects, find the entry type YAML, follow `fieldUid`
into `fields/` for each handle's type, and — if an entry of that type
already exists — query `elements_sites.content` directly to confirm the
stored shape matches what you expect (Matrix data lives in the *owned*
elements, not the owner's own `content` column; join through
`elements_owners`).

---

## Related docs

- [README.md](README.md) — plugin overview and where this fits.
- [integration.md](integration.md) — how a ContentiQ export reaches this
  plugin (API sync, CLI import, CP upload) before block mapping runs.
- [import-pipeline.md](import-pipeline.md) — the page-level pipeline this
  doc's builders are called from: entry resolution, collection children,
  the empty-matrix guard, transactions.
- [globals.md](globals.md) — the separate, non-per-page globals import
  (offices, company info) this doc's block mapping has no part in — except
  the `'global'`-source CTA path above, which shares its consent gate.
- [assets.md](assets.md) — `ImageImportService`, consumed by every `image`/
  `images` handler in this doc.
- [cp-and-widget.md](cp-and-widget.md) — the sidebar sync widget and CP
  screens that trigger the pipeline this doc describes.
