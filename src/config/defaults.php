<?php

/**
 * ContentIQ — default block type mappings.
 *
 * Keys are ContentIQ JSON block type strings (exact, from export).
 * Values define the outer Craft Matrix block type and nested inner Matrix structure.
 *
 * Structure per mapping:
 *   outerType   — handle of the Craft contentBlocks entry type
 *   outerFields — fields set directly on the outer entry, keyed by ContentIQ source key:
 *                   [contentiqKey => [craftHandle, handlerType]]
 *   innerMatrix — nested Matrix field config:
 *                   outerField  — Matrix field handle on the outer entry type
 *                   innerType   — inner entry type handle
 *                   mode        — 'single' (one inner entry from block fields)
 *                               | 'repeated' (one inner entry per item in sourceKey array)
 *                               | 'grouped' (consecutive blocks of same type merge into one
 *                                            outer entry with one inner entry per block)
 *                   sourceKey   — (repeated only) ContentIQ field key holding the items array
 *                   fields      — inner entry field mapping: [contentiqKey => [craftHandle, handlerType]]
 *
 * Handler types:
 *   'nodes'           → nodes array → NodesRenderer → HTML string
 *   'mediaNodes'      → nodes array → richText HTML + actionButtons Matrix (ctaButtons lifted out)
 *   'textMediaMedia'  → whole inner block ('_block') → mediaType + image|desktop/mobileBackgroundImage
 *   'image'           → {key, url, alt} object → ImageImportService → asset ID array
 *   'heading'         → {level, text} object or plain string → <hN>text</hN> HTML
 *   'body'            → plain string → <p>text</p> HTML
 *   'layout'          → plain string → pass through as-is
 *   'textMediaLayout' → ContentIQ layout string → mapped to Craft dropdown value
 *   'tableHtml'       → rows array [{isHeader, cells}] → HTML <table> string
 *   'hyperButton'     → {label, url} object → Hyper link field array
 *   'buttonNodes'     → ContentNode[] → filters ctaButton nodes → actionButtons Matrix entries
 *   'faqNodes'        → ContentNode[] → splits at faq_items → richText/extraRichText/_faqItems
 *   'uspContent'      → entire block fields → {heading:{level,text}, items:[string]} → <hN>+<ul> HTML
 *   'collectionSection' → ContentIQ collection slug → Craft section handle (via content_types map;
 *                         unmapped slug stored raw + page warning)
 *   'gallerySource'   → 'images'|'folder' → Craft imageSource dropdown value ('images'|'folders')
 *   'assetFolder'     → raw ContentIQ folder name → resolved Craft folder UID string (or null);
 *                       requires assetFolderStrategy 'sitemap' — see docs/assets.md (Image Gallery)
 *
 * Special contentiqKey '_block':
 *   When the contentiqKey is '_block' (in outerFields OR innerMatrix.fields), the entire block
 *   fields array is passed to the handler instead of a single field value. Used where the output
 *   is derived from multiple source keys (e.g. USP combines heading+items; text_and_media's
 *   textMediaMedia derives mediaType and the image destination from the block's layout + image).
 *
 * Developer notes:
 *   'notes' is a block-level key (not inside 'fields') emitted by ContentIQ when the CSM
 *   adds a developer note to a block. It is read directly from $block['notes'] in
 *   ImportService and written to the contentiqNotes field on the outer entry. It is NOT
 *   present in outerFields here because MatrixBuilder reads from $block['fields'] only.
 *
 * Blocks NOT in this mapping (handled or skipped elsewhere):
 *   hero          — imported separately into entry.hero Matrix field (not contentBlocks)
 *   call_to_action — handled separately; creates a callToActionEntry and relates it
 *   table         — no matching block type in this project
 *
 * Override any mapping in config/contentiq.php using the 'blockOverrides' key.
 * Overrides replace the entire block definition (not merged at field level).
 *
 * ---------------------------------------------------------------------------
 * Content types ('content_types' key, below)
 * ---------------------------------------------------------------------------
 * Maps ContentIQ content_type slugs (set on collection children) to the Craft
 * section / entry type / content field they import into. Collection children
 * carry a raw `content` (ProseMirror) field instead of a `blocks` array, which
 * is serialised to HTML and written to `contentField`.
 *
 * Per-slug keys:
 *   section      — Craft section handle
 *   entryType    — Craft entry type handle
 *   contentField — field the serialised ProseMirror HTML is written to
 *   headingField — (optional) field to receive the H1. When set, the first
 *                  level-1 heading is lifted out as plain text into this field
 *                  and removed from the body so it isn't rendered twice.
 *   blocksField  — (optional) Matrix field handle to receive `blocks[]` when
 *                  the collection child carries it (ContentIQ's wire contract
 *                  is either `blocks` or `content`, never both). Defaults to
 *                  config['matrixField'] ('contentBlocks') when unset.
 *
 * These defaults match the Craft Starter. Override per-project in
 * config/contentiq.php under the 'content_types' key (per-slug replace).
 * A content_type with no matching entry here is logged and skipped (non-fatal).
 *
 * NOTE: 'content_types' is NOT a block mapping — MatrixBuilder excludes it.
 */

return [
    'text' => [
        'outerType'   => 'text',
        'outerFields' => [
            'columns' => ['columnLayout', 'layout'],
        ],
        'innerMatrix' => [
            'outerField' => 'textBlocks',
            'innerType'  => 'textBlock',
            'mode'       => 'text_columns',
            // The first column goes on the outer entry itself; only a second
            // column (twoColumns split at the first heading) becomes an inner
            // block. Dropped automatically on sites whose 'text' entry type
            // predates the field — see MatrixBuilder's text_columns branch.
            'firstColumnField' => 'richText',
            'fields'     => [
                'nodes' => ['richText', 'nodes'],
            ],
        ],
    ],

    'text_and_media' => [
        'outerType'   => 'textAndMedia',
        'outerFields' => [
            'layout' => ['blockLayout', 'textMediaLayout'],
        ],
        'innerMatrix' => [
            'outerField' => 'textAndMediaBlocks',
            'innerType'  => 'textAndMediaBlock',
            'mode'       => 'grouped',
            'fields'     => [
                // mediaNodes renders text to richText AND lifts ctaButton nodes
                // into the inner block's actionButtons Matrix (not raw <a> tags).
                'nodes'  => ['richText', 'mediaNodes'],
                // textMediaMedia receives the whole inner block (via '_block') so it
                // can set mediaType (image | backgroundImage) from the block's layout
                // and route the image to the matching Craft field.
                '_block' => ['mediaType', 'textMediaMedia'],
            ],
        ],
    ],

    'faq' => [
        'outerType'   => 'faq',
        'outerFields' => [
            // faqNodes splits nodes at faq_items boundary:
            //   before → richText, after → extraRichText, items → _faqItems
            'nodes' => ['richText', 'faqNodes'],
        ],
        'innerMatrix' => [
            'outerField' => 'accordionItems',
            'innerType'  => 'accordionItem',
            'mode'       => 'repeated',
            'sourceKey'         => 'items',       // legacy: flat array, older ContentIQ instances only
            'fallbackSourceKey' => '_faqItems',   // current: merged from fields.nodes' faq_items nodes
            'fields'     => [
                'question' => ['itemTitle',   'heading'],  // plain string → <h3>
                'answer'   => ['itemContent', 'body'],     // plain string → <p>
            ],
        ],
    ],

    'cards' => [
        'outerType'   => 'entryCards',
        'outerFields' => [
            'intro' => ['richText', 'nodes'],  // ContentNode[] → HTML above card grid
        ],
        'innerMatrix' => [
            'outerField' => 'entryCards',
            'innerType'  => 'card',
            'mode'       => 'repeated',
            'sourceKey'  => 'cards',
            'fields'     => [
                'heading' => ['cardTitle',         'heading'],     // {level,text} → <hN>
                'body'    => ['cardText',          'nodes'],       // ContentNode[] → HTML via NodesRenderer
                'image'   => ['cardImage',         'image'],
                'button'  => ['actionButtonLabel', 'buttonLabel'], // {label,url} → label text only
            ],
        ],
    ],

    'price_list' => [
        'outerType'   => 'priceList',
        'outerFields' => [
            'nodes'     => ['richText',      'nodes'],       // intro nodes → CKEditor HTML
            'rows'      => ['priceList',     'tableHtml'],   // rows array → HTML <table>
            'postNodes' => ['actionButtons', 'buttonNodes'], // ctaButton nodes after table → actionButtons Matrix
        ],
        'innerMatrix' => null,
    ],

    'usp' => [
        'outerType'   => 'contentiqUsp',
        'outerFields' => [
            // '_block' passes the entire block fields to the handler.
            // USP API shape: {heading: {level, text}, items: [string, ...]}
            // Falls back to rendering a 'nodes' array if present instead.
            '_block' => ['richText', 'uspContent'],
        ],
        'innerMatrix' => null,
    ],

    'global' => [
        'outerType'   => 'contentiqGlobal',
        'outerFields' => [
            'nodes' => ['richText', 'nodes'],  // rendered as HTML into the shared richText CKEditor field
        ],
        'innerMatrix' => null,
    ],

    // The ONLY block that keeps bracketed placeholder text. Custom content is
    // free markup typed by hand in ContentiQ's markup editor, so a standalone
    // "[Client quote]" there is content the editor meant, not a layout aide —
    // hence the 'customNodes' handler rather than 'nodes'. Every other block
    // drops such nodes (NodesRenderer::PLACEHOLDER_PATTERN).
    'custom' => [
        'outerType'   => 'contentiqCustom',
        'outerFields' => [
            'nodes'  => ['richText',        'customNodes'],  // all content nodes → CKEditor HTML, placeholders kept
            'images' => ['contentiqImages', 'images'],       // optional multiple assets (up to 10)
        ],
        'innerMatrix' => null,
    ],

    // Two modes, selected by the wire's 'source' key ('images' | 'folder'):
    //   'images' — 'images'/'nodes' populate the Craft images/richText
    //              fields; 'source'/'folder' still resolve (gallerySource →
    //              'images', assetFolder → null) but are otherwise inert.
    //   'folder' — 'images' is always [] on the wire; gallerySource → the
    //              Craft imageSource dropdown's 'folders' value, assetFolder
    //              → the resolved Craft folder UID (requires
    //              assetFolderStrategy 'sitemap' — see docs/assets.md, R9).
    'image_gallery' => [
        'outerType'   => 'imageGallery',
        'outerFields' => [
            'source' => ['imageSource', 'gallerySource'],
            'images' => ['images',      'images'],  // reuses the same handler as 'custom' — no cap
            'folder' => ['assetFolder', 'assetFolder'],
            'nodes'  => ['richText',    'nodes'],
        ],
        'innerMatrix' => null,
    ],

    'collection_listing' => [
        'outerType'   => 'collectionListing',
        'outerFields' => [
            // Intro nodes → CKEditor HTML, minus bracketed listing placeholders
            // like "[Blog Listing]" / "[Listing Grid]" — the rendered listing
            // takes that space, so the placeholder text is dropped on import.
            'nodes'      => ['richText', 'collectionListingNodes'],
            // collectionSection resolves the ContentIQ collection slug to a Craft
            // section handle via the content_types map. An unmapped slug is stored
            // raw and surfaces a page warning.
            'collection' => ['listingSection', 'collectionSection'],
        ],
        'innerMatrix' => null,
    ],

    // Content type routing for collection children (see header note).
    // Keys are ContentiQ's default registry slugs; values map to the Craft
    // Starter's sections/entry types.
    // slug => [section, entryType, contentField]
    //
    // NOTE: there is deliberately no default 'offices' route — offices are now
    // fed by the globals import (GlobalsImportService), not collection children.
    // A project that still exports offices as a collection can add an explicit
    // 'offices' override in config/contentiq.php as a legacy escape hatch.
    'content_types' => [
        'blog' => [
            'section'      => 'articles',
            'entryType'    => 'article',
            'contentField' => 'articleContent',
            'headingField' => 'headline',  // H1 lifted out of the body into this field
        ],
        'blog_categories' => [
            'section'      => 'blogCategories',
            'entryType'    => 'blogCategory',
            'contentField' => 'body',
        ],
        // These two rows use the Craft Starter's real field/entry-type handles,
        // verified against config/project/entryTypes/caseStudy--*.yaml and
        // team--*.yaml. A site with different handles must override this slug
        // under 'content_types' in its own config/contentiq.php — the file
        // override always wins over these defaults (see _getContentTypesMap()).
        'case_studies' => [
            'section'      => 'caseStudies',
            'entryType'    => 'caseStudy',
            'contentField' => 'articleBody',
            // caseStudy shares the `headline` field with article (same field UID),
            // and caseStudy.twig renders it as the entry's <h1> exactly as
            // article.twig does. Without this the H1 stays in the body, and once
            // blocks own the page the stale headline is never cleared — the
            // duplicate-<h1> defect the blocks-present clearing rule exists to
            // prevent. The starter/groves/lucia already set this in their saved CP
            // mappings; the default was simply missing.
            'headingField' => 'headline',
        ],
        // `team` intentionally has no headingField — team.twig does not render
        // `headline`, and the team entry type does not carry that field.
        'team' => [
            'section'      => 'team',
            'entryType'    => 'team',
            'contentField' => 'bio',
        ],
    ],
];
