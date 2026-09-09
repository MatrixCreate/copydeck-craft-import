<?php

namespace matrixcreate\contentiqimporter\services;

use matrixcreate\contentiqimporter\helpers\UrlSafety;
use yii\base\Component;

/**
 * Converts a ContentIQ nodes array to an HTML string for Craft rich text fields.
 *
 * Supported node types:
 *   - heading (level 1–4) → <h1>–<h4>
 *   - paragraph           → <p>
 *   - blockquote          → <blockquote><p>…</p></blockquote>
 *   - list                → <ul> or <ol> (based on 'ordered' flag)
 *   - ordered_list        → <ol><li> (legacy alias)
 *   - unordered_list      → <ul><li> (legacy alias)
 *   - faq_items           → <details><summary>…</summary><p>…</p></details>
 *   - table               → <table><thead>/<tbody> with <th>/<td> cells
 *   - ctaButton           → <p><a href="url">label</a></p>
 *
 * Placeholder stripping (unconditional, no config key): ContentiQ authors use
 * standalone bracketed strings — "[Image gallery]", "[Product grid]",
 * "[Testimonials]", anything — as layout aides marking where other content
 * (a rendered listing, a gallery, a widget) will sit. There's no fixed
 * vocabulary, so the match is structural, not a word list: a paragraph,
 * heading, blockquote, or list item whose ENTIRE trimmed text is one
 * bracketed string (see PLACEHOLDER_PATTERN) is dropped before rendering —
 * never a bracket occurring inside a sentence (`our range [see fig 3] is
 * wide` is untouched; that's the rule that protects `[sic]`-style legitimate
 * content, mid-sentence). A list left with no items after stripping is
 * dropped entirely rather than emitting an empty `<ul></ul>`. See
 * _stripPlaceholderNodes()/_stripPlaceholderDocNodes() — the two entry
 * points below are the only places this filtering happens; every other
 * caller (MatrixBuilder, ImportService) reaches it through render()/
 * renderDocument()/extractHeading().
 *
 * Not stateless: a per-page placeholder-drop counter is threaded through the
 * three methods above and read via getPlaceholderCount() — see that
 * method's docblock for the reset contract.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class NodesRenderer extends Component
{
    /**
     * Matches a string whose ENTIRE (already-trimmed) content is a single
     * bracketed placeholder — e.g. "[Image gallery]", "[Product grid]",
     * "[sic]". No inner brackets allowed, so nested/adjacent bracket pairs
     * never match. Deliberately does NOT match brackets occurring inside a
     * sentence ("our range [see fig 3] is wide") — see the class docblock.
     */
    private const PLACEHOLDER_PATTERN = '/^\[[^\[\]]*\]$/';

    // Private Properties
    // =========================================================================

    /**
     * Count of placeholder nodes/items dropped since the last resetPlaceholderCount()
     * call — see getPlaceholderCount().
     *
     * @var int
     */
    private int $_placeholdersDropped = 0;

    // Public Methods
    // =========================================================================

    /**
     * Resets the placeholder-drop counter to zero.
     *
     * Call once per page, before any render()/renderDocument()/extractHeading()
     * call for that page — ImportService::importPage() is the single caller,
     * right at the top, so every downstream nodes-> call for the page
     * (MatrixBuilder::build(), collection-child content, CTA richText, …)
     * accumulates into the same count. Mirrors MatrixBuilder's own
     * $_warnings reset-at-start-of-build() idiom, one level up.
     *
     * @return void
     */
    public function resetPlaceholderCount(): void
    {
        $this->_placeholdersDropped = 0;
    }

    /**
     * Returns the number of placeholder nodes/items dropped since the last
     * resetPlaceholderCount() call — read by ImportService after building a
     * page's result, to populate the sync report's per-page count.
     *
     * @return int
     */
    public function getPlaceholderCount(): int
    {
        return $this->_placeholdersDropped;
    }

    /**
     * Renders an array of ContentIQ nodes to an HTML string.
     *
     * Returns an empty string for null or empty input — callers should handle
     * empty-string fields as they see fit.
     *
     * @param array|null $nodes
     * @return string
     */
    public function render(?array $nodes): string
    {
        if (empty($nodes)) {
            return '';
        }

        $nodes = $this->_stripPlaceholderNodes($nodes);

        $html = '';

        foreach ($nodes as $node) {
            $html .= $this->_renderNode($node);
        }

        return $html;
    }

    /**
     * Public access to inline content rendering for other services (e.g. MatrixBuilder).
     *
     * @param array $inlineNodes
     * @return string
     */
    public function renderInlineContent(array $inlineNodes): string
    {
        return $this->_renderInlineContent($inlineNodes);
    }

    /**
     * Renders a raw ProseMirror document to an HTML string.
     *
     * Unlike render(), which consumes ContentIQ's block-serialised node shape,
     * this consumes the raw `pages.content` ProseMirror AST carried by collection
     * children: a doc node (or bare content array) of block nodes. Handles the
     * node/mark types ContentIQ produces: paragraph, heading (h1–h6 via attrs.level),
     * blockquote, bulletList, orderedList, listItem, hardBreak, and the inline
     * marks bold/italic/link (reusing the shared inline renderer).
     *
     * `horizontalRule` nodes are deliberately NOT rendered — they're a
     * ContentiQ layout aide, never CMS content (see _renderDocNode()). The app
     * strips them at export now, but this is skipped defensively here too, for
     * older app payloads and legacy full-content syncs.
     *
     * @param array|null $doc Raw ProseMirror doc ({type:'doc', content:[...]}) or its content array.
     * @return string
     */
    public function renderDocument(?array $doc): string
    {
        if (empty($doc)) {
            return '';
        }

        // Accept either a doc node or a bare content array.
        $nodes = $doc['content'] ?? (isset($doc['type']) ? [] : $doc);

        if (!is_array($nodes)) {
            return '';
        }

        $nodes = $this->_stripPlaceholderDocNodes($nodes);

        $html = '';
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $html .= $this->_renderDocNode($node);
            }
        }

        return $html;
    }

    /**
     * Extracts the first heading of the given level from a raw ProseMirror doc.
     *
     * Returns the heading's plain text (inline marks stripped) plus the document
     * with that one heading node removed — so it isn't duplicated when the
     * remaining content is rendered to the body field. When no matching heading
     * is found, `text` is null and the doc is returned unchanged. The returned
     * doc keeps the same shape it arrived in (doc node or bare content array).
     *
     * @param array|null $doc Raw ProseMirror doc or its content array.
     * @param int $level Heading level to extract (default 1 — the H1).
     * @return array{text: ?string, doc: array|null}
     */
    public function extractHeading(?array $doc, int $level = 1): array
    {
        if (empty($doc)) {
            return ['text' => null, 'doc' => $doc];
        }

        $nodes = $doc['content'] ?? (isset($doc['type']) ? [] : $doc);

        if (!is_array($nodes)) {
            return ['text' => null, 'doc' => $doc];
        }

        $text                       = null;
        $kept                       = [];
        $placeholderHeadingsSkipped = 0;

        foreach ($nodes as $node) {
            if ($text === null
                && is_array($node)
                && ($node['type'] ?? '') === 'heading'
                && (int)($node['attrs']['level'] ?? 0) === $level
            ) {
                $headingText = $this->_plainText($node['content'] ?? []);

                if ($this->_isPlaceholder($headingText)) {
                    // A placeholder heading (e.g. "[Product grid]") is a
                    // layout aide, never a real title — it must never become
                    // the lifted title. Drop it from consideration and keep
                    // scanning for a genuine H1; only counted below if this
                    // doc is actually the one that ends up used (see the
                    // $text === null early return just below the loop —
                    // when nothing ever matches, the ORIGINAL doc is
                    // returned untouched, and renderDocument()'s own pass
                    // over that doc catches this same node — counting it
                    // here too would double-count it).
                    $placeholderHeadingsSkipped++;
                    continue;
                }

                $text = $headingText;
                continue; // drop this heading from the body
            }

            $kept[] = $node;
        }

        // Nothing matched — return the doc untouched.
        if ($text === null) {
            return ['text' => null, 'doc' => $doc];
        }

        $this->_placeholdersDropped += $placeholderHeadingsSkipped;

        if (isset($doc['content'])) {
            $doc['content'] = $kept;
        } else {
            $doc = $kept;
        }

        return ['text' => $text, 'doc' => $doc];
    }

    // Private Methods
    // =========================================================================

    /**
     * Flattens ProseMirror inline content to plain text, dropping marks.
     *
     * Recurses through any nested `content` (e.g. nodes that wrap inline runs)
     * and concatenates `text`. Used to lift a heading into a plain-text field.
     *
     * @param array $inlineNodes
     * @return string
     */
    private function _plainText(array $inlineNodes): string
    {
        $text = '';

        foreach ($inlineNodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (isset($node['text'])) {
                $text .= $node['text'];
            } elseif (isset($node['content']) && is_array($node['content'])) {
                $text .= $this->_plainText($node['content']);
            }
        }

        return trim($text);
    }

    /**
     * Whether a (trimmed) text string is a standalone bracketed placeholder —
     * see PLACEHOLDER_PATTERN and the class docblock.
     *
     * @param string $text
     * @return bool
     */
    private function _isPlaceholder(string $text): bool
    {
        return (bool)preg_match(self::PLACEHOLDER_PATTERN, trim($text));
    }

    /**
     * Extracts a ContentiQ block-format node's (paragraph/heading/blockquote)
     * plain text — `content[]` inline nodes (marks stripped) when present,
     * otherwise the `text` string. Same fallback _renderHeading()/
     * _renderParagraph()/_renderBlockquote() use for rendering; here it's used
     * only to test the placeholder shape, so marks never matter.
     *
     * @param array $node
     * @return string
     */
    private function _blockNodeText(array $node): string
    {
        if (isset($node['content']) && is_array($node['content'])) {
            return $this->_plainText($node['content']);
        }

        return is_scalar($node['text'] ?? null) ? trim((string)$node['text']) : '';
    }

    /**
     * Filters placeholder nodes out of a ContentiQ block-format nodes array —
     * the render() entry point. Drops a paragraph/heading/blockquote whose
     * entire text is a standalone bracketed placeholder, and filters
     * placeholder items out of a list (dropping the whole list if every item
     * was one). See the class docblock; this is the ONLY place render()'s
     * placeholder rule lives — every _handle*() caller in MatrixBuilder
     * reaches it by calling render(), never by re-implementing the check.
     *
     * @param array $nodes
     * @return array
     */
    private function _stripPlaceholderNodes(array $nodes): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                $kept[] = $node;
                continue;
            }

            $type = $node['type'] ?? '';

            if ($type === 'paragraph' || $type === 'heading' || $type === 'blockquote') {
                if ($this->_isPlaceholder($this->_blockNodeText($node))) {
                    $this->_placeholdersDropped++;
                    continue;
                }

                $kept[] = $node;
                continue;
            }

            if ($type === 'list' || $type === 'ordered_list' || $type === 'unordered_list') {
                $filtered = $this->_stripPlaceholderListItems($node);

                if ($filtered === null) {
                    continue; // every item was a placeholder — drop the whole list
                }

                $kept[] = $filtered;
                continue;
            }

            $kept[] = $node;
        }

        return $kept;
    }

    /**
     * Filters placeholder items out of a block-format list node's `items`/
     * `itemContents` arrays (kept index-aligned — same pairing _renderList()
     * reads). Returns null when every item was a placeholder, signalling the
     * caller to drop the whole list rather than keep an empty one.
     *
     * @param array $node
     * @return array|null
     */
    private function _stripPlaceholderListItems(array $node): ?array
    {
        $items = $node['items'] ?? null;

        if (!is_array($items)) {
            return $node; // no items[] to filter — leave the node untouched
        }

        $itemContents = $node['itemContents'] ?? null;

        $keptItems        = [];
        $keptItemContents = [];
        $anyKept          = false;

        foreach ($items as $i => $item) {
            if (is_array($itemContents) && isset($itemContents[$i]) && is_array($itemContents[$i])) {
                $text = $this->_plainText($itemContents[$i]);
            } elseif (is_string($item)) {
                $text = trim($item);
            } else {
                $text = is_array($item) && is_scalar($item['text'] ?? null) ? trim((string)$item['text']) : '';
            }

            if ($this->_isPlaceholder($text)) {
                $this->_placeholdersDropped++;
                continue;
            }

            $keptItems[] = $item;
            if (is_array($itemContents) && isset($itemContents[$i])) {
                $keptItemContents[] = $itemContents[$i];
            }
            $anyKept = true;
        }

        if (!$anyKept) {
            return null;
        }

        $node['items'] = $keptItems;
        if ($itemContents !== null) {
            $node['itemContents'] = $keptItemContents;
        }

        return $node;
    }

    /**
     * Filters placeholder nodes out of a raw ProseMirror doc-format nodes
     * array — the renderDocument() entry point. Drops a paragraph/heading
     * whose entire text is a standalone bracketed placeholder, and filters
     * placeholder listItem children out of a bulletList/orderedList
     * (dropping the whole list if every item was one). Blockquote is
     * deliberately NOT covered here — the general rule only applies to
     * paragraph/heading/list in the raw-ProseMirror shape (see the brief this
     * shipped against); render()'s block-format path does cover blockquote.
     *
     * @param array $nodes
     * @return array
     */
    private function _stripPlaceholderDocNodes(array $nodes): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                $kept[] = $node;
                continue;
            }

            $type = $node['type'] ?? '';

            if ($type === 'paragraph' || $type === 'heading') {
                if ($this->_isPlaceholder($this->_plainText($node['content'] ?? []))) {
                    $this->_placeholdersDropped++;
                    continue;
                }

                $kept[] = $node;
                continue;
            }

            if ($type === 'bulletList' || $type === 'orderedList') {
                $filtered = $this->_stripPlaceholderDocListItems($node);

                if ($filtered === null) {
                    continue; // every listItem was a placeholder — drop the whole list
                }

                $kept[] = $filtered;
                continue;
            }

            $kept[] = $node;
        }

        return $kept;
    }

    /**
     * Filters placeholder `listItem` children out of a raw ProseMirror
     * bulletList/orderedList node's `content`. Returns null when nothing but
     * (placeholder) listItems remain, signalling the caller to drop the whole
     * list rather than keep an empty one.
     *
     * @param array $node
     * @return array|null
     */
    private function _stripPlaceholderDocListItems(array $node): ?array
    {
        $children = $node['content'] ?? [];

        if (!is_array($children)) {
            return $node;
        }

        $kept        = [];
        $hasListItem = false;

        foreach ($children as $child) {
            if (!is_array($child) || ($child['type'] ?? '') !== 'listItem') {
                $kept[] = $child;
                continue;
            }

            if ($this->_isPlaceholder($this->_docListItemText($child))) {
                $this->_placeholdersDropped++;
                continue;
            }

            $kept[]      = $child;
            $hasListItem = true;
        }

        if (!$hasListItem) {
            return null;
        }

        $node['content'] = $kept;

        return $node;
    }

    /**
     * A raw ProseMirror listItem's plain text — the concatenation of its
     * direct paragraph children's inline text (marks stripped). Mirrors
     * _renderListItem()'s own unwrapping of paragraph children; a nested
     * list inside the listItem contributes nothing to this text (it isn't a
     * paragraph), matching how _renderListItem() recurses it separately
     * rather than flattening it to text.
     *
     * @param array $listItem
     * @return string
     */
    private function _docListItemText(array $listItem): string
    {
        $text = '';

        foreach ($listItem['content'] ?? [] as $child) {
            if (is_array($child) && ($child['type'] ?? '') === 'paragraph') {
                $text .= $this->_plainText($child['content'] ?? []);
            }
        }

        return trim($text);
    }

    /**
     * Renders a single node to HTML.
     *
     * Unknown node types are silently skipped.
     *
     * @param array $node
     * @return string
     */
    private function _renderNode(array $node): string
    {
        $type = $node['type'] ?? '';

        return match ($type) {
            'heading'        => $this->_renderHeading($node),
            'paragraph'      => $this->_renderParagraph($node),
            'blockquote'     => $this->_renderBlockquote($node),
            'list'           => $this->_renderList($node, !empty($node['ordered']) ? 'ol' : 'ul'),
            'ordered_list'   => $this->_renderList($node, 'ol'),
            'unordered_list' => $this->_renderList($node, 'ul'),
            'faq_items'      => $this->_renderFaqItems($node),
            'table'          => $this->_renderTable($node),
            'ctaButton'      => $this->_renderCtaButton($node),
            default          => '',
        };
    }

    /**
     * Renders a single raw-ProseMirror block node to HTML.
     *
     * Unknown node types are silently skipped.
     *
     * @param array $node
     * @return string
     */
    private function _renderDocNode(array $node): string
    {
        $type = $node['type'] ?? '';

        return match ($type) {
            'heading'        => $this->_renderDocHeading($node),
            'paragraph'      => '<p>' . $this->_renderInlineContent($node['content'] ?? []) . '</p>',
            'blockquote'     => $this->_renderDocBlockquote($node),
            'bulletList'     => $this->_renderDocList($node, 'ul'),
            'orderedList'    => $this->_renderDocList($node, 'ol'),
            'listItem'       => '<li>' . $this->_renderListItem($node) . '</li>',
            // horizontalRule nodes are a ContentiQ layout aide, not CMS
            // content — they must never reach the CMS. The app strips them
            // at export now, but this arm skips them defensively too, for
            // older app payloads and legacy full-content syncs. Listed
            // explicitly (rather than left to `default`) so the intent is
            // visible next to the other node types.
            'horizontalRule' => '',
            'hardBreak'      => '<br>',
            default          => '',
        };
    }

    /**
     * Renders a raw ProseMirror heading node to <h1>–<h6> (level from attrs.level).
     *
     * @param array $node
     * @return string
     */
    private function _renderDocHeading(array $node): string
    {
        $level = (int)($node['attrs']['level'] ?? 1);
        $level = max(1, min(6, $level));

        return "<h{$level}>" . $this->_renderInlineContent($node['content'] ?? []) . "</h{$level}>";
    }

    /**
     * Renders a raw ProseMirror blockquote node to <blockquote>.
     *
     * A blockquote wraps one or more block children (paragraphs, lists, etc.).
     * Each child is rendered through the block-node renderer so nested markup
     * (e.g. a list inside the quote) keeps its own tags. Returns an empty string
     * when the blockquote produces no inner content.
     *
     * @param array $node
     * @return string
     */
    private function _renderDocBlockquote(array $node): string
    {
        $inner = '';

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $inner .= $this->_renderDocNode($child);
            }
        }

        return $inner === '' ? '' : "<blockquote>{$inner}</blockquote>";
    }

    /**
     * Renders a raw ProseMirror bulletList/orderedList to <ul>/<ol>.
     *
     * @param array  $node
     * @param string $tag 'ul' or 'ol'
     * @return string
     */
    private function _renderDocList(array $node, string $tag): string
    {
        $items = '';
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child) && ($child['type'] ?? '') === 'listItem') {
                $items .= '<li>' . $this->_renderListItem($child) . '</li>';
            }
        }

        return $items === '' ? '' : "<{$tag}>{$items}</{$tag}>";
    }

    /**
     * Renders the inner content of a listItem.
     *
     * A listItem holds block children (typically a paragraph, possibly a nested
     * list). Paragraph children are unwrapped to inline content so the output is
     * clean `<li>text</li>`; nested lists recurse via the block renderer.
     *
     * @param array $node
     * @return string
     */
    private function _renderListItem(array $node): string
    {
        $html = '';
        foreach ($node['content'] ?? [] as $child) {
            if (!is_array($child)) {
                continue;
            }
            if (($child['type'] ?? '') === 'paragraph') {
                $html .= $this->_renderInlineContent($child['content'] ?? []);
            } else {
                $html .= $this->_renderDocNode($child);
            }
        }

        return $html;
    }

    /**
     * Renders a heading node to <h1>–<h4>.
     *
     * Clamps level to the range 1–4. Defaults to <h2> if level is missing.
     * Uses inline content (with marks) when present, otherwise falls back to plain text.
     *
     * @param array $node
     * @return string
     */
    private function _renderHeading(array $node): string
    {
        $level = (int)($node['level'] ?? 2);
        $level = max(1, min(4, $level));

        $inner = isset($node['content']) && is_array($node['content'])
            ? $this->_renderInlineContent($node['content'])
            : htmlspecialchars($node['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<h{$level}>{$inner}</h{$level}>";
    }

    /**
     * Renders a paragraph node to <p>.
     *
     * Uses inline content (with marks) when present, otherwise falls back to plain text.
     *
     * @param array $node
     * @return string
     */
    private function _renderParagraph(array $node): string
    {
        $inner = isset($node['content']) && is_array($node['content'])
            ? $this->_renderInlineContent($node['content'])
            : htmlspecialchars($node['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<p>{$inner}</p>";
    }

    /**
     * Renders a blockquote node to <blockquote><p>…</p></blockquote>.
     *
     * Uses inline content (with marks) when present, otherwise falls back to plain
     * text — same idiom as _renderParagraph(). CKEditor's Block Quote feature
     * expects a paragraph inside the blockquote, not bare inline content. Returns
     * an empty string for an empty blockquote so nothing is rendered.
     *
     * @param array $node
     * @return string
     */
    private function _renderBlockquote(array $node): string
    {
        $inner = isset($node['content']) && is_array($node['content'])
            ? $this->_renderInlineContent($node['content'])
            : htmlspecialchars($node['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($inner === '') {
            return '';
        }

        return "<blockquote><p>{$inner}</p></blockquote>";
    }

    /**
     * Renders an ordered or unordered list node.
     *
     * Uses itemContents (with marks) when present, otherwise falls back to plain text items.
     *
     * @param array  $node
     * @param string $tag  'ol' or 'ul'
     * @return string
     */
    private function _renderList(array $node, string $tag): string
    {
        $items        = $node['items'] ?? [];
        $itemContents = $node['itemContents'] ?? [];

        if (empty($items)) {
            return '';
        }

        $lis = '';

        foreach ($items as $i => $item) {
            if (isset($itemContents[$i]) && is_array($itemContents[$i])) {
                $inner = $this->_renderInlineContent($itemContents[$i]);
            } else {
                $text  = is_string($item) ? $item : ($item['text'] ?? '');
                $inner = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $lis .= "<li>{$inner}</li>";
        }

        return "<{$tag}>{$lis}</{$tag}>";
    }

    /**
     * Renders a faq_items node as details/summary accordion elements.
     *
     * Each FAQ item becomes a <details><summary>question</summary><p>answer</p></details>.
     * CKEditor's Rich Text field supports details/summary natively.
     * Uses questionContent/answerContent (with marks) when present.
     *
     * @param array $node
     * @return string
     */
    private function _renderFaqItems(array $node): string
    {
        $items = $node['faqItems'] ?? [];

        if (empty($items)) {
            return '';
        }

        $html = '';

        foreach ($items as $item) {
            $question = isset($item['questionContent']) && is_array($item['questionContent'])
                ? $this->_renderInlineContent($item['questionContent'])
                : htmlspecialchars($item['question'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $answer = isset($item['answerContent']) && is_array($item['answerContent'])
                ? $this->_renderInlineContent($item['answerContent'])
                : htmlspecialchars($item['answer'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if ($question !== '' || $answer !== '') {
                $html .= "<details><summary>{$question}</summary><p>{$answer}</p></details>";
            }
        }

        return $html;
    }

    /**
     * Renders a table node as an HTML table.
     *
     * Rows with isHeader = true are rendered in <thead> using <th> cells.
     * All other rows are rendered in <tbody> using <td> cells.
     * Uses cellContents (with marks) when present.
     *
     * @param array $node
     * @return string
     */
    private function _renderTable(array $node): string
    {
        $rows = $node['tableRows'] ?? [];

        if (empty($rows)) {
            return '';
        }

        $headerRows = array_values(array_filter($rows, fn ($r) => !empty($r['isHeader'])));
        $bodyRows   = array_values(array_filter($rows, fn ($r) => empty($r['isHeader'])));

        $thead = '';
        foreach ($headerRows as $row) {
            $cells        = '';
            $cellContents = $row['cellContents'] ?? [];
            foreach ($row['cells'] ?? [] as $i => $cell) {
                $inner   = isset($cellContents[$i]) && is_array($cellContents[$i])
                    ? $this->_renderInlineContent($cellContents[$i])
                    : htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $cells .= "<th>{$inner}</th>";
            }
            $thead .= "<tr>{$cells}</tr>";
        }

        $tbody = '';
        foreach ($bodyRows as $row) {
            $cells        = '';
            $cellContents = $row['cellContents'] ?? [];
            foreach ($row['cells'] ?? [] as $i => $cell) {
                $inner   = isset($cellContents[$i]) && is_array($cellContents[$i])
                    ? $this->_renderInlineContent($cellContents[$i])
                    : htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $cells .= "<td>{$inner}</td>";
            }
            $tbody .= "<tr>{$cells}</tr>";
        }

        $html = '<table>';
        if ($thead !== '') {
            $html .= "<thead>{$thead}</thead>";
        }
        if ($tbody !== '') {
            $html .= "<tbody>{$tbody}</tbody>";
        }
        $html .= '</table>';

        return $html;
    }

    /**
     * Renders an InlineNode array to an HTML string.
     *
     * Handles text nodes (with marks) and hardBreak nodes. Marks are applied
     * innermost-first so the first mark in the array becomes the outermost tag.
     *
     * @param array $inlineNodes
     * @return string
     */
    private function _renderInlineContent(array $inlineNodes): string
    {
        $html = '';

        foreach ($inlineNodes as $node) {
            if (($node['type'] ?? '') === 'hardBreak') {
                $html .= '<br>';
                continue;
            }

            $text = htmlspecialchars($node['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            foreach (array_reverse($node['marks'] ?? []) as $mark) {
                $text = $this->_wrapMark($mark, $text);
            }

            $html .= $text;
        }

        return $html;
    }

    /**
     * Wraps an HTML string in the appropriate tag for a ProseMirror mark.
     *
     * Unknown mark types pass through unchanged.
     *
     * @param array  $mark
     * @param string $inner
     * @return string
     */
    private function _wrapMark(array $mark, string $inner): string
    {
        return match ($mark['type'] ?? '') {
            'bold'      => "<strong>{$inner}</strong>",
            'italic'    => "<em>{$inner}</em>",
            'code'      => "<code>{$inner}</code>",
            'strike'    => "<s>{$inner}</s>",
            'underline' => "<u>{$inner}</u>",
            'link'      => $this->_wrapLink($mark, $inner),
            default     => $inner,
        };
    }

    /**
     * Wraps an HTML string in an anchor tag using the link mark's href attr.
     *
     * @param array  $mark
     * @param string $inner
     * @return string
     */
    private function _wrapLink(array $mark, string $inner): string
    {
        $safeUrl = UrlSafety::safeHref((string)($mark['attrs']['href'] ?? ''));
        $href    = htmlspecialchars($safeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $target  = $mark['attrs']['target'] ?? '';
        $rel     = $mark['attrs']['rel'] ?? '';

        $attrs = "href=\"{$href}\"";
        if ($target !== '' && $target !== null) {
            $attrs .= ' target="' . htmlspecialchars((string)$target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        if ($rel !== '' && $rel !== null) {
            $attrs .= ' rel="' . htmlspecialchars((string)$rel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return "<a {$attrs}>{$inner}</a>";
    }

    /**
     * Renders a ctaButton node as an anchor link.
     *
     * URL is always empty from ContentIQ (set by editors in the CMS after import).
     * Wraps in <p> so it occupies its own line in CKEditor output.
     *
     * @param array $node
     * @return string
     */
    private function _renderCtaButton(array $node): string
    {
        $label = htmlspecialchars($node['label'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url   = htmlspecialchars(UrlSafety::safeHref((string)($node['url'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($label === '') {
            return '';
        }

        // `not-prose` opts this button out of the rich-text prose styling. Unlike
        // template-rendered buttons (button.twig adds not-prose), this <a> is
        // embedded in CKEditor HTML where prose styles would otherwise restyle it.
        return "<p><a href=\"{$url}\" class=\"btn btn-primary not-prose\">{$label}</a></p>";
    }
}
