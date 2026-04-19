# Copydeck Craft Importer — Settled Patterns

Read this at the start of every session. Do not re-discover these patterns.

---

## Do not use drafts

The importer saves **directly to the canonical entry** — no draft creation. Early versions used `getDrafts()->createDraft()`, which caused images to appear in the DB but be invisible in the CP (the CP shows the canonical, not the draft). This wasted days of debugging.

- Existing entries: `$existing->setFieldValues($values)` then `saveElement($existing, false)`
- New entries: `new Entry()`, set fields, `saveElement($entry, false)` — no `DraftBehavior`

---

## CLI webroot requirement

`ImportController` must call `chdir(Craft::getAlias('@webroot'))` before any asset operations. Without this, local filesystem volume paths (e.g. `assets/cms/images`) don't resolve in CLI context — they're relative to `web/`, not the project root.

---

## Saving nested Matrix data

### Data shape

```php
$entry->setFieldValue('contentBlocks', [
    'new1' => [
        'type'   => 'textAndMedia',
        'fields' => [
            'blockLayout' => 'text-left',
            'textAndMediaBlocks' => [
                'new1' => [
                    'type'   => 'textAndMediaBlock',
                    'fields' => [
                        'richText' => '<p>HTML string</p>',
                        'image'    => [144],
                    ],
                ],
            ],
        ],
    ],
]);
Craft::$app->getElements()->saveElement($entry, false);
```

### Rules

- **Keys** must start with `'new'` (`'new1'`, `'new2'`). Integer keys are treated as existing entry IDs.
- **Assets fields**: `[int]` array. Not bare int, not string, not nested array. `[]` clears the field.
- **CKEditor fields**: HTML string. Wrap plain text in `<p>` tags.
- **Save with `false`**: skip validation to bypass MatrixBlockAnchorField uniqueness failures.
- No nesting depth limit — Craft's `afterElementPropagate` chain is recursive.

---

## SEOmatic SeoSettings field

Single field (handle: `seo`, type: `SeoSettings`). Not individual handles per meta tag.

```php
$entry->setFieldValue('seo', [
    'metaGlobalVars' => [
        'seoTitle'       => 'Page title',
        'seoDescription' => 'Description',
        'ogTitle'        => 'OG title',
        'ogDescription'  => 'OG description',
        'canonicalUrl'   => 'https://...',
    ],
    'metaBundleSettings' => [
        'seoTitleSource'       => 'fromCustom',
        'seoDescriptionSource' => 'fromCustom',
        'seoImageSource'       => 'fromAsset',
        'seoImageIds'          => [144],
        'ogImageSource'        => 'fromAsset',
        'ogImageIds'           => [144],
    ],
]);
```

Empty strings are valid. Omit `seoImageIds`/`ogImageIds` keys entirely if no image.

---

## Hyper link fields (Verbb)

`actionButton` is a Hyper field (`verbb\hyper\fields\HyperField`). Set via serialized array — `normalizeValue()` accepts the raw format directly.

```php
$entry->setFieldValue('actionButton', [
    [
        'type'      => 'verbb\\hyper\\links\\Url',
        'handle'    => 'default-verbb-hyper-links-url',
        'linkValue' => 'https://example.com',
        'linkText'  => 'Button Label',
    ],
]);
```

Link types and handles (from `actionButton` field config):
- `verbb\hyper\links\Url` → handle `default-verbb-hyper-links-url`
- `verbb\hyper\links\Entry` → handle `default-verbb-hyper-links-entry`
- `verbb\hyper\links\Email` → handle `default-verbb-hyper-links-email`
- `verbb\hyper\links\Asset` → handle `default-verbb-hyper-links-asset`
- `verbb\hyper\links\Custom` → handle varies (`7NRQUzTeW9` for Call Now, `aBaytLJksn` for In-Page Link)

Base properties on `Link`: `linkValue`, `linkText`, `ariaLabel`, `urlSuffix`, `linkTitle`, `classes`, `customAttributes`, `newWindow`, `fields`.

---

## Entries relation fields

`chooseCallToAction` is `craft\fields\Entries`. Set as `[$entryId]` — same format as Assets.

```php
// On the outer callToAction contentBlock:
'fields' => ['chooseCallToAction' => [$ctaEntryId]]
```

The CTA workflow: import creates a `callToActionEntry` in the `callsToAction` section (channel), then relates it via the `chooseCallToAction` field on the `callToAction` contentBlock.

---

## Asset import

`ImageImportService::importFromField()` — idempotent by filename in target folder.

Critical requirements:
- `$asset->newLocation = "{folder:{$folderId}}{$filename}"` — SCENARIO_CREATE requires this
- `$asset->setScenario(Asset::SCENARIO_CREATE)` — must be set
- `$asset->tempFilePath` — downloaded file path
- Orphaned files (on disk but not in DB) are cleaned up before save

---

## Block type mappings (defaults.php)

### Standard blocks (MatrixBuilder handles these)

| Copydeck type | Outer entry type | Inner Matrix | Inner type | Mode | Outer fields |
|---|---|---|---|---|---|
| `text` | `text` | `textBlocks` | `textBlock` | single | — |
| `text_and_media` | `textAndMedia` | `textAndMediaBlocks` | `textAndMediaBlock` | grouped | `blockLayout` |
| `faq` | `faq` | `accordionItems` | `accordionItem` | repeated | `richText`, `extraRichText`, `actionButtons` (via `faqNodes`) |
| `cards` | `copydeckCards` | `copydeckCards` | `copydeckCard` | repeated | `richText` (intro) |
| `price_list` | `priceList` | *(none)* | — | outer fields only | `richText`, `priceList` |
| `usp` | `copydeckUsp` | *(none)* | — | outer fields only | `uspText` |
| `global` | `copydeckGlobal` | *(none)* | — | outer fields only | `copydeckNotes` |

### Special blocks (ImportService handles these)

| Copydeck type | What happens |
|---|---|
| `hero` | ContentBlock field `hero` on page entry: `heading`, `richText` (subheading + body), `desktopImage`, `actionButtons`. Sets `enableHero = true`. |
| `call_to_action` | Creates `callToActionEntry` in `callsToAction` section, relates via `chooseCallToAction` |
| `table` | Skipped (no block type in Craft Starter) |

### Modes

- **single**: one inner entry from the block's fields
- **repeated**: one inner entry per item in a `sourceKey` array
- **grouped**: consecutive blocks of the same type merge into one outer entry with multiple inner entries (e.g. text_and_media)

### Handler types

| Handler | Input | Output |
|---|---|---|
| `nodes` | `ContentNode[]` | HTML string via NodesRenderer |
| `image` | `{key, url, alt}` | asset ID array `[$id]` |
| `heading` | `{level, text}` or string | `<hN>text</hN>` |
| `body` | plain string | `<p>text</p>` (legacy — use `nodes` for structured content) |
| `layout` | string | pass through |
| `textMediaLayout` | `image_right`/`image_left` | `text-left`/`image-left` |
| `tableHtml` | `[{isHeader, cells}]` | `<table>` HTML string |
| `hyperButton` | `{label, url}` | Hyper link field array |
| `faqNodes` | `ContentNode[]` with `faq_items` | splits into `richText`, `extraRichText`, `actionButtons`, `_faqItems` |

### NodesRenderer supported node types

| Node type | Output |
|---|---|
| `heading` | `<h1>`–`<h6>` (clamped) |
| `paragraph` | `<p>` |
| `list` | `<ul>` or `<ol>` (based on `ordered` flag) |
| `ordered_list` | `<ol>` (legacy alias) |
| `unordered_list` | `<ul>` (legacy alias) |
| `faq_items` | nested `<ul><li>question<ul><li>answer</li></ul></li></ul>` |

---

## Text & Media grouping

Consecutive `text_and_media` blocks in the JSON are merged into a single `textAndMedia` outer entry with multiple `textAndMediaBlock` inner entries. The outer entry's `blockLayout` field is set from the first block's `layout` value. The CMS template handles alternating image positions automatically.

A non-`text_and_media` block (e.g. faq, price_list) breaks the consecutive run and starts a new group.

---

## Call to Action entry creation

The importer creates entries in the `callsToAction` section (channel):
- **Section**: `callsToAction` (handle)
- **Entry type**: `callToActionEntry` (handle)
- **Fields**: `title`, `richText` (CKEditor), `image` (Assets), `actionButtons` (Matrix → `actionButton` entries with Hyper `actionButton` field)
- **Idempotent**: matches by title to avoid duplicates across batch imports

The `actionButtons` field is a Matrix containing `actionButton` entry types. Each has one field: `actionButton` (Hyper). Buttons without a URL are skipped.

---

## Import command

```
php craft copydeck-importer/import --file=export.json [--dry-run] [--verbose]
```

Supports single-page (top-level `blocks`) and batch (top-level `pages`) JSON formats.

---

## Plugin settings (Craft 5 pattern)

Settings are stored via Craft's built-in plugin settings mechanism (project config).

```php
// Model: src/models/Settings.php
class Settings extends \craft\base\Model
{
    public string $copydeckUrl = '';
    public string $apiKey = '';
    public string $projectSlug = '';
}

// Plugin class:
public bool $hasCpSettings = true;

protected function createSettingsModel(): ?Model
{
    return new Settings();
}

protected function settingsHtml(): ?string
{
    return Craft::$app->view->renderTemplate(
        'copydeck-importer/_cp/settings',
        ['settings' => $this->getSettings()],
    );
}

// Access anywhere:
$settings = CopydeckImporter::$plugin->getSettings();
```

Template uses `{% import '_includes/forms' as forms %}` with standard Craft form macros. The `settings` namespace is handled automatically by Craft's settings response.

---

## Hierarchy / parent entries (Structure sections)

For entries in Structure sections, set the parent after the entry is saved:

```php
// By parent entry ID:
$entry->setParentId($parentId);
Craft::$app->getElements()->saveElement($entry, false);

// Or by parent object (auto-sets level):
$entry->setParent($parentEntry);
Craft::$app->getElements()->saveElement($entry, false);
```

The batch importer maintains a `$slugToEntryId` map during the run so parent lookups don't require DB queries. Pages must be sorted depth-first (parents before children) in the JSON.

If `parent_slug` is present in `document`, the importer sets the parent. If the parent slug isn't found in the map, a warning is logged and the entry is saved at root level.

---

## Copydeck API sync

The sync flow uses Craft's queue system to avoid HTTP timeouts:

1. Controller creates a `pending` import run record
2. Pushes `SyncJob` to Craft's queue with the run ID
3. Frontend polls `sync/status?runId=N` until status changes from `pending`
4. Queue job: fetches export via `CopydeckApiService`, imports each page, updates the run record

API call uses `Craft::createGuzzleClient()` — the recommended HTTP client in Craft 5.

```php
$response = Craft::createGuzzleClient()->request('GET', $endpoint, [
    RequestOptions::HEADERS => [
        'Authorization' => "Bearer {$apiKey}",
        'Accept'        => 'application/json',
    ],
    RequestOptions::TIMEOUT => 120,
]);
```

---

## Copydeck Cards staging block

Cards from Copydeck import to `copydeckCards` (not `contentCards`). This is a staging block — editors manually migrate cards to the appropriate final block type with proper entry links. Copydeck can't know which card type to use or what internal links to set.

Card body fields are `ContentNode[]` arrays (not plain strings), processed through `NodesRenderer`. This supports paragraphs, lists, and embedded FAQ items in card content.

The `intro` field on the outer `copydeckCards` entry is also a `ContentNode[]` array, rendered to the `richText` CKEditor field above the card grid.

---

## Homepage import

Pages with `"is_homepage": true` in the document object import into the `homepage` Single section instead of the `pages` Structure. The importer:

- Looks up the Single entry by section (not slug — Singles always have exactly one)
- Does not overwrite the title
- Skips structure positioning (Singles don't have parents)
- Uses the same `hero` ContentBlock field as pages

---

## Hero ContentBlock field

Both pages and homepage use a `craft\fields\ContentBlock` field (`heroContent`, handle override `hero`) for hero data. The importer builds the ContentBlock value as:

```php
[
    'enableHero' => true,
    'hero' => [
        'fields' => [
            'heading'       => '<h1>Title</h1>',
            'richText'      => '<h2>Subheading</h2><p>Body text</p>',
            'desktopImage'  => [$assetId],
            'actionButtons' => [
                'new1' => ['type' => 'actionButton', 'fields' => ['actionButton' => [hyperData]]],
            ],
        ],
    ],
]
```

The `subheading` field (optional `{level, text}` object) is rendered as an `<hN>` tag prepended to the body text in `richText`.

---

## Slug mapping

When the Craft slug differs from the Copydeck slug (e.g. homepage → home), configure a mapping in `config/copydeck.php`:

```php
'slugMap' => [
    'homepage' => 'home',
],
```

Used by the sidebar widget sync to translate Craft slugs to Copydeck API slugs.

---

## Asset filename sanitization

Filenames from Copydeck keys are sanitized via `craft\helpers\Assets::prepareAssetName()` before the idempotency lookup. Craft converts spaces to hyphens on save (e.g. `Styles - Luxury - Card Image.jpg` → `Styles-Luxury-Card-Image.jpg`), so the lookup must use the sanitized name to find existing assets.

---

## Image downloads use Guzzle

Image downloads use `Craft::createGuzzleClient()` instead of `file_get_contents()`. This handles self-signed SSL certificates on dev domains (e.g. `copydeck.test`) and respects `config/guzzle.php` settings.

---

## Field layout filtering

Always filter field values against the entry's field layout before `setFieldValues()`. Unknown handles throw `"Setting unknown property: CustomFieldBehavior::handleName"`.

```php
$validHandles = array_map(fn($f) => $f->handle, $fieldLayout->getCustomFields());
$filtered = array_intersect_key($fieldValues, array_flip($validHandles));
```

---

## Exploring Craft project config YAML

All field definitions, entry types, sections, and field layouts live in `config/project/`. Understanding how to navigate these files is essential for mapping Copydeck blocks to Craft fields.

### File naming convention

```
config/project/
  fields/              # Field definitions: {handle}--{uid}.yaml
  entryTypes/          # Entry type definitions + field layouts: {handle}--{uid}.yaml
  sections/            # Section definitions: {handle}--{uid}.yaml
```

The UID in the filename is the canonical identifier — it's stable across environments. Handles can change.

### Finding a field's type and options

```bash
# Find a field by handle
ls config/project/fields/blockLayout--*.yaml

# Read it — key properties:
#   handle: blockLayout
#   type: craft\fields\Dropdown          ← Craft field class
#   settings.options[].value             ← dropdown option values
#   settings.options[].label             ← what editors see
```

### Finding what fields are on an entry type

Entry type YAMLs contain the full field layout under `fieldLayouts.{uid}.tabs[].elements[]`. Each element has:

```yaml
elements:
  - type: craft\fieldlayoutelements\CustomField
    fieldUid: 05e398c0-9d22-47a9-bccc-cef3688bd6e6  # ← look this up in fields/
    handle: blockLayout       # handle override (null = use field's own handle)
    label: 'Block Layout'     # label override
    required: false
```

**The `fieldUid` is the link.** Cross-reference it with `config/project/fields/` to find the field definition:

```bash
# Find which field a UID belongs to
grep -l "05e398c0" config/project/fields/*.yaml
# → fields/textAndMediaBlockLayout--05e398c0-9d22-47a9-bccc-cef3688bd6e6.yaml
```

**Handle override**: if `handle: null` on the field layout element, the field's own handle from its YAML definition is used. If `handle: blockLayout`, that overrides the field's native handle for this entry type. The same field can appear on multiple entry types with different handles.

### Finding what entry types a Matrix field allows

Matrix fields list their allowed entry types in `settings.entryTypes`:

```yaml
# fields/contentBlocks--f3e37f1f-....yaml
settings:
  entryTypes:
    - uid: b31e80bd-...  # Call to Action
    - uid: 2144c2be-...  # Price List
    - uid: ...
```

Cross-reference with `config/project/entryTypes/` to find the entry type definition.

### Finding what section an entry type belongs to

Sections list their entry types:

```yaml
# sections/callsToAction--0f3b9437-....yaml
type: channel
entryTypes:
  - e93e931e-...  # callToActionEntry
```

### Practical workflow for mapping a new block type

1. **Start with the Twig template** — `templates/_content-blocks/{blockType}.twig` shows what field handles the template expects
2. **Find the entry type** — `ls config/project/entryTypes/{blockType}--*.yaml`
3. **Read the field layout** — look at `fieldLayouts.*.tabs[].elements[]` for `fieldUid` references
4. **Look up each field** — `grep -l "{fieldUid}" config/project/fields/*.yaml`
5. **Check the field type** — `type:` in the field YAML tells you what data shape it expects
6. **Verify with DB** — if an entry of this type already exists, query its `elements_sites.content` to see the actual stored format

### Common field types and their data shapes

| Field type | YAML `type:` | Data shape for `setFieldValue` |
|---|---|---|
| CKEditor | `craft\ckeditor\Field` | HTML string |
| Assets | `craft\fields\Assets` | `[int]` array of asset IDs |
| Entries | `craft\fields\Entries` | `[int]` array of entry IDs |
| Matrix | `craft\fields\Matrix` | `['new1' => ['type' => '...', 'fields' => [...]]]` |
| Dropdown | `craft\fields\Dropdown` | string matching `options[].value` |
| Lightswitch | `craft\fields\Lightswitch` | `true` / `false` |
| Hyper | `verbb\hyper\fields\HyperField` | array of link objects (see Hyper section) |
| ColourSwatches | colour swatches plugin | leave as default — importer doesn't set colours |

---

## Adding sidebar content to the entry edit screen

**The correct mechanism is `Entry::EVENT_DEFINE_SIDEBAR_HTML`.**

This is NOT the field layout designer / `BaseUiElement` approach. `BaseUiElement` + `EVENT_DEFINE_UI_ELEMENTS` only adds elements to the field layout designer palette for the main content area — it does not add sidebar content.

`EVENT_DEFINE_SIDEBAR_HTML` is defined in `craft\base\Element` (line 402):
```php
public const EVENT_DEFINE_SIDEBAR_HTML = 'defineSidebarHtml';
```

It fires from `Element::getSidebarHtml()` which is called by `ElementsController` when rendering the entry edit screen. The event fires on the **element instance** being edited (not on a static class), so listen via `Event::on(Entry::class, ...)`.

### Registration pattern

```php
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\base\Element;
use yii\base\Event;

Event::on(
    Entry::class,
    Element::EVENT_DEFINE_SIDEBAR_HTML,
    static function (DefineHtmlEvent $event) {
        /** @var Entry $entry */
        $entry = $event->sender;
        if ($entry === null || !$entry->id) {
            return; // skip new unsaved entries
        }
        // APPEND to $event->html — never replace it
        $event->html .= Craft::$app->view->renderTemplate(
            'my-plugin/_sidebar/widget',
            ['entry' => $entry],
        );
    },
);
```

Register this in `Plugin::init()`. Confirmed working pattern — used by SEOmatic (`nystudio107/craft-seomatic/src/seoelements/SeoEntry.php`).

### Sidebar HTML structure

Craft's native sidebar sections use `<fieldset>` + `<legend class="h6">` wrapping a `<div class="meta">` with `.field` rows inside. This matches what `Element::statusFieldHtml()` produces:

```html
<fieldset>
    <legend class="h6">COPYDECK</legend>
    <div class="meta">
        <div class="field">
            <div class="heading"><label>Status</label></div>
            <div class="input ltr"><button ...>Sync</button></div>
        </div>
        <div class="field">
            <div class="heading"><label>Synced at</label></div>
            <div class="input ltr"><span>Never</span></div>
        </div>
    </div>
</fieldset>
```

SEOmatic uses this same pattern in its sidebar Twig templates (e.g. `_sidebars/_includes/sidebar-preview.twig`).

### Inline JavaScript

Render via a Twig template. The view's `renderTemplate()` call returns HTML that Craft injects into the DOM; any `<script>` tags in the output execute after injection. Embed the element ID in widget DOM IDs to avoid collisions when multiple entries are open.

---

## DB is the source of truth

If the CP appears to show empty fields, query the DB:

```sql
SELECT es.content FROM elements_sites es
JOIN elements e ON es.elementId = e.id WHERE e.id = <entry_id>;
```

Matrix field data is NOT in the owner's content column — it's in owned elements. Query `elements_owners` to find inner entries:

```sql
SELECT e.id, et.handle, es.content FROM elements e
JOIN entries en ON e.id = en.id
JOIN entrytypes et ON en.typeId = et.id
JOIN elements_sites es ON e.id = es.elementId
JOIN elements_owners eo ON e.id = eo.elementId
WHERE eo.ownerId = <parent_id> AND e.dateDeleted IS NULL;
```
