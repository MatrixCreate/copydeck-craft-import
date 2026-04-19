<?php

/**
 * Copydeck Importer — default block type mappings.
 *
 * Keys are Copydeck JSON block type strings (exact, from export).
 * Values define the outer Craft Matrix block type and nested inner Matrix structure.
 *
 * Structure per mapping:
 *   outerType   — handle of the Craft contentBlocks entry type
 *   outerFields — fields set directly on the outer entry, keyed by Copydeck source key:
 *                   [copydeckKey => [craftHandle, handlerType]]
 *   innerMatrix — nested Matrix field config:
 *                   outerField  — Matrix field handle on the outer entry type
 *                   innerType   — inner entry type handle
 *                   mode        — 'single' (one inner entry from block fields)
 *                               | 'repeated' (one inner entry per item in sourceKey array)
 *                               | 'grouped' (consecutive blocks of same type merge into one
 *                                            outer entry with one inner entry per block)
 *                   sourceKey   — (repeated only) Copydeck field key holding the items array
 *                   fields      — inner entry field mapping: [copydeckKey => [craftHandle, handlerType]]
 *
 * Handler types:
 *   'nodes'           → nodes array → NodesRenderer → HTML string
 *   'image'           → {key, url, alt} object → ImageImportService → asset ID array
 *   'heading'         → {level, text} object or plain string → <hN>text</hN> HTML
 *   'body'            → plain string → <p>text</p> HTML
 *   'layout'          → plain string → pass through as-is
 *   'textMediaLayout' → Copydeck layout string → mapped to Craft dropdown value
 *   'tableHtml'       → rows array [{isHeader, cells}] → HTML <table> string
 *   'hyperButton'     → {label, url} object → Hyper link field array
 *   'buttonNodes'     → ContentNode[] → filters ctaButton nodes → actionButtons Matrix entries
 *   'faqNodes'        → ContentNode[] → splits at faq_items → richText/extraRichText/_faqItems
 *
 * Developer notes:
 *   'notes' is a block-level key (not inside 'fields') emitted by Copydeck when the CSM
 *   adds a developer note to a block. It is read directly from $block['notes'] in
 *   ImportService and written to the copydeckNotes field on the outer entry. It is NOT
 *   present in outerFields here because MatrixBuilder reads from $block['fields'] only.
 *
 * Blocks NOT in this mapping (handled or skipped elsewhere):
 *   hero          — imported separately into entry.hero Matrix field (not contentBlocks)
 *   call_to_action — handled separately; creates a callToActionEntry and relates it
 *   table         — no matching block type in this project
 *
 * Override any mapping in config/copydeck.php using the 'blockOverrides' key.
 * Overrides replace the entire block definition (not merged at field level).
 */

return [
    'text' => [
        'outerType'   => 'text',
        'outerFields' => [],
        'innerMatrix' => [
            'outerField' => 'textBlocks',
            'innerType'  => 'textBlock',
            'mode'       => 'single',
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
                'nodes' => ['richText', 'nodes'],
                'image' => ['image',    'image'],
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
            'sourceKey'         => 'items',       // new: flat array from Copydeck
            'fallbackSourceKey' => '_faqItems',   // legacy: extracted from nodes.faq_items
            'fields'     => [
                'question' => ['itemTitle',   'heading'],  // plain string → <h3>
                'answer'   => ['itemContent', 'body'],     // plain string → <p>
            ],
        ],
    ],

    'cards' => [
        'outerType'   => 'copydeckCards',
        'outerFields' => [
            'intro' => ['richText', 'nodes'],  // ContentNode[] → HTML above card grid
        ],
        'innerMatrix' => [
            'outerField' => 'copydeckCards',
            'innerType'  => 'copydeckCard',
            'mode'       => 'repeated',
            'sourceKey'  => 'cards',
            'fields'     => [
                'heading' => ['heading',      'heading'],     // {level,text} → <hN>
                'body'    => ['richText',     'nodes'],       // ContentNode[] → HTML via NodesRenderer
                'image'   => ['image',        'image'],
                'button'  => ['actionButton', 'hyperButton'], // {label,url} → Hyper link
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
        'outerType'   => 'copydeckUsp',
        'outerFields' => [
            'nodes' => ['uspText', 'nodes'],  // heading + list → CKEditor HTML (richText with list support)
        ],
        'innerMatrix' => null,
    ],

    'global' => [
        'outerType'   => 'copydeckGlobal',
        'outerFields' => [
            'nodes' => ['richText',      'nodes'],  // rendered as HTML into the content field
        ],
        'innerMatrix' => null,
    ],
];
