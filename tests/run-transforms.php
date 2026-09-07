<?php

/**
 * Zero-dependency unit runner for the pure globals transforms.
 *
 * Requires the helper class directly (no Craft bootstrap), asserts with plain
 * PHP, and exits non-zero on the first failure.
 *
 *   php tests/run-transforms.php
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.3.0
 */

require __DIR__ . '/../src/helpers/GlobalsTransforms.php';

use matrixcreate\contentiqimporter\helpers\GlobalsTransforms;

$failures = 0;
$passes   = 0;

/**
 * Asserts two values are equal (loose structural compare via var_export).
 */
function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures, $passes;

    if (var_export($expected, true) === var_export($actual, true)) {
        $passes++;
        echo "  PASS  {$label}\n";
        return;
    }

    $failures++;
    echo "  FAIL  {$label}\n";
    echo "        expected: " . var_export($expected, true) . "\n";
    echo "        actual:   " . var_export($actual, true) . "\n";
}

// -----------------------------------------------------------------------------
// Opening hours — real lab shapes.
// -----------------------------------------------------------------------------
echo "Opening hours\n";

$labGroups = [
    ['days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'closed' => false, 'opens' => '09:00', 'closes' => '17:00'],
    ['days' => ['saturday'], 'closed' => false, 'opens' => '09:00', 'closes' => '22:00'],
    ['days' => ['sunday'], 'closed' => true, 'opens' => null, 'closes' => null],
];

$expected = [
    0 => ['open' => null, 'close' => null],       // Sunday closed
    1 => ['open' => '09:00', 'close' => '17:00'],  // Monday
    2 => ['open' => '09:00', 'close' => '17:00'],  // Tuesday
    3 => ['open' => '09:00', 'close' => '17:00'],  // Wednesday
    4 => ['open' => '09:00', 'close' => '17:00'],  // Thursday
    5 => ['open' => '09:00', 'close' => '17:00'],  // Friday
    6 => ['open' => '09:00', 'close' => '22:00'],  // Saturday
];
check('mon-fri + sat groups, sunday closed', $expected, GlobalsTransforms::openingHours($labGroups));

$allBlank = [
    0 => ['open' => null, 'close' => null],
    1 => ['open' => null, 'close' => null],
    2 => ['open' => null, 'close' => null],
    3 => ['open' => null, 'close' => null],
    4 => ['open' => null, 'close' => null],
    5 => ['open' => null, 'close' => null],
    6 => ['open' => null, 'close' => null],
];
check('empty array → all blank', $allBlank, GlobalsTransforms::openingHours([]));

// A day named in no group stays blank; only the named day is set.
$oneDay = GlobalsTransforms::openingHours([
    ['days' => ['wednesday'], 'closed' => false, 'opens' => '10:00', 'closes' => '16:00'],
]);
check('unnamed days stay blank (monday)', ['open' => null, 'close' => null], $oneDay[1]);
check('named day set (wednesday)', ['open' => '10:00', 'close' => '16:00'], $oneDay[3]);

// -----------------------------------------------------------------------------
// Country lookup.
// -----------------------------------------------------------------------------
echo "Country lookup\n";

$codeToName = [
    'GB' => 'United Kingdom',
    'US' => 'United States',
    'FR' => 'France',
    'IE' => 'Ireland',
];

check('United Kingdom → GB', 'GB', GlobalsTransforms::countryCode('United Kingdom', $codeToName));
check('UK → GB', 'GB', GlobalsTransforms::countryCode('UK', $codeToName));
check('USA → US', 'US', GlobalsTransforms::countryCode('USA', $codeToName));
check('US of A → US', 'US', GlobalsTransforms::countryCode('US of A', $codeToName));
check('france (lowercase name) → FR', 'FR', GlobalsTransforms::countryCode('france', $codeToName));
check('existing ISO GB passes through', 'GB', GlobalsTransforms::countryCode('gb', $codeToName));
check('garbage → null', null, GlobalsTransforms::countryCode('Wakanda', $codeToName));
check('empty → null', null, GlobalsTransforms::countryCode('', $codeToName));
check('null → null', null, GlobalsTransforms::countryCode(null, $codeToName));

// -----------------------------------------------------------------------------
// Address split.
// -----------------------------------------------------------------------------
echo "Address split\n";

check('single line → line 1 only', [
    'addressLine1' => '1 Main Street',
    'addressLine2' => '',
    'addressLine3' => '',
], GlobalsTransforms::splitAddress('1 Main Street'));

check('two lines', [
    'addressLine1' => '10 High Street',
    'addressLine2' => 'Parker Parish',
    'addressLine3' => '',
], GlobalsTransforms::splitAddress("10 High Street\nParker Parish"));

check('three lines', [
    'addressLine1' => 'A',
    'addressLine2' => 'B',
    'addressLine3' => 'C',
], GlobalsTransforms::splitAddress("A\nB\nC"));

check('four+ lines fold into line 3', [
    'addressLine1' => 'A',
    'addressLine2' => 'B',
    'addressLine3' => 'C, D',
], GlobalsTransforms::splitAddress("A\nB\nC\nD"));

check('empty string → all blank', [
    'addressLine1' => '',
    'addressLine2' => '',
    'addressLine3' => '',
], GlobalsTransforms::splitAddress(''));

check('null → all blank', [
    'addressLine1' => '',
    'addressLine2' => '',
    'addressLine3' => '',
], GlobalsTransforms::splitAddress(null));

// -----------------------------------------------------------------------------
// URL-prefix drift.
// -----------------------------------------------------------------------------
echo "URL-prefix drift\n";

check('blog vs the-blog drifts', true, GlobalsTransforms::urlPrefixDrifts('the-blog', 'blog/{slug}'));
check('team vs meet-the-team drifts', true, GlobalsTransforms::urlPrefixDrifts('meet-the-team', 'team/{slug}'));
check('projects vs projects matches', false, GlobalsTransforms::urlPrefixDrifts('projects', 'projects/{slug}'));
check('static prefix extraction', 'blog/category', GlobalsTransforms::staticUriPrefix('blog/category/{slug}'));
check('leading token → empty prefix, no drift', false, GlobalsTransforms::urlPrefixDrifts('anything', '{slug}'));

// -----------------------------------------------------------------------------
// content_types map — every row must have the keys _getContentTypesMap()
// consumers rely on, with non-empty section/entryType/contentField.
// headingField is genuinely optional (see blog_categories) so it is not
// required. This is a shape guard, not a live-Craft check — it cannot catch
// a handle that doesn't exist in a given site's schema, only a row that's
// missing a piece the importer will dereference unconditionally.
// -----------------------------------------------------------------------------
echo "\ncontent_types map shape\n";

$defaults = require __DIR__ . '/../src/config/defaults.php';
$contentTypes = $defaults['content_types'] ?? [];

check('content_types is non-empty', true, count($contentTypes) > 0);

foreach ($contentTypes as $slug => $row) {
    check("{$slug}: is array", true, is_array($row));

    if (!is_array($row)) {
        continue;
    }

    foreach (['section', 'entryType', 'contentField'] as $requiredKey) {
        check(
            "{$slug}: has non-empty '{$requiredKey}'",
            true,
            isset($row[$requiredKey]) && $row[$requiredKey] !== '',
        );
    }
}

// -----------------------------------------------------------------------------
// MatrixBuilder — collection child carrying blocks[] (§7.1/7.2).
//
// ContentIQ is about to start emitting blocks[] for collection members (case
// studies, team, blog posts) instead of one undifferentiated `content` blob.
// _importCollectionChild() now runs the same MatrixBuilder machinery the page
// path uses when blocks[] is present and non-empty. This exercises that
// machinery directly against a captured collection-child shape and asserts on
// the matrixData MatrixBuilder::build() actually returns — the real risk
// surface, given a whole-Matrix overwrite writes straight to client entries.
//
// MatrixBuilder/NodesRenderer reach Craft/Yii only by name (Craft::warning(),
// yii\base\Component, ContentIQImporter::$plugin) — none of it is exercised
// by the two block types below, so lightweight stubs (tests/fixtures/craft-
// stubs.php) are enough to run the real service classes standalone.
// -----------------------------------------------------------------------------
echo "\nMatrixBuilder — collection child blocks[] shape\n";

require __DIR__ . '/fixtures/craft-stubs.php';
// LinkHelper::hyperInertUrl() delegates scheme checking to UrlSafety, so it
// must be loaded first — this harness require-s classes by hand rather than
// autoloading (no Craft bootstrap here).
require __DIR__ . '/../src/helpers/UrlSafety.php';
require __DIR__ . '/../src/helpers/LinkHelper.php';
require __DIR__ . '/../src/services/NodesRenderer.php';
require __DIR__ . '/../src/services/MatrixBuilder.php';

$fakeImages = new class {
    public function importFromField($value, $dryRun)
    {
        return null;
    }
};

$fakeImports = new class {
    public function getContentTypesMap(): array
    {
        return [];
    }
};

$fakePlugin = new class {
    public $nodes;
    public $images;
    public $imports;
};
$fakePlugin->nodes   = new \matrixcreate\contentiqimporter\services\NodesRenderer();
$fakePlugin->images  = $fakeImages;
$fakePlugin->imports = $fakeImports;

\matrixcreate\contentiqimporter\ContentIQImporter::$plugin = $fakePlugin;

$fixture = json_decode(
    file_get_contents(__DIR__ . '/fixtures/collection-child-with-blocks.json'),
    true,
);

$matrixBuilder = new \matrixcreate\contentiqimporter\services\MatrixBuilder();
$matrixBuilder->prepare(['blockOverrides' => []]);

$built = $matrixBuilder->build($fixture['blocks'], false, $fixture['document']['slug']);

check('matrixData has one outer entry per block', 2, count($built['matrixData']));
check('new1 (usp) outer type', 'contentiqUsp', $built['matrixData']['new1']['type'] ?? null);
check(
    'new1 (usp) richText renders heading + list',
    '<h2>Why it matters</h2><ul><li>Reduces bycatch</li><li>Protects reef habitats</li><li>Supports local fisheries</li></ul>',
    $built['matrixData']['new1']['fields']['richText'] ?? null,
);
check('new2 (text) outer type', 'text', $built['matrixData']['new2']['type'] ?? null);
check('new2 (text) columnLayout passes through', 'singleColumn', $built['matrixData']['new2']['fields']['columnLayout'] ?? null);

$textBlocks = $built['matrixData']['new2']['fields']['textBlocks'] ?? [];
check('new2 (text) has one inner textBlock', 1, count($textBlocks));
check('new2 (text) inner entry type', 'textBlock', $textBlocks['new1']['type'] ?? null);
check(
    'new2 (text) inner richText renders the paragraph',
    '<p>Our approach combines survey data with on-the-ground partnerships.</p>',
    $textBlocks['new1']['fields']['richText'] ?? null,
);

check('blockReport has one row per block, none skipped', [false, false], array_column($built['blockReport'], 'skipped'));

// -----------------------------------------------------------------------------
// MatrixBuilder — text block column routing.
//
// The Craft Starter's `text` entry type now carries the first column's Rich
// Text itself; `textBlocks` holds at most one further column. Older forks have
// no such field on the outer entry type, and writing to it there would lose the
// column without a trace (Craft's Matrix save path catches
// InvalidFieldException and moves on), so MatrixBuilder probes the layout and
// falls back to the pre-split shape. Both shapes are asserted here through the
// entryTypeFieldProbe seam — the standalone runner has no Craft app to ask.
// -----------------------------------------------------------------------------
echo "\nMatrixBuilder — text block column routing\n";

/**
 * Builds one `text` block against a given layout shape.
 */
$buildTextBlock = static function(array $fields, bool $outerHasRichText): array {
    $builder = new \matrixcreate\contentiqimporter\services\MatrixBuilder();
    $builder->prepare(['blockOverrides' => []]);
    $builder->entryTypeFieldProbe = static fn(string $entryType, string $handle): bool => $outerHasRichText
        && $entryType === 'text'
        && $handle === 'richText';

    return $builder->build([['type' => 'text', 'fields' => $fields]]);
};

$heading = ['type' => 'heading', 'level' => 2, 'text' => 'Our Approach'];
$para1   = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First column body.']]];
$para2   = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second column body.']]];

// Single column, current layout — everything lands on the outer entry.
$single = $buildTextBlock(['columns' => 'singleColumn', 'nodes' => [$heading, $para1]], true);

check(
    'singleColumn: outer richText holds the whole column',
    '<h2>Our Approach</h2><p>First column body.</p>',
    $single['matrixData']['new1']['fields']['richText'] ?? null,
);
check(
    'singleColumn: textBlocks emitted empty (clears stale inner blocks)',
    [],
    $single['matrixData']['new1']['fields']['textBlocks'] ?? null,
);
check('singleColumn: no layout warning', [], $single['warnings']);

// Two columns, current layout — split at the first heading, second half nested.
$two = $buildTextBlock(['columns' => 'twoColumns', 'nodes' => [$heading, $para1, $para2]], true);

check(
    'twoColumns: outer richText holds the first column',
    '<h2>Our Approach</h2>',
    $two['matrixData']['new1']['fields']['richText'] ?? null,
);
check(
    'twoColumns: one inner block holds the second column',
    ['new1'],
    array_keys($two['matrixData']['new1']['fields']['textBlocks'] ?? []),
);
check(
    'twoColumns: inner richText holds everything after the heading',
    '<p>First column body.</p><p>Second column body.</p>',
    $two['matrixData']['new1']['fields']['textBlocks']['new1']['fields']['richText'] ?? null,
);

// Two columns, pre-split layout — both columns stay in the inner Matrix.
$legacy = $buildTextBlock(['columns' => 'twoColumns', 'nodes' => [$heading, $para1, $para2]], false);

check(
    'legacy layout: nothing written to the outer richText',
    null,
    $legacy['matrixData']['new1']['fields']['richText'] ?? null,
);
check(
    'legacy layout: both columns stay as inner blocks',
    ['new1', 'new2'],
    array_keys($legacy['matrixData']['new1']['fields']['textBlocks'] ?? []),
);
check(
    'legacy layout: first inner block is the heading column',
    '<h2>Our Approach</h2>',
    $legacy['matrixData']['new1']['fields']['textBlocks']['new1']['fields']['richText'] ?? null,
);
check('legacy layout: warns once about the pre-split layout', 1, count($legacy['warnings']));
check(
    'legacy layout: warning names the entry type and field',
    true,
    isset($legacy['warnings'][0])
        && str_contains($legacy['warnings'][0], "'text'")
        && str_contains($legacy['warnings'][0], "'richText'"),
);

// -----------------------------------------------------------------------------
// ImportService — §7.5 hero shape probe; §7.6/§7.6.1 legacy-field clearing /
// leftover-content replacement (rulings O2/O4) and its guard-2 "was this
// previously non-empty" warnings.
//
// These are exercised through private methods via Reflection rather than the
// full importPage()/​_importCollectionChild() pipeline, because that pipeline
// is inseparable from live Craft (Entry::find(), Craft::$app->entries,
// saveElement()...). Each method under test here was deliberately kept pure
// (or reduced to the minimal Craft surface — FieldLayout::getFieldByHandle())
// specifically so the actual judgement calls — which hero shape, whether to
// clear, replace, or warn — are unit-testable without a Craft bootstrap. See
// tests/fixtures/craft-stubs.php for the FieldLayout/ContentBlock stand-ins.
// -----------------------------------------------------------------------------
echo "\nImportService — hero shape probe (§7.5)\n";

require __DIR__ . '/../src/services/ImportService.php';

$importService = new \matrixcreate\contentiqimporter\services\ImportService();

/**
 * Invokes a private/protected method via Reflection — this test file has no
 * Craft bootstrap to construct these services through their public API alone.
 */
function callPrivate(object $obj, string $method, array $args = []): mixed
{
    // No setAccessible() call — private methods have been reflection-callable
    // without it since PHP 8.1; setAccessible() itself is deprecated as of 8.5.
    return (new ReflectionMethod($obj, $method))->invokeArgs($obj, $args);
}

$contentBlockLayout = new \craft\models\FieldLayout([
    'hero' => new \craft\fields\ContentBlock(),
]);
check(
    "'hero' field is a ContentBlock instance → contentblock shape",
    'contentblock',
    callPrivate($importService, '_detectHeroShape', [$contentBlockLayout]),
);

$flatLayout = new \craft\models\FieldLayout([
    'heroTitle' => new class {}, // any field object — not a ContentBlock
]);
check(
    "flat 'heroTitle' field, no ContentBlock 'hero' → flat shape",
    'flat',
    callPrivate($importService, '_detectHeroShape', [$flatLayout]),
);

$neitherLayout = new \craft\models\FieldLayout([]);
check(
    'neither shape present on layout → falls back to contentblock',
    'contentblock',
    callPrivate($importService, '_detectHeroShape', [$neitherLayout]),
);

check(
    'no field layout given → falls back to contentblock',
    'contentblock',
    callPrivate($importService, '_detectHeroShape', [null]),
);

// End-to-end through _buildHeroField() — same hero block, both shapes, wired
// through the real image/nodes/LinkHelper machinery (fakeImages/nodes reused
// from the MatrixBuilder fixture above).
$heroBlock = [
    'fields' => [
        'heading'    => ['level' => 1, 'text' => 'Welcome'],
        'subheading' => ['level' => 2, 'text' => 'Subtitle'],
        'body'       => 'Body text.',
        'image'      => ['key' => 'k1', 'url' => 'https://example.com/hero.jpg', 'alt' => 'Hero'],
        'buttons'    => [
            ['text' => 'Learn more', 'url' => 'https://example.com/learn'],
        ],
    ],
];

$contentBlockHero = callPrivate($importService, '_buildHeroField', [$heroBlock, false, $contentBlockLayout]);
check('contentblock shape: enableHero', true, $contentBlockHero['enableHero'] ?? null);
check('contentblock shape: heading nested under hero.fields', '<h1>Welcome</h1>', $contentBlockHero['hero']['fields']['heading'] ?? null);
check('contentblock shape: richText nested under hero.fields', '<h2>Subtitle</h2><p>Body text.</p>', $contentBlockHero['hero']['fields']['richText'] ?? null);
check('contentblock shape: no flat heroTitle key written', false, array_key_exists('heroTitle', $contentBlockHero));

$flatHero = callPrivate($importService, '_buildHeroField', [$heroBlock, false, $flatLayout]);
check('flat shape: enableHero', true, $flatHero['enableHero'] ?? null);
check('flat shape: heroTitle (not nested)', '<h1>Welcome</h1>', $flatHero['heroTitle'] ?? null);
check('flat shape: heroRichText (not nested)', '<h2>Subtitle</h2><p>Body text.</p>', $flatHero['heroRichText'] ?? null);
check(
    'flat shape: heroActionButtons carries the button',
    'https://example.com/learn',
    $flatHero['heroActionButtons']['new1']['fields']['actionButton'][0]['linkValue'] ?? null,
);
check('flat shape: no nested hero key written', false, array_key_exists('hero', $flatHero));
check('flat shape: heroMobileImage absent (no mobile_image on the block)', false, array_key_exists('heroMobileImage', $flatHero));
check('flat shape: no heroStyle key written (out of scope for flat sites)', false, array_key_exists('heroStyle', $flatHero));

// -----------------------------------------------------------------------------
echo "\nImportService — heroStyle mapping (ContentBlock shape only)\n";

// Destination layout that DOES have heroStyle on the 'hero' ContentBlock's own
// nested field layout (current Craft Starter shape).
$heroInnerLayoutWithStyle = new \craft\models\FieldLayout(['heroStyle' => new class {}]);
$contentBlockLayoutWithStyle = new \craft\models\FieldLayout([
    'hero' => new \craft\fields\ContentBlock($heroInnerLayoutWithStyle),
]);

// Destination layout WITHOUT heroStyle — simulates an older starter-based site
// that predates the field. getFieldLayout() still returns a real (empty)
// FieldLayout here, same as live Craft always does — the missing piece is the
// heroStyle handle on it, not the layout object itself.
$heroInnerLayoutNoStyle = new \craft\models\FieldLayout([]);
$contentBlockLayoutNoStyle = new \craft\models\FieldLayout([
    'hero' => new \craft\fields\ContentBlock($heroInnerLayoutNoStyle),
]);

$heroBlockTextOnly = $heroBlock;
$heroBlockTextOnly['fields']['hero_style'] = 'textOnly';

$heroBlockNoKey = $heroBlock; // no 'hero_style' key at all — older ContentIQ instances

$heroBlockGarbage = $heroBlock;
$heroBlockGarbage['fields']['hero_style'] = 'bananas';

check(
    "hero_style: 'textOnly' maps to heroStyle => 'textOnly'",
    'textOnly',
    callPrivate($importService, '_buildHeroField', [$heroBlockTextOnly, false, $contentBlockLayoutWithStyle])['hero']['fields']['heroStyle'] ?? null,
);
check(
    'missing hero_style key defaults heroStyle to textImage',
    'textImage',
    callPrivate($importService, '_buildHeroField', [$heroBlockNoKey, false, $contentBlockLayoutWithStyle])['hero']['fields']['heroStyle'] ?? null,
);
check(
    "garbage hero_style value ('bananas') falls back to textImage",
    'textImage',
    callPrivate($importService, '_buildHeroField', [$heroBlockGarbage, false, $contentBlockLayoutWithStyle])['hero']['fields']['heroStyle'] ?? null,
);
check(
    'no field layout given (null) still defaults heroStyle to textImage — assume present',
    'textImage',
    callPrivate($importService, '_buildHeroField', [$heroBlockNoKey, false, null])['hero']['fields']['heroStyle'] ?? null,
);

$heroOnOldSite = callPrivate($importService, '_buildHeroField', [$heroBlockTextOnly, false, $contentBlockLayoutNoStyle]);
check(
    'compatibility guard: heroStyle omitted entirely when destination layout lacks the field (no crash)',
    false,
    array_key_exists('heroStyle', $heroOnOldSite['hero']['fields'] ?? []),
);
check(
    'compatibility guard: other hero fields are unaffected',
    '<h1>Welcome</h1>',
    $heroOnOldSite['hero']['fields']['heading'] ?? null,
);

// -----------------------------------------------------------------------------
echo "\nImportService — collection-child legacy-field clearing / leftover-content replacement (§7.6/§7.6.1, rulings O2/O4)\n";

$docWithH1 = [
    'type' => 'doc',
    'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'My Article Heading']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body copy.']]],
    ],
];

$docWithoutH1 = [
    'type' => 'doc',
    'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Just a paragraph.']]],
    ],
];

$emptyLeftoverDoc = ['type' => 'doc', 'content' => []];

// (b) blocks present, no leftover `content` (empty/absent) → contentField AND
// headingField are '' — explicitly present as empty strings, never omitted.
// This is also what an older ContentiQ deployment produces: blocks[] with no
// `content` key at all normalises to [] before this call.
$blocksPresentNoLeftover = callPrivate($importService, '_buildCollectionChildContentFields', [
    $emptyLeftoverDoc, true, 'articleContent', 'headline',
]);
check('blocks present, no leftover content: contentField key exists', true, array_key_exists('articleContent', $blocksPresentNoLeftover));
check('blocks present, no leftover content: contentField is empty string (not absent)', '', $blocksPresentNoLeftover['articleContent'] ?? 'MISSING');
check('blocks present, no leftover content: headingField key exists', true, array_key_exists('headline', $blocksPresentNoLeftover));
check('blocks present, no leftover content: headingField is empty string (not absent)', '', $blocksPresentNoLeftover['headline'] ?? 'MISSING');
check('blocks present, no leftover content: exactly two keys written (no stray heading omission)', 2, count($blocksPresentNoLeftover));

// blocks present, no leftover content, content_type has no headingField
// configured (e.g. blog_categories) → only contentField is cleared; there is
// no heading key to clear.
$blocksPresentNoLeftoverNoHeading = callPrivate($importService, '_buildCollectionChildContentFields', [
    [], true, 'body', null,
]);
check('blocks present, no leftover content, no headingField configured: only contentField key written', ['body'], array_keys($blocksPresentNoLeftoverNoHeading));
check('blocks present, no leftover content, no headingField configured: contentField is empty string', '', $blocksPresentNoLeftoverNoHeading['body'] ?? 'MISSING');

// (a) blocks present WITH non-empty leftover `content` (a range only covered
// part of the page) → the leftover prose renders wholesale into contentField
// (H1 kept, NOT extracted — blocks own the heading, extractHeading() must
// never run on this branch); headingField is still cleared to ''.
$blocksPresentWithLeftover = callPrivate($importService, '_buildCollectionChildContentFields', [
    $docWithH1, true, 'articleContent', 'headline',
]);
check(
    'blocks present + leftover content: contentField renders the leftover doc wholesale, H1 kept (not extracted)',
    '<h1>My Article Heading</h1><p>Body copy.</p>',
    $blocksPresentWithLeftover['articleContent'] ?? null,
);
check('blocks present + leftover content: headingField still cleared to empty string', '', $blocksPresentWithLeftover['headline'] ?? 'MISSING');
check('blocks present + leftover content: exactly two keys written', 2, count($blocksPresentWithLeftover));

// blocks present + leftover content, no headingField configured → only
// contentField, holding the rendered leftover.
$blocksPresentWithLeftoverNoHeading = callPrivate($importService, '_buildCollectionChildContentFields', [
    $docWithoutH1, true, 'body', null,
]);
check('blocks present + leftover content, no headingField configured: only contentField key written', ['body'], array_keys($blocksPresentWithLeftoverNoHeading));
check(
    'blocks present + leftover content, no headingField configured: contentField renders the leftover paragraph',
    '<p>Just a paragraph.</p>',
    $blocksPresentWithLeftoverNoHeading['body'] ?? null,
);

// (c) blocks absent (pre-§7.1 behaviour, unchanged) → normal H1 extraction: the
// H1 is lifted into headingField and stripped from the body that renders
// into contentField (so it isn't rendered twice).
$blocksAbsentWithH1 = callPrivate($importService, '_buildCollectionChildContentFields', [
    $docWithH1, false, 'articleContent', 'headline',
]);
check('blocks absent + H1 present: headingField extracted', 'My Article Heading', $blocksAbsentWithH1['headline'] ?? null);
check('blocks absent + H1 present: contentField renders remaining body, H1 stripped', '<p>Body copy.</p>', $blocksAbsentWithH1['articleContent'] ?? null);

// blocks absent, no H1 in the doc → headingField is never populated (stays
// genuinely absent, not '') — this is the pre-existing behaviour and must
// stay untouched by the new blocks-present clearing path.
$blocksAbsentNoH1 = callPrivate($importService, '_buildCollectionChildContentFields', [
    $docWithoutH1, false, 'articleContent', 'headline',
]);
check('blocks absent, no H1: headingField key is genuinely absent (not cleared to \'\')', false, array_key_exists('headline', $blocksAbsentNoH1));
check('blocks absent, no H1: contentField renders the paragraph', '<p>Just a paragraph.</p>', $blocksAbsentNoH1['articleContent'] ?? null);

// blocks absent, no headingField configured → same as today: only contentField.
$blocksAbsentNoHeadingField = callPrivate($importService, '_buildCollectionChildContentFields', [
    $docWithoutH1, false, 'body', null,
]);
check('blocks absent, no headingField configured: only contentField key written', ['body'], array_keys($blocksAbsentNoHeadingField));

// -----------------------------------------------------------------------------
echo "\nImportService — §7.6.1 guard 2 / §7.7: 'previously non-empty' warnings\n";

check('empty string is not non-empty', false, callPrivate($importService, '_isFieldValueNonEmpty', ['']));
check('whitespace-only string is not non-empty', false, callPrivate($importService, '_isFieldValueNonEmpty', ["   \n\t"]));
check('empty tags-only HTML is not non-empty', false, callPrivate($importService, '_isFieldValueNonEmpty', ['<p></p>']));
check('null is not non-empty', false, callPrivate($importService, '_isFieldValueNonEmpty', [null]));
check('empty array is not non-empty', false, callPrivate($importService, '_isFieldValueNonEmpty', [[]]));
check('HTML with real text is non-empty', true, callPrivate($importService, '_isFieldValueNonEmpty', ['<p>Hello</p>']));
check('plain non-empty string is non-empty', true, callPrivate($importService, '_isFieldValueNonEmpty', ['Hello']));
check('non-empty array is non-empty', true, callPrivate($importService, '_isFieldValueNonEmpty', [[1, 2]]));

check(
    'nothing previously populated → no warnings',
    [],
    callPrivate($importService, '_buildBlockOwnershipWarnings', [false, false, 0, 'articleContent', 'headline', 'contentBlocks', false]),
);

$contentWarnings = callPrivate($importService, '_buildBlockOwnershipWarnings', [true, false, 0, 'articleContent', 'headline', 'contentBlocks', false]);
check('contentField previously non-empty, cleared (no leftover content) → exactly one warning', 1, count($contentWarnings));
check(
    'contentField warning (cleared) names the handle and says "clearing"',
    "Blocks now own this page — clearing previously non-empty 'articleContent' field.",
    $contentWarnings[0] ?? null,
);

$contentReplacedWarnings = callPrivate($importService, '_buildBlockOwnershipWarnings', [true, false, 0, 'articleContent', 'headline', 'contentBlocks', true]);
check('contentField previously non-empty, replaced with leftover content → exactly one warning', 1, count($contentReplacedWarnings));
check(
    'contentField warning (replaced with leftover) names the handle and says "replacing", not "clearing"',
    "Blocks now own this page — replacing previously non-empty 'articleContent' field with leftover (unmarked) content.",
    $contentReplacedWarnings[0] ?? null,
);

$headingWarnings = callPrivate($importService, '_buildBlockOwnershipWarnings', [false, true, 0, 'articleContent', 'headline', 'contentBlocks', false]);
check('headingField previously non-empty → exactly one warning', 1, count($headingWarnings));
check(
    'headingField warning names the handle',
    "Blocks now own this page's heading — clearing previously non-empty 'headline' field.",
    $headingWarnings[0] ?? null,
);

// headingField wording is unaffected by $contentReplacedWithLeftover — blocks
// always own the heading, whether or not there's leftover content.
$headingWarningsWithLeftover = callPrivate($importService, '_buildBlockOwnershipWarnings', [false, true, 0, 'articleContent', 'headline', 'contentBlocks', true]);
check('headingField warning wording unaffected by $contentReplacedWithLeftover', $headingWarnings, $headingWarningsWithLeftover);

$matrixWarnings = callPrivate($importService, '_buildBlockOwnershipWarnings', [false, false, 3, 'articleContent', 'headline', 'contentBlocks', false]);
check('matrix previously non-empty → exactly one warning', 1, count($matrixWarnings));
check(
    'matrix warning names the handle and count',
    "Content Blocks field 'contentBlocks' already holds 3 block(s) not written by this importer — this sync will replace them wholesale with new element IDs.",
    $matrixWarnings[0] ?? null,
);

$allThreeWarnings = callPrivate($importService, '_buildBlockOwnershipWarnings', [true, true, 1, 'articleContent', 'headline', 'contentBlocks', false]);
check('all three previously non-empty → three warnings', 3, count($allThreeWarnings));

// -----------------------------------------------------------------------------
// ImportService — CTA source routing (fields.source: 'page'|'global') and the
// footerCallToAction.showGlobalCallToAction lightswitch decision it drives.
//
// _ctaSource() is the pure classify-a-block-payload decision this feature is
// built on. _resolveCtaBlocks() itself needs a live Craft app for its
// per-block CTA-entry writes (same gap _resolveCtaEntry()/
// _resolveGlobalCtaEntry() already have — see PROGRESS.md), but its two
// early-return-null ("untouched") paths — dry run, no CTA blocks at all —
// never touch Craft and are covered directly below.
// _buildFooterGlobalCtaField() is the ContentBlock existence guard that
// mirrors _buildHeroInnerFields()'s heroStyle guard (the 1.22.0
// staging-field lesson — an unrecognised handle inside a ContentBlock's
// nested 'fields' array throws yii\base\UnknownPropertyException, uncaught
// by Craft's own save path); its $showGlobal parameter's ON/OFF ×
// field-present/field-missing matrix — including the OFF-side silent-skip
// asymmetry — is exercised in full below. All of it uses the same
// Reflection + craft-stubs approach as the hero-shape probe above.
// -----------------------------------------------------------------------------
echo "\nImportService — CTA source routing\n";

check(
    "absent fields.source → 'global' (ContentiQ's own default)",
    'global',
    callPrivate($importService, '_ctaSource', [['fields' => []]]),
);
check(
    "fields.source: 'global' → 'global'",
    'global',
    callPrivate($importService, '_ctaSource', [['fields' => ['source' => 'global']]]),
);
check(
    "fields.source: 'page' → 'page'",
    'page',
    callPrivate($importService, '_ctaSource', [['fields' => ['source' => 'page']]]),
);
check(
    "unrecognised fields.source value → 'global' (conservative default)",
    'global',
    callPrivate($importService, '_ctaSource', [['fields' => ['source' => 'bananas']]]),
);
check(
    "no 'fields' key at all → 'global'",
    'global',
    callPrivate($importService, '_ctaSource', [[]]),
);

// _resolveCtaBlocks()'s "untouched" (null) leg of the ON/OFF/untouched
// decision table — both callers already gate the whole CTA step behind
// !$dryRun, so the dry-run branch below is defensive only, but it's still
// pure enough to assert directly.
$ctaBlocksProbeResult = ['warnings' => []];
$untouchedMatrixData          = [];
$untouchedBlockKeyConsumption = [];
check(
    'dry run → untouched (null) regardless of ctaBlocks content',
    null,
    callPrivate($importService, '_resolveCtaBlocks', [
        &$untouchedMatrixData, &$untouchedBlockKeyConsumption, [['fields' => ['source' => 'page']]], &$ctaBlocksProbeResult, true, null,
    ]),
);
check(
    'no CTA blocks at all → untouched (null)',
    null,
    callPrivate($importService, '_resolveCtaBlocks', [
        &$untouchedMatrixData, &$untouchedBlockKeyConsumption, [], &$ctaBlocksProbeResult, false, null,
    ]),
);

// _buildFooterGlobalCtaField() guard — config carries the (default) handles;
// takes $config as a parameter rather than calling _getConfig() itself
// specifically so it's testable here without a Craft::$app bootstrap. The
// same three layout shapes are probed under both $showGlobal intents, since
// the warn-vs-silent behaviour on a miss is the part that differs by intent.
$footerCtaConfig = [
    'footerCtaField'           => 'footerCallToAction',
    'footerCtaShowGlobalField' => 'showGlobalCallToAction',
];

// --- ON ($showGlobal = true) — a miss is worth a per-page warning.
$footerResultA = ['warnings' => []];
check(
    'ON + null owner field layout → nothing written',
    null,
    callPrivate($importService, '_buildFooterGlobalCtaField', [null, $footerCtaConfig, &$footerResultA, true]),
);
check('ON + null owner field layout → one warning', 1, count($footerResultA['warnings']));

// Outer 'footerCallToAction' present but not a ContentBlock field (e.g. an
// older/flat-shape fork) — same "skip, don't crash" outcome as a missing field.
$footerResultB = ['warnings' => []];
$notAContentBlockLayout = new \craft\models\FieldLayout(['footerCallToAction' => new class {}]);
check(
    "ON + 'footerCallToAction' not a ContentBlock instance → nothing written",
    null,
    callPrivate($importService, '_buildFooterGlobalCtaField', [$notAContentBlockLayout, $footerCtaConfig, &$footerResultB, true]),
);
check("ON + 'footerCallToAction' not a ContentBlock instance → one warning", 1, count($footerResultB['warnings']));

// Outer field is a ContentBlock, but its OWN nested layout predates
// 'showGlobalCallToAction' — the load-bearing guard this whole method exists
// for (writing the handle anyway would throw UnknownPropertyException).
$footerResultC = ['warnings' => []];
$innerLayoutNoHandle = new \craft\models\FieldLayout([]);
$layoutMissingInnerHandle = new \craft\models\FieldLayout([
    'footerCallToAction' => new \craft\fields\ContentBlock($innerLayoutNoHandle),
]);
check(
    "ON + ContentBlock present but missing 'showGlobalCallToAction' inner handle → nothing written",
    null,
    callPrivate($importService, '_buildFooterGlobalCtaField', [$layoutMissingInnerHandle, $footerCtaConfig, &$footerResultC, true]),
);
check("ON + missing inner handle → one warning naming it", true, str_contains($footerResultC['warnings'][0] ?? '', 'showGlobalCallToAction'));

// Both layers present (current Craft Starter shape) — the lightswitch value
// is returned, nested under the ContentBlock's own 'fields' shape, no warning.
$footerResultD = ['warnings' => []];
$innerLayoutWithHandle = new \craft\models\FieldLayout(['showGlobalCallToAction' => new class {}]);
$layoutWithInnerHandle = new \craft\models\FieldLayout([
    'footerCallToAction' => new \craft\fields\ContentBlock($innerLayoutWithHandle),
]);
$footerFieldValuesOn = callPrivate($importService, '_buildFooterGlobalCtaField', [$layoutWithInnerHandle, $footerCtaConfig, &$footerResultD, true]);
check(
    'ON + both layers present → showGlobalCallToAction: true nested under footerCallToAction.fields',
    true,
    ($footerFieldValuesOn['footerCallToAction']['fields']['showGlobalCallToAction'] ?? null) === true,
);
check('ON + both layers present → no warning', [], $footerResultD['warnings']);

// --- OFF ($showGlobal = false) — the new page-source-CTA-only decision. Same
// layout-guard logic, but a miss is SILENT: there's nothing to disable, and
// collection children (case studies/team) routinely carry 'page'-source CTA
// blocks with no footerCallToAction field at all — warning on every one of
// them would be spam, not signal.
$footerResultE = ['warnings' => []];
check(
    'OFF + null owner field layout → nothing written',
    null,
    callPrivate($importService, '_buildFooterGlobalCtaField', [null, $footerCtaConfig, &$footerResultE, false]),
);
check('OFF + null owner field layout → SILENT, no warning', [], $footerResultE['warnings']);

$footerResultF = ['warnings' => []];
check(
    "OFF + 'footerCallToAction' not a ContentBlock instance → nothing written",
    null,
    callPrivate($importService, '_buildFooterGlobalCtaField', [$notAContentBlockLayout, $footerCtaConfig, &$footerResultF, false]),
);
check("OFF + 'footerCallToAction' not a ContentBlock instance → SILENT, no warning", [], $footerResultF['warnings']);

$footerResultG = ['warnings' => []];
check(
    "OFF + ContentBlock present but missing 'showGlobalCallToAction' inner handle → nothing written",
    null,
    callPrivate($importService, '_buildFooterGlobalCtaField', [$layoutMissingInnerHandle, $footerCtaConfig, &$footerResultG, false]),
);
check('OFF + missing inner handle → SILENT, no warning', [], $footerResultG['warnings']);

// Both layers present, OFF — the lightswitch value written is false (not
// simply omitted), still with no warning (there's a field, and it wrote fine).
$footerResultH = ['warnings' => []];
$footerFieldValuesOff = callPrivate($importService, '_buildFooterGlobalCtaField', [$layoutWithInnerHandle, $footerCtaConfig, &$footerResultH, false]);
check(
    'OFF + both layers present → showGlobalCallToAction: false nested under footerCallToAction.fields',
    true,
    ($footerFieldValuesOff['footerCallToAction']['fields']['showGlobalCallToAction'] ?? null) === false,
);
check('OFF + both layers present → no warning', [], $footerResultH['warnings']);

// -----------------------------------------------------------------------------
// NodesRenderer — blockquote support (Gap 1: flat nodes[] shape; Gap 2: raw
// ProseMirror content shape). NodesRenderer/UrlSafety are already required
// above for the MatrixBuilder section.
// -----------------------------------------------------------------------------
echo "\nNodesRenderer — blockquote\n";

$nodesRenderer = new \matrixcreate\contentiqimporter\services\NodesRenderer();

// Gap 1: flat node (nodes[] blocks path) — text only, no content array.
check(
    'flat blockquote: text only',
    '<blockquote><p>Just a quote.</p></blockquote>',
    $nodesRenderer->render([
        ['type' => 'blockquote', 'text' => 'Just a quote.'],
    ]),
);

// Gap 1: flat node with content carrying a hardBreak and a mark.
check(
    'flat blockquote: content with hardBreak + mark',
    '<blockquote><p><strong>First line.</strong><br>Second line.</p></blockquote>',
    $nodesRenderer->render([
        [
            'type'    => 'blockquote',
            'text'    => "First line.\nSecond line.",
            'content' => [
                ['type' => 'text', 'text' => 'First line.', 'marks' => [['type' => 'bold']]],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => 'Second line.'],
            ],
        ],
    ]),
);

// Gap 1: empty flat node → nothing rendered.
check(
    'flat blockquote: empty → empty string',
    '',
    $nodesRenderer->render([
        ['type' => 'blockquote', 'text' => ''],
    ]),
);

// Gap 2: raw ProseMirror content path — nested blockquote wrapping one paragraph.
check(
    'nested blockquote: one paragraph',
    '<blockquote><p>Quoted line.</p></blockquote>',
    $nodesRenderer->renderDocument([
        [
            'type'    => 'blockquote',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quoted line.']]],
            ],
        ],
    ]),
);

// Gap 2: nested blockquote wrapping two paragraphs — each keeps its own <p>.
check(
    'nested blockquote: two paragraphs',
    '<blockquote><p>First para.</p><p>Second para.</p></blockquote>',
    $nodesRenderer->renderDocument([
        [
            'type'    => 'blockquote',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First para.']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second para.']]],
            ],
        ],
    ]),
);

// Gap 2: empty nested blockquote → nothing rendered.
check(
    'nested blockquote: empty → empty string',
    '',
    $nodesRenderer->renderDocument([
        ['type' => 'blockquote', 'content' => []],
    ]),
);

// -----------------------------------------------------------------------------
// Summary.
// -----------------------------------------------------------------------------
echo "\n" . ($failures === 0 ? "OK" : "FAILED") . ": {$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
